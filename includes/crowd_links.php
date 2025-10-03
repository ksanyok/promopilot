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
            'no_form' => ['label' => __('Форма не найдена'), 'class' => 'badge bg-danger'],
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
        return in_array($status, ['redirect', 'client_error', 'server_error', 'unreachable', 'no_form'], true);
    }
}

if (!function_exists('pp_crowd_links_is_stalled_run')) {
    function pp_crowd_links_is_stalled_run(array $row, int $thresholdSeconds = 120): bool {
        $status = (string)($row['status'] ?? '');
        if (!in_array($status, ['queued', 'running'], true)) {
            return false;
        }
        $now = time();
        $lastActivityRaw = $row['last_activity_at'] ?? null;
        $lastActivityTs = null;
        if ($lastActivityRaw && $lastActivityRaw !== '0000-00-00 00:00:00') {
            $lastActivityTs = strtotime((string)$lastActivityRaw) ?: null;
        }
        if ($lastActivityTs !== null && ($now - $lastActivityTs) >= $thresholdSeconds) {
            return true;
        }
        $startedRaw = $row['started_at'] ?? null;
        if ($lastActivityTs === null && $startedRaw && $startedRaw !== '0000-00-00 00:00:00') {
            $startedTs = strtotime((string)$startedRaw) ?: null;
            if ($startedTs !== null && ($now - $startedTs) >= $thresholdSeconds) {
                return true;
            }
        }
        return false;
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
        $allowedStatuses = array_keys(pp_crowd_links_status_meta());
        $allowedStatuses[] = 'pending';
        $allowedStatuses[] = 'checking';
        $allowedStatuses = array_fill_keys(array_map('strtolower', array_unique($allowedStatuses)), true);
        $maxErrorLength = 1000;
        $cutString = static function(string $value, int $maxLen) {
            if ($maxLen <= 0) { return ''; }
            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, $maxLen);
            }
            return substr($value, 0, $maxLen);
        };

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
            $rowIndex = 0;
            while (($rawColumns = fgetcsv($handle)) !== false) {
                $rowIndex++;
                if ($rawColumns === null) {
                    continue;
                }
                if (count($rawColumns) === 1 && ($rawColumns[0] === null || trim((string)$rawColumns[0]) === '')) {
                    continue;
                }

                if ($rowIndex === 1 && isset($rawColumns[0]) && is_string($rawColumns[0])) {
                    $rawColumns[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $rawColumns[0]);
                }

                if (count($rawColumns) === 1 && strpos((string)$rawColumns[0], ';') !== false) {
                    $rawColumns = str_getcsv((string)$rawColumns[0], ';');
                }

                $rawColumns = array_map(static function ($value) {
                    return is_string($value) ? trim($value) : $value;
                }, $rawColumns);

                $urlCandidate = (string)($rawColumns[0] ?? '');
                if ($urlCandidate === '' || strtolower($urlCandidate) === 'url') {
                    continue;
                }

                $normalized = pp_crowd_links_normalize_url($urlCandidate);
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

                if (count($rawColumns) > 1) {
                    $metaUpdates = [];
                    $statusRaw = strtolower(trim((string)($rawColumns[1] ?? '')));
                    if ($statusRaw !== '' && isset($allowedStatuses[$statusRaw])) {
                        $metaUpdates['status'] = $statusRaw;
                    }
                    $statusCodeRaw = trim((string)($rawColumns[2] ?? ''));
                    if ($statusCodeRaw !== '' && is_numeric($statusCodeRaw)) {
                        $metaUpdates['status_code'] = (int)$statusCodeRaw;
                    }
                    $languageRaw = trim((string)($rawColumns[3] ?? ''));
                    if ($languageRaw !== '' && preg_match('~^[a-zA-Z0-9\-]{2,12}$~', $languageRaw)) {
                        $metaUpdates['language'] = strtolower($languageRaw);
                    }
                    $regionRaw = trim((string)($rawColumns[4] ?? ''));
                    if ($regionRaw !== '' && preg_match('~^[a-zA-Z0-9\-]{2,12}$~', $regionRaw)) {
                        $metaUpdates['region'] = strtoupper($regionRaw);
                    }
                    $lastCheckedRaw = trim((string)($rawColumns[5] ?? ''));
                    if ($lastCheckedRaw !== '') {
                        $ts = strtotime($lastCheckedRaw);
                        if ($ts) {
                            $metaUpdates['last_checked_at'] = date('Y-m-d H:i:s', $ts);
                        } elseif (preg_match('~^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$~', $lastCheckedRaw)) {
                            $normalizedTs = str_replace('T', ' ', substr($lastCheckedRaw, 0, 19));
                            $metaUpdates['last_checked_at'] = $normalizedTs;
                        }
                    }
                    $errorRaw = trim((string)($rawColumns[9] ?? ''));
                    if ($errorRaw !== '') {
                        $metaUpdates['error'] = $cutString($errorRaw, $maxErrorLength);
                    }

                    if (!empty($metaUpdates)) {
                        $setParts = [];
                        $bindTypes = '';
                        $bindValues = [];
                        foreach ($metaUpdates as $column => $value) {
                            $setParts[] = $column . ' = ?';
                            $bindTypes .= ($column === 'status_code') ? 'i' : 's';
                            $bindValues[] = $value;
                        }
                        $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
                        $sqlUpdate = 'UPDATE crowd_links SET ' . implode(', ', $setParts) . ' WHERE url_hash = ? LIMIT 1';
                        $bindTypes .= 's';
                        $bindValues[] = $hash;
                        if ($updateStmt = $conn->prepare($sqlUpdate)) {
                            $updateStmt->bind_param($bindTypes, ...$bindValues);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
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
                $where[] = "(cl.status IN ('redirect','client_error','server_error','unreachable','no_form'))";
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
                        SUM(cl.status IN ('redirect','client_error','server_error','unreachable','no_form')) AS error_links,
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
        // Use DELETE to respect foreign key constraints (TRUNCATE ignores FKs and fails when referenced)
        @$conn->query('DELETE FROM crowd_links');
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
        $sql = "DELETE FROM crowd_links WHERE status IN ('redirect','client_error','server_error','unreachable','no_form')";
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
        // Prefer a real CLI php over lsphp wrappers
        $phpBinary = PHP_BINARY ?: '';
        $origBinary = $phpBinary;
        $phpEnv = getenv('PP_PHP_CLI');
        if ($phpEnv && @is_executable($phpEnv)) {
            $phpBinary = $phpEnv;
        }
        if (defined('PP_PHP_CLI')) {
            $constVal = constant('PP_PHP_CLI');
            if ($constVal && @is_executable($constVal)) {
                $phpBinary = $constVal;
            }
        }
        $bn = $phpBinary ? strtolower(basename($phpBinary)) : '';
        $needsCli = ($phpBinary === '' || $bn === 'lsphp' || $bn === 'litespeed');
        $candidates = [];
        if ($needsCli) {
            $candidates = array_filter([
                // User-provided override first
                $phpEnv ?: null,
                // Common locations
                '/usr/bin/php',
                '/usr/local/bin/php',
                '/bin/php',
                // CloudLinux alt-php paths (most common versions)
                '/opt/alt/php83/usr/bin/php',
                '/opt/alt/php82/usr/bin/php',
                '/opt/alt/php81/usr/bin/php',
                '/opt/alt/php80/usr/bin/php',
                '/opt/alt/php74/usr/bin/php',
            ]);
            foreach ($candidates as $cand) {
                if ($cand && @is_file($cand) && @is_executable($cand)) {
                    $phpBinary = $cand;
                    break;
                }
            }
            // As a last resort, try system PATH 'php'
            if ($phpBinary === '' || strtolower(basename($phpBinary)) === 'lsphp') {
                $which = @shell_exec('command -v php 2>/dev/null');
                if (is_string($which)) {
                    $which = trim($which);
                    if ($which !== '' && @is_executable($which)) {
                        $phpBinary = $which;
                    }
                }
            }
            // If still nothing sensible, fall back to original (may be lsphp)
            if ($phpBinary === '') {
                $phpBinary = $origBinary ?: 'php';
            }
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $disabled = array_filter($disabled, static function($v){ return $v !== ''; });
        $canPopen = function_exists('popen') && !in_array('popen', $disabled, true);
        $canExec = function_exists('exec') && !in_array('exec', $disabled, true);
        $canShell = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
        $canProc = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        pp_crowd_links_log('Worker PHP binary selected', [
            'runId' => $runId,
            'binary' => $phpBinary,
            'orig' => $origBinary,
            'canPopen' => $canPopen,
            'canExec' => $canExec,
            'canShell' => $canShell,
            'canProc' => $canProc,
            'disabled' => array_values($disabled),
        ]);
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
        // Try nohup first to disown from web server process; then setsid; then plain background
        $cmdNohup = 'nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId . ' > /dev/null 2>&1 &';
        $cmdSetsid = 'setsid ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId . ' > /dev/null 2>&1 &';
        $cmdPlain = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId . ' > /dev/null 2>&1 &';
        $tried = [];
        foreach ([$cmdNohup, $cmdSetsid, $cmdPlain] as $cmd) {
            $tried[] = $cmd;
            pp_crowd_links_log('Launching worker', ['runId' => $runId, 'cmd' => $cmd]);
            if (function_exists('popen')) {
                $handle = @popen($cmd, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                    return true;
                }
            }
            @exec($cmd);
            // We cannot reliably detect success here; continue to next variant
        }
        // Best effort
        pp_crowd_links_log('Worker launch attempted (fallback)', ['runId' => $runId, 'tried' => $tried]);
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
        $activeRow = null;
        if ($res = @$conn->query("SELECT id, status, scope, total_links, processed_count, cancel_requested, started_at, last_activity_at,
                TIMESTAMPDIFF(SECOND, last_activity_at, NOW()) AS diff_last,
                TIMESTAMPDIFF(SECOND, started_at, NOW()) AS diff_started
            FROM crowd_link_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $activeRow = $row;
            }
            $res->free();
        }
        if ($activeRow) {
            $activeId = (int)($activeRow['id'] ?? 0);
            $status = (string)($activeRow['status'] ?? '');
            $alreadyCancelRequested = !empty($activeRow['cancel_requested']);
            $isStalled = false;
            $diffLast = isset($activeRow['diff_last']) ? (int)$activeRow['diff_last'] : null;
            $diffStarted = isset($activeRow['diff_started']) ? (int)$activeRow['diff_started'] : null;
            if (in_array((string)($activeRow['status'] ?? ''), ['queued','running'], true)) {
                if ($diffLast !== null && $diffLast >= 150) { $isStalled = true; }
                elseif (($activeRow['last_activity_at'] ?? null) === null && $diffStarted !== null && $diffStarted >= 150) { $isStalled = true; }
                else { $isStalled = pp_crowd_links_is_stalled_run($activeRow, 150); }
            }
            // If there are no links bound to this run (e.g., after DB cleanup), treat as stalled and cancel
            if ($activeId) {
                $hasBoundLinks = false;
                if ($res2 = @$conn->query('SELECT 1 FROM crowd_links WHERE processing_run_id = ' . $activeId . ' LIMIT 1')) {
                    $hasBoundLinks = (bool)$res2->fetch_row();
                    $res2->free();
                }
                if (!$hasBoundLinks) {
                    $isStalled = true;
                }
            }
            if ($activeId && ($alreadyCancelRequested || $isStalled)) {
                $note = __('Автоматическая остановка из-за отсутствия активности.');
                $stmt = $conn->prepare("UPDATE crowd_link_runs SET status='cancelled', finished_at=CURRENT_TIMESTAMP, cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP, notes=TRIM(CONCAT_WS('\n', NULLIF(notes,''), ?)) WHERE id=? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $note, $activeId);
                    $stmt->execute();
                    $stmt->close();
                }
                @$conn->query('UPDATE crowd_links SET processing_run_id = NULL WHERE processing_run_id = ' . $activeId);
                pp_crowd_links_log('Auto-cancelled stalled run', ['runId' => $activeId, 'cancelRequested' => $alreadyCancelRequested, 'stalled' => $isStalled]);
            } elseif ($activeId) {
                $conn->close();
                return ['ok' => true, 'runId' => $activeId, 'alreadyRunning' => true, 'status' => $status];
            }
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
        if (!pp_crowd_links_wait_for_worker_start($runId, 8.0)) {
            if ($total > 200) {
                // Для больших запусков не уходим в inline, оставляем очередь на фонового воркера
                pp_crowd_links_log('Worker start not confirmed yet, leaving queued for background', ['runId' => $runId, 'total' => $total]);
            } else {
                pp_crowd_links_log('Worker did not start in time, running inline', ['runId' => $runId]);
                try {
                    pp_crowd_links_process_run($runId);
                } catch (Throwable $e) {
                    pp_crowd_links_log('Inline processing failed', ['runId' => $runId, 'error' => $e->getMessage()]);
                }
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
    $stmt = $conn->prepare('SELECT *, TIMESTAMPDIFF(SECOND, last_activity_at, NOW()) AS diff_last, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS diff_started FROM crowd_link_runs WHERE id = ? LIMIT 1');
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
    // Ошибки считаем как всё, что не OK: это включает redirect, 4xx/5xx, недоступна и случаи без формы
    $run['error_count'] = max(0, $run['processed_count'] - $run['ok_count']);
        $run['progress_percent'] = $run['total_links'] > 0 ? min(100, (int)round($run['processed_count'] * 100 / $run['total_links'])) : 0;
        $run['in_progress'] = in_array($run['status'], ['queued','running'], true);
        $diffLast = isset($row['diff_last']) ? (int)$row['diff_last'] : null;
        $diffStarted = isset($row['diff_started']) ? (int)$row['diff_started'] : null;
        $run['stalled'] = false;
        if ($run['in_progress']) {
            if ($diffLast !== null && $diffLast >= 150) { $run['stalled'] = true; }
            elseif (($row['last_activity_at'] ?? null) === null && $diffStarted !== null && $diffStarted >= 150) { $run['stalled'] = true; }
            else { $run['stalled'] = pp_crowd_links_is_stalled_run($row, 150); }
        }
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
    $stmt = $conn->prepare('SELECT id, status, cancel_requested, started_at, last_activity_at, notes,
            TIMESTAMPDIFF(SECOND, last_activity_at, NOW()) AS diff_last,
            TIMESTAMPDIFF(SECOND, started_at, NOW()) AS diff_started
        FROM crowd_link_runs WHERE id = ? LIMIT 1');
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
        $alreadyRequested = !empty($row['cancel_requested']);
        $shouldForce = $force;
        if (!$shouldForce && $status === 'running') {
            if ($alreadyRequested) {
                $shouldForce = true;
            } else {
                $diffLast = isset($row['diff_last']) ? (int)$row['diff_last'] : null;
                $diffStarted = isset($row['diff_started']) ? (int)$row['diff_started'] : null;
                if ($diffLast !== null && $diffLast >= 150) { $shouldForce = true; }
                elseif (($row['last_activity_at'] ?? null) === null && $diffStarted !== null && $diffStarted >= 150) { $shouldForce = true; }
                elseif (pp_crowd_links_is_stalled_run($row, 150)) { $shouldForce = true; }
            }
        }
        @$conn->query("UPDATE crowd_link_runs SET cancel_requested = 1 WHERE id = " . (int)$runId . " LIMIT 1");
        if ($status === 'queued') {
            @$conn->query("UPDATE crowd_link_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            @$conn->query("UPDATE crowd_links SET processing_run_id = NULL WHERE processing_run_id = " . (int)$runId);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'finished' => true];
        }
        if ($shouldForce) {
            $note = __('Принудительно остановлено администратором.');
            $stmt = $conn->prepare("UPDATE crowd_link_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP, notes=TRIM(CONCAT_WS('\\n', NULLIF(notes,''), ?)) WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $note, $runId);
                $stmt->execute();
                $stmt->close();
            } else {
                @$conn->query("UPDATE crowd_link_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            }
            @$conn->query("UPDATE crowd_links SET processing_run_id = NULL WHERE processing_run_id = " . (int)$runId);
            pp_crowd_links_log('Run force-cancelled', ['runId' => $runId, 'requested' => $alreadyRequested]);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'finished' => true, 'forced' => true];
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

if (!function_exists('pp_crowd_links_fetch_parallel')) {
    /**
     * Fetch multiple URLs in parallel using curl_multi when available.
     * Falls back to sequential requests through pp_http_fetch.
     * @param array<int,array{id:int,url:string}> $items
     * @param int $timeout Request timeout per item (seconds)
     * @param int $parallel Maximum number of concurrent requests
     * @return array<int,array{status:int,headers:array<string,string>,body:string,final_url:string,error:string}>
     */
    function pp_crowd_links_fetch_parallel(array $items, int $timeout = 15, int $parallel = 10): array {
        $results = [];
        if (empty($items)) {
            return $results;
        }
        $timeout = max(4, $timeout);
        $parallel = max(1, $parallel);
        if (!function_exists('curl_multi_init')) {
            foreach ($items as $item) {
                $fetch = pp_http_fetch($item['url'], $timeout);
                $results[(int)$item['id']] = $fetch + ['error' => ''];
            }
            return $results;
        }

        // Use a realistic browser UA to avoid bot-specific content and challenges
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $headersCommon = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
            'Upgrade-Insecure-Requests: 1',
        ];
        $chunks = array_chunk($items, $parallel);
        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $item) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $item['url'],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 6,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_ACCEPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => $headersCommon,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[(int)$ch] = ['handle' => $ch, 'item' => $item];
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc === CURLM_OK) {
                if (curl_multi_select($mh, 1.0) === -1) {
                    usleep(100000);
                }
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($handles as $info) {
                $ch = $info['handle'];
                $item = $info['item'];
                $id = (int)$item['id'];
                $url = (string)$item['url'];
                $raw = curl_multi_getcontent($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $body = '';
                $respHeaders = [];
                if ($raw !== false && $headerSize >= 0) {
                    $headerPart = substr($raw, 0, $headerSize);
                    $body = substr($raw, $headerSize);
                    // Cap body at 1MB to keep memory predictable yet include most comment sections
                    if ($body !== '' && strlen($body) > 1048576) {
                        $body = substr($body, 0, 1048576);
                    }
                    $blocks = preg_split("/\r?\n\r?\n/", trim((string)$headerPart));
                    $last = $blocks ? end($blocks) : '';
                    foreach (preg_split("/\r?\n/", (string)$last) as $line) {
                        if (strpos($line, ':') !== false) {
                            [$k, $v] = array_map('trim', explode(':', $line, 2));
                            if ($k !== '') {
                                $respHeaders[strtolower($k)] = $v;
                            }
                        }
                    }
                }
                $results[$id] = [
                    'status' => $status,
                    'headers' => $respHeaders,
                    'body' => $body,
                    'final_url' => $finalUrl,
                    'error' => curl_error($ch) ?: '',
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $results;
    }
}

    if (!function_exists('pp_crowd_links_has_comment_form')) {
        /**
         * Simple heuristic: does the page contain at least one <form> with a <textarea>?
         * Allows for common anti-bot attributes and nested structures.
         */
        function pp_crowd_links_has_comment_form(string $html): bool {
            if ($html === '') { return false; }
            $doc = pp_html_dom($html);
            if (!$doc) {
                // Fallback: quick regex search
                if (preg_match('~<form\b[^>]*>.*?<textarea\b~is', $html)) { return true; }
                // WordPress signature often present
                if (stripos($html, 'wp-comments-post.php') !== false && stripos($html, '<textarea') !== false) { return true; }
                return false;
            }
            $xp = new DOMXPath($doc);
            // WordPress/common comment form markers
            $wpForm = $xp->query("//form[@id='commentform' or contains(concat(' ', normalize-space(@class), ' '), ' comment-form ')]")->item(0);
            if ($wpForm instanceof DOMElement) { return true; }
            $respond = $xp->query("//*[@id='respond' or contains(concat(' ', normalize-space(@class), ' '), ' comment-respond ')]")->item(0);
            if ($respond instanceof DOMElement) { return true; }
            $wpHidden = $xp->query("//input[@name='comment_post_ID' or @name='comment_parent']")->item(0);
            if ($wpHidden instanceof DOMElement) { return true; }
            $commentTextarea = $xp->query("//textarea[@id='comment' or @name='comment']")->item(0);
            if ($commentTextarea instanceof DOMElement) { return true; }
            // Direct: any form containing a textarea
            $node = $xp->query('//form[.//textarea]')->item(0);
            if ($node instanceof DOMElement) { return true; }
            // HTML5: textarea referencing a form by @form attribute
            $ta = $xp->query('//textarea[@form]')->item(0);
            if ($ta instanceof DOMElement) {
                $formId = trim($ta->getAttribute('form'));
                if ($formId !== '') {
                    $ref = $xp->query('//form[@id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"]')->item(0);
                    if ($ref instanceof DOMElement) { return true; }
                }
            }
            // Fallback: malformed markup where textarea and submit are siblings under a likely container
            $candidate = $xp->query('//*[self::section or self::article or self::div or self::main or self::aside][.//textarea and (.//button | .//input[@type="submit" or @type="button"])][not(.//form)]')->item(0);
            if ($candidate instanceof DOMElement) { return true; }
            // Ultra-fallback: regex check in case of broken DOM tree
            if (preg_match('~<form\b[^>]*>.*?<textarea\b~is', $html)) { return true; }
            // WordPress signature often present
            if (stripos($html, 'wp-comments-post.php') !== false && stripos($html, '<textarea') !== false) { return true; }
            // Finally, consider any standalone textarea as signal
            $anyTextarea = $xp->query('//textarea')->item(0);
            return $anyTextarea instanceof DOMElement;
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
    @$conn->query("UPDATE crowd_link_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
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
            'no_form' => 0,
        ];
        $checkCancel = static function(mysqli $conn, int $runId): bool {
            $res = @$conn->query('SELECT cancel_requested FROM crowd_link_runs WHERE id = ' . (int)$runId . ' LIMIT 1');
            if ($res && $row = $res->fetch_assoc()) {
                $res->free();
                return !empty($row['cancel_requested']);
            }
            return false;
        };
    // Faster defaults: higher parallelism, slightly lower timeout, smaller batches for quicker UI updates
    $parallelLimit = 24;
    $httpTimeout = 12;
    $minParallel = 12;
    $maxParallel = 64;
    if ($parallelLimit < $minParallel) { $parallelLimit = $minParallel; }
    if ($parallelLimit > $maxParallel) { $parallelLimit = $maxParallel; }
    // Smaller batch per cycle so progress updates more frequently
    $batchSize = max((int)floor($parallelLimit * 1.5), 24);
    if ($batchSize > 200) { $batchSize = 200; }

        $updateStmt = $conn->prepare("UPDATE crowd_links SET status=?, status_code=?, error=?, form_required=?, language=IF(? = '', language, ?), region=IF(? = '', region, ?), processing_run_id=NULL, last_checked_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if ($updateStmt) {
            $statusParam = '';
            $statusCodeParam = 0;
            $errorParam = '';
            $requiredParam = null;
            $langInParam = '';
            $langOutParam = '';
            $regionInParam = '';
            $regionOutParam = '';
            $idParam = 0;
            $updateStmt->bind_param('sissssssi', $statusParam, $statusCodeParam, $errorParam, $requiredParam, $langInParam, $langOutParam, $regionInParam, $regionOutParam, $idParam);
        }

        $cancelled = false;
        $totalLinks = count($links);
        $offset = 0;
        $chunkIndex = 0;
        while ($offset < $totalLinks) {
            $chunkIndex++;
            $currentBatchSize = min($batchSize, $totalLinks - $offset);
            $chunk = array_slice($links, $offset, $currentBatchSize);
            $offset += $currentBatchSize;

            if ($checkCancel($conn, $runId)) {
                $cancelled = true;
                break;
            }

            $chunkStart = microtime(true);
            $chunkIds = array_column($chunk, 'id');
            if (!empty($chunkIds)) {
                $idList = implode(',', array_map('intval', $chunkIds));
                @$conn->query("UPDATE crowd_links SET status='checking', last_run_id=" . (int)$runId . ", last_checked_at=CURRENT_TIMESTAMP WHERE id IN ($idList)");
            }

            $responses = pp_crowd_links_fetch_parallel($chunk, $httpTimeout, $parallelLimit);

            $chunkTimeouts = 0;
            $chunkUnreachables = 0;
            foreach ($chunk as $item) {
                $linkId = (int)$item['id'];
                $url = (string)$item['url'];
                $http = $responses[$linkId] ?? ['status' => 0, 'body' => '', 'final_url' => $url, 'headers' => [], 'error' => ''];
                $statusCode = (int)($http['status'] ?? 0);
                $body = (string)($http['body'] ?? '');
                $finalUrl = (string)($http['final_url'] ?? $url);
                $curlError = (string)($http['error'] ?? '');
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
                    if ($statusCode > 0) {
                        $errorText = sprintf(__('Неожиданный статус HTTP %d'), $statusCode);
                    } elseif ($curlError !== '') {
                        if (stripos($curlError, 'timed out') !== false || stripos($curlError, 'timeout') !== false) {
                            $errorText = __('Таймаут соединения.');
                            $chunkTimeouts++;
                        } else {
                            $errorText = $curlError;
                        }
                    } else {
                        $errorText = __('Сайт недоступен или превышено время ожидания.');
                    }
                    if ($statusCode <= 0) {
                        $chunkUnreachables++;
                    }
                }
                if ($body === '' && $newStatus === 'ok') {
                    $newStatus = 'unreachable';
                    $errorText = __('Получен пустой ответ от сервера.');
                    $chunkUnreachables++;
                }
                $langRegion = ['language' => '', 'region' => ''];
                $requiredFields = '';
                if ($newStatus === 'ok' && $body !== '') {
                    // Simple presence check: require a form with textarea; otherwise mark as no_form
                    if (!pp_crowd_links_has_comment_form($body)) {
                        $newStatus = 'no_form';
                        $errorText = __('На странице нет формы с полем комментария (textarea).');
                        // Lightweight diagnostics to help analyze false negatives without storing full HTML
                        $hasWpEndpoint = (stripos($body, 'wp-comments-post.php') !== false);
                        $hasCommentFormId = (stripos($body, 'id="commentform"') !== false) || (stripos($body, 'class="comment-form"') !== false);
                        $hasTextarea = (stripos($body, '<textarea') !== false);
                        pp_crowd_links_log('no_form_detected', [
                            'link_id' => $linkId,
                            'url' => $url,
                            'final_url' => $finalUrl,
                            'wp_comments_post' => $hasWpEndpoint,
                            'commentform_hint' => $hasCommentFormId,
                            'textarea_present' => $hasTextarea,
                        ]);
                    }
                    // Extract required fields from first form with textarea
                    try {
                        $doc = pp_html_dom($body);
                        if ($doc) {
                            $xp = new DOMXPath($doc);
                            $form = $xp->query('//form[.//textarea]')->item(0);
                            if ($form instanceof DOMElement) {
                                $req = [];
                                $inputs = $xp->query('.//input | .//select | .//textarea', $form);
                                if ($inputs) {
                                    foreach ($inputs as $node) {
                                        if (!$node instanceof DOMElement) { continue; }
                                        $isReq = $node->hasAttribute('required');
                                        if (!$isReq) {
                                            $cls = strtolower($node->getAttribute('class'));
                                            if (strpos($cls, 'required') !== false) { $isReq = true; }
                                            $aria = strtolower($node->getAttribute('aria-required'));
                                            if (in_array($aria, ['1','true'], true)) { $isReq = true; }
                                        }
                                        if ($isReq) {
                                            $name = trim((string)$node->getAttribute('name'));
                                            if ($name === '') { $name = strtolower($node->tagName); }
                                            $type = strtolower((string)$node->getAttribute('type'));
                                            if ($node->tagName === 'textarea') { $type = 'textarea'; }
                                            $req[] = $name . ($type ? (':' . $type) : '');
                                        }
                                    }
                                }
                                if (!empty($req)) { $requiredFields = implode(', ', array_slice(array_unique($req), 0, 40)); }
                            }
                        }
                    } catch (Throwable $e) {
                        // ignore
                    }
                    // Extract language/region regardless of no_form to enrich meta
                    $lr = pp_crowd_links_extract_lang_region($body);
                    if (is_array($lr)) { $langRegion = $lr; }
                }
                $langVal = $langRegion['language'] ?? '';
                $regionVal = $langRegion['region'] ?? '';
                if ($langVal !== '') {
                    $langVal = strtolower($langVal);
                }
                if ($regionVal !== '') {
                    $regionVal = strtoupper($regionVal);
                }

                if ($updateStmt) {
                    $statusParam = $newStatus;
                    $statusCodeParam = $statusCode;
                    $errorParam = $errorText;
                    $requiredParam = $requiredFields;
                    $langInParam = $langVal;
                    $langOutParam = $langVal;
                    $regionInParam = $regionVal;
                    $regionOutParam = $regionVal;
                    $idParam = $linkId;
                    $updateStmt->execute();
                } else {
                    @$conn->query('UPDATE crowd_links SET status=' . "'" . $conn->real_escape_string($newStatus) . "'" . ', status_code=' . (int)$statusCode . ', error=' . "'" . $conn->real_escape_string($errorText) . "'" . ', form_required=' . "'" . $conn->real_escape_string($requiredFields) . "'" . ', processing_run_id=NULL, last_checked_at=CURRENT_TIMESTAMP WHERE id=' . (int)$linkId . ' LIMIT 1');
                }

                $counts['processed']++;
                if (array_key_exists($newStatus, $counts)) {
                    $counts[$newStatus]++;
                }
            }

            pp_crowd_links_update_run_counts($conn, $runId, $counts, 'running');

            $chunkDuration = microtime(true) - $chunkStart;
            $timeoutRatio = $currentBatchSize > 0 ? ($chunkTimeouts / $currentBatchSize) : 0.0;
            $unreachableRatio = $currentBatchSize > 0 ? ($chunkUnreachables / $currentBatchSize) : 0.0;
            if ($chunkDuration < 4.5 && $timeoutRatio < 0.05 && $parallelLimit < $maxParallel) {
                $parallelLimit += 4;
                if ($parallelLimit > $maxParallel) { $parallelLimit = $maxParallel; }
                pp_crowd_links_log('Adaptive concurrency increase', ['runId' => $runId, 'chunk' => $chunkIndex, 'parallel' => $parallelLimit, 'duration' => $chunkDuration]);
            } elseif (($chunkDuration > 18.0 || $timeoutRatio > 0.2 || $unreachableRatio > 0.4) && $parallelLimit > $minParallel) {
                $parallelLimit = max($minParallel, $parallelLimit - 4);
                pp_crowd_links_log('Adaptive concurrency decrease', ['runId' => $runId, 'chunk' => $chunkIndex, 'parallel' => $parallelLimit, 'duration' => $chunkDuration, 'timeouts' => $chunkTimeouts, 'unreachables' => $chunkUnreachables]);
            }
            $batchSize = max((int)floor($parallelLimit * 1.5), 24);
            if ($batchSize > 200) { $batchSize = 200; }

            if ($checkCancel($conn, $runId)) {
                $cancelled = true;
                break;
            }
        }

        if ($updateStmt) {
            $updateStmt->close();
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
    $errorSum = $counts['redirect'] + $counts['client_error'] + $counts['server_error'] + $counts['unreachable'] + ($counts['no_form'] ?? 0);
        $finalStatus = ($counts['processed'] === $total && $errorSum === 0) ? 'success' : 'completed';
        pp_crowd_links_update_run_counts($conn, $runId, $counts, $finalStatus);
        @$conn->query("UPDATE crowd_link_runs SET finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
        $conn->close();
        pp_crowd_links_log('Worker finished', ['runId' => $runId, 'processed' => $counts['processed'], 'errors' => $errorSum]);
    }
}

?>
