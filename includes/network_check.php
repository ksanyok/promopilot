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

// The rest of network check API (start/status/cancel/process) remains in functions.php for now.

?>
