<?php
// Crowd marketing links management helpers: import, listing, background checks

if (!function_exists('pp_crowd_links_log')) {
    function pp_crowd_links_log(string $message, array $context = []): void {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (!is_dir($dir) || !is_writable($dir)) { return; }
            $file = $dir . '/crowd_links.log';
            $timestamp = date('Y-m-d H:i:s');
            $line = '[' . $timestamp . '] ' . $message;
            if (!empty($context)) {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($encoded !== false && $encoded !== null) {
                    $line .= ' ' . $encoded;
                }
            }
            $line .= "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // ignore logging errors
        }
    }
}

if (!function_exists('pp_crowd_links_status_meta')) {
    function pp_crowd_links_status_meta(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [
            'pending' => ['label' => __('Не проверена'), 'class' => 'badge bg-secondary'],
            'checking' => ['label' => __('Проверяется'), 'class' => 'badge bg-info text-dark'],
            'ok' => ['label' => __('Рабочая'), 'class' => 'badge bg-success'],
            'redirect' => ['label' => __('Редирект (3xx)'), 'class' => 'badge bg-warning text-dark'],
            'client_error' => ['label' => __('Ошибка клиента (4xx)'), 'class' => 'badge bg-danger'],
            'server_error' => ['label' => __('Ошибка сервера (5xx)'), 'class' => 'badge bg-danger'],
            'unreachable' => ['label' => __('Недоступна'), 'class' => 'badge bg-dark'],
            'cancelled' => ['label' => __('Отменена'), 'class' => 'badge bg-secondary'],
        ];
        return $cache;
    }
}

if (!function_exists('pp_crowd_links_is_error_status')) {
    function pp_crowd_links_is_error_status(string $status): bool {
        return in_array($status, ['redirect', 'client_error', 'server_error', 'unreachable'], true);
    }
}

if (!function_exists('pp_crowd_links_normalize_url')) {
    function pp_crowd_links_normalize_url(string $rawUrl): ?array {
        $url = trim($rawUrl);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = @parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (':' . (int)$parts['port']) : '';
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';
        $normalized = $scheme . '://' . $host . $port . ($path !== '' ? $path : '/') . $query . $fragment;
        $domain = preg_replace('~^www\.~i', '', $host);
        $hash = sha1($normalized);
        return [
            'url' => $normalized,
            'domain' => $domain,
            'host' => $host,
            'hash' => $hash,
        ];
    }
}

if (!function_exists('pp_crowd_links_import_files')) {
    /**
     * Import multiple TXT files with URLs.
     * @param array $fileSpec Raw $_FILES entry (with name/tmp_name arrays when multiple)
     * @return array{ok:bool,imported:int,duplicates:int,invalid:int,errors:array}
     */
    function pp_crowd_links_import_files(array $fileSpec): array {
        $result = [
            'ok' => false,
            'imported' => 0,
            'duplicates' => 0,
            'invalid' => 0,
            'errors' => [],
        ];
        $fileCount = 0;
        $files = [];
        if (isset($fileSpec['tmp_name']) && is_array($fileSpec['tmp_name'])) {
            $fileCount = count($fileSpec['tmp_name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $files[] = [
                    'tmp_name' => $fileSpec['tmp_name'][$i] ?? '',
                    'name' => $fileSpec['name'][$i] ?? ('file_' . ($i + 1)),
                    'size' => $fileSpec['size'][$i] ?? 0,
                    'error' => $fileSpec['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                ];
            }
        } elseif (!empty($fileSpec['tmp_name'])) {
            $files[] = [
                'tmp_name' => $fileSpec['tmp_name'],
                'name' => $fileSpec['name'] ?? 'upload.txt',
                'size' => $fileSpec['size'] ?? 0,
                'error' => $fileSpec['error'] ?? UPLOAD_ERR_NO_FILE,
            ];
        }
        if (empty($files)) {
            $result['errors'][] = __('Файлы для импорта не выбраны.');
            return $result;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            $result['errors'][] = __('Не удалось подключиться к базе данных.');
            return $result;
        }
        if (!$conn) {
            $result['errors'][] = __('Не удалось подключиться к базе данных.');
            return $result;
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO crowd_links (url, url_hash, domain) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE domain = VALUES(domain)");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            $result['errors'][] = __('Не удалось подготовить запрос вставки ссылок.');
            return $result;
        }
        $seen = [];
        $maxSize = 10 * 1024 * 1024; // 10 MB per file
        foreach ($files as $file) {
            $tmp = $file['tmp_name'];
            $name = (string)$file['name'];
            $size = (int)($file['size'] ?? 0);
            $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $result['errors'][] = sprintf(__('Файл %s не был загружен.'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
                continue;
            }
            if ($size <= 0) {
                $result['errors'][] = sprintf(__('Файл %s пуст.'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
                continue;
            }
            if ($size > $maxSize) {
                $result['errors'][] = sprintf(__('Файл %s превышает допустимый размер %d МБ.'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), (int)($maxSize / 1048576));
                continue;
            }
            $handle = @fopen($tmp, 'r');
            if (!$handle) {
                $result['errors'][] = sprintf(__('Не удалось открыть файл %s.'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
                continue;
            }
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $normalized = pp_crowd_links_normalize_url($line);
                if (!$normalized) {
                    $result['invalid']++;
                    continue;
                }
                $hash = $normalized['hash'];
                if (isset($seen[$hash])) {
                    $result['duplicates']++;
                    continue;
                }
                $seen[$hash] = true;
                $stmt->bind_param('sss', $normalized['url'], $hash, $normalized['domain']);
                try {
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        $result['imported']++;
                    } else {
                        $result['duplicates']++;
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() === 1062) {
                        $result['duplicates']++;
                    } else {
                        $result['errors'][] = sprintf(__('Ошибка при импорте ссылки %s: %s'), htmlspecialchars($normalized['url'], ENT_QUOTES, 'UTF-8'), $e->getMessage());
                    }
                }
            }
            fclose($handle);
        }
        $stmt->close();
        $conn->commit();
        $conn->close();
        $result['ok'] = empty($result['errors']);
        return $result;
    }
}

if (!function_exists('pp_crowd_links_get_stats')) {
    function pp_crowd_links_get_stats(): array {
        $stats = [
            'total' => 0,
            'ok' => 0,
            'errors' => 0,
            'pending' => 0,
            'checking' => 0,
        ];
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return $stats;
        }
        if (!$conn) {
            return $stats;
        }
        $sql = "SELECT status, COUNT(*) AS cnt FROM crowd_links GROUP BY status";
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)($row['status'] ?? '');
                $count = (int)($row['cnt'] ?? 0);
                $stats['total'] += $count;
                if ($status === 'ok') {
                    $stats['ok'] += $count;
                } elseif ($status === 'checking') {
                    $stats['checking'] += $count;
                } elseif ($status === 'pending') {
                    $stats['pending'] += $count;
                } elseif (pp_crowd_links_is_error_status($status)) {
                    $stats['errors'] += $count;
                }
            }
            $res->free();
        }
        $conn->close();
        return $stats;
    }
}

if (!function_exists('pp_crowd_links_list')) {
    /**
     * Fetch paginated list of crowd links or grouped domains.
     * @param array $options
     * @return array{items:array,total:int,page:int,per_page:int,pages:int,group:string}
     */
    function pp_crowd_links_list(array $options): array {
        $page = max(1, (int)($options['page'] ?? 1));
        $perPage = (int)($options['per_page'] ?? 25);
        if ($perPage < 10) { $perPage = 10; }
        if ($perPage > 200) { $perPage = 200; }
        $group = in_array(($options['group'] ?? 'links'), ['links', 'domains'], true) ? $options['group'] : 'links';
        $status = trim((string)($options['status'] ?? ''));
        $domain = trim((string)($options['domain'] ?? ''));
        $language = trim((string)($options['language'] ?? ''));
        $region = trim((string)($options['region'] ?? ''));
        $search = trim((string)($options['search'] ?? ''));
        $order = trim((string)($options['order'] ?? 'recent'));
        $allowedOrders = [
            'recent' => 'cl.created_at DESC',
            'oldest' => 'cl.created_at ASC',
            'status' => 'cl.status ASC, cl.created_at DESC',
            'domain' => 'cl.domain ASC, cl.created_at DESC',
            'checked' => 'cl.last_checked_at DESC NULLS LAST',
        ];
        $orderBy = $allowedOrders[$order] ?? $allowedOrders['recent'];
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0, 'group' => $group];
        }
        if (!$conn) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0, 'group' => $group];
        }
        $where = [];
        if ($status !== '') {
            if ($status === 'errors') {
                $where[] = "(cl.status IN ('redirect','client_error','server_error','unreachable'))";
            } else {
                $where[] = "cl.status = '" . $conn->real_escape_string($status) . "'";
            }
        }
        if ($domain !== '') {
            $where[] = "cl.domain LIKE '%" . $conn->real_escape_string($domain) . "%'";
        }
        if ($language !== '') {
            $where[] = "cl.language = '" . $conn->real_escape_string($language) . "'";
        }
        if ($region !== '') {
            $where[] = "cl.region = '" . $conn->real_escape_string($region) . "'";
        }
        if ($search !== '') {
            $esc = $conn->real_escape_string($search);
            $where[] = "(cl.url LIKE '%$esc%' OR cl.error LIKE '%$esc%')";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        if ($group === 'domains') {
            $sqlCount = "SELECT COUNT(*) AS c FROM (SELECT cl.domain FROM crowd_links cl $whereSql GROUP BY cl.domain) t";
            $total = 0;
            if ($res = @$conn->query($sqlCount)) {
                if ($row = $res->fetch_assoc()) {
                    $total = (int)($row['c'] ?? 0);
                }
                $res->free();
            }
            $offset = ($page - 1) * $perPage;
            if ($offset < 0) { $offset = 0; }
            $sql = "SELECT cl.domain, COUNT(*) AS total_links,
                        SUM(cl.status = 'ok') AS ok_links,
                        SUM(cl.status = 'pending') AS pending_links,
                        SUM(cl.status = 'checking') AS checking_links,
                        SUM(cl.status IN ('redirect','client_error','server_error','unreachable')) AS error_links,
                        MAX(cl.last_checked_at) AS last_checked_at
                    FROM crowd_links cl
                    $whereSql
                    GROUP BY cl.domain
                    ORDER BY cl.domain ASC
                    LIMIT $offset, $perPage";
            $items = [];
            if ($res = @$conn->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $items[] = [
                        'domain' => (string)$row['domain'],
                        'total_links' => (int)($row['total_links'] ?? 0),
                        'ok_links' => (int)($row['ok_links'] ?? 0),
                        'pending_links' => (int)($row['pending_links'] ?? 0),
                        'checking_links' => (int)($row['checking_links'] ?? 0),
                        'error_links' => (int)($row['error_links'] ?? 0),
                        'last_checked_at' => $row['last_checked_at'] ?? null,
                    ];
                }
                $res->free();
            }
            $conn->close();
            $pages = $total > 0 ? (int)ceil($total / $perPage) : 0;
            return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'group' => 'domains'];
        }
        $sqlCount = "SELECT COUNT(*) AS c FROM crowd_links cl $whereSql";
        $total = 0;
        if ($res = @$conn->query($sqlCount)) {
            if ($row = $res->fetch_assoc()) {
                $total = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
        $offset = ($page - 1) * $perPage;
        if ($offset < 0) { $offset = 0; }
        $sql = "SELECT cl.* FROM crowd_links cl $whereSql ORDER BY $orderBy LIMIT $offset, $perPage";
        $items = [];
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
            $res->free();
        }
        $conn->close();
        $pages = $total > 0 ? (int)ceil($total / $perPage) : 0;
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'group' => 'links'];
    }
}

if (!function_exists('pp_crowd_links_delete_all')) {
    function pp_crowd_links_delete_all(): int {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$conn) {
            return 0;
        }
        $affected = 0;
        if ($res = @$conn->query('SELECT COUNT(*) AS c FROM crowd_links')) {
            if ($row = $res->fetch_assoc()) {
                $affected = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
        @$conn->query('TRUNCATE TABLE crowd_links');
        $conn->close();
        return $affected;
    }
}

if (!function_exists('pp_crowd_links_delete_errors')) {
    function pp_crowd_links_delete_errors(): int {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$conn) {
            return 0;
        }
        $sql = "DELETE FROM crowd_links WHERE status IN ('redirect','client_error','server_error','unreachable')";
        @$conn->query($sql);
        $affected = $conn->affected_rows;
        $conn->close();
        return max(0, (int)$affected);
    }
}

if (!function_exists('pp_crowd_links_delete_selected')) {
    function pp_crowd_links_delete_selected(array $ids): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function($v){ return $v > 0; })));
        if (empty($ids)) {
            return 0;
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$conn) {
            return 0;
        }
        $chunks = array_chunk($ids, 500);
        $total = 0;
        foreach ($chunks as $chunk) {
            $list = implode(',', $chunk);
            @$conn->query("DELETE FROM crowd_links WHERE id IN ($list)");
            $total += max(0, (int)$conn->affected_rows);
        }
        $conn->close();
        return $total;
    }
}

if (!function_exists('pp_crowd_links_scope_options')) {
    function pp_crowd_links_scope_options(): array {
        return [
            'all' => __('Все ссылки'),
            'pending' => __('Только не проверенные'),
            'errors' => __('Только с ошибками'),
            'selection' => __('Только выбранные'),
        ];
    }
}

if (!function_exists('pp_crowd_links_launch_worker')) {
    function pp_crowd_links_launch_worker(int $runId): bool {
        $script = PP_ROOT_PATH . '/scripts/crowd_links_worker.php';
        if (!is_file($script)) {
            return false;
        }
        $phpBinary = PHP_BINARY ?: 'php';
        $runId = max(1, $runId);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId;
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                return true;
            }
            return false;
        }
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId . ' > /dev/null 2>&1 &';
        pp_crowd_links_log('Launching worker', ['runId' => $runId, 'cmd' => $cmd]);
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                return true;
            }
        }
        @exec($cmd);
        return true;
    }
}

if (!function_exists('pp_crowd_links_wait_for_worker_start')) {
    function pp_crowd_links_wait_for_worker_start(int $runId, float $timeoutSeconds = 3.0): bool {
        $deadline = microtime(true) + max(0.5, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            try {
                $conn = @connect_db();
            } catch (Throwable $e) {
                return false;
            }
            if (!$conn) {
                return false;
            }
            $row = null;
            if ($stmt = $conn->prepare('SELECT status, started_at FROM crowd_link_runs WHERE id = ? LIMIT 1')) {
                $stmt->bind_param('i', $runId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                }
                $stmt->close();
            }
            $conn->close();
            if ($row) {
                $status = (string)($row['status'] ?? '');
                $started = (string)($row['started_at'] ?? '');
                if ($status !== 'queued' || ($started !== '' && $started !== '0000-00-00 00:00:00')) {
                    return true;
                }
            } else {
                return false;
            }
            usleep(200000);
        }
        return false;
    }
}

if (!function_exists('pp_crowd_links_collect_ids')) {
    function pp_crowd_links_collect_ids(mysqli $conn, string $scope, array $selectedIds): array {
        $ids = [];
        if ($scope === 'selection') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static function($v){ return $v > 0; })));
            return $ids;
        }
        $where = '';
        if ($scope === 'pending') {
            $where = "WHERE status IN ('pending','checking')";
        } elseif ($scope === 'errors') {
            $where = "WHERE status IN ('redirect','client_error','server_error','unreachable')";
        }
        if ($res = @$conn->query("SELECT id FROM crowd_links $where")) {
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $res->free();
        }
        return $ids;
    }
}

if (!function_exists('pp_crowd_links_start_check')) {
    function pp_crowd_links_start_check(?int $userId = null, string $scope = 'all', array $selectedIds = []): array {
        $scope = in_array($scope, ['all','pending','errors','selection'], true) ? $scope : 'all';
        pp_crowd_links_log('Request to start crowd check', ['userId' => $userId, 'scope' => $scope, 'selected' => count($selectedIds)]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        $active = null;
        if ($res = @$conn->query("SELECT id FROM crowd_link_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $active = (int)($row['id'] ?? 0);
            }
            $res->free();
        }
        if ($active) {
            $conn->close();
            return ['ok' => true, 'runId' => $active, 'alreadyRunning' => true];
        }
        $ids = pp_crowd_links_collect_ids($conn, $scope, $selectedIds);
        if (empty($ids)) {
            $conn->close();
            return ['ok' => false, 'error' => 'NO_LINKS'];
        }
        $total = count($ids);
        if ($userId !== null) {
            $stmt = $conn->prepare("INSERT INTO crowd_link_runs (status, scope, total_links, initiated_by) VALUES ('queued', ?, ?, ?)");
            if (!$stmt) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_WRITE'];
            }
            $stmt->bind_param('sii', $scope, $total, $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO crowd_link_runs (status, scope, total_links) VALUES ('queued', ?, ?)");
            if (!$stmt) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_WRITE'];
            }
            $stmt->bind_param('si', $scope, $total);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            return ['ok' => false, 'error' => 'DB_WRITE'];
        }
        $stmt->close();
        $runId = (int)$conn->insert_id;
        $chunks = array_chunk($ids, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "UPDATE crowd_links SET processing_run_id = ? WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $params = array_merge([$runId], $chunk);
                $types = str_repeat('i', count($params));
                $bind = [$types];
                foreach ($params as $idx => $value) {
                    $bind[] = &$params[$idx];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->close();
        if (!pp_crowd_links_launch_worker($runId)) {
            try {
                $conn2 = @connect_db();
                if ($conn2) {
                    $msg = __('Не удалось запустить фоновый процесс.');
                    $upd = $conn2->prepare("UPDATE crowd_link_runs SET status='failed', notes=? WHERE id=? LIMIT 1");
                    if ($upd) {
                        $upd->bind_param('si', $msg, $runId);
                        $upd->execute();
                        $upd->close();
                    }
                    $conn2->close();
                }
            } catch (Throwable $e) {
                // ignore
            }
            return ['ok' => false, 'error' => 'WORKER_LAUNCH_FAILED'];
        }
        if (!pp_crowd_links_wait_for_worker_start($runId, 3.0)) {
            pp_crowd_links_log('Worker did not start in time, running inline', ['runId' => $runId]);
            try {
                pp_crowd_links_process_run($runId);
            } catch (Throwable $e) {
                pp_crowd_links_log('Inline processing failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            }
        }
        return ['ok' => true, 'runId' => $runId, 'total' => $total, 'alreadyRunning' => false];
    }
}

if (!function_exists('pp_crowd_links_format_ts')) {
    function pp_crowd_links_format_ts(?string $ts): ?string {
        if (!$ts) { return null; }
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') { return null; }
        $time = strtotime($ts);
        if ($time === false) { return $ts; }
        return date(DATE_ATOM, $time);
    }
}

if (!function_exists('pp_crowd_links_get_status')) {
    function pp_crowd_links_get_status(?int $runId = null): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query('SELECT id FROM crowd_link_runs ORDER BY id DESC LIMIT 1')) {
                if ($row = $res->fetch_assoc()) {
                    $runId = (int)($row['id'] ?? 0);
                }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'run' => null];
        }
        $stmt = $conn->prepare('SELECT * FROM crowd_link_runs WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_READ'];
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();
        if (!$row) {
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }
        $run = [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'scope' => (string)($row['scope'] ?? 'all'),
            'total_links' => (int)($row['total_links'] ?? 0),
            'processed_count' => (int)($row['processed_count'] ?? 0),
            'ok_count' => (int)($row['ok_count'] ?? 0),
            'redirect_count' => (int)($row['redirect_count'] ?? 0),
            'client_error_count' => (int)($row['client_error_count'] ?? 0),
            'server_error_count' => (int)($row['server_error_count'] ?? 0),
            'unreachable_count' => (int)($row['unreachable_count'] ?? 0),
            'initiated_by' => $row['initiated_by'] !== null ? (int)$row['initiated_by'] : null,
            'notes' => (string)($row['notes'] ?? ''),
            'cancel_requested' => !empty($row['cancel_requested']),
            'created_at' => $row['created_at'] ?? null,
            'created_at_iso' => pp_crowd_links_format_ts($row['created_at'] ?? null),
            'started_at' => $row['started_at'] ?? null,
            'started_at_iso' => pp_crowd_links_format_ts($row['started_at'] ?? null),
            'finished_at' => $row['finished_at'] ?? null,
            'finished_at_iso' => pp_crowd_links_format_ts($row['finished_at'] ?? null),
            'last_activity_at' => $row['last_activity_at'] ?? null,
            'last_activity_iso' => pp_crowd_links_format_ts($row['last_activity_at'] ?? null),
        ];
        $run['error_count'] = $run['redirect_count'] + $run['client_error_count'] + $run['server_error_count'] + $run['unreachable_count'];
        $run['progress_percent'] = $run['total_links'] > 0 ? min(100, (int)round($run['processed_count'] * 100 / $run['total_links'])) : 0;
        $run['in_progress'] = in_array($run['status'], ['queued','running'], true);
        return ['ok' => true, 'run' => $run];
    }
}

if (!function_exists('pp_crowd_links_cancel')) {
    function pp_crowd_links_cancel(?int $runId = null, bool $force = false): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM crowd_link_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) {
                    $runId = (int)($row['id'] ?? 0);
                }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'status' => 'idle'];
        }
        $stmt = $conn->prepare('SELECT id, status, cancel_requested FROM crowd_link_runs WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_READ'];
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }
        $status = (string)($row['status'] ?? '');
        if (!in_array($status, ['queued','running'], true)) {
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => $status, 'alreadyFinished' => true];
        }
        @$conn->query("UPDATE crowd_link_runs SET cancel_requested = 1 WHERE id = " . (int)$runId . " LIMIT 1");
        if ($force || $status === 'queued') {
            @$conn->query("UPDATE crowd_link_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE id = " . (int)$runId . " LIMIT 1");
            @$conn->query("UPDATE crowd_links SET processing_run_id = NULL WHERE processing_run_id = " . (int)$runId);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'finished' => true];
        }
        $conn->close();
        return ['ok' => true, 'runId' => $runId, 'status' => $status, 'cancelRequested' => true];
    }
}

if (!function_exists('pp_crowd_links_extract_lang_region')) {
    function pp_crowd_links_extract_lang_region(string $html): array {
        $lang = '';
        $region = '';
        $doc = pp_html_dom($html);
        if ($doc) {
            $xp = new DOMXPath($doc);
            $htmlEl = $xp->query('//html')->item(0);
            if ($htmlEl instanceof DOMElement) {
                $langAttr = trim($htmlEl->getAttribute('lang'));
                if ($langAttr !== '') {
                    $parts = preg_split('~[-_]~', $langAttr);
                    $lang = strtolower($parts[0] ?? '');
                    if (isset($parts[1])) { $region = strtoupper($parts[1]); }
                }
            }
            if ($lang === '') {
                $firstHref = $xp->query("//link[translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='alternate' and @hreflang]")
                    ->item(0);
                if ($firstHref instanceof DOMElement) {
                    $hl = trim($firstHref->getAttribute('hreflang'));
                    if ($hl !== '') {
                        $parts = preg_split('~[-_]~', $hl);
                        $lang = strtolower($parts[0] ?? '');
                        if (isset($parts[1])) { $region = strtoupper($parts[1]); }
                    }
                }
            }
        }
        return ['language' => $lang, 'region' => $region];
    }
}

if (!function_exists('pp_crowd_links_update_run_counts')) {
    function pp_crowd_links_update_run_counts(mysqli $conn, int $runId, array $counts, string $status = 'running'): void {
        $stmt = $conn->prepare('UPDATE crowd_link_runs SET processed_count=?, ok_count=?, redirect_count=?, client_error_count=?, server_error_count=?, unreachable_count=?, status=?, last_activity_at=CURRENT_TIMESTAMP WHERE id = ? LIMIT 1');
        if ($stmt) {
            $types = 'iiiiii' . 'si';
            $stmt->bind_param($types, $counts['processed'], $counts['ok'], $counts['redirect'], $counts['client_error'], $counts['server_error'], $counts['unreachable'], $status, $runId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('pp_crowd_links_process_run')) {
    function pp_crowd_links_process_run(int $runId): void {
        if ($runId <= 0) { return; }
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        pp_crowd_links_log('Worker started', ['runId' => $runId]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_crowd_links_log('Worker cannot connect to DB', ['runId' => $runId, 'error' => $e->getMessage()]);
            return;
        }
        if (!$conn) {
            return;
        }
        $stmt = $conn->prepare('SELECT id, status, cancel_requested, total_links FROM crowd_link_runs WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return;
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return;
        }
        $status = (string)($runRow['status'] ?? '');
        if (!in_array($status, ['queued','running'], true)) {
            $conn->close();
            return;
        }
        @$conn->query("UPDATE crowd_link_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = " . (int)$runId . " LIMIT 1");
        $links = [];
        if ($res = @$conn->query('SELECT id, url FROM crowd_links WHERE processing_run_id = ' . (int)$runId . ' ORDER BY id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                $url = (string)($row['url'] ?? '');
                if ($id > 0 && $url !== '') {
                    $links[] = ['id' => $id, 'url' => $url];
                }
            }
            $res->free();
        }
        $total = count($links);
        if ($total === 0) {
            @$conn->query("UPDATE crowd_link_runs SET status='failed', notes='No links to process', finished_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            $conn->close();
            return;
        }
        if ((int)$runRow['total_links'] !== $total) {
            @$conn->query("UPDATE crowd_link_runs SET total_links=" . $total . " WHERE id=" . (int)$runId . " LIMIT 1");
        }
        $counts = [
            'processed' => 0,
            'ok' => 0,
            'redirect' => 0,
            'client_error' => 0,
            'server_error' => 0,
            'unreachable' => 0,
        ];
        $checkCancel = static function(mysqli $conn, int $runId): bool {
            $res = @$conn->query('SELECT cancel_requested FROM crowd_link_runs WHERE id = ' . (int)$runId . ' LIMIT 1');
            if ($res && $row = $res->fetch_assoc()) {
                $res->free();
                return !empty($row['cancel_requested']);
            }
            return false;
        };
        foreach ($links as $item) {
            if ($checkCancel($conn, $runId)) {
                pp_crowd_links_log('Worker cancellation requested', ['runId' => $runId]);
                break;
            }
            $linkId = $item['id'];
            $url = $item['url'];
            $conn->query("UPDATE crowd_links SET status='checking', last_run_id=" . (int)$runId . ", last_checked_at=CURRENT_TIMESTAMP WHERE id=" . (int)$linkId . " LIMIT 1");
            $http = pp_http_fetch($url, 18);
            $statusCode = (int)($http['status'] ?? 0);
            $body = (string)($http['body'] ?? '');
            $finalUrl = (string)($http['final_url'] ?? $url);
            $errorText = '';
            $newStatus = 'pending';
            if ($statusCode >= 200 && $statusCode < 300) {
                $newStatus = 'ok';
            } elseif ($statusCode >= 300 && $statusCode < 400) {
                $newStatus = 'redirect';
                $errorText = __('Редирект на') . ' ' . $finalUrl;
            } elseif ($statusCode >= 400 && $statusCode < 500) {
                $newStatus = 'client_error';
                $errorText = sprintf(__('HTTP %d (клиентская ошибка)'), $statusCode);
            } elseif ($statusCode >= 500 && $statusCode < 600) {
                $newStatus = 'server_error';
                $errorText = sprintf(__('HTTP %d (ошибка сервера)'), $statusCode);
            } else {
                $newStatus = 'unreachable';
                $errorText = $statusCode > 0 ? sprintf(__('Неожиданный статус HTTP %d'), $statusCode) : __('Сайт недоступен или превышено время ожидания.');
            }
            if ($body === '' && $newStatus === 'ok') {
                $newStatus = 'unreachable';
                $errorText = __('Получен пустой ответ от сервера.');
            }
            $langRegion = ['language' => '', 'region' => ''];
            if ($newStatus === 'ok' && $body !== '') {
                $langRegion = pp_crowd_links_extract_lang_region($body);
            }
            $stmt = $conn->prepare("UPDATE crowd_links SET status=?, status_code=?, error=?, language=IF(? = '', language, ?), region=IF(? = '', region, ?), processing_run_id=NULL, last_checked_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
            if ($stmt) {
                $langVal = $langRegion['language'] ?? '';
                $regionVal = $langRegion['region'] ?? '';
                if ($langVal !== '') {
                    $langVal = strtolower($langVal);
                }
                if ($regionVal !== '') {
                    $regionVal = strtoupper($regionVal);
                }
                $stmt->bind_param('sisssssi', $newStatus, $statusCode, $errorText, $langVal, $langVal, $regionVal, $regionVal, $linkId);
                $stmt->execute();
                $stmt->close();
            } else {
                @$conn->query('UPDATE crowd_links SET status=' . "'" . $conn->real_escape_string($newStatus) . "'" . ', status_code=' . (int)$statusCode . ', error=' . "'" . $conn->real_escape_string($errorText) . "'" . ', processing_run_id=NULL, last_checked_at=CURRENT_TIMESTAMP WHERE id=' . (int)$linkId . ' LIMIT 1');
            }
            $counts['processed']++;
            if (array_key_exists($newStatus, $counts)) {
                $counts[$newStatus]++;
            }
            pp_crowd_links_update_run_counts($conn, $runId, $counts, 'running');
        }
        $cancelled = $checkCancel($conn, $runId);
        if ($cancelled) {
            @$conn->query("UPDATE crowd_links SET processing_run_id=NULL WHERE processing_run_id=" . (int)$runId);
            pp_crowd_links_update_run_counts($conn, $runId, $counts, 'cancelled');
            @$conn->query("UPDATE crowd_link_runs SET status='cancelled', finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
            $conn->close();
            pp_crowd_links_log('Worker finished with cancellation', ['runId' => $runId]);
            return;
        }
        $errorSum = $counts['redirect'] + $counts['client_error'] + $counts['server_error'] + $counts['unreachable'];
        $finalStatus = ($counts['processed'] === $total && $errorSum === 0) ? 'success' : 'completed';
        pp_crowd_links_update_run_counts($conn, $runId, $counts, $finalStatus);
        @$conn->query("UPDATE crowd_link_runs SET finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
        $conn->close();
        pp_crowd_links_log('Worker finished', ['runId' => $runId, 'processed' => $counts['processed'], 'errors' => $errorSum]);
    }
}

?>
