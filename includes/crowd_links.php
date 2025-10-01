<?php
// Crowd links management module: import, stats, queue, background checks
// All functions are guarded with function_exists to allow multiple includes.

if (!function_exists('pp_crowd_links_log')) {
    function pp_crowd_links_log(string $message, array $context = []): void {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
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

if (!function_exists('pp_crowd_links_normalize_url')) {
    function pp_crowd_links_normalize_url(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }
        $parts = @parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'http');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (isset($parts['path'])) {
            $path = $parts['path'];
        } else {
            $path = '/';
        }
        if ($path === '') {
            $path = '/';
        }
        $path = preg_replace('~/{2,}~', '/', $path);
        $path = str_replace('/./', '/', $path);
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $fragment = '';
        return $scheme . '://' . $host . $path . $query . $fragment;
    }
}

if (!function_exists('pp_crowd_links_hash_url')) {
    function pp_crowd_links_hash_url(string $url): string {
        return hash('sha256', $url);
    }
}

if (!function_exists('pp_crowd_links_extract_test_url')) {
    function pp_crowd_links_extract_test_url(string $message, ?string $fallback = null): ?string {
        if (preg_match('~https?://[^\s]+~i', $message, $m)) {
            return $m[0];
        }
        return $fallback;
    }
}

if (!function_exists('pp_crowd_links_insert_urls')) {
    function pp_crowd_links_insert_urls(array $urls, ?int $userId = null): array {
        $inserted = 0;
        $duplicates = 0;
        $invalid = 0;
        $normalizeCache = [];
        $seenHashes = [];
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        $stmt = $conn->prepare("INSERT INTO crowd_links (url, url_hash, status) VALUES (?, ?, 'pending')");
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_PREPARE'];
        }
        foreach ($urls as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            $normalized = $normalizeCache[$raw] ?? pp_crowd_links_normalize_url($raw);
            $normalizeCache[$raw] = $normalized;
            if (!$normalized) {
                $invalid++;
                continue;
            }
            $hash = pp_crowd_links_hash_url($normalized);
            // Skip duplicates within the same batch to reduce DB errors/overhead
            if (isset($seenHashes[$hash])) {
                $duplicates++;
                continue;
            }
            $seenHashes[$hash] = true;
            $stmt->bind_param('ss', $normalized, $hash);
            try {
                $ok = $stmt->execute();
                if ($ok) {
                    $inserted++;
                } else {
                    if ((int)$stmt->errno === 1062) {
                        $duplicates++;
                    } else {
                        $invalid++;
                        pp_crowd_links_log('Insert crowd link failed', ['url' => $normalized, 'errno' => $stmt->errno, 'error' => $stmt->error]);
                    }
                }
            } catch (Throwable $ex) {
                // mysqli may be configured to throw exceptions (MYSQLI_REPORT_STRICT). Handle duplicate key and generic errors.
                $code = (int)($ex->getCode() ?? 0);
                if ($code === 1062) {
                    $duplicates++;
                } else {
                    $invalid++;
                    pp_crowd_links_log('Insert crowd link exception', ['url' => $normalized, 'code' => $code, 'message' => $ex->getMessage()]);
                }
            }
        }
        $stmt->close();
        $conn->close();
        return [
            'ok' => true,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }
}

if (!function_exists('pp_crowd_links_get_stats')) {
    function pp_crowd_links_get_stats(): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return [
                'total' => 0,
                'checked' => 0,
                'success' => 0,
                'pending' => 0,
            ];
        }
        if (!$conn) {
            return [
                'total' => 0,
                'checked' => 0,
                'success' => 0,
                'pending' => 0,
            ];
        }
        $total = 0;
        $checked = 0;
        $success = 0;
        $pending = 0;
        if ($res = $conn->query("SELECT status, COUNT(*) AS cnt FROM crowd_links GROUP BY status")) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)$row['status'];
                $cnt = (int)$row['cnt'];
                $total += $cnt;
                if (in_array($status, ['success', 'failed', 'needs_review'], true)) {
                    $checked += $cnt;
                }
                if ($status === 'success') {
                    $success += $cnt;
                }
                if ($status === 'pending') {
                    $pending += $cnt;
                }
            }
            $res->free();
        }
        $conn->close();
        return [
            'total' => $total,
            'checked' => $checked,
            'success' => $success,
            'pending' => $pending,
        ];
    }
}

if (!function_exists('pp_crowd_links_fetch_links')) {
    function pp_crowd_links_fetch_links(int $page = 1, int $perPage = 25, array $filters = []): array {
        $page = max(1, $page);
        $perPage = max(5, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $filterStatus = isset($filters['status']) ? strtolower((string)$filters['status']) : 'all';
        $searchTerm = trim((string)($filters['search'] ?? ''));
        $where = [];
        $params = [];
        $types = '';
        if ($filterStatus !== 'all' && $filterStatus !== '') {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $filterStatus;
        }
        if ($searchTerm !== '') {
            $where[] = 'url LIKE ?';
            $types .= 's';
            $params[] = '%' . $searchTerm . '%';
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }
        if (!$conn) {
            return ['rows' => [], 'total' => 0];
        }
        $sql = "SELECT SQL_CALC_FOUND_ROWS id, url, status, region, language, is_indexed, follow_type, http_status, last_checked_at, last_success_at, last_error, last_run_id FROM crowd_links {$whereSql} ORDER BY id DESC LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return ['rows' => [], 'total' => 0];
        }
        $typesWithLimit = $types . 'ii';
        $paramsWithLimit = $params;
        $paramsWithLimit[] = $offset;
        $paramsWithLimit[] = $perPage;
        $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        $total = 0;
        if ($resTotal = $conn->query('SELECT FOUND_ROWS() AS total')) {
            if ($rowTotal = $resTotal->fetch_assoc()) {
                $total = (int)($rowTotal['total'] ?? 0);
            }
            $resTotal->free();
        }
        $conn->close();
        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('pp_crowd_links_wait_for_worker_start')) {
    function pp_crowd_links_wait_for_worker_start(int $runId, float $timeoutSeconds = 3.0): bool {
        $deadline = microtime(true) + max(0.5, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            try {
                $conn = @connect_db();
            } catch (Throwable $e) {
                pp_crowd_links_log('Worker start check failed: DB connection', ['runId' => $runId, 'error' => $e->getMessage()]);
                return false;
            }
            if (!$conn) {
                return false;
            }
            $row = null;
            if ($stmt = $conn->prepare('SELECT status, started_at FROM crowd_check_runs WHERE id = ? LIMIT 1')) {
                $stmt->bind_param('i', $runId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                }
                $stmt->close();
            }
            $conn->close();
            if (!$row) {
                return false;
            }
            $status = (string)($row['status'] ?? '');
            $started = (string)($row['started_at'] ?? '');
            if ($status !== 'queued' || ($started !== '' && $started !== '0000-00-00 00:00:00')) {
                return true;
            }
            usleep(200000);
        }
        return false;
    }
}

if (!function_exists('pp_crowd_links_launch_worker')) {
    function pp_crowd_links_launch_worker(int $runId): bool {
        $script = PP_ROOT_PATH . '/scripts/crowd_links_worker.php';
        if (!is_file($script)) {
            return false;
        }
        // Prefer true PHP CLI over lsphp/fpm wrappers
        $phpBinary = function_exists('pp_get_php_cli') ? pp_get_php_cli() : (PHP_BINARY ?: 'php');
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
        pp_crowd_links_log('Launching crowd links worker', [
            'runId' => $runId,
            'command' => $cmd,
            'phpBinary' => $phpBinary,
            'phpSapi' => PHP_SAPI,
            'cwd' => getcwd(),
            'root' => PP_ROOT_PATH,
        ]);
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                return true;
            }
        }
        // Try exec as secondary option
        if (function_exists('exec')) { @exec($cmd); return true; }
        // Fallback: best-effort proc_open without waiting
        $descriptor = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc = @proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId, $descriptor, $pipes, PP_ROOT_PATH);
        if (is_resource($proc)) {
            foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
            // Do not wait; assume started
            @proc_close($proc);
            pp_crowd_links_log('Worker started via proc_open fallback', ['runId' => $runId, 'phpBinary' => $phpBinary]);
            return true;
        }
        return false;
    }
}

if (!function_exists('pp_crowd_links_start_run')) {
    function pp_crowd_links_start_run(?int $userId, string $mode, array $linkIds = [], ?int $singleId = null, array $options = []): array {
        $mode = strtolower($mode);
        if (!in_array($mode, ['all', 'selection', 'single', 'filtered', 'pending'], true)) {
            $mode = 'all';
        }
        $filters = $options['filters'] ?? [];
        $statusFilter = isset($filters['status']) ? strtolower((string)$filters['status']) : 'pending';
        // Generate default message/link from core generators instead of settings
        $testMessage = isset($options['test_message']) ? trim((string)$options['test_message']) : '';
        $testUrl = isset($options['test_url']) ? trim((string)$options['test_url']) : '';
        if ($testUrl === '') { $testUrl = pp_generate_website('https://example.com'); }
        if ($testMessage === '') { $testMessage = pp_generate_message($testUrl, 'PromoPilot'); }
        if ($mode === 'pending') {
            $filters['status'] = 'pending';
            $statusFilter = 'pending';
            $mode = 'filtered';
        }

        $extracted = pp_crowd_links_extract_test_url($testMessage, $testUrl);
        if ($extracted) {
            $testUrl = $extracted;
        }
        $concurrency = isset($options['concurrency']) ? max(1, min(20, (int)$options['concurrency'])) : (int)get_setting('crowd_concurrency', 5);
        if ($concurrency < 1) {
            $concurrency = 5;
        }
        $chunkSize = isset($options['chunk_size']) ? max(1, min(200, (int)$options['chunk_size'])) : max($concurrency, 10);
        $timeout = isset($options['timeout']) ? max(5, min(120, (int)$options['timeout'])) : (int)get_setting('crowd_request_timeout', 25);
        $timeout = max(5, min(180, $timeout));

        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }

        $activeId = null;
        if ($res = $conn->query("SELECT id FROM crowd_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $activeId = (int)$row['id'];
            }
            $res->free();
        }
        if ($activeId) {
            $conn->close();
            return ['ok' => true, 'runId' => $activeId, 'alreadyRunning' => true];
        }

        $eligibleIds = [];
        if ($mode === 'single' && $singleId) {
            $eligibleIds = [(int)$singleId];
        } elseif ($mode === 'selection' && !empty($linkIds)) {
            foreach ($linkIds as $lid) {
                $id = (int)$lid;
                if ($id > 0) {
                    $eligibleIds[$id] = $id;
                }
            }
            $eligibleIds = array_values($eligibleIds);
        } else {
            $where = [];
            $types = '';
            $params = [];
            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $where[] = 'status = ?';
                $types .= 's';
                $params[] = $statusFilter;
            }
            if (!empty($filters['search'])) {
                $where[] = 'url LIKE ?';
                $types .= 's';
                $params[] = '%' . trim((string)$filters['search']) . '%';
            }
            $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
            $sql = "SELECT id FROM crowd_links {$whereSql}";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_PREPARE'];
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $eligibleIds[] = (int)$row['id'];
            }
            $stmt->close();
        }

        $total = count($eligibleIds);
        if ($total === 0) {
            $conn->close();
            return ['ok' => false, 'error' => 'NO_LINKS'];
        }

        $optionsToStore = [
            'concurrency' => $concurrency,
            'chunk_size' => $chunkSize,
            'timeout' => $timeout,
            'filters' => $filters,
        ];
        $optionsJson = json_encode($optionsToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($userId !== null) {
            $stmtRun = $conn->prepare("INSERT INTO crowd_check_runs (status, run_mode, total_links, initiated_by, test_message, test_url, options) VALUES ('queued', ?, ?, ?, ?, ?, ?)");
            if (!$stmtRun) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_PREPARE'];
            }
            $stmtRun->bind_param('siisss', $mode, $total, $userId, $testMessage, $testUrl, $optionsJson);
        } else {
            $stmtRun = $conn->prepare("INSERT INTO crowd_check_runs (status, run_mode, total_links, test_message, test_url, options) VALUES ('queued', ?, ?, ?, ?, ?)");
            if (!$stmtRun) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_PREPARE'];
            }
            $stmtRun->bind_param('sisss', $mode, $total, $testMessage, $testUrl, $optionsJson);
        }
        if (!$stmtRun->execute()) {
            $stmtRun->close();
            $conn->close();
            return ['ok' => false, 'error' => 'DB_WRITE'];
        }
        $stmtRun->close();
        $runId = (int)$conn->insert_id;

        $stmtRes = $conn->prepare('INSERT INTO crowd_check_results (run_id, link_id) VALUES (?, ?)');
        if (!$stmtRes) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_PREPARE'];
        }
        foreach ($eligibleIds as $linkId) {
            $linkId = (int)$linkId;
            if ($linkId <= 0) {
                continue;
            }
            $stmtRes->bind_param('ii', $runId, $linkId);
            $stmtRes->execute();
        }
        $stmtRes->close();
        $conn->close();

        pp_crowd_links_log('Crowd links run created', ['runId' => $runId, 'mode' => $mode, 'total' => $total]);

        if (!pp_crowd_links_launch_worker($runId)) {
            try {
                $conn2 = @connect_db();
                if ($conn2) {
                    $msg = __('Не удалось запустить фоновый процесс.');
                    $upd = $conn2->prepare("UPDATE crowd_check_runs SET status='failed', notes=? WHERE id=? LIMIT 1");
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

        // Do not fallback to inline processing; return immediately and let background worker handle the run.
        // This avoids locking the PHP session on the request thread and keeps the admin UI responsive.
        // We still do a short best-effort wait to detect that the worker transitioned out of "queued".
        pp_crowd_links_wait_for_worker_start($runId, 2.0);

        return ['ok' => true, 'runId' => $runId, 'alreadyRunning' => false];
    }
}

if (!function_exists('pp_crowd_links_format_ts')) {
    function pp_crowd_links_format_ts(?string $ts): ?string {
        if (!$ts) {
            return null;
        }
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') {
            return null;
        }
        $time = strtotime($ts);
        if ($time === false) {
            return $ts;
        }
        return date(DATE_ATOM, $time);
    }
}

if (!function_exists('pp_crowd_links_get_status')) {
    function pp_crowd_links_get_status(?int $runId = null, int $limit = 50): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if ($runId === null || $runId <= 0) {
            if ($res = $conn->query('SELECT id FROM crowd_check_runs ORDER BY id DESC LIMIT 1')) {
                if ($row = $res->fetch_assoc()) {
                    $runId = (int)$row['id'];
                }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'run' => null, 'results' => []];
        }
        $stmt = $conn->prepare('SELECT id, status, run_mode, total_links, processed_count, success_count, failure_count, needs_review_count, cancel_requested, notes, initiated_by, test_message, test_url, created_at, started_at, finished_at FROM crowd_check_runs WHERE id=? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_READ'];
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }
        $results = [];
        $stmtRes = $conn->prepare('SELECT r.id, r.link_id, r.status, r.http_status, r.follow_type, r.index_status, r.detected_language, r.detected_region, r.message_found, r.link_found, r.error, r.started_at, r.finished_at, l.url FROM crowd_check_results r JOIN crowd_links l ON l.id = r.link_id WHERE r.run_id = ? ORDER BY r.id ASC LIMIT ?');
        if ($stmtRes) {
            $stmtRes->bind_param('ii', $runId, $limit);
            $stmtRes->execute();
            $res = $stmtRes->get_result();
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'id' => (int)$row['id'],
                    'link_id' => (int)$row['link_id'],
                    'url' => (string)$row['url'],
                    'status' => (string)$row['status'],
                    'http_status' => $row['http_status'] !== null ? (int)$row['http_status'] : null,
                    'follow_type' => (string)($row['follow_type'] ?? ''),
                    'index_status' => (string)($row['index_status'] ?? ''),
                    'language' => (string)($row['detected_language'] ?? ''),
                    'region' => (string)($row['detected_region'] ?? ''),
                    'message_found' => !empty($row['message_found']),
                    'link_found' => !empty($row['link_found']),
                    'error' => (string)($row['error'] ?? ''),
                    'started_at' => $row['started_at'],
                    'started_at_iso' => pp_crowd_links_format_ts($row['started_at'] ?? null),
                    'finished_at' => $row['finished_at'],
                    'finished_at_iso' => pp_crowd_links_format_ts($row['finished_at'] ?? null),
                ];
            }
            $stmtRes->close();
        }
        $conn->close();
        $run = [
            'id' => (int)$runRow['id'],
            'status' => (string)$runRow['status'],
            'mode' => (string)$runRow['run_mode'],
            'total' => (int)$runRow['total_links'],
            'processed' => (int)$runRow['processed_count'],
            'success' => (int)$runRow['success_count'],
            'failed' => (int)$runRow['failure_count'],
            'needs_review' => (int)$runRow['needs_review_count'],
            'cancel_requested' => !empty($runRow['cancel_requested']),
            'notes' => (string)($runRow['notes'] ?? ''),
            'initiated_by' => $runRow['initiated_by'] !== null ? (int)$runRow['initiated_by'] : null,
            'test_message' => (string)($runRow['test_message'] ?? ''),
            'test_url' => (string)($runRow['test_url'] ?? ''),
            'created_at' => $runRow['created_at'],
            'created_at_iso' => pp_crowd_links_format_ts($runRow['created_at'] ?? null),
            'started_at' => $runRow['started_at'],
            'started_at_iso' => pp_crowd_links_format_ts($runRow['started_at'] ?? null),
            'finished_at' => $runRow['finished_at'],
            'finished_at_iso' => pp_crowd_links_format_ts($runRow['finished_at'] ?? null),
        ];
        return ['ok' => true, 'run' => $run, 'results' => $results];
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
            if ($res = $conn->query("SELECT id FROM crowd_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) {
                    $runId = (int)$row['id'];
                }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'status' => 'idle'];
        }
        $stmt = $conn->prepare('SELECT id, status, cancel_requested FROM crowd_check_runs WHERE id=? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_READ'];
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }
        $status = (string)$runRow['status'];
        if (!in_array($status, ['queued', 'running'], true) && !$force) {
            $conn->close();
            return ['ok' => true, 'status' => $status, 'runId' => $runId, 'alreadyFinished' => true];
        }
        $conn->query("UPDATE crowd_check_runs SET cancel_requested=1 WHERE id=" . (int)$runId . " LIMIT 1");
        if ($force || $status === 'queued') {
            $conn->query("UPDATE crowd_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . (int)$runId . " AND status IN ('queued','running')");
            $conn->query("UPDATE crowd_links l JOIN crowd_check_results r ON r.link_id = l.id SET l.status='pending', l.last_error=NULL WHERE r.run_id=" . (int)$runId . " AND r.status='cancelled'");
            $conn->query("UPDATE crowd_check_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE id=" . (int)$runId . " LIMIT 1");
        }
        $conn->close();
        return ['ok' => true, 'status' => $status, 'runId' => $runId, 'cancelRequested' => true];
    }
}

if (!function_exists('pp_crowd_links_process_run')) {
    function pp_crowd_links_process_run(int $runId): void {
        pp_crowd_links_log('Worker start', ['runId' => $runId]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_crowd_links_log('Worker DB connect failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            return;
        }
        if (!$conn) {
            return;
        }
        $stmt = $conn->prepare('SELECT status, test_message, test_url, options FROM crowd_check_runs WHERE id=? LIMIT 1');
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
        $status = (string)$runRow['status'];
        if (!in_array($status, ['queued', 'running'], true)) {
            $conn->close();
            return;
        }
        $testMessage = (string)($runRow['test_message'] ?? '');
        $testUrl = (string)($runRow['test_url'] ?? '');
        $options = [];
        if (!empty($runRow['options'])) {
            $decoded = json_decode((string)$runRow['options'], true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }
        $concurrency = isset($options['concurrency']) ? max(1, min(20, (int)$options['concurrency'])) : 5;
        $chunkSize = isset($options['chunk_size']) ? max(1, min(200, (int)$options['chunk_size'])) : max($concurrency, 10);
        $timeout = isset($options['timeout']) ? max(5, min(180, (int)$options['timeout'])) : 25;
        $filters = isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : [];

        $conn->query("UPDATE crowd_check_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . (int)$runId . " LIMIT 1");

        $processed = 0;
        $success = 0;
        $failed = 0;
        $needsReview = 0;

        do {
            if ($conn->connect_errno) {
                break;
            }
            $cancel = false;
            if ($resCancel = $conn->query("SELECT cancel_requested FROM crowd_check_runs WHERE id=" . (int)$runId . " LIMIT 1")) {
                if ($rowCancel = $resCancel->fetch_assoc()) {
                    $cancel = !empty($rowCancel['cancel_requested']);
                }
                $resCancel->free();
            }
            if ($cancel) {
                break;
            }

            $stmtChunk = $conn->prepare('SELECT r.id AS result_id, r.link_id, l.url, l.status FROM crowd_check_results r JOIN crowd_links l ON l.id = r.link_id WHERE r.run_id = ? AND r.status = "queued" ORDER BY r.id ASC LIMIT ?');
            if (!$stmtChunk) {
                break;
            }
            $stmtChunk->bind_param('ii', $runId, $chunkSize);
            $stmtChunk->execute();
            $resChunk = $stmtChunk->get_result();
            $tasks = [];
            while ($row = $resChunk->fetch_assoc()) {
                $tasks[] = [
                    'result_id' => (int)$row['result_id'],
                    'link_id' => (int)$row['link_id'],
                    'url' => (string)$row['url'],
                ];
            }
            $stmtChunk->close();
            if (empty($tasks)) {
                break;
            }

            $now = date('Y-m-d H:i:s');
            $stmtRunMark = $conn->prepare("UPDATE crowd_check_results SET status='running', started_at=? WHERE id=?");
            if ($stmtRunMark) {
                foreach ($tasks as $task) {
                    $stmtRunMark->bind_param('si', $now, $task['result_id']);
                    $stmtRunMark->execute();
                }
                $stmtRunMark->close();
            }
            $stmtLinkMark = $conn->prepare("UPDATE crowd_links SET status='checking', last_run_id=? WHERE id=?");
            if ($stmtLinkMark) {
                foreach ($tasks as $task) {
                    $stmtLinkMark->bind_param('ii', $runId, $task['link_id']);
                    $stmtLinkMark->execute();
                }
                $stmtLinkMark->close();
            }

            $results = pp_crowd_links_process_tasks($tasks, $testMessage, $testUrl, $timeout, $concurrency);

            $stmtUpdateRes = $conn->prepare('UPDATE crowd_check_results SET status=?, finished_at=?, http_status=?, follow_type=?, index_status=?, detected_language=?, detected_region=?, message_found=?, link_found=?, error=?, response_url=? WHERE id=?');
            $stmtUpdateLink = $conn->prepare('UPDATE crowd_links SET status=?, http_status=?, follow_type=?, is_indexed=?, language=?, region=?, last_checked_at=?, last_success_at=CASE WHEN ? THEN ? ELSE last_success_at END, last_error=?, last_detected_url=? WHERE id=?');
            if (!$stmtUpdateRes || !$stmtUpdateLink) {
                if ($stmtUpdateRes) {
                    $stmtUpdateRes->close();
                }
                if ($stmtUpdateLink) {
                    $stmtUpdateLink->close();
                }
                break;
            }
            foreach ($results as $entry) {
                $processed++;
                $statusResult = $entry['status'];
                if ($statusResult === 'success') {
                    $success++;
                } elseif ($statusResult === 'needs_review') {
                    $needsReview++;
                } elseif ($statusResult === 'failed') {
                    $failed++;
                }
                $finishedAt = date('Y-m-d H:i:s');
                $httpStatus = $entry['http_status'];
                $followType = $entry['follow_type'];
                $indexStatus = $entry['index_status'];
                $language = $entry['language'];
                $region = $entry['region'];
                $messageFound = $entry['message_found'] ? 1 : 0;
                $linkFound = $entry['link_found'] ? 1 : 0;
                $error = $entry['error'];
                $responseUrl = $entry['response_url'];
                // status(s), finished_at(s), http_status(i), follow_type(s), index_status(s), detected_language(s), detected_region(s), message_found(i), link_found(i), error(s), response_url(s), id(i)
                $stmtUpdateRes->bind_param('ssissssiissi',
                    $statusResult,
                    $finishedAt,
                    $httpStatus,
                    $followType,
                    $indexStatus,
                    $language,
                    $region,
                    $messageFound,
                    $linkFound,
                    $error,
                    $responseUrl,
                    $entry['result_id']
                );
                $stmtUpdateRes->execute();

                $linkStatus = $statusResult;
                $nowTs = date('Y-m-d H:i:s');
                $isSuccess = $statusResult === 'success' ? 1 : 0;
                $lastSuccessAt = $isSuccess ? $nowTs : null;
                // status(s), http_status(i), follow_type(s), is_indexed(s), language(s), region(s), last_checked_at(s), is_success(i), last_success_at(s), last_error(s), last_detected_url(s), id(i)
                $stmtUpdateLink->bind_param('sisssssisssi',
                    $linkStatus,
                    $httpStatus,
                    $followType,
                    $indexStatus,
                    $language,
                    $region,
                    $nowTs,
                    $isSuccess,
                    $lastSuccessAt,
                    $error,
                    $responseUrl,
                    $entry['link_id']
                );
                $stmtUpdateLink->execute();
            }
            $stmtUpdateRes->close();
            $stmtUpdateLink->close();

            $stmtTotals = $conn->prepare('UPDATE crowd_check_runs SET processed_count=?, success_count=?, failure_count=?, needs_review_count=?, last_progress_at=CURRENT_TIMESTAMP WHERE id=?');
            if ($stmtTotals) {
                $stmtTotals->bind_param('iiiii', $processed, $success, $failed, $needsReview, $runId);
                $stmtTotals->execute();
                $stmtTotals->close();
            }
        } while (true);

        $finalStatus = 'finished';
        if ($conn->connect_errno) {
            $finalStatus = 'failed';
        } else {
            if ($res = $conn->query("SELECT cancel_requested FROM crowd_check_runs WHERE id=" . (int)$runId . " LIMIT 1")) {
                if ($row = $res->fetch_assoc()) {
                    if (!empty($row['cancel_requested'])) {
                        $finalStatus = 'cancelled';
                    }
                }
                $res->free();
            }
        }
        $stmtFinish = $conn->prepare('UPDATE crowd_check_runs SET status=?, processed_count=?, success_count=?, failure_count=?, needs_review_count=?, finished_at=CURRENT_TIMESTAMP WHERE id=?');
        if ($stmtFinish) {
            $stmtFinish->bind_param('siiiii', $finalStatus, $processed, $success, $failed, $needsReview, $runId);
            $stmtFinish->execute();
            $stmtFinish->close();
        }
        $conn->close();
        pp_crowd_links_log('Worker done', ['runId' => $runId, 'status' => $finalStatus, 'processed' => $processed]);
    }
}

// Cooperative progress: advance a small chunk within a regular HTTP request.
if (!function_exists('pp_crowd_links_tick')) {
    function pp_crowd_links_tick(?int $runId = null, int $maxBatch = 0): int {
        try { $conn = @connect_db(); } catch (Throwable $e) { return 0; }
        if (!$conn) { return 0; }
        // Pick latest active run if none provided
        if (!$runId) {
            if ($res = $conn->query("SELECT id FROM crowd_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) { $runId = (int)$row['id']; }
                $res->free();
            }
        }
        if (!$runId) { $conn->close(); return 0; }
        // Load run + options
        $stmt = $conn->prepare('SELECT status, test_message, test_url, options, cancel_requested, processed_count, success_count, failure_count, needs_review_count FROM crowd_check_runs WHERE id=? LIMIT 1');
        if (!$stmt) { $conn->close(); return 0; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) { $conn->close(); return 0; }
        $status = (string)$runRow['status'];
        $testMessage = (string)($runRow['test_message'] ?? '');
        $testUrl = (string)($runRow['test_url'] ?? '');
        $opts = is_string($runRow['options'] ?? '') ? json_decode((string)$runRow['options'], true) : [];
        if (!is_array($opts)) { $opts = []; }
        $concurrency = isset($opts['concurrency']) ? max(1, min(20, (int)$opts['concurrency'])) : 5;
        $chunkSize  = isset($opts['chunk_size'])  ? max(1, min(200, (int)$opts['chunk_size']))  : max($concurrency, 10);
        $timeout    = isset($opts['timeout'])     ? max(5, min(180, (int)$opts['timeout']))     : 25;
        $processed  = (int)($runRow['processed_count'] ?? 0);
        $success    = (int)($runRow['success_count'] ?? 0);
        $failed     = (int)($runRow['failure_count'] ?? 0);
        $needsRev   = (int)($runRow['needs_review_count'] ?? 0);
        $cancel     = !empty($runRow['cancel_requested']);

        // If cancel requested or run finished, finalize quickly
        if ($cancel) {
            $conn->query("UPDATE crowd_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . (int)$runId . " AND status IN ('queued','running')");
            $stmtC = $conn->prepare('UPDATE crowd_check_runs SET status=\'cancelled\', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE id=? LIMIT 1');
            if ($stmtC) { $stmtC->bind_param('i', $runId); $stmtC->execute(); $stmtC->close(); }
            $conn->close();
            return 0;
        }

        if ($status === 'queued') {
            $conn->query("UPDATE crowd_check_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . (int)$runId . " LIMIT 1");
            $status = 'running';
        }
        if ($status !== 'running') { $conn->close(); return 0; }

        // Determine batch size per tick
        $batch = $maxBatch > 0 ? min($maxBatch, $chunkSize, $concurrency) : min($concurrency, $chunkSize);

        // Fetch tasks
        $stmtChunk = $conn->prepare('SELECT r.id AS result_id, r.link_id, l.url FROM crowd_check_results r JOIN crowd_links l ON l.id=r.link_id WHERE r.run_id=? AND r.status=\'queued\' ORDER BY r.id ASC LIMIT ?');
        if (!$stmtChunk) { $conn->close(); return 0; }
        $stmtChunk->bind_param('ii', $runId, $batch);
        $stmtChunk->execute();
        $res = $stmtChunk->get_result();
        $tasks = [];
        while ($row = $res->fetch_assoc()) { $tasks[] = ['result_id'=>(int)$row['result_id'], 'link_id'=>(int)$row['link_id'], 'url'=>(string)$row['url']]; }
        $stmtChunk->close();
        if (empty($tasks)) {
            // Nothing queued — finalize if no running remain
            $rem = 0;
            if ($resRem = $conn->query("SELECT COUNT(*) AS c FROM crowd_check_results WHERE run_id=" . (int)$runId . " AND status IN ('queued','running')")) { if ($rowRem = $resRem->fetch_assoc()) { $rem = (int)$rowRem['c']; } $resRem->free(); }
            if ($rem === 0) {
                $stmtF = $conn->prepare('UPDATE crowd_check_runs SET status=\'finished\', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE id=? LIMIT 1');
                if ($stmtF) { $stmtF->bind_param('i', $runId); $stmtF->execute(); $stmtF->close(); }
            }
            $conn->close();
            return 0;
        }

        // Mark tasks running + links checking
        $now = date('Y-m-d H:i:s');
        if ($stmtMark = $conn->prepare("UPDATE crowd_check_results SET status='running', started_at=? WHERE id=?")) {
            foreach ($tasks as $t) { $stmtMark->bind_param('si', $now, $t['result_id']); $stmtMark->execute(); }
            $stmtMark->close();
        }
        if ($stmtLink = $conn->prepare('UPDATE crowd_links SET status=\'checking\', last_run_id=? WHERE id=?')) {
            foreach ($tasks as $t) { $stmtLink->bind_param('ii', $runId, $t['link_id']); $stmtLink->execute(); }
            $stmtLink->close();
        }

        // Process tasks concurrently (curl_multi)
        $results = pp_crowd_links_process_tasks($tasks, $testMessage, $testUrl, $timeout, $concurrency);

        // Persist results
        $stmtUpdateRes = $conn->prepare('UPDATE crowd_check_results SET status=?, finished_at=?, http_status=?, follow_type=?, index_status=?, detected_language=?, detected_region=?, message_found=?, link_found=?, error=?, response_url=? WHERE id=?');
        $stmtUpdateLink = $conn->prepare('UPDATE crowd_links SET status=?, http_status=?, follow_type=?, is_indexed=?, language=?, region=?, last_checked_at=?, last_success_at=CASE WHEN ? THEN ? ELSE last_success_at END, last_error=?, last_detected_url=? WHERE id=?');
        if ($stmtUpdateRes && $stmtUpdateLink) {
            foreach ($results as $entry) {
                $processed++;
                $st = $entry['status'];
                if ($st === 'success') { $success++; }
                elseif ($st === 'needs_review') { $needsRev++; }
                elseif ($st === 'failed') { $failed++; }
                $finishedAt = date('Y-m-d H:i:s');
                $stmtUpdateRes->bind_param('ssissssiissi',
                    $st,
                    $finishedAt,
                    $entry['http_status'],
                    $entry['follow_type'],
                    $entry['index_status'],
                    $entry['language'],
                    $entry['region'],
                    $entry['message_found'] ? 1 : 0,
                    $entry['link_found'] ? 1 : 0,
                    $entry['error'],
                    $entry['response_url'],
                    $entry['result_id']
                );
                $stmtUpdateRes->execute();

                $isSuccess = ($st === 'success') ? 1 : 0;
                $nowTs = $finishedAt;
                $stmtUpdateLink->bind_param('sisssssisssi',
                    $st,
                    $entry['http_status'],
                    $entry['follow_type'],
                    $entry['index_status'],
                    $entry['language'],
                    $entry['region'],
                    $nowTs,
                    $isSuccess,
                    $isSuccess ? $nowTs : null,
                    $entry['error'],
                    $entry['response_url'],
                    $entry['link_id']
                );
                $stmtUpdateLink->execute();
            }
            $stmtUpdateRes->close();
            $stmtUpdateLink->close();
        }

        // Update counters
        if ($stmtTotals = $conn->prepare('UPDATE crowd_check_runs SET processed_count=?, success_count=?, failure_count=?, needs_review_count=?, last_progress_at=CURRENT_TIMESTAMP WHERE id=?')) {
            $stmtTotals->bind_param('iiiii', $processed, $success, $failed, $needsRev, $runId);
            $stmtTotals->execute();
            $stmtTotals->close();
        }

        $conn->close();
        return count($results);
    }
}

if (!function_exists('pp_crowd_links_process_tasks')) {
    function pp_crowd_links_process_tasks(array $tasks, string $testMessage, string $testUrl, int $timeout, int $concurrency): array {
        $results = [];
        if (empty($tasks)) {
            return $results;
        }
        $mh = curl_multi_init();
        $handles = [];
        $taskData = [];
        $active = 0;
        $queue = $tasks;
        $cookieFiles = [];
        while (!empty($queue) || $active > 0) {
            while (!empty($queue) && count($handles) < $concurrency) {
                $task = array_shift($queue);
                $cookieFile = tempnam(sys_get_temp_dir(), 'ppcrowd');
                $cookieFiles[] = $cookieFile;
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $task['url'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => 'PromoPilotCrowdBot/1.0 (+https://promopilot.ai)',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_ACCEPT_ENCODING => 'gzip,deflate',
                    CURLOPT_HEADER => true,
                    CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_COOKIEJAR => $cookieFile,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[(int)$ch] = $ch;
                $taskData[(int)$ch] = $task + ['cookie' => $cookieFile];
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            curl_multi_select($mh, 0.5);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                $content = curl_multi_getcontent($ch);
                $infoData = curl_getinfo($ch);
                $headerSize = $infoData['header_size'] ?? 0;
                $headersRaw = substr($content, 0, $headerSize);
                $body = substr($content, $headerSize);
                $headers = [];
                if ($headersRaw) {
                    $lines = preg_split("/\r?\n/", $headersRaw, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($lines as $line) {
                        if (strpos($line, ':') !== false) {
                            [$name, $value] = explode(':', $line, 2);
                            $headers[trim(strtolower($name))] = trim($value);
                        }
                    }
                }
                $task = $taskData[$key];
                $checkResult = pp_crowd_links_handle_single_task($task, $infoData, $headers, $body, $testMessage, $testUrl, $timeout);
                $results[] = $checkResult;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[$key], $taskData[$key]);
            }
        }
        curl_multi_close($mh);
        foreach ($cookieFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return $results;
    }
}

if (!function_exists('pp_crowd_links_handle_single_task')) {
    function pp_crowd_links_handle_single_task(array $task, array $info, array $headers, string $body, string $testMessage, string $testUrl, int $timeout): array {
        $status = 'failed';
        $httpStatus = (int)($info['http_code'] ?? 0);
        $error = '';
        $messageFound = false;
        $linkFound = false;
        $followType = 'unknown';
        $indexStatus = 'unknown';
        $language = '';
        $region = '';
        $responseUrl = $info['url'] ?? $task['url'];
        // Stage 1: only proceed for 2xx; treat 3xx/4xx/5xx as immediate error
        if ($httpStatus >= 200 && $httpStatus < 300 && $body !== '') {
            $parsed = pp_crowd_links_parse_document($body);
            $language = $parsed['language'] ?? '';
            $region = $parsed['region'] ?? '';
            if (!empty($parsed['hreflang'])) {
                if ($language === '' && isset($parsed['hreflang']['language'])) {
                    $language = $parsed['hreflang']['language'];
                }
                if ($region === '' && isset($parsed['hreflang']['region'])) {
                    $region = $parsed['hreflang']['region'];
                }
            }
            $indexStatus = pp_crowd_links_detect_index_status($headers, $body);
            if ($testUrl !== '') {
                $followType = pp_crowd_links_detect_follow_type($body, $testUrl);
                $linkFound = $followType !== 'missing';
                if ($followType === 'missing') {
                    $followType = 'unknown';
                }
            }
            $normMsg = mb_strtolower(strip_tags($testMessage));
            $normBody = mb_strtolower(strip_tags($body));
            if ($normMsg !== '' && strpos($normBody, $normMsg) !== false) {
                $messageFound = true;
            } elseif ($testUrl !== '' && strpos($normBody, $testUrl) !== false) {
                $messageFound = true;
                $linkFound = true;
            }
            if ($messageFound && ($linkFound || $testUrl === '')) {
                $status = 'success';
            } elseif ($messageFound) {
                $status = 'needs_review';
                $error = __('Найден текст, но ссылка требует проверки.');
            } else {
                $status = 'needs_review';
                $error = __('Текст или ссылка не найдены.');
            }
        } else {
            $error = sprintf(__('HTTP статус: %d'), $httpStatus);
        }

        $result = array(
            'result_id' => $task['result_id'],
            'link_id' => $task['link_id'],
            'status' => $status,
            'http_status' => $httpStatus,
            'follow_type' => $followType,
            'index_status' => $indexStatus,
            'language' => $language,
            'region' => $region,
            'message_found' => $messageFound,
            'link_found' => $linkFound,
            'error' => $error,
            'response_url' => $responseUrl,
        );

        return $result;
    }
}

if (!function_exists('pp_crowd_links_parse_document')) {
    function pp_crowd_links_parse_document(string $html): array {
        $result = [
            'language' => '',
            'region' => '',
            'hreflang' => [],
        ];
        if ($html === '') {
            return $result;
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML($html);
        libxml_clear_errors();
        if (!$loaded) {
            return $result;
        }
        $xpath = new DOMXPath($dom);
        /** @var DOMElement|null $htmlNode */
        $htmlNode = $xpath->query('//html')->item(0);
        if ($htmlNode && $htmlNode->hasAttribute('lang')) {
            $langAttr = trim(strtolower($htmlNode->getAttribute('lang')));
            if ($langAttr !== '') {
                if (strpos($langAttr, '-') !== false) {
                    [$langPart, $regionPart] = explode('-', $langAttr, 2);
                    $result['language'] = $langPart;
                    $result['region'] = strtoupper($regionPart);
                } else {
                    $result['language'] = $langAttr;
                }
            }
        }
        /** @var DOMElement|null $hreflangNode */
        $hreflangNode = $xpath->query('//link[@rel="alternate" and @hreflang]')->item(0);
        if ($hreflangNode) {
            $hreflang = trim(strtolower($hreflangNode->getAttribute('hreflang')));
            if ($hreflang !== '') {
                if (strpos($hreflang, '-') !== false) {
                    [$langPart, $regionPart] = explode('-', $hreflang, 2);
                    $result['hreflang'] = [
                        'value' => $hreflang,
                        'language' => $langPart,
                        'region' => strtoupper($regionPart),
                    ];
                } else {
                    $result['hreflang'] = [
                        'value' => $hreflang,
                        'language' => $hreflang,
                        'region' => '',
                    ];
                }
            }
        }
        return $result;
    }
}

if (!function_exists('pp_crowd_links_detect_index_status')) {
    function pp_crowd_links_detect_index_status(array $headers, string $html): string {
        $robotsHeader = $headers['x-robots-tag'] ?? '';
        $robotsMeta = '';
        if (preg_match('~<meta[^>]+name=["\"]robots["\"][^>]*content=["\"]([^"\"]+)["\"][^>]*>~i', $html, $m)) {
            $robotsMeta = strtolower($m[1]);
        }
        $combined = strtolower(trim($robotsHeader . ' ' . $robotsMeta));
        if ($combined === '') {
            return 'unknown';
        }
        if (strpos($combined, 'noindex') !== false) {
            return 'noindex';
        }
        if (strpos($combined, 'index') !== false) {
            return 'index';
        }
        return 'unknown';
    }
}

if (!function_exists('pp_crowd_links_detect_follow_type')) {
    function pp_crowd_links_detect_follow_type(string $html, string $targetUrl): string {
        if ($targetUrl === '') {
            return 'unknown';
        }
        $targetUrl = rtrim($targetUrl, '/');
        $pattern = '~<a[^>]+href=["\"]' . preg_quote($targetUrl, '~') . '(/)?["\"][^>]*>~i';
        if (!preg_match($pattern, $html, $m)) {
            return 'missing';
        }
        $anchor = $m[0];
        if (stripos($anchor, 'nofollow') !== false) {
            return 'nofollow';
        }
        return 'follow';
    }
}
