<?php
// Crowd deep submission verification: detect forms, submit test message, log evidence

if (!function_exists('pp_crowd_deep_status_meta')) {
    function pp_crowd_deep_status_meta(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [
            'pending' => ['label' => __('Глубокая проверка не выполнялась'), 'class' => 'badge bg-secondary text-uppercase fw-normal'],
            'queued' => ['label' => __('Ожидает глубокой проверки'), 'class' => 'badge bg-info text-dark'],
            'running' => ['label' => __('Глубокая проверка выполняется'), 'class' => 'badge bg-primary'],
            'success' => ['label' => __('Сообщение найдено'), 'class' => 'badge bg-success'],
            'partial' => ['label' => __('Проверить вручную'), 'class' => 'badge bg-warning text-dark'],
            'failed' => ['label' => __('Отправка не удалась'), 'class' => 'badge bg-danger'],
            'blocked' => ['label' => __('Блокировка/капча'), 'class' => 'badge bg-dark'],
            'no_form' => ['label' => __('Форма не найдена'), 'class' => 'badge bg-secondary'],
            'skipped' => ['label' => __('Пропущено'), 'class' => 'badge bg-secondary-subtle text-body-secondary'],
        ];
        return $cache;
    }
}

if (!function_exists('pp_crowd_deep_scope_options')) {
    function pp_crowd_deep_scope_options(): array {
        return [
            'all' => __('Все ссылки'),
            'pending' => __('Только без глубокой проверки'),
            'errors' => __('Только с ошибками'),
            'selection' => __('Только выбранные'),
        ];
    }
}

if (!function_exists('pp_crowd_deep_is_error_status')) {
    function pp_crowd_deep_is_error_status(string $status): bool {
        return in_array($status, ['failed', 'blocked', 'no_form'], true);
    }
}

if (!function_exists('pp_crowd_deep_debug_sanitize')) {
    function pp_crowd_deep_debug_sanitize($value, int $depth = 0)
    {
        if ($depth > 4) {
            if (is_scalar($value) || $value === null) {
                return $value;
            }
            return '...';
        }
        if (is_array($value)) {
            $sanitized = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 40) {
                    $sanitized['__truncated__'] = true;
                    break;
                }
                $sanitized[$key] = pp_crowd_deep_debug_sanitize($item, $depth + 1);
            }
            return $sanitized;
        }
        if (is_string($value)) {
            if (function_exists('pp_crowd_deep_clip')) {
                return pp_crowd_deep_clip($value, 600);
            }
            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, 600, 'UTF-8');
            }
            return substr($value, 0, 600);
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_object($value)) {
            return 'object:' . get_class($value);
        }
        return (string)$value;
    }
}

if (!function_exists('pp_crowd_deep_debug_log')) {
    function pp_crowd_deep_debug_log(string $message, array $context = []): void
    {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (!is_dir($dir) || !is_writable($dir)) { return; }
            $file = $dir . '/crowd_deep_debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $line = '[' . $timestamp . '] ' . $message;
            if (!empty($context)) {
                $sanitized = pp_crowd_deep_debug_sanitize($context);
                $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($encoded !== false && $encoded !== null) {
                    $line .= ' ' . $encoded;
                }
            }
            $line .= "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

if (!function_exists('pp_crowd_deep_default_options')) {
    function pp_crowd_deep_default_options(): array {
        return [
            'message_template' => __('Здравствуйте! Это тестовое сообщение PromoPilot. Ссылка для проверки: {{link}}. Код проверки: {{token}}'),
            'message_link' => 'https://example.com/',
            'name' => 'PromoPilot QA',
            'company' => 'PromoPilot',
            'email_user' => 'promopilot.qa',
            'email_domain' => 'example.com',
            'phone' => '+79990000000',
            'token_prefix' => strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        ];
    }
}

if (!function_exists('pp_crowd_deep_prepare_options')) {
    function pp_crowd_deep_prepare_options(array $input): array {
        $defaults = pp_crowd_deep_default_options();
        $options = array_merge($defaults, array_filter($input, static function($v) {
            return $v !== null && $v !== '';
        }));
        $errors = [];

        $tpl = trim((string)($options['message_template'] ?? ''));
        if ($tpl === '') {
            $tpl = $defaults['message_template'];
        }
        if (strpos($tpl, '{{link}}') === false) {
            $tpl .= "\n" . __('Ссылка: {{link}}');
        }
        if (strpos($tpl, '{{token}}') === false) {
            $tpl .= "\n" . __('Код: {{token}}');
        }
        $options['message_template'] = $tpl;

        $linkRaw = trim((string)($options['message_link'] ?? ''));
        if ($linkRaw === '') {
            $linkRaw = $defaults['message_link'];
        }
        if (!preg_match('~^https?://~i', $linkRaw)) {
            $linkRaw = 'https://' . ltrim($linkRaw, '/');
        }
        if (!filter_var($linkRaw, FILTER_VALIDATE_URL)) {
            $errors[] = __('Укажите корректную ссылку, которую нужно включить в сообщение.');
        }
        $options['message_link'] = $linkRaw;

        $emailUser = strtolower(preg_replace('~[^a-z0-9._+-]~i', '', (string)($options['email_user'] ?? '')));
        if ($emailUser === '') {
            $emailUser = $defaults['email_user'];
        }
        $options['email_user'] = $emailUser;

        $emailDomain = strtolower(preg_replace('~[^a-z0-9.-]~i', '', (string)($options['email_domain'] ?? '')));
        if ($emailDomain === '' || strpos($emailDomain, '.') === false) {
            $emailDomain = $defaults['email_domain'];
        }
        $options['email_domain'] = $emailDomain;

        $tokenPrefix = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($options['token_prefix'] ?? '')));
        if ($tokenPrefix === '') {
            $tokenPrefix = $defaults['token_prefix'];
        }
        if (strlen($tokenPrefix) > 12) {
            $tokenPrefix = substr($tokenPrefix, 0, 12);
        }
        $options['token_prefix'] = $tokenPrefix;

        $phone = trim((string)($options['phone'] ?? ''));
        if ($phone === '') {
            $phone = $defaults['phone'];
        }
        $options['phone'] = $phone;

        $name = trim((string)($options['name'] ?? ''));
        if ($name === '') {
            $name = $defaults['name'];
        }
        $options['name'] = $name;

        $company = trim((string)($options['company'] ?? ''));
        if ($company === '') {
            $company = $defaults['company'];
        }
        $options['company'] = $company;

        return ['ok' => empty($errors), 'options' => $options, 'errors' => $errors];
    }
}

if (!function_exists('pp_crowd_deep_collect_ids')) {
    function pp_crowd_deep_collect_ids(mysqli $conn, string $scope, array $selectedIds): array {
        $ids = [];
        if ($scope === 'selection') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn($v) => $v > 0)));
            return $ids;
        }
        $where = '';
        if ($scope === 'pending') {
            $where = "WHERE deep_status IN ('pending','queued','running','skipped','no_form')";
        } elseif ($scope === 'errors') {
            $where = "WHERE deep_status IN ('failed','blocked','no_form')";
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

if (!function_exists('pp_crowd_deep_launch_worker')) {
    function pp_crowd_deep_launch_worker(int $runId): bool {
        $script = PP_ROOT_PATH . '/scripts/crowd_links_deep_worker.php';
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
        pp_crowd_links_log('Launching deep worker', ['runId' => $runId, 'cmd' => $cmd]);
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

if (!function_exists('pp_crowd_deep_wait_for_worker_start')) {
    function pp_crowd_deep_wait_for_worker_start(int $runId, float $timeoutSeconds = 3.0): bool {
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
            if ($stmt = $conn->prepare('SELECT status, started_at FROM crowd_deep_runs WHERE id = ? LIMIT 1')) {
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

if (!function_exists('pp_crowd_deep_start_check')) {
    function pp_crowd_deep_start_check(?int $userId, string $scope, array $selectedIds = [], array $optionsInput = []): array {
        $scope = in_array($scope, ['all', 'pending', 'errors', 'selection'], true) ? $scope : 'all';
        $prepared = pp_crowd_deep_prepare_options($optionsInput);
        if (!$prepared['ok']) {
            return ['ok' => false, 'error' => 'INVALID_OPTIONS', 'messages' => $prepared['errors']];
        }
        $options = $prepared['options'];
        pp_crowd_deep_debug_log('Deep run request', [
            'userId' => $userId,
            'scope' => $scope,
            'selectedCount' => count($selectedIds),
            'token_prefix' => $options['token_prefix'] ?? null,
            'message_link' => $options['message_link'] ?? null,
            'template_length' => isset($options['message_template']) ? (function_exists('mb_strlen') ? mb_strlen($options['message_template'], 'UTF-8') : strlen($options['message_template'])) : null,
        ]);
        pp_crowd_links_log('Request to start deep crowd check', ['userId' => $userId, 'scope' => $scope, 'selected' => count($selectedIds)]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }

        $activeRow = null;
        if ($res = @$conn->query("SELECT id, status, cancel_requested, started_at, last_activity_at FROM crowd_deep_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $activeRow = $row;
            }
            $res->free();
        }
        if ($activeRow) {
            $activeId = (int)($activeRow['id'] ?? 0);
            $status = (string)($activeRow['status'] ?? '');
            $alreadyCancelRequested = !empty($activeRow['cancel_requested']);
            $isStalled = pp_crowd_links_is_stalled_run($activeRow, 180);
            if ($activeId && ($alreadyCancelRequested || $isStalled)) {
                $note = __('Автоматическая остановка глубокой проверки из-за отсутствия активности.');
                $stmt = $conn->prepare("UPDATE crowd_deep_runs SET status='cancelled', finished_at=CURRENT_TIMESTAMP, cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP, notes=TRIM(CONCAT_WS('\n', NULLIF(notes,''), ?)) WHERE id=? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $note, $activeId);
                    $stmt->execute();
                    $stmt->close();
                }
                @$conn->query('UPDATE crowd_links SET deep_processing_run_id = NULL WHERE deep_processing_run_id = ' . $activeId);
                pp_crowd_links_log('Auto-cancelled stalled deep run', ['runId' => $activeId, 'cancelRequested' => $alreadyCancelRequested, 'stalled' => $isStalled]);
            } elseif ($activeId) {
                $conn->close();
                return ['ok' => true, 'runId' => $activeId, 'alreadyRunning' => true, 'status' => $status];
            }
        }

        $ids = pp_crowd_deep_collect_ids($conn, $scope, $selectedIds);
        if (empty($ids)) {
            $conn->close();
            return ['ok' => false, 'error' => 'NO_LINKS'];
        }
        $total = count($ids);
        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($optionsJson === false) {
            $optionsJson = '{}';
        }
        if ($userId !== null) {
            $stmt = $conn->prepare("INSERT INTO crowd_deep_runs (status, scope, total_links, message_template, message_url, options_json, token_prefix, initiated_by) VALUES ('queued', ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_WRITE'];
            }
            $stmt->bind_param('sissssi', $scope, $total, $options['message_template'], $options['message_link'], $optionsJson, $options['token_prefix'], $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO crowd_deep_runs (status, scope, total_links, message_template, message_url, options_json, token_prefix) VALUES ('queued', ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                $conn->close();
                return ['ok' => false, 'error' => 'DB_WRITE'];
            }
            $stmt->bind_param('sissss', $scope, $total, $options['message_template'], $options['message_link'], $optionsJson, $options['token_prefix']);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            return ['ok' => false, 'error' => 'DB_WRITE'];
        }
        $stmt->close();
        $runId = (int)$conn->insert_id;

        $chunks = array_chunk($ids, 400);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "UPDATE crowd_links SET deep_processing_run_id = ?, deep_last_run_id = ?, deep_status='queued', deep_error=NULL, deep_message_excerpt=NULL, deep_evidence_url=NULL, deep_checked_at=NULL WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $params = array_merge([$runId, $runId], $chunk);
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

        if (!pp_crowd_deep_launch_worker($runId)) {
            try {
                $conn2 = @connect_db();
                if ($conn2) {
                    $msg = __('Не удалось запустить фоновую глубокую проверку.');
                    $upd = $conn2->prepare("UPDATE crowd_deep_runs SET status='failed', notes=? WHERE id=? LIMIT 1");
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
        if (!pp_crowd_deep_wait_for_worker_start($runId, 3.0)) {
            pp_crowd_links_log('Deep worker did not start in time, running inline', ['runId' => $runId]);
            try {
                pp_crowd_deep_process_run($runId);
            } catch (Throwable $e) {
                pp_crowd_links_log('Inline deep processing failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            }
        }
        return ['ok' => true, 'runId' => $runId, 'total' => $total, 'alreadyRunning' => false];
    }
}

if (!function_exists('pp_crowd_deep_format_ts')) {
    function pp_crowd_deep_format_ts(?string $ts): ?string {
        if (!$ts) { return null; }
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') { return null; }
        $time = strtotime($ts);
        if ($time === false) { return $ts; }
        return date(DATE_ATOM, $time);
    }
}

if (!function_exists('pp_crowd_deep_get_status')) {
    function pp_crowd_deep_get_status(?int $runId = null): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query('SELECT id FROM crowd_deep_runs ORDER BY id DESC LIMIT 1')) {
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
        $stmt = $conn->prepare('SELECT * FROM crowd_deep_runs WHERE id = ? LIMIT 1');
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
            'success_count' => (int)($row['success_count'] ?? 0),
            'partial_count' => (int)($row['partial_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'skipped_count' => (int)($row['skipped_count'] ?? 0),
            'message_template' => (string)($row['message_template'] ?? ''),
            'message_url' => (string)($row['message_url'] ?? ''),
            'token_prefix' => (string)($row['token_prefix'] ?? ''),
            'initiated_by' => $row['initiated_by'] !== null ? (int)$row['initiated_by'] : null,
            'notes' => (string)($row['notes'] ?? ''),
            'cancel_requested' => !empty($row['cancel_requested']),
            'created_at' => $row['created_at'] ?? null,
            'created_at_iso' => pp_crowd_deep_format_ts($row['created_at'] ?? null),
            'started_at' => $row['started_at'] ?? null,
            'started_at_iso' => pp_crowd_deep_format_ts($row['started_at'] ?? null),
            'finished_at' => $row['finished_at'] ?? null,
            'finished_at_iso' => pp_crowd_deep_format_ts($row['finished_at'] ?? null),
            'last_activity_at' => $row['last_activity_at'] ?? null,
            'last_activity_iso' => pp_crowd_deep_format_ts($row['last_activity_at'] ?? null),
        ];
        $run['error_count'] = $run['failed_count'];
        $total = max(1, $run['total_links']);
        $run['progress_percent'] = min(100, (int)round($run['processed_count'] * 100 / $total));
        $run['in_progress'] = in_array($run['status'], ['queued', 'running'], true);
        $run['stalled'] = pp_crowd_links_is_stalled_run($row, 180);
        return ['ok' => true, 'run' => $run];
    }
}

if (!function_exists('pp_crowd_deep_cancel')) {
    function pp_crowd_deep_cancel(?int $runId = null, bool $force = false): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM crowd_deep_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
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
        $stmt = $conn->prepare('SELECT id, status, cancel_requested, started_at, last_activity_at, notes FROM crowd_deep_runs WHERE id = ? LIMIT 1');
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
        if (!in_array($status, ['queued', 'running'], true)) {
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => $status, 'alreadyFinished' => true];
        }
        $alreadyRequested = !empty($row['cancel_requested']);
        $shouldForce = $force;
        if (!$shouldForce && $status === 'running') {
            if ($alreadyRequested) {
                $shouldForce = true;
            } elseif (pp_crowd_links_is_stalled_run($row, 180)) {
                $shouldForce = true;
            }
        }
        @$conn->query("UPDATE crowd_deep_runs SET cancel_requested = 1 WHERE id = " . (int)$runId . " LIMIT 1");
        if ($status === 'queued') {
            @$conn->query("UPDATE crowd_deep_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            @$conn->query("UPDATE crowd_links SET deep_processing_run_id = NULL WHERE deep_processing_run_id = " . (int)$runId);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'finished' => true];
        }
        if ($shouldForce) {
            $note = __('Принудительно остановлено администратором (глубокая проверка).');
            $stmt = $conn->prepare("UPDATE crowd_deep_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP, notes=TRIM(CONCAT_WS('\n', NULLIF(notes,''), ?)) WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $note, $runId);
                $stmt->execute();
                $stmt->close();
            } else {
                @$conn->query("UPDATE crowd_deep_runs SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), cancel_requested=0, last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            }
            @$conn->query("UPDATE crowd_links SET deep_processing_run_id = NULL WHERE deep_processing_run_id = " . (int)$runId);
            pp_crowd_links_log('Deep run force-cancelled', ['runId' => $runId, 'requested' => $alreadyRequested]);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'finished' => true, 'forced' => true];
        }
        $conn->close();
        return ['ok' => true, 'runId' => $runId, 'status' => $status, 'cancelRequested' => true];
    }
}

if (!function_exists('pp_crowd_deep_update_run_counts')) {
    function pp_crowd_deep_update_run_counts(mysqli $conn, int $runId, array $counts, string $status = 'running'): void {
        $stmt = $conn->prepare('UPDATE crowd_deep_runs SET processed_count=?, success_count=?, partial_count=?, failed_count=?, skipped_count=?, status=?, last_activity_at=CURRENT_TIMESTAMP WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('iiiiisi', $counts['processed'], $counts['success'], $counts['partial'], $counts['failed'], $counts['skipped'], $status, $runId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('pp_crowd_deep_fetch_results')) {
    function pp_crowd_deep_fetch_results(int $runId, int $limit = 50, int $offset = 0): array {
        $items = [];
        $total = 0;
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['items' => [], 'total' => 0];
        }
        if (!$conn) {
            return ['items' => [], 'total' => 0];
        }
        $limit = max(1, min(200, $limit));
        if ($stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM crowd_deep_results WHERE run_id = ?')) {
            $stmt->bind_param('i', $runId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) { $total = (int)$row['cnt']; }
            }
            $stmt->close();
        }
        $sql = 'SELECT id, link_id, url, status, http_status, final_url, message_token, message_excerpt, response_excerpt, evidence_url, request_payload, duration_ms, error, created_at FROM crowd_deep_results WHERE run_id = ? ORDER BY id DESC LIMIT ? OFFSET ?';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('iii', $runId, $limit, $offset);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $items[] = [
                        'id' => (int)$row['id'],
                        'link_id' => (int)$row['link_id'],
                        'url' => (string)$row['url'],
                        'status' => (string)$row['status'],
                        'http_status' => $row['http_status'] !== null ? (int)$row['http_status'] : null,
                        'final_url' => (string)($row['final_url'] ?? ''),
                        'message_token' => (string)($row['message_token'] ?? ''),
                        'message_excerpt' => (string)($row['message_excerpt'] ?? ''),
                        'response_excerpt' => (string)($row['response_excerpt'] ?? ''),
                        'evidence_url' => (string)($row['evidence_url'] ?? ''),
                        'request_payload' => (string)($row['request_payload'] ?? ''),
                        'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
                        'error' => (string)($row['error'] ?? ''),
                        'created_at' => (string)($row['created_at'] ?? ''),
                    ];
                }
            }
            $stmt->close();
        }
        $conn->close();
        return ['items' => $items, 'total' => $total];
    }
}

if (!function_exists('pp_crowd_deep_get_recent_results')) {
    function pp_crowd_deep_get_recent_results(?int $runId, int $limit = 20): array {
        if (!$runId) { return []; }
        $data = pp_crowd_deep_fetch_results($runId, $limit, 0);
        return $data['items'];
    }
}

if (!function_exists('pp_crowd_deep_clip')) {
    function pp_crowd_deep_clip(string $text, int $max = 600): string {
        if ($max <= 0) { return ''; }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') <= $max) { return $text; }
            return mb_substr($text, 0, $max, 'UTF-8');
        }
        if (strlen($text) <= $max) { return $text; }
        return substr($text, 0, $max);
    }
}

if (!function_exists('pp_crowd_deep_collect_text_nodes')) {
    function pp_crowd_deep_collect_text_nodes(DOMNode $node): string {
        return trim((string)$node->textContent);
    }
}

if (!function_exists('pp_crowd_deep_find_label_text')) {
    function pp_crowd_deep_find_label_text(DOMElement $element, DOMXPath $xp): string {
        $id = $element->getAttribute('id');
        if ($id !== '') {
            $labels = $xp->query('//label[@for]');
            if ($labels) {
                foreach ($labels as $label) {
                    if ($label instanceof DOMElement && $label->getAttribute('for') === $id) {
                        return pp_crowd_deep_collect_text_nodes($label);
                    }
                }
            }
        }
        $parent = $element->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'label') {
            return pp_crowd_deep_collect_text_nodes($parent);
        }
        return '';
    }
}

if (!function_exists('pp_crowd_deep_collect_tokens')) {
    function pp_crowd_deep_collect_tokens(DOMElement $field, DOMXPath $xp): array {
        $tokens = [];
        foreach (['name', 'id', 'class', 'placeholder', 'title', 'aria-label'] as $attr) {
            $val = trim((string)$field->getAttribute($attr));
            if ($val !== '') {
                $tokens[] = strtolower($val);
            }
        }
        $label = strtolower(trim(pp_crowd_deep_find_label_text($field, $xp)));
        if ($label !== '') {
            $tokens[] = $label;
        }
        $parent = $field->parentNode;
        if ($parent instanceof DOMElement) {
            $legend = $xp->query('./ancestor::fieldset/legend', $field)->item(0);
            if ($legend instanceof DOMElement) {
                $tokens[] = strtolower(trim($legend->textContent));
            }
        }
        $tokens = array_values(array_unique(array_filter($tokens, static fn($v) => $v !== '')));
        return $tokens;
    }
}

if (!function_exists('pp_crowd_deep_match_tokens')) {
    function pp_crowd_deep_match_tokens(array $tokens, array $needles): bool {
        foreach ($tokens as $token) {
            foreach ($needles as $needle) {
                if ($needle === '') { continue; }
                if (function_exists('mb_stripos')) {
                    if (mb_stripos($token, $needle, 0, 'UTF-8') !== false) { return true; }
                } else {
                    if (stripos($token, $needle) !== false) { return true; }
                }
            }
        }
        return false;
    }
}

if (!function_exists('pp_crowd_deep_classify_field')) {
    function pp_crowd_deep_classify_field(DOMElement $field, DOMXPath $xp): array {
        static $commentTokens = ['comment', 'message', 'review', 'feedback', 'body', 'opis', 'posta', 'опыт', 'коммент', 'сообщ', 'жалоб', 'question', 'ответ', 'texto', 'coment', 'review', 'mesaj', 'content', 'thoughts', 'discussion', 'descr', 'enquiry'];
        static $nameTokens = ['name', 'author', 'fname', 'lname', 'fullname', 'fio', 'имя', 'фамил', 'surname', 'nome', 'nombre', 'prenom', 'ten', 'nick', 'username', 'contact', 'appellation'];
        static $emailTokens = ['mail', 'email', 'courriel', 'correo', 'почт', 'adresse', 'e-mail'];
        static $urlTokens = ['url', 'website', 'site', 'link', 'homepage', 'http', 'web'];
        static $phoneTokens = ['phone', 'tel', 'mobile', 'whatsapp', 'номер', 'telefon', 'telefone', 'cel', 'whats'];
        static $subjectTokens = ['subject', 'tema', 'topic', 'title', 'heading', 'тема', 'заголов', 'betreff'];
        static $companyTokens = ['company', 'organisation', 'organization', 'org', 'business', 'brand', 'firma', 'empresa', 'компан'];
        static $captchaTokens = ['captcha', 'recaptcha', 'cptch', 'security code', 'verification code', 'antispam', 'anti-spam', 'botcheck', 'are you human', 'g-recaptcha'];
        static $termsTokens = ['privacy', 'policy', 'terms', 'consent', 'rgpd', 'gdpr', 'agree', 'accept', 'processing', 'compliance'];
        static $ratingTokens = ['rating', 'stars', 'vote', 'score', 'оцен'];
        static $honeypotTokens = ['honeypot', 'hp_', 'trap', 'ak_hp', 'fakefield', 'antispam'];

        $tag = strtolower($field->tagName);
        $type = strtolower((string)$field->getAttribute('type'));
        if ($tag === 'textarea') {
            $type = 'textarea';
        }
        $tokens = pp_crowd_deep_collect_tokens($field, $xp);
        $required = $field->hasAttribute('required');
        $classAttr = strtolower((string)$field->getAttribute('class'));
        if (!$required && strpos($classAttr, 'required') !== false) {
            $required = true;
        }
        $ariaRequired = strtolower((string)$field->getAttribute('aria-required'));
        if (!$required && in_array($ariaRequired, ['1', 'true'], true)) {
            $required = true;
        }
        $role = 'generic';
        $nameLower = strtolower((string)$field->getAttribute('name'));
        if ($nameLower !== '' && preg_match('~(honeypot|_hp_|ak_hp|trapfield|spamtrap|no_bot)~i', $nameLower)) {
            $role = 'honeypot';
        } elseif (pp_crowd_deep_match_tokens($tokens, $honeypotTokens)) {
            $role = 'honeypot';
        }
        if ($type === 'hidden') {
            $role = 'hidden';
        } elseif ($type === 'textarea') {
            if ($role !== 'honeypot') {
                $role = 'comment';
            }
        } elseif ($type === 'checkbox') {
            $role = pp_crowd_deep_match_tokens($tokens, $termsTokens) ? 'accept' : 'checkbox';
        } elseif ($type === 'radio') {
            if (pp_crowd_deep_match_tokens($tokens, $ratingTokens)) {
                $role = 'rating';
            } else {
                $role = 'radio';
            }
        } elseif ($type === 'file') {
            $role = 'file';
        } elseif ($type === 'email' || pp_crowd_deep_match_tokens($tokens, $emailTokens)) {
            $role = 'email';
        } elseif ($type === 'url' || pp_crowd_deep_match_tokens($tokens, $urlTokens)) {
            $role = 'url';
        } elseif ($type === 'tel' || pp_crowd_deep_match_tokens($tokens, $phoneTokens)) {
            $role = 'phone';
        } elseif ($type === 'password') {
            $role = 'password';
        } elseif (pp_crowd_deep_match_tokens($tokens, $captchaTokens) || strtolower((string)$field->getAttribute('id')) === 'g-recaptcha-response') {
            $role = 'captcha';
        } elseif (pp_crowd_deep_match_tokens($tokens, $commentTokens)) {
            if ($role !== 'honeypot') {
                $role = 'comment';
            }
        } elseif (pp_crowd_deep_match_tokens($tokens, $nameTokens)) {
            $role = 'name';
        } elseif (pp_crowd_deep_match_tokens($tokens, $subjectTokens)) {
            $role = 'subject';
        } elseif (pp_crowd_deep_match_tokens($tokens, $companyTokens)) {
            $role = 'company';
        } elseif ($tag === 'select') {
            if (pp_crowd_deep_match_tokens($tokens, $ratingTokens)) {
                $role = 'rating';
            } elseif (pp_crowd_deep_match_tokens($tokens, $commentTokens)) {
                $role = 'comment';
            } elseif (pp_crowd_deep_match_tokens($tokens, $companyTokens)) {
                $role = 'company';
            } elseif (pp_crowd_deep_match_tokens($tokens, $subjectTokens)) {
                $role = 'subject';
            }
        }
        return [
            'role' => $role,
            'required' => $required,
            'tokens' => $tokens,
            'tag' => $tag,
            'type' => $type,
            'name' => $nameLower,
        ];
    }
}

if (!function_exists('pp_crowd_deep_prepare_fields')) {
    function pp_crowd_deep_prepare_fields(DOMElement $form, DOMXPath $xp, array $identity): array {
        $fields = [];
        $issues = [];
        $hasComment = false;
        $radioGroups = [];
        $commentFieldName = null;
        $fieldMeta = [];
        $acceptNames = [];
        $checkboxNames = [];
        $nodeList = $xp->query('.//input | .//textarea | .//select', $form);
        $recaptchaPresent = false;
        if ($nodeList) {
            foreach ($nodeList as $node) {
                if (!$node instanceof DOMElement) { continue; }
                $info = pp_crowd_deep_classify_field($node, $xp);
                $name = trim((string)$node->getAttribute('name'));
                if ($name === '' && $info['role'] === 'comment') {
                    $fallbackId = trim((string)$node->getAttribute('id'));
                    if ($fallbackId !== '') {
                        $name = $fallbackId;
                    }
                }
                $metaEntry = [
                    'role' => $info['role'],
                    'required' => $info['required'],
                    'tag' => strtolower($node->tagName),
                    'type' => $node->tagName === 'input' ? strtolower((string)$node->getAttribute('type')) : '',
                ];
                if ($name !== '') {
                    $fieldMeta[$name][] = $metaEntry;
                } else {
                    $fieldMeta['_unnamed'][] = $metaEntry;
                }
                if ($info['role'] === 'captcha') {
                    $recaptchaPresent = true;
                }
                if ($info['role'] === 'hidden') {
                    if ($name !== '') {
                        $fields[$name][] = $node->getAttribute('value');
                    }
                    continue;
                }
                if ($info['role'] === 'file') {
                    $issues['file'] = true;
                    continue;
                }
                if ($info['role'] === 'honeypot') {
                    if ($name !== '') {
                        $fields[$name][] = '';
                    }
                    $issues['honeypot'] = true;
                    continue;
                }
                if ($name === '') {
                    if ($info['role'] === 'comment') {
                        $issues['comment_missing_name'] = true;
                    }
                    continue;
                }
                $value = null;
                switch ($info['role']) {
                    case 'comment':
                        $value = $identity['message'];
                        $hasComment = true;
                        $commentFieldName = $name;
                        break;
                    case 'name':
                        $value = $identity['name'];
                        break;
                    case 'email':
                        $value = $identity['email'];
                        break;
                    case 'url':
                        $value = $identity['website'];
                        break;
                    case 'phone':
                        $value = $identity['phone'];
                        break;
                    case 'password':
                        $value = $identity['password'];
                        break;
                    case 'subject':
                        $value = sprintf('PromoPilot %s', $identity['token']);
                        break;
                    case 'company':
                        $value = $identity['company'];
                        break;
                    case 'accept':
                    case 'checkbox':
                        $value = $node->hasAttribute('value') ? $node->getAttribute('value') : 'on';
                        $nameLowerLocal = strtolower($name);
                        if ($info['role'] === 'accept') {
                            $acceptNames[] = $name;
                            if ($node->hasAttribute('value') === false || $value === '' || strtolower($value) === 'on') {
                                if (strpos($nameLowerLocal, 'consent') !== false || strpos($nameLowerLocal, 'agree') !== false) {
                                    $value = 'yes';
                                } elseif (strpos($nameLowerLocal, 'cookie') !== false) {
                                    $value = 'yes';
                                } else {
                                    $value = '1';
                                }
                            }
                        } else {
                            $checkboxNames[] = $name;
                        }
                        if ($name !== '') {
                            if ($nameLowerLocal === 'wp-comment-cookies-consent') {
                                $value = 'yes';
                            }
                        }
                        break;
                    case 'rating':
                        $value = '5';
                        break;
                    case 'radio':
                        $group = $name;
                        if (!isset($radioGroups[$group])) {
                            $radioGroups[$group] = true;
                            $value = $node->hasAttribute('value') ? $node->getAttribute('value') : '1';
                        } else {
                            continue 2;
                        }
                        break;
                    default:
                        if ($info['required']) {
                            $value = $identity['fallback'];
                        } else {
                            $value = $node->getAttribute('value');
                            if ($value === '') {
                                continue 2;
                            }
                        }
                        break;
                }
                if ($info['role'] === 'captcha') {
                    $issues['captcha'] = true;
                    continue;
                }
                if ($node->tagName === 'select') {
                    $selected = null;
                    foreach ($node->getElementsByTagName('option') as $option) {
                        $optValue = $option->getAttribute('value');
                        $optText = trim((string)$option->textContent);
                        $disabled = $option->hasAttribute('disabled');
                        if ($option->hasAttribute('selected') && !$disabled && $optValue !== '') {
                            $selected = $optValue;
                            break;
                        }
                        if ($selected === null && !$disabled && ($optValue !== '' || $optText !== '')) {
                            $selected = $optValue !== '' ? $optValue : $optText;
                        }
                    }
                    if ($selected === null) {
                        if ($info['required']) {
                            $issues['select'] = true;
                        }
                        continue;
                    }
                    $value = $selected;
                }
                if ($node->tagName === 'textarea') {
                    $value = $value ?? $node->textContent;
                }
                if ($node->tagName === 'input') {
                    $type = strtolower($node->getAttribute('type'));
                    if ($type === 'number' && !is_numeric($value)) {
                        $value = '5';
                    }
                }
                if ($info['role'] === 'checkbox' || $info['role'] === 'accept') {
                    $fields[$name][] = (string)$value;
                } else {
                    $fields[$name] = [$value !== null ? (string)$value : ''];
                }
            }
        }
        if ($recaptchaPresent) {
            $issues['captcha'] = true;
        }
        return [
            'fields' => $fields,
            'issues' => $issues,
            'has_comment' => $hasComment,
            'comment_field' => $commentFieldName,
            'field_meta' => $fieldMeta,
            'accept_fields' => array_values(array_unique(array_filter($acceptNames))),
            'checkbox_fields' => array_values(array_unique(array_filter($checkboxNames))),
        ];
    }
}

if (!function_exists('pp_crowd_deep_debug_field_meta')) {
    function pp_crowd_deep_debug_field_meta(array $fieldMeta): array
    {
        $summary = [];
        foreach ($fieldMeta as $name => $entries) {
            if (!is_array($entries)) { continue; }
            $roles = [];
            $tags = [];
            $types = [];
            $required = false;
            foreach ($entries as $entry) {
                if (!is_array($entry)) { continue; }
                $role = (string)($entry['role'] ?? '');
                if ($role !== '') { $roles[] = $role; }
                if (!empty($entry['required'])) { $required = true; }
                $tag = (string)($entry['tag'] ?? '');
                if ($tag !== '') { $tags[] = $tag; }
                $type = (string)($entry['type'] ?? '');
                if ($type !== '') { $types[] = $type; }
            }
            $summary[$name] = [
                'roles' => array_values(array_unique($roles)),
                'required' => $required,
                'tags' => array_values(array_unique($tags)),
            ];
            $types = array_values(array_filter(array_unique($types)));
            if (!empty($types)) {
                $summary[$name]['types'] = $types;
            }
        }
        return $summary;
    }
}

if (!function_exists('pp_crowd_deep_build_plan')) {
    function pp_crowd_deep_build_plan(DOMElement $form, string $baseUrl, array $identity): array {
        $xp = new DOMXPath($form->ownerDocument);
        $method = strtoupper(trim($form->getAttribute('method')));
        if ($method === '') { $method = 'POST'; }
        $action = trim($form->getAttribute('action'));
        $action = $action !== '' ? pp_abs_url($action, $baseUrl) : $baseUrl;
        $plan = pp_crowd_deep_prepare_fields($form, $xp, $identity);
        $issues = [];
        foreach (($plan['issues'] ?? []) as $issueName => $flag) {
            if ($flag) { $issues[] = $issueName; }
        }
        $debug = [
            'method' => $method,
            'action' => $action,
            'issues' => $issues,
            'has_comment' => !empty($plan['has_comment']),
            'comment_field' => $plan['comment_field'] ?? null,
            'field_count' => isset($plan['fields']) && is_array($plan['fields']) ? count($plan['fields']) : 0,
            'accept_fields' => $plan['accept_fields'] ?? [],
            'checkbox_fields' => $plan['checkbox_fields'] ?? [],
            'field_meta' => pp_crowd_deep_debug_field_meta($plan['field_meta'] ?? []),
            'payload_keys' => [],
        ];
        if (!empty($plan['issues']['captcha'])) {
            return ['ok' => false, 'reason' => 'captcha', 'debug' => $debug];
        }
        if (!empty($plan['issues']['file'])) {
            return ['ok' => false, 'reason' => 'file', 'debug' => $debug];
        }
        if (!$plan['has_comment']) {
            return ['ok' => false, 'reason' => 'no_comment', 'debug' => $debug];
        }
        if (empty($plan['fields'])) {
            return ['ok' => false, 'reason' => 'no_fields', 'debug' => $debug];
        }
        $payload = [];
        foreach ($plan['fields'] as $name => $values) {
            if (count($values) === 1) {
                $payload[$name] = $values[0];
            } else {
                $payload[$name] = $values;
            }
        }
        $debug['payload_keys'] = array_keys($payload);
        return [
            'ok' => true,
            'method' => $method,
            'action' => $action,
            'payload' => $payload,
            'debug' => $debug,
        ];
    }
}

if (!function_exists('pp_crowd_deep_http_request')) {
    function pp_crowd_deep_http_request(string $url, array $opts): array {
        $timeout = isset($opts['timeout']) ? max(5, (int)$opts['timeout']) : 25;
        $method = strtoupper($opts['method'] ?? 'GET');
        $data = $opts['data'] ?? null;
        $headers = $opts['headers'] ?? [];
        $cookieFile = $opts['cookieFile'] ?? null;
        $referer = $opts['referer'] ?? '';
        $ua = 'PromoPilotDeepBot/1.0 (+https://github.com/ksanyok/promopilot)';
        $defaultHeaders = [
            'User-Agent: ' . $ua,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru,en;q=0.8',
        ];
        $headers = array_merge($defaultHeaders, $headers);
        $body = '';
        $status = 0;
        $respHeaders = [];
        $finalUrl = $url;
        $error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init();
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($cookieFile) {
                $options[CURLOPT_COOKIEFILE] = $cookieFile;
                $options[CURLOPT_COOKIEJAR] = $cookieFile;
            }
            if ($referer !== '') {
                $options[CURLOPT_REFERER] = $referer;
            }
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                if (is_array($data)) {
                    $options[CURLOPT_POSTFIELDS] = http_build_query($data);
                } else {
                    $options[CURLOPT_POSTFIELDS] = (string)$data;
                }
            } elseif ($method !== 'GET') {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                if (is_array($data)) {
                    $options[CURLOPT_POSTFIELDS] = http_build_query($data);
                } elseif ($data !== null) {
                    $options[CURLOPT_POSTFIELDS] = (string)$data;
                }
            }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            if ($raw !== false) {
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headerPart = substr($raw, 0, $headerSize);
                $body = substr($raw, $headerSize);
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
            } else {
                $error = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $contextOptions = [
                'http' => [
                    'method' => $method,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers),
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ];
            if ($method !== 'GET' && $data !== null) {
                $contextOptions['http']['content'] = is_array($data) ? http_build_query($data) : (string)$data;
            }
            $context = stream_context_create($contextOptions);
            $raw = @file_get_contents($url, false, $context);
            if ($raw !== false) {
                $body = (string)$raw;
                global $http_response_header;
                if (is_array($http_response_header)) {
                    foreach ($http_response_header as $line) {
                        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                            $status = (int)$m[1];
                        } elseif (strpos($line, ':') !== false) {
                            [$k, $v] = array_map('trim', explode(':', $line, 2));
                            if ($k !== '') {
                                $respHeaders[strtolower($k)] = $v;
                            }
                        }
                    }
                }
            } else {
                $error = __('Не удалось выполнить HTTP запрос (stream).');
            }
        }
        return [
            'status' => $status,
            'body' => (string)$body,
            'headers' => $respHeaders,
            'final_url' => $finalUrl,
            'error' => $error,
        ];
    }
}

if (!function_exists('pp_crowd_deep_generate_identity')) {
    function pp_crowd_deep_generate_identity(array $options, int $runId, int $linkId): array {
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $token = strtoupper($options['token_prefix'] . '-' . base_convert($linkId, 10, 36) . '-' . $random);
        $email = $options['email_user'] . '+' . strtolower(str_replace('-', '', $token)) . '@' . $options['email_domain'];
        return [
            'token' => $token,
            'message' => str_replace(['{{token}}', '{{link}}'], [$token, $options['message_link']], $options['message_template']),
            'email' => $email,
            'name' => $options['name'] . ' ' . substr($random, 0, 4),
            'phone' => $options['phone'],
            'website' => $options['message_link'],
            'password' => substr(bin2hex(random_bytes(6)), 0, 12),
            'company' => $options['company'],
            'fallback' => 'PromoPilot QA',
        ];
    }
}

if (!function_exists('pp_crowd_deep_detect_success_keywords')) {
    function pp_crowd_deep_detect_success_keywords(string $body): bool {
        $keywords = ['awaiting moderation', 'your comment is awaiting', 'спасибо', 'успешно', 'thank you', 'we received', 'moderation', 'pending review', 'будет проверен', 'komentarz oczekuje', 'yorumunuz'];
        $bodyLower = function_exists('mb_strtolower') ? mb_strtolower($body, 'UTF-8') : strtolower($body);
        foreach ($keywords as $word) {
            if (strpos($bodyLower, $word) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('pp_crowd_deep_detect_failure_keywords')) {
    function pp_crowd_deep_detect_failure_keywords(string $body): bool {
        $keywords = ['captcha', 'bot', 'error', 'ошибка', 'spam', 'denied', 'blocked', 'please log in', 'must be logged', 'не прошла', 'не удалось'];
        $bodyLower = function_exists('mb_strtolower') ? mb_strtolower($body, 'UTF-8') : strtolower($body);
        foreach ($keywords as $word) {
            if (strpos($bodyLower, $word) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('pp_crowd_deep_extract_excerpt')) {
    function pp_crowd_deep_extract_excerpt(string $body, string $token, int $radius = 120): string {
        if ($body === '' || $token === '') { return ''; }
        $pos = stripos($body, $token);
        if ($pos === false) { return ''; }
        $start = max(0, $pos - $radius);
        $length = min(strlen($body) - $start, strlen($token) + $radius * 2);
        return substr($body, $start, $length);
    }
}

if (!function_exists('pp_crowd_deep_handle_link')) {
    function pp_crowd_deep_handle_link(array $link, array $identity, array $options): array {
        $runId = isset($options['_run_id']) ? (int)$options['_run_id'] : null;
        $linkId = isset($link['id']) ? (int)$link['id'] : 0;
        $url = (string)$link['url'];
        $cookieFile = tempnam(sys_get_temp_dir(), 'ppdeep_');
        $result = [
            'status' => 'failed',
            'http_status' => 0,
            'error' => '',
            'message_excerpt' => pp_crowd_deep_clip((string)$identity['message'], 300),
            'response_excerpt' => '',
            'evidence_url' => '',
            'request_payload' => '',
            'duration_ms' => null,
            'token' => $identity['token'],
        ];
        pp_crowd_deep_debug_log('Deep link start', [
            'runId' => $runId,
            'linkId' => $linkId,
            'url' => $url,
            'token' => $identity['token'] ?? '',
        ]);
        $startAll = microtime(true);
        $fetch = pp_crowd_deep_http_request($url, [
            'method' => 'GET',
            'timeout' => 25,
            'cookieFile' => $cookieFile,
        ]);
        pp_crowd_deep_debug_log('Deep initial fetch', [
            'runId' => $runId,
            'linkId' => $linkId,
            'httpStatus' => $fetch['status'] ?? 0,
            'finalUrl' => $fetch['final_url'] ?? '',
            'bodyBytes' => isset($fetch['body']) ? strlen((string)$fetch['body']) : 0,
            'error' => $fetch['error'] ?? '',
        ]);
        if ($fetch['error'] !== '') {
            $result['error'] = $fetch['error'];
            $result['status'] = 'failed';
            pp_crowd_deep_debug_log('Deep fetch error', [
                'runId' => $runId,
                'linkId' => $linkId,
                'error' => $result['error'],
            ]);
            @unlink($cookieFile);
            return $result;
        }
        $body = (string)($fetch['body'] ?? '');
        if ($body === '') {
            $result['error'] = __('Не получен HTML контент страницы.');
            $result['status'] = 'failed';
            pp_crowd_deep_debug_log('Deep fetch empty body', [
                'runId' => $runId,
                'linkId' => $linkId,
                'finalUrl' => $fetch['final_url'] ?? '',
            ]);
            @unlink($cookieFile);
            return $result;
        }
        $doc = pp_html_dom($body);
        if (!$doc) {
            $result['error'] = __('Не удалось разобрать HTML.');
            $result['status'] = 'failed';
            pp_crowd_deep_debug_log('Deep fetch parse failed', [
                'runId' => $runId,
                'linkId' => $linkId,
                'finalUrl' => $fetch['final_url'] ?? '',
            ]);
            @unlink($cookieFile);
            return $result;
        }
        $forms = $doc->getElementsByTagName('form');
        $formsCount = ($forms instanceof DOMNodeList) ? $forms->length : 0;
        pp_crowd_deep_debug_log('Deep forms detected', [
            'runId' => $runId,
            'linkId' => $linkId,
            'count' => $formsCount,
            'finalUrl' => $fetch['final_url'] ?? $url,
        ]);
        $plan = null;
        $formIndex = 0;
        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) { continue; }
            $formIndex++;
            $candidate = pp_crowd_deep_build_plan($form, $fetch['final_url'] ?? $url, $identity);
            pp_crowd_deep_debug_log('Deep form candidate', [
                'runId' => $runId,
                'linkId' => $linkId,
                'index' => $formIndex,
                'ok' => !empty($candidate['ok']),
                'reason' => $candidate['reason'] ?? null,
                'method' => $candidate['method'] ?? null,
                'action' => $candidate['action'] ?? null,
                'debug' => $candidate['debug'] ?? [],
            ]);
            if (!empty($candidate['ok'])) {
                $plan = $candidate;
                break;
            }
            if (!empty($candidate['reason']) && in_array($candidate['reason'], ['captcha', 'file'], true)) {
                $result['status'] = $candidate['reason'] === 'captcha' ? 'blocked' : 'skipped';
                $result['error'] = $candidate['reason'] === 'captcha' ? __('Обнаружена CAPTCHA, автоматическая отправка невозможна.') : __('Форма требует загрузку файла.');
                pp_crowd_deep_debug_log('Deep form rejected critical', [
                    'runId' => $runId,
                    'linkId' => $linkId,
                    'index' => $formIndex,
                    'reason' => $candidate['reason'],
                    'debug' => $candidate['debug'] ?? [],
                ]);
                @unlink($cookieFile);
                return $result;
            }
        }
        if (!$plan) {
            $result['status'] = 'no_form';
            $result['error'] = __('На странице не найдена форма с полем комментария.');
            pp_crowd_deep_debug_log('Deep plan not found', [
                'runId' => $runId,
                'linkId' => $linkId,
                'formsChecked' => $formIndex,
            ]);
            @unlink($cookieFile);
            return $result;
        }
        pp_crowd_deep_debug_log('Deep form selected', [
            'runId' => $runId,
            'linkId' => $linkId,
            'method' => $plan['method'],
            'action' => $plan['action'],
            'payloadKeys' => array_keys($plan['payload']),
            'debug' => $plan['debug'] ?? [],
        ]);
        $result['request_payload'] = pp_crowd_deep_clip(is_array($plan['payload']) ? http_build_query($plan['payload']) : (string)$plan['payload'], 800);
        $submit = pp_crowd_deep_http_request($plan['action'], [
            'method' => $plan['method'],
            'timeout' => 25,
            'cookieFile' => $cookieFile,
            'referer' => $url,
            'data' => $plan['payload'],
        ]);
        pp_crowd_deep_debug_log('Deep submit request', [
            'runId' => $runId,
            'linkId' => $linkId,
            'method' => $plan['method'],
            'action' => $plan['action'],
            'httpStatus' => $submit['status'] ?? 0,
            'finalUrl' => $submit['final_url'] ?? '',
            'error' => $submit['error'] ?? '',
            'payloadPreview' => $result['request_payload'],
        ]);
        $durationMs = (int)round((microtime(true) - $startAll) * 1000);
        $result['duration_ms'] = $durationMs;
        $result['http_status'] = (int)($submit['status'] ?? 0);
        $result['evidence_url'] = (string)($submit['final_url'] ?? $plan['action']);
        $responseBody = (string)($submit['body'] ?? '');
        if ($submit['error'] !== '') {
            $result['error'] = $submit['error'];
            $result['status'] = 'failed';
            pp_crowd_deep_debug_log('Deep submit error', [
                'runId' => $runId,
                'linkId' => $linkId,
                'error' => $result['error'],
            ]);
            @unlink($cookieFile);
            return $result;
        }
        $token = $identity['token'];
        $excerpt = pp_crowd_deep_extract_excerpt($responseBody, $token);
        $successKeywordsDetected = false;
        $failureKeywordsDetected = false;
        $statusReason = 'token_not_found';
        if ($excerpt !== '') {
            $result['status'] = 'success';
            $result['response_excerpt'] = pp_crowd_deep_clip($excerpt, 400);
            $statusReason = 'token_match';
        } else {
            $successKeywordsDetected = pp_crowd_deep_detect_success_keywords($responseBody);
            if ($successKeywordsDetected) {
                $result['status'] = 'partial';
                $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
                $result['error'] = __('Сообщение не найдено автоматически, требуется ручная проверка.');
                $statusReason = 'success_keywords';
            } else {
                $failureKeywordsDetected = pp_crowd_deep_detect_failure_keywords($responseBody);
                if ($failureKeywordsDetected) {
                    $result['status'] = 'failed';
                    $result['error'] = __('Сайт отверг отправку комментария.');
                    $statusReason = 'failure_keywords';
                } elseif ($result['http_status'] >= 200 && $result['http_status'] < 400) {
                    $result['status'] = 'partial';
                    $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
                    $result['error'] = __('Статус успешный, но сообщение не найдено.');
                    $statusReason = 'http_ok_no_token';
                } else {
                    $result['status'] = 'failed';
                    $result['error'] = __('Не удалось подтвердить публикацию сообщения.');
                    $statusReason = 'http_error';
                }
            }
        }
        if ($result['response_excerpt'] === '' && $responseBody !== '') {
            $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
        }
        pp_crowd_deep_debug_log('Deep handle result', [
            'runId' => $runId,
            'linkId' => $linkId,
            'status' => $result['status'],
            'reason' => $statusReason,
            'http_status' => $result['http_status'],
            'duration_ms' => $result['duration_ms'],
            'error' => $result['error'],
            'excerpt_found' => $excerpt !== '',
            'success_keywords' => $successKeywordsDetected,
            'failure_keywords' => $failureKeywordsDetected,
            'response_excerpt' => $result['response_excerpt'],
            'evidence_url' => $result['evidence_url'],
        ]);
        @unlink($cookieFile);
        return $result;
    }
}

if (!function_exists('pp_crowd_deep_merge_options')) {
    function pp_crowd_deep_merge_options(array $row): array {
        $decoded = [];
        if (!empty($row['options_json'])) {
            $decoded = json_decode((string)$row['options_json'], true);
            if (!is_array($decoded)) { $decoded = []; }
        }
        $options = array_merge(pp_crowd_deep_default_options(), $decoded);
        if (!empty($row['message_template'])) {
            $options['message_template'] = (string)$row['message_template'];
        }
        if (!empty($row['message_url'])) {
            $options['message_link'] = (string)$row['message_url'];
        }
        if (!empty($row['token_prefix'])) {
            $options['token_prefix'] = (string)$row['token_prefix'];
        }
        return $options;
    }
}

if (!function_exists('pp_crowd_deep_process_run')) {
    function pp_crowd_deep_process_run(int $runId): void {
        if ($runId <= 0) { return; }
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        pp_crowd_links_log('Deep worker started', ['runId' => $runId]);
        pp_crowd_deep_debug_log('Deep run start', ['runId' => $runId]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_crowd_deep_debug_log('Deep run db connect failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            pp_crowd_links_log('Deep worker cannot connect to DB', ['runId' => $runId, 'error' => $e->getMessage()]);
            return;
        }
        if (!$conn) { return; }
        $stmt = $conn->prepare('SELECT * FROM crowd_deep_runs WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return;
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            pp_crowd_deep_debug_log('Deep run metadata missing', ['runId' => $runId]);
            $conn->close();
            return;
        }
        $status = (string)($runRow['status'] ?? '');
        if (!in_array($status, ['queued', 'running'], true)) {
            pp_crowd_deep_debug_log('Deep run skipped due to status', ['runId' => $runId, 'status' => $status]);
            $conn->close();
            return;
        }
        @$conn->query("UPDATE crowd_deep_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), last_activity_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
        $links = [];
        if ($res = @$conn->query('SELECT id, url FROM crowd_links WHERE deep_processing_run_id = ' . (int)$runId . ' ORDER BY id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                $url = (string)($row['url'] ?? '');
                if ($id > 0 && $url !== '') {
                    $links[] = ['id' => $id, 'url' => $url];
                }
            }
            $res->free();
        }
        if (empty($links)) {
            @$conn->query("UPDATE crowd_deep_runs SET status='failed', notes='No links to process', finished_at=CURRENT_TIMESTAMP WHERE id = " . (int)$runId . " LIMIT 1");
            pp_crowd_deep_debug_log('Deep run has no links', ['runId' => $runId]);
            $conn->close();
            return;
        }
        $options = pp_crowd_deep_merge_options($runRow);
        $options['_run_id'] = $runId;
        pp_crowd_deep_debug_log('Deep run prepared', [
            'runId' => $runId,
            'scope' => $runRow['scope'] ?? null,
            'total_links' => count($links),
            'token_prefix' => $options['token_prefix'] ?? null,
        ]);
        $counts = [
            'processed' => 0,
            'success' => 0,
            'partial' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        $checkCancel = static function(mysqli $conn, int $runId): bool {
            $res = @$conn->query('SELECT cancel_requested FROM crowd_deep_runs WHERE id = ' . (int)$runId . ' LIMIT 1');
            if ($res && $row = $res->fetch_assoc()) {
                $res->free();
                return !empty($row['cancel_requested']);
            }
            return false;
        };

        $updateStmt = $conn->prepare("UPDATE crowd_links SET deep_status=?, deep_error=?, deep_message_excerpt=?, deep_evidence_url=?, deep_checked_at=CURRENT_TIMESTAMP, deep_processing_run_id=NULL, deep_last_run_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if ($updateStmt) {
            $statusParam = $errorParam = $messageParam = $evidenceParam = '';
            $runIdParam = $linkIdParam = 0;
            $updateStmt->bind_param('ssssii', $statusParam, $errorParam, $messageParam, $evidenceParam, $runIdParam, $linkIdParam);
        }
        $insertStmt = $conn->prepare("INSERT INTO crowd_deep_results (run_id, link_id, url, status, http_status, final_url, message_token, message_excerpt, response_excerpt, evidence_url, request_payload, duration_ms, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($insertStmt) {
            $runIdInsert = $linkIdInsert = $httpStatusInsert = $durationInsert = 0;
            $urlInsert = $statusInsert = $finalUrlInsert = $tokenInsert = $messageExcerptInsert = $responseExcerptInsert = $evidenceInsert = $payloadInsert = $errorInsert = '';
            $insertStmt->bind_param('iississssssds', $runIdInsert, $linkIdInsert, $urlInsert, $statusInsert, $httpStatusInsert, $finalUrlInsert, $tokenInsert, $messageExcerptInsert, $responseExcerptInsert, $evidenceInsert, $payloadInsert, $durationInsert, $errorInsert);
        }

        $cancelled = false;
        foreach ($links as $item) {
            if ($checkCancel($conn, $runId)) {
                $cancelled = true;
                pp_crowd_deep_debug_log('Deep run cancellation requested', ['runId' => $runId, 'processed' => $counts['processed']]);
                break;
            }
            $linkId = (int)$item['id'];
            $identity = pp_crowd_deep_generate_identity($options, $runId, $linkId);
            $handle = pp_crowd_deep_handle_link($item, $identity, $options);
            $counts['processed']++;
            switch ($handle['status']) {
                case 'success':
                    $counts['success']++;
                    break;
                case 'partial':
                    $counts['partial']++;
                    break;
                case 'no_form':
                case 'skipped':
                    $counts['skipped']++;
                    break;
                case 'blocked':
                    $counts['failed']++;
                    break;
                default:
                    $counts['failed']++;
                    break;
            }
            if ($updateStmt) {
                $statusParam = $handle['status'];
                $errorParam = pp_crowd_deep_clip((string)$handle['error'], 600);
                $messageParam = pp_crowd_deep_clip((string)$handle['message_excerpt'], 600);
                $evidenceParam = pp_crowd_deep_clip((string)$handle['evidence_url'], 600);
                $runIdParam = $runId;
                $linkIdParam = $linkId;
                $updateStmt->execute();
            } else {
                @$conn->query("UPDATE crowd_links SET deep_status='" . $conn->real_escape_string($handle['status']) . "', deep_error='" . $conn->real_escape_string(pp_crowd_deep_clip((string)$handle['error'], 600)) . "', deep_message_excerpt='" . $conn->real_escape_string(pp_crowd_deep_clip((string)$handle['message_excerpt'], 600)) . "', deep_evidence_url='" . $conn->real_escape_string(pp_crowd_deep_clip((string)$handle['evidence_url'], 600)) . "', deep_checked_at=CURRENT_TIMESTAMP, deep_processing_run_id=NULL, deep_last_run_id=" . (int)$runId . " WHERE id=" . (int)$linkId . " LIMIT 1");
            }
            if ($insertStmt) {
                $runIdInsert = $runId;
                $linkIdInsert = $linkId;
                $urlInsert = $item['url'];
                $statusInsert = $handle['status'];
                $httpStatusInsert = (int)($handle['http_status'] ?? 0);
                $finalUrlInsert = (string)$handle['evidence_url'];
                $tokenInsert = (string)$handle['token'];
                $messageExcerptInsert = pp_crowd_deep_clip((string)$handle['message_excerpt'], 600);
                $responseExcerptInsert = pp_crowd_deep_clip((string)$handle['response_excerpt'], 600);
                $evidenceInsert = pp_crowd_deep_clip((string)$handle['evidence_url'], 600);
                $payloadInsert = pp_crowd_deep_clip((string)$handle['request_payload'], 600);
                $durationInsert = $handle['duration_ms'] !== null ? (int)$handle['duration_ms'] : 0;
                $errorInsert = pp_crowd_deep_clip((string)$handle['error'], 600);
                $insertStmt->execute();
            }
            pp_crowd_deep_update_run_counts($conn, $runId, $counts, 'running');
        }

        if ($updateStmt) { $updateStmt->close(); }
        if ($insertStmt) { $insertStmt->close(); }

        if ($cancelled || $checkCancel($conn, $runId)) {
            @$conn->query("UPDATE crowd_links SET deep_processing_run_id=NULL WHERE deep_processing_run_id=" . (int)$runId);
            pp_crowd_deep_update_run_counts($conn, $runId, $counts, 'cancelled');
            @$conn->query("UPDATE crowd_deep_runs SET status='cancelled', finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
            $conn->close();
            pp_crowd_deep_debug_log('Deep run cancelled', ['runId' => $runId, 'counts' => $counts]);
            pp_crowd_links_log('Deep worker finished with cancellation', ['runId' => $runId]);
            return;
        }
        $errorSum = $counts['failed'];
        $finalStatus = ($counts['processed'] === count($links) && $errorSum === 0 && $counts['partial'] === 0) ? 'success' : 'completed';
        pp_crowd_deep_update_run_counts($conn, $runId, $counts, $finalStatus);
        @$conn->query("UPDATE crowd_deep_runs SET finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
        $conn->close();
        pp_crowd_deep_debug_log('Deep run finished', ['runId' => $runId, 'counts' => $counts, 'finalStatus' => $finalStatus]);
        pp_crowd_links_log('Deep worker finished', ['runId' => $runId, 'processed' => $counts['processed'], 'failed' => $counts['failed'], 'partial' => $counts['partial']]);
    }
}
