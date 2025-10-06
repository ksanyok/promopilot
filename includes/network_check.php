<?php
// Network diagnostics and queue-related helpers extracted from functions.php

// Logging
if (!function_exists('pp_network_check_log')) {
    function pp_network_check_log(string $message, array $context = []): void {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $file = $dir . '/network_check.log';
            $timestamp = date('Y-m-d H:i:s');
            $line = '[' . $timestamp . '] ' . $message;
            if (!empty($context)) {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($encoded !== false && $encoded !== null) { $line .= ' ' . $encoded; }
            }
            $line .= "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Update networks row with last check status
if (!function_exists('pp_network_check_update_network_row')) {
    function pp_network_check_update_network_row(mysqli $conn, string $slug, array $fields): void {
        $allowed = [
            'last_check_status' => 'string', 'last_check_run_id' => 'int', 'last_check_started_at' => 'datetime',
            'last_check_finished_at' => 'datetime', 'last_check_url' => 'string', 'last_check_error' => 'string',
        ];
        $assignments = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) { continue; }
            if ($value === null) { $assignments[] = "`{$key}` = NULL"; }
            elseif ($allowed[$key] === 'int') { $assignments[] = "`{$key}` = " . (int)$value; }
            else { $assignments[] = "`{$key}` = '" . $conn->real_escape_string((string)$value) . "'"; }
        }
        $assignments[] = "`last_check_updated_at` = CURRENT_TIMESTAMP";
        $slugEsc = $conn->real_escape_string($slug);
        $sql = "UPDATE networks SET " . implode(', ', $assignments) . " WHERE slug = '{$slugEsc}' LIMIT 1";
        @$conn->query($sql);
    }
}

// Wait for worker start flag
if (!function_exists('pp_network_check_wait_for_worker_start')) {
    function pp_network_check_wait_for_worker_start(int $runId, float $timeoutSeconds = 3.0): bool {
        $deadline = microtime(true) + max(0.5, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            try { $conn = @connect_db(); } catch (Throwable $e) {
                pp_network_check_log('Worker start check failed: DB connection', ['runId' => $runId, 'error' => $e->getMessage()]);
                return false;
            }
            if (!$conn) { return false; }
            $runRow = null;
            if ($stmt = $conn->prepare("SELECT status, started_at FROM network_check_runs WHERE id = ? LIMIT 1")) {
                $stmt->bind_param('i', $runId);
                if ($stmt->execute()) { $runRow = $stmt->get_result()->fetch_assoc(); }
                $stmt->close();
            }
            $conn->close();
            if ($runRow) {
                $status = (string)($runRow['status'] ?? '');
                $startedAt = (string)($runRow['started_at'] ?? '');
                if ($status !== 'queued' || ($startedAt !== '' && $startedAt !== '0000-00-00 00:00:00')) {
                    return true;
                }
            } else { return false; }
            usleep(200000);
        }
        return false;
    }
}

// Background worker launch
if (!function_exists('pp_network_check_launch_worker')) {
    function pp_network_check_launch_worker(int $runId): bool {
        $script = PP_ROOT_PATH . '/scripts/network_check_worker.php';
        if (!is_file($script)) { return false; }
        $phpBinary = PHP_BINARY ?: 'php';
        $runId = max(1, $runId);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId;
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) { @pclose($handle); return true; }
            return false;
        }
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' ' . $runId . ' > /dev/null 2>&1 &';
        pp_network_check_log('Launching network check worker', ['runId' => $runId, 'command' => $cmd, 'phpBinary' => $phpBinary]);
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) { @pclose($handle); return true; }
        }
        @exec($cmd);
        return true;
    }
}

// Network check orchestration API (moved from functions.php)

if (!function_exists('pp_network_check_start')) {
    function pp_network_check_start(?int $userId = null, ?string $mode = 'bulk', ?string $targetSlug = null, ?array $targetSlugs = null): array {
        $mode = in_array($mode, ['bulk','single','selection'], true) ? $mode : 'bulk';
        $targetSlug = pp_normalize_slug((string)$targetSlug);
        $selectionMap = [];
        if (is_array($targetSlugs)) {
            foreach ($targetSlugs as $sel) {
                $normalized = pp_normalize_slug((string)$sel);
                if ($normalized !== '') {
                    $selectionMap[$normalized] = true;
                }
            }
        }
        $targetSlugs = array_keys($selectionMap);

        $allNetworks = pp_get_networks(false, false);
        $availableNetworks = [];
        foreach ($allNetworks as $network) {
            $slug = pp_normalize_slug((string)$network['slug']);
            if ($slug === '') { continue; }
            if (!empty($network['is_missing'])) { continue; }
            $availableNetworks[$slug] = $network;
        }

        $eligibleNetworks = [];
        if ($mode === 'bulk') {
            foreach ($availableNetworks as $net) {
                if (!empty($net['enabled'])) {
                    $eligibleNetworks[] = $net;
                }
            }
            if (empty($eligibleNetworks)) {
                return ['ok' => false, 'error' => 'NO_ENABLED_NETWORKS'];
            }
        } elseif ($mode === 'single') {
            if ($targetSlug === '') {
                return ['ok' => false, 'error' => 'MISSING_SLUG'];
            }
            if (!isset($availableNetworks[$targetSlug])) {
                return ['ok' => false, 'error' => 'NETWORK_NOT_FOUND'];
            }
            $eligibleNetworks[] = $availableNetworks[$targetSlug];
        } else { // selection
            foreach ($targetSlugs as $sel) {
                if (isset($availableNetworks[$sel])) {
                    $eligibleNetworks[] = $availableNetworks[$sel];
                }
            }
            if (empty($eligibleNetworks)) {
                return ['ok' => false, 'error' => 'NETWORK_NOT_FOUND'];
            }
        }

        pp_network_check_log('Request to start network check', [
            'mode' => $mode,
            'targetSlug' => $targetSlug ?: null,
            'selectedSlugs' => $mode === 'selection' ? $targetSlugs : null,
            'eligibleNetworks' => count($eligibleNetworks),
            'userId' => $userId,
        ]);

        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_network_check_log('Network check start failed: DB connection error', ['mode' => $mode, 'targetSlug' => $targetSlug]);
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        $activeId = null;
        if ($res = @$conn->query("SELECT id FROM network_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) { $activeId = (int)$row['id']; }
            $res->free();
        }
        if ($activeId) {
            pp_network_check_log('Network check already running', ['existingRunId' => $activeId]);
            $conn->close();
            return ['ok' => true, 'runId' => $activeId, 'alreadyRunning' => true];
        }

        $total = count($eligibleNetworks);
        if ($userId !== null) {
            $stmt = $conn->prepare("INSERT INTO network_check_runs (status, total_networks, initiated_by, run_mode) VALUES ('queued', ?, ?, ?)");
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_WRITE']; }
            $stmt->bind_param('iis', $total, $userId, $mode);
        } else {
            $stmt = $conn->prepare("INSERT INTO network_check_runs (status, total_networks, run_mode) VALUES ('queued', ?, ?)");
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_WRITE']; }
            $stmt->bind_param('is', $total, $mode);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            pp_network_check_log('Network check start failed: insert run', ['mode' => $mode, 'targetSlug' => $targetSlug]);
            return ['ok' => false, 'error' => 'DB_WRITE'];
        }
        $stmt->close();
        $runId = (int)$conn->insert_id;

        $resStmt = $conn->prepare("INSERT INTO network_check_results (run_id, network_slug, network_title) VALUES (?, ?, ?)");
        if ($resStmt) {
            foreach ($eligibleNetworks as $net) {
                $slug = (string)$net['slug'];
                $title = (string)($net['title'] ?? $slug);
                $resStmt->bind_param('iss', $runId, $slug, $title);
                $resStmt->execute();
            }
            $resStmt->close();
        }
        $conn->close();

        pp_network_check_log('Network check run created', ['runId' => $runId, 'mode' => $mode, 'targetSlug' => $targetSlug ?: null, 'networks' => $total]);

        if (!pp_network_check_launch_worker($runId)) {
            try {
                $conn2 = @connect_db();
                if ($conn2) {
                    $msg = __('Не удалось запустить фоновый процесс.');
                    $upd = $conn2->prepare("UPDATE network_check_runs SET status='failed', notes=? WHERE id=? LIMIT 1");
                    if ($upd) {
                        $upd->bind_param('si', $msg, $runId);
                        $upd->execute();
                        $upd->close();
                    }
                    $conn2->close();
                }
            } catch (Throwable $e) { /* ignore */ }
            pp_network_check_log('Network check worker launch failed', ['runId' => $runId]);
            return ['ok' => false, 'error' => 'WORKER_LAUNCH_FAILED'];
        }

        pp_network_check_log('Network check worker launched', ['runId' => $runId]);

        if (!pp_network_check_wait_for_worker_start($runId, 3.0)) {
            pp_network_check_log('Worker did not start in time; processing inline', ['runId' => $runId]);
            try {
                pp_process_network_check_run($runId);
                pp_network_check_log('Inline network check processing completed', ['runId' => $runId]);
            } catch (Throwable $e) {
                pp_network_check_log('Inline network check processing failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => true, 'runId' => $runId, 'alreadyRunning' => false];
    }
}

if (!function_exists('pp_network_check_format_ts')) {
    function pp_network_check_format_ts(?string $ts): ?string {
        if (!$ts) { return null; }
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') { return null; }
        $time = strtotime($ts);
        if ($time === false) { return $ts; }
        return date(DATE_ATOM, $time);
    }
}

if (!function_exists('pp_network_check_get_status')) {
    function pp_network_check_get_status(?int $runId = null): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM network_check_runs ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) { $runId = (int)$row['id']; }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'run' => null, 'results' => []];
        }

        $stmt = $conn->prepare("SELECT id, status, total_networks, success_count, failure_count, notes, initiated_by, run_mode, cancel_requested, created_at, started_at, finished_at FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_READ']; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }

        $results = [];
        $stmtRes = $conn->prepare("SELECT id, network_slug, network_title, status, started_at, finished_at, published_url, error, created_at FROM network_check_results WHERE run_id = ? ORDER BY id ASC");
        if ($stmtRes) {
            $stmtRes->bind_param('i', $runId);
            $stmtRes->execute();
            $res = $stmtRes->get_result();
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'id' => (int)$row['id'],
                    'network_slug' => (string)$row['network_slug'],
                    'network_title' => (string)$row['network_title'],
                    'status' => (string)$row['status'],
                    'started_at' => $row['started_at'],
                    'started_at_iso' => pp_network_check_format_ts($row['started_at'] ?? null),
                    'finished_at' => $row['finished_at'],
                    'finished_at_iso' => pp_network_check_format_ts($row['finished_at'] ?? null),
                    'created_at' => $row['created_at'],
                    'created_at_iso' => pp_network_check_format_ts($row['created_at'] ?? null),
                    'published_url' => (string)($row['published_url'] ?? ''),
                    'error' => (string)($row['error'] ?? ''),
                ];
            }
            $stmtRes->close();
        }
        $conn->close();

        $run = [
            'id' => (int)$runRow['id'],
            'status' => (string)$runRow['status'],
            'total_networks' => (int)$runRow['total_networks'],
            'success_count' => (int)$runRow['success_count'],
            'failure_count' => (int)$runRow['failure_count'],
            'notes' => (string)($runRow['notes'] ?? ''),
            'run_mode' => (string)($runRow['run_mode'] ?? 'bulk'),
            'initiated_by' => $runRow['initiated_by'] !== null ? (int)$runRow['initiated_by'] : null,
            'cancel_requested' => !empty($runRow['cancel_requested']),
            'created_at' => $runRow['created_at'],
            'created_at_iso' => pp_network_check_format_ts($runRow['created_at'] ?? null),
            'started_at' => $runRow['started_at'],
            'started_at_iso' => pp_network_check_format_ts($runRow['started_at'] ?? null),
            'finished_at' => $runRow['finished_at'],
            'finished_at_iso' => pp_network_check_format_ts($runRow['finished_at'] ?? null),
        ];
        $run['completed_count'] = $run['success_count'] + $run['failure_count'];
        $run['in_progress'] = ($run['status'] === 'running');
        $run['has_failures'] = ($run['failure_count'] > 0);

        return ['ok' => true, 'run' => $run, 'results' => $results];
    }
}

if (!function_exists('pp_network_check_cancel')) {
    function pp_network_check_cancel(?int $runId = null, bool $force = false): array {
        pp_network_check_log('Cancel network check requested', ['runId' => $runId, 'force' => $force]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_network_check_log('Cancel network check failed: DB connection error', ['runId' => $runId]);
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM network_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) { $runId = (int)$row['id']; }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'status' => 'idle'];
        }

        $stmt = $conn->prepare("SELECT id, status, cancel_requested, notes, success_count, failure_count FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_READ']; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }

        $status = (string)$runRow['status'];
        $alreadyDone = !in_array($status, ['queued','running'], true);
        $existingNote = trim((string)($runRow['notes'] ?? ''));
        $cancelRequested = !empty($runRow['cancel_requested']);
        $cancelNote = __('Проверка остановлена администратором.');
        $note = $cancelNote;
        if ($existingNote !== '') {
            if (stripos($existingNote, $cancelNote) !== false) {
                $note = $existingNote;
            } else {
                $note .= ' | ' . $existingNote;
            }
        }

        @$conn->query("UPDATE network_check_runs SET cancel_requested=1 WHERE id=" . (int)$runId . " LIMIT 1");

        if ($alreadyDone && !$force) {
            pp_network_check_log('Cancel ignored: run already finished', ['runId' => $runId, 'status' => $status]);
            $conn->close();
            return [
                'ok' => true,
                'runId' => $runId,
                'status' => $status,
                'cancelRequested' => true,
                'alreadyFinished' => true,
                'finished' => true,
            ];
        }

        $forceApply = $force || $status === 'queued';
        if ($forceApply) {
            @$conn->query("UPDATE network_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . (int)$runId . " AND status IN ('queued','running')");
            $success = 0;
            $failed = 0;
            if ($resCnt = @$conn->query("SELECT SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_count, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failure_count FROM network_check_results WHERE run_id=" . (int)$runId)) {
                if ($rowCnt = $resCnt->fetch_assoc()) {
                    $success = (int)($rowCnt['success_count'] ?? 0);
                    $failed = (int)($rowCnt['failure_count'] ?? 0);
                }
                $resCnt->free();
            }
            $upd = $conn->prepare("UPDATE network_check_runs SET status='cancelled', success_count=?, failure_count=?, finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('iisi', $success, $failed, $note, $runId);
                $upd->execute();
                $upd->close();
            }
            if ($resSlugs = @$conn->query("SELECT DISTINCT network_slug FROM network_check_results WHERE run_id=" . (int)$runId . " AND status='cancelled'")) {
                while ($rowSlug = $resSlugs->fetch_assoc()) {
                    $slugCancel = (string)$rowSlug['network_slug'];
                    if ($slugCancel === '') { continue; }
                    pp_network_check_update_network_row($conn, $slugCancel, [
                        'last_check_status' => 'cancelled',
                        'last_check_run_id' => $runId,
                        'last_check_finished_at' => date('Y-m-d H:i:s'),
                        'last_check_error' => null,
                    ]);
                }
                $resSlugs->free();
            }
            pp_network_check_log('Cancel applied immediately', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'cancelRequested' => true, 'finished' => true];
        }

        if (!$cancelRequested) {
            $updNote = $conn->prepare("UPDATE network_check_runs SET notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($updNote) {
                $updNote->bind_param('si', $note, $runId);
                $updNote->execute();
                $updNote->close();
            }
        }
        $conn->close();
        pp_network_check_log('Cancel request recorded', ['runId' => $runId, 'status' => $status]);
        return ['ok' => true, 'runId' => $runId, 'status' => $status, 'cancelRequested' => true, 'finished' => false];
    }
}

if (!function_exists('pp_process_network_check_run')) {
    function pp_process_network_check_run(int $runId): void {
        if ($runId <= 0) { return; }
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);

        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_network_check_log('Worker unable to connect to DB', ['runId' => $runId]);
            return;
        }
        if (!$conn) { return; }

        $stmt = $conn->prepare("SELECT id, status, total_networks, run_mode, cancel_requested, notes, success_count, failure_count FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            pp_network_check_log('Worker run not found', ['runId' => $runId]);
            $conn->close();
            return;
        }

        $status = (string)$runRow['status'];
        $runMode = isset($runRow['run_mode']) ? (string)$runRow['run_mode'] : 'bulk';
        $existingNote = trim((string)($runRow['notes'] ?? ''));
        $cancelRequested = !empty($runRow['cancel_requested']);
        $cancelNoteBase = __('Проверка остановлена администратором.');
        $noteFormatter = static function(string $baseNote, string $existing): string {
            $base = trim($baseNote);
            $existing = trim($existing);
            if ($base === '') { return $existing; }
            if ($existing === '') { return $base; }
            if (stripos($existing, $base) !== false) { return $existing; }
            return $base . ' | ' . $existing;
        };
        $recalcCounts = function() use ($conn, $runId): array {
            $success = 0;
            $failed = 0;
            if ($res = @$conn->query("SELECT SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_count, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failure_count FROM network_check_results WHERE run_id = " . (int)$runId)) {
                if ($row = $res->fetch_assoc()) {
                    $success = (int)($row['success_count'] ?? 0);
                    $failed = (int)($row['failure_count'] ?? 0);
                }
                $res->free();
            }
            return [$success, $failed];
        };
        $finalizeCancelled = function(?int $successOverride = null, ?int $failureOverride = null) use ($conn, $runId, $cancelNoteBase, &$existingNote, $recalcCounts, $noteFormatter): void {
            @$conn->query("UPDATE network_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id = " . (int)$runId . " AND status IN ('queued','running')");
            [$success, $failed] = $recalcCounts();
            if ($successOverride !== null) { $success = $successOverride; }
            if ($failureOverride !== null) { $failed = $failureOverride; }
            $note = $noteFormatter($cancelNoteBase, $existingNote);
            $existingNote = $note;
            $upd = $conn->prepare("UPDATE network_check_runs SET status='cancelled', success_count=?, failure_count=?, finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('iisi', $success, $failed, $note, $runId);
                $upd->execute();
                $upd->close();
            }
            if ($resSlugs = @$conn->query("SELECT DISTINCT network_slug FROM network_check_results WHERE run_id = " . (int)$runId . " AND status = 'cancelled'")) {
                while ($rowSlug = $resSlugs->fetch_assoc()) {
                    $slugCancel = (string)$rowSlug['network_slug'];
                    if ($slugCancel === '') { continue; }
                    pp_network_check_update_network_row($conn, $slugCancel, [
                        'last_check_status' => 'cancelled',
                        'last_check_run_id' => $runId,
                        'last_check_finished_at' => date('Y-m-d H:i:s'),
                        'last_check_error' => null,
                    ]);
                }
                $resSlugs->free();
            }
            pp_network_check_log('Cancel applied immediately', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
        };

        if (!in_array($status, ['queued','running'], true)) {
            pp_network_check_log('Worker exiting: status not actionable', ['runId' => $runId, 'status' => $status]);
            $conn->close();
            return;
        }

        if ($cancelRequested && $status === 'queued') {
            $finalizeCancelled(null, null);
            pp_network_check_log('Worker cancelled queued run before start', ['runId' => $runId]);
            $conn->close();
            return;
        }

        $conn->query("UPDATE network_check_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . (int)$runId . " LIMIT 1");
        pp_network_check_log('Worker started processing', ['runId' => $runId, 'status' => $status]);

        $results = [];
        $resStmt = $conn->prepare("SELECT id, network_slug, network_title FROM network_check_results WHERE run_id = ? ORDER BY id ASC");
        if ($resStmt) {
            $resStmt->bind_param('i', $runId);
            $resStmt->execute();
            $res = $resStmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $results[] = $row;
            }
            $resStmt->close();
        }

        $total = count($results);
        if ($total === 0) {
            $msg = __('Нет активных сетей для проверки.');
            $upd = $conn->prepare("UPDATE network_check_runs SET status='failed', notes=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('si', $msg, $runId);
                $upd->execute();
                $upd->close();
            }
            pp_network_check_log('Worker found no networks to process', ['runId' => $runId]);
            $conn->close();
            return;
        }
        if ((int)$runRow['total_networks'] !== $total) {
            $conn->query("UPDATE network_check_runs SET total_networks=" . $total . " WHERE id=" . (int)$runId . " LIMIT 1");
            pp_network_check_log('Worker adjusted total networks', ['runId' => $runId, 'total' => $total]);
        }

        if ($cancelRequested && $status === 'running') {
            $successExisting = isset($runRow['success_count']) ? (int)$runRow['success_count'] : null;
            $failureExisting = isset($runRow['failure_count']) ? (int)$runRow['failure_count'] : null;
            $finalizeCancelled($successExisting, $failureExisting);
            pp_network_check_log('Worker aborted run before loop due to cancellation', ['runId' => $runId]);
            $conn->close();
            return;
        }

        $success = 0;
        $failed = 0;
        $checkCancelled = function() use ($conn, $runId): bool {
            if ($res = @$conn->query("SELECT cancel_requested FROM network_check_runs WHERE id = " . (int)$runId . " LIMIT 1")) {
                $row = $res->fetch_assoc();
                $res->free();
                return !empty($row['cancel_requested']);
            }
            return false;
        };

        $updateResultRunning = $conn->prepare("UPDATE network_check_results SET status='running', started_at=CURRENT_TIMESTAMP, error=NULL WHERE id=? LIMIT 1");
        $updateResultSuccess = $conn->prepare("UPDATE network_check_results SET status='success', finished_at=CURRENT_TIMESTAMP, published_url=?, error=NULL WHERE id=? LIMIT 1");
        $updateResultFail = $conn->prepare("UPDATE network_check_results SET status='failed', finished_at=CURRENT_TIMESTAMP, error=? WHERE id=? LIMIT 1");
        $updateRunCounts = $conn->prepare("UPDATE network_check_runs SET success_count=?, failure_count=?, status='running' WHERE id=? LIMIT 1");

        $cancelledMidway = false;
        foreach ($results as $row) {
            if ($checkCancelled()) {
                $cancelledMidway = true;
                pp_network_check_log('Worker detected cancellation during loop', ['runId' => $runId]);
                break;
            }

            $resId = (int)$row['id'];
            $slug = (string)$row['network_slug'];
            pp_network_check_log('Worker starting network check', ['runId' => $runId, 'resultId' => $resId, 'slug' => $slug]);
            if ($updateResultRunning) {
                $updateResultRunning->bind_param('i', $resId);
                $updateResultRunning->execute();
            }
            pp_network_check_update_network_row($conn, $slug, [
                'last_check_status' => 'running',
                'last_check_run_id' => $runId,
                'last_check_started_at' => date('Y-m-d H:i:s'),
                'last_check_finished_at' => null,
                'last_check_url' => null,
                'last_check_error' => null,
            ]);

            $network = pp_get_network($slug);
            $allowDisabled = in_array($runMode, ['single','selection'], true);
            if (!$network || !empty($network['is_missing']) || (!$allowDisabled && empty($network['enabled']))) {
                $errMsg = __('Обработчик сети недоступен.');
                if ($updateResultFail) {
                    $updateResultFail->bind_param('si', $errMsg, $resId);
                    $updateResultFail->execute();
                }
                $failed++;
                if ($updateRunCounts) {
                    $updateRunCounts->bind_param('iii', $success, $failed, $runId);
                    $updateRunCounts->execute();
                }
                pp_network_check_log('Worker skipped network: handler missing/disabled', ['runId' => $runId, 'slug' => $slug]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'failed',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_error' => $errMsg,
                ]);
                continue;
            }

            $aiProvider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';
            $openaiKey = trim((string)get_setting('openai_api_key', ''));
            $openaiModel = trim((string)get_setting('openai_model', 'gpt-3.5-turbo')) ?: 'gpt-3.5-turbo';
            $job = [
                'url' => 'https://example.com/promo-diagnostics',
                'anchor' => 'PromoPilot diagnostics link',
                'language' => 'ru',
                'wish' => 'Пожалуйста, создай короткую тестовую заметку (диагностика сетей PromoPilot) с положительным нейтральным тоном.',
                'projectId' => 0,
                'projectName' => 'PromoPilot Diagnostics',
                'testMode' => true,
                'aiProvider' => $aiProvider,
                'openaiApiKey' => $openaiKey,
                'openaiModel' => $openaiModel,
                'waitBetweenCallsMs' => 2000,
                'diagnosticRunId' => $runId,
                'networkSlug' => $slug,
                'page_meta' => null,
                'captcha' => [
                    'provider' => (string)get_setting('captcha_provider', 'none'),
                    'apiKey' => (string)get_setting('captcha_api_key', ''),
                    'fallback' => [
                        'provider' => (string)get_setting('captcha_fallback_provider', 'none'),
                        'apiKey' => (string)get_setting('captcha_fallback_api_key', ''),
                    ],
                ],
            ];

            $result = null;
            try {
                pp_network_check_log('Worker invoking network handler', [
                    'runId' => $runId,
                    'slug' => $slug,
                    'jobUrl' => $job['url'],
                    'testMode' => !empty($job['testMode']),
                ]);
                $result = pp_publish_via_network($network, $job, 480);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => 'PHP_EXCEPTION', 'details' => $e->getMessage()];
            }

            $publishedUrl = '';
            $ok = is_array($result) && !empty($result['ok']) && !empty($result['publishedUrl']);
            if ($ok) {
                $publishedUrl = trim((string)$result['publishedUrl']);
                if ($updateResultSuccess) {
                    $updateResultSuccess->bind_param('si', $publishedUrl, $resId);
                    $updateResultSuccess->execute();
                }
                $success++;
                pp_network_check_log('Worker network success', ['runId' => $runId, 'slug' => $slug, 'publishedUrl' => $publishedUrl]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'success',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_url' => $publishedUrl,
                    'last_check_error' => null,
                ]);
            } else {
                $err = '';
                if (is_array($result)) {
                    $err = (string)($result['details'] ?? $result['error'] ?? $result['stderr'] ?? 'UNKNOWN_ERROR');
                } else {
                    $err = 'UNKNOWN_ERROR';
                }
                $errLen = function_exists('mb_strlen') ? mb_strlen($err) : strlen($err);
                if ($errLen > 2000) {
                    $err = function_exists('mb_substr') ? mb_substr($err, 0, 2000) : substr($err, 0, 2000);
                }
                if ($updateResultFail) {
                    $updateResultFail->bind_param('si', $err, $resId);
                    $updateResultFail->execute();
                }
                $failed++;
                pp_network_check_log('Worker network failed', ['runId' => $runId, 'slug' => $slug, 'error' => $err]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'failed',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_error' => $err,
                ]);
            }

            if ($updateRunCounts) {
                $updateRunCounts->bind_param('iii', $success, $failed, $runId);
                $updateRunCounts->execute();
            }
        }

        if ($updateResultRunning) { $updateResultRunning->close(); }
        if ($updateResultSuccess) { $updateResultSuccess->close(); }
        if ($updateResultFail) { $updateResultFail->close(); }
        if ($updateRunCounts) { $updateRunCounts->close(); }

        if ($cancelledMidway || $checkCancelled()) {
            $finalizeCancelled($success, $failed);
            pp_network_check_log('Worker finalised cancellation after partial run', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
            $conn->close();
            return;
        }

        $finalStatus = ($failed === 0) ? 'success' : 'completed';
        $updFinish = $conn->prepare("UPDATE network_check_runs SET status=?, success_count=?, failure_count=?, total_networks=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if ($updFinish) {
            $updFinish->bind_param('siiii', $finalStatus, $success, $failed, $total, $runId);
            $updFinish->execute();
            $updFinish->close();
        }

        pp_network_check_log('Worker finished run', ['runId' => $runId, 'status' => $finalStatus, 'success' => $success, 'failed' => $failed, 'total' => $total]);

        $conn->close();
    }
}


?>
