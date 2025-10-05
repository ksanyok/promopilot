<?php
// Crowd deep submission verification: detect forms, submit test message, log evidence

if (!function_exists('pp_crowd_deep_status_meta')) {
    function pp_crowd_deep_status_meta(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [
            // Short, consistent label for the "not run" state
            'pending' => ['label' => __('Не запускалась'), 'class' => 'badge bg-secondary text-uppercase fw-normal'],
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

if (!function_exists('pp_crowd_deep_get_link_stats')) {
    function pp_crowd_deep_get_link_stats(?mysqli $existingConn = null): array {
        $stats = [
            'total' => 0,
            'pending' => 0,
            'queued' => 0,
            'running' => 0,
            'success' => 0,
            'partial' => 0,
            'failed' => 0,
            'skipped' => 0,
            'processed' => 0,
            'errors' => 0,
            'in_progress' => 0,
        ];

        $conn = $existingConn;
        $ownsConnection = false;
        if (!$conn) {
            try {
                $conn = @connect_db();
            } catch (Throwable $e) {
                return $stats;
            }
            if (!$conn) {
                return $stats;
            }
            $ownsConnection = true;
        }

        $sql = "SELECT deep_status, COUNT(*) AS cnt FROM crowd_links GROUP BY deep_status";
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)($row['deep_status'] ?? '');
                $count = (int)($row['cnt'] ?? 0);
                if ($count <= 0) {
                    continue;
                }
                $stats['total'] += $count;
                switch ($status) {
                    case 'success':
                        $stats['success'] += $count;
                        break;
                    case 'partial':
                        $stats['partial'] += $count;
                        break;
                    case 'failed':
                    case 'blocked':
                        $stats['failed'] += $count;
                        break;
                    case 'skipped':
                    case 'no_form':
                        $stats['skipped'] += $count;
                        break;
                    case 'queued':
                        $stats['queued'] += $count;
                        break;
                    case 'running':
                        $stats['running'] += $count;
                        break;
                    case 'pending':
                    default:
                        $stats['pending'] += $count;
                        break;
                }
            }
            $res->free();
        }

        $stats['processed'] = $stats['success'] + $stats['partial'] + $stats['failed'] + $stats['skipped'];
        $stats['errors'] = $stats['failed'];
        $stats['in_progress'] = $stats['queued'] + $stats['running'];

        if ($ownsConnection && $conn) {
            $conn->close();
        }

        return $stats;
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
            // If there are no links bound to this deep run (e.g., after DB cleanup), treat as stalled and cancel
            if ($activeId) {
                $hasBoundLinks = false;
                if ($res2 = @$conn->query('SELECT 1 FROM crowd_links WHERE deep_processing_run_id = ' . $activeId . ' LIMIT 1')) {
                    $hasBoundLinks = (bool)$res2->fetch_row();
                    $res2->free();
                }
                if (!$hasBoundLinks) {
                    $isStalled = true;
                }
            }
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
        $linkStats = pp_crowd_deep_get_link_stats($conn);
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
            return ['ok' => true, 'run' => null, 'link_stats' => $linkStats];
        }
        $stmt = $conn->prepare('SELECT * FROM crowd_deep_runs WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'error' => 'DB_READ', 'link_stats' => $linkStats];
        }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();
        if (!$row) {
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND', 'link_stats' => $linkStats];
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
        return ['ok' => true, 'run' => $run, 'link_stats' => $linkStats];
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

if (!function_exists('pp_crowd_deep_normalize_token')) {
    function pp_crowd_deep_normalize_token(string $text): string {
        $text = preg_replace('~\s+~u', ' ', trim($text));
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }
        return strtolower($text);
    }
}

if (!function_exists('pp_crowd_deep_add_token')) {
    function pp_crowd_deep_add_token(array &$tokens, string $value): void {
        $normalized = pp_crowd_deep_normalize_token($value);
        if ($normalized === '') {
            return;
        }
        $tokens[] = $normalized;
        $parts = preg_split('~[\s,;:/\\|_\-]+~u', $normalized);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && $part !== $normalized) {
                    $tokens[] = $part;
                }
            }
        }
    }
}

if (!function_exists('pp_crowd_deep_collect_context_tokens')) {
    function pp_crowd_deep_collect_context_tokens(DOMElement $field): array {
        $tokens = [];
        $push = static function($text) use (&$tokens): void {
            if ($text === null) { return; }
            pp_crowd_deep_add_token($tokens, (string)$text);
        };

        $parent = $field->parentNode;
        if ($parent instanceof DOMElement) {
            foreach ($parent->childNodes as $child) {
                if ($child === $field) {
                    break;
                }
                if ($child instanceof DOMText) {
                    $push($child->nodeValue);
                } elseif ($child instanceof DOMElement) {
                    $tag = strtolower($child->tagName);
                    if (in_array($tag, ['label', 'span', 'strong', 'b', 'em', 'i', 'small', 'p', 'div', 'legend', 'th'], true)) {
                        $push($child->textContent);
                    }
                }
            }

            $parentTag = strtolower($parent->tagName);
            if (in_array($parentTag, ['td', 'th', 'li', 'dt', 'dd'], true)) {
                $sibling = $parent->previousSibling;
                $checked = 0;
                while ($sibling && $checked < 3) {
                    if ($sibling instanceof DOMText) {
                        $push($sibling->nodeValue);
                    } elseif ($sibling instanceof DOMElement) {
                        $push($sibling->textContent);
                        $checked++;
                    }
                    $sibling = $sibling->previousSibling;
                }
            }
        }

        return $tokens;
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
        foreach (['name', 'id', 'class', 'placeholder', 'title', 'aria-label', 'aria-labelledby', 'aria-describedby', 'autocomplete'] as $attr) {
            $val = trim((string)$field->getAttribute($attr));
            if ($val !== '') {
                if ($attr === 'class') {
                    foreach (preg_split('~\s+~u', $val) as $cls) {
                        pp_crowd_deep_add_token($tokens, $cls);
                    }
                } elseif (in_array($attr, ['aria-labelledby', 'aria-describedby'], true)) {
                    $doc = $field->ownerDocument;
                    if ($doc instanceof DOMDocument) {
                        foreach (preg_split('~\s+~u', $val) as $refId) {
                            $refId = trim($refId);
                            if ($refId === '') { continue; }
                            $labelNode = $doc->getElementById($refId);
                            if ($labelNode instanceof DOMElement) {
                                pp_crowd_deep_add_token($tokens, $labelNode->textContent);
                            }
                        }
                    }
                } else {
                    pp_crowd_deep_add_token($tokens, $val);
                }
            }
        }
        if ($field->hasAttributes()) {
            foreach ($field->attributes as $attribute) {
                if (!$attribute instanceof DOMAttr) { continue; }
                $name = strtolower($attribute->name);
                if (strpos($name, 'data-') === 0) {
                    pp_crowd_deep_add_token($tokens, (string)$attribute->value);
                }
            }
        }
        $labelText = trim(pp_crowd_deep_find_label_text($field, $xp));
        if ($labelText !== '') {
            pp_crowd_deep_add_token($tokens, $labelText);
        }
        foreach (pp_crowd_deep_collect_context_tokens($field) as $ctx) {
            pp_crowd_deep_add_token($tokens, $ctx);
        }
        $parent = $field->parentNode;
        if ($parent instanceof DOMElement) {
            $legend = $xp->query('./ancestor::fieldset/legend', $field)->item(0);
            if ($legend instanceof DOMElement) {
                pp_crowd_deep_add_token($tokens, $legend->textContent);
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
    static $commentTokens = [
        'comment', 'commentary', 'commentaire', 'commento', 'commenti', 'coment', 'comentario', 'comentarios', 'comentários',
        'message', 'messages', 'mensaje', 'mensajes', 'mensagem', 'mensagens', 'messaggio', 'messaggi', 'messagerie', 'msg',
        'review', 'feedback', 'reply', 'respond', 'response', 'réponse', 'responda', 'resposta', 'risposta', 'antwort',
        'body', 'content', 'discussion', 'thoughts', 'descr', 'description', 'opis', 'posta', 'texto',
    'guestbook', 'gästebuch', 'opinie', 'opinia', 'otziv', 'отзыв', 'опыт', 'коммент', 'комментар', 'сообщ', 'жалоб', 'ответ', 'вопрос', 'відгук',
        'hozzászól', 'hozzaszol', 'vélemény', 'velemeny', 'yorum', 'yorumunuz', 'yorumlar',
        'nachricht', 'nachrichten', 'nachrichtentext', 'bemerkung', 'anmerkung', 'bericht',
        'wiadomość', 'wiadomosc', 'wiadomości', 'wiadomosci', 'komentarz', 'komentarze', 'komentar', 'komentari', 'komentár', 'komentář',
        'recensione', 'reseña', 'resena', 'recenzja', 'recenzje', 'recenzija',
        'balasan', 'pesan', 'ulasan', 'jawaban',
        '留言', '留言板', '留言内容', '留言內容', '评论', '評論', '评论内容', '評論內容', '反馈', '反饋',
        '메시지', '메세지', '댓글', '리뷰',
        'تعليق', 'تعليقات', 'رسالة', 'نص', 'مراجعة', 'رد'
    ];
    static $nameTokens = [
    'name', 'author', 'fname', 'lname', 'fullname', 'fio', 'имя', 'фамил', 'фио', 'surname', 'nome', 'nombre', 'apellido',
    'prenom', 'prénom', 'ten', 'nick', 'username', 'contact', 'kontakt', 'kontak', 'appellation', 'név', 'neve', 'nev', 'jméno', 'jmeno', 'naam', 'navn',
        '名字', '姓名', '氏名', 'お名前', '名前', '이름', 'имяфамилия', 'імʼя', 'імя', 'नाम', 'nome completo', 'full name'
    ];
    static $emailTokens = ['mail', 'email', 'e-mail', 'courriel', 'correo', 'корресп', 'почт', 'adresse', 'adres', 'mailing', 'mailadresse', 'e-mailadres'];
    static $urlTokens = ['url', 'website', 'site', 'link', 'homepage', 'http', 'web', 'honlap', 'weboldal', 'сайт', 'страница', '网址', '站点'];
        static $phoneTokens = ['phone', 'tel', 'telephone', 'mobile', 'whatsapp', 'номер', 'telefon', 'telefone', 'cel', 'celular', 'whats', 'gsm', 'handy'];
        static $subjectTokens = ['subject', 'tema', 'topic', 'title', 'heading', 'тема', 'заголов', 'betreff', 'assunto', 'asunto', 'temat'];
    static $companyTokens = ['company', 'organisation', 'organization', 'org', 'business', 'brand', 'firma', 'empresa', 'компан', 'vállalat', 'vallalat', 'unternehmen', 'compania'];
    static $captchaTokens = ['captcha', 'recaptcha', 'cptch', 'security code', 'verification code', 'antispam', 'anti-spam', 'botcheck', 'bot-check', 'are you human', 'g-recaptcha'];
    static $termsTokens = ['privacy', 'policy', 'terms', 'consent', 'rgpd', 'gdpr', 'agree', 'accept', 'processing', 'compliance', 'conditions', 'política', 'política de privacidad', 'условия'];
    static $honeypotTokens = ['honeypot', 'hp_', 'trap', 'ak_hp', 'fakefield', 'antispam', 'anti spam', 'spamtrap', 'spam-trap', 'nobot', 'no_bot', 'botcheck', 'leave blank'];
        static $ratingTokens = ['rating', 'stars', 'vote', 'score', 'оцен'];

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
        $isHoneypot = false;
        if ($nameLower !== '' && preg_match('~(honeypot|_hp_|ak_hp|trapfield|spamtrap|nobot|no_bot|testfield|bot_trap)~i', $nameLower)) {
            $isHoneypot = true;
        } elseif (pp_crowd_deep_match_tokens($tokens, $honeypotTokens)) {
            $isHoneypot = true;
        }
        if ($isHoneypot) {
            $role = 'honeypot';
        }
        if ($type === 'hidden') {
            $role = 'hidden';
        } elseif ($role !== 'honeypot' && $type === 'textarea') {
            $role = 'comment';
        } elseif ($role !== 'honeypot' && in_array($type, ['submit', 'button', 'image'], true)) {
            $role = 'submit';
        } elseif ($role !== 'honeypot' && pp_crowd_deep_match_tokens($tokens, $commentTokens)) {
            $role = 'comment';
        } elseif ($role !== 'honeypot' && ($type === 'email' || pp_crowd_deep_match_tokens($tokens, $emailTokens))) {
            $role = 'email';
        } elseif ($role !== 'honeypot' && ($type === 'url' || pp_crowd_deep_match_tokens($tokens, $urlTokens))) {
            $role = 'url';
        } elseif ($role !== 'honeypot' && ($type === 'tel' || pp_crowd_deep_match_tokens($tokens, $phoneTokens))) {
            $role = 'phone';
        } elseif ($role !== 'honeypot' && $type === 'password') {
            $role = 'password';
        } elseif ($role !== 'honeypot' && $type === 'checkbox') {
            $role = pp_crowd_deep_match_tokens($tokens, $termsTokens) ? 'accept' : 'checkbox';
        } elseif ($role !== 'honeypot' && $type === 'radio') {
            if (pp_crowd_deep_match_tokens($tokens, $ratingTokens)) {
                $role = 'rating';
            } else {
                $role = 'radio';
            }
        } elseif ($role !== 'honeypot' && $type === 'file') {
            $role = 'file';
        } elseif ($role !== 'honeypot' && (pp_crowd_deep_match_tokens($tokens, $captchaTokens) || strtolower((string)$field->getAttribute('id')) === 'g-recaptcha-response')) {
            $role = 'captcha';
        } elseif ($role !== 'honeypot' && pp_crowd_deep_match_tokens($tokens, $commentTokens)) {
            $role = 'comment';
        } elseif ($role !== 'honeypot' && pp_crowd_deep_match_tokens($tokens, $nameTokens)) {
            $role = 'name';
        } elseif ($role !== 'honeypot' && pp_crowd_deep_match_tokens($tokens, $subjectTokens)) {
            $role = 'subject';
        } elseif ($role !== 'honeypot' && pp_crowd_deep_match_tokens($tokens, $companyTokens)) {
            $role = 'company';
        } elseif ($role !== 'honeypot' && $tag === 'select') {
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
        $overridesRaw = isset($identity['overrides']) && is_array($identity['overrides']) ? $identity['overrides'] : [];
        $overrides = [];
        foreach ($overridesRaw as $overrideName => $overrideValue) {
            if (!is_string($overrideName) || trim($overrideName) === '') {
                continue;
            }
            if (is_array($overrideValue)) {
                $values = [];
                foreach ($overrideValue as $item) {
                    if ($item === null) { continue; }
                    $values[] = (string)$item;
                }
                if (!empty($values)) {
                    $overrides[$overrideName] = $values;
                }
            } elseif ($overrideValue !== null) {
                $overrides[$overrideName] = [(string)$overrideValue];
            }
        }
        $nodeList = $xp->query('.//input | .//textarea | .//select', $form);
        $recaptchaPresent = false;
        if ($nodeList) {
            foreach ($nodeList as $node) {
                if (!$node instanceof DOMElement) { continue; }
                $info = pp_crowd_deep_classify_field($node, $xp);
                $name = trim((string)$node->getAttribute('name'));
                if ($info['role'] === 'captcha') {
                    $recaptchaPresent = true;
                }
                if ($name !== '' && !in_array($info['role'], ['captcha','file','honeypot'], true) && array_key_exists($name, $overrides)) {
                    $overrideValues = $overrides[$name];
                    if (!empty($overrideValues)) {
                        $fields[$name] = $overrideValues;
                        if (!$hasComment) {
                            if ($info['role'] === 'comment') {
                                $hasComment = true;
                                $commentFieldName = $name;
                            } elseif (count($overrideValues) === 1 && isset($identity['message'])) {
                                $originalBody = (string)$identity['message'];
                                $candidate = (string)$overrideValues[0];
                                if ($originalBody !== '' && $candidate !== '') {
                                    $origNorm = function_exists('mb_strtolower') ? mb_strtolower($originalBody, 'UTF-8') : strtolower($originalBody);
                                    $candNorm = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
                                    if ($origNorm === $candNorm || strpos($origNorm, $candNorm) !== false || strpos($candNorm, $origNorm) !== false) {
                                        $hasComment = true;
                                        $commentFieldName = $name;
                                    }
                                }
                            }
                        }
                        continue;
                    }
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
        ];
    }
}

// Fallback collector for malformed markup where controls are outside <form> but in the same container (e.g., tables)
if (!function_exists('pp_crowd_deep_prepare_fields_from_container')) {
    function pp_crowd_deep_prepare_fields_from_container(DOMElement $container, DOMXPath $xp, array $identity, ?DOMElement $form = null): array {
        $fields = [];
        $issues = [];
        $hasComment = false;
        $radioGroups = [];
        $commentFieldName = null;
        $recaptchaPresent = false;
        $overridesRaw = isset($identity['overrides']) && is_array($identity['overrides']) ? $identity['overrides'] : [];
        $overrides = [];
        foreach ($overridesRaw as $overrideName => $overrideValue) {
            if (!is_string($overrideName) || trim($overrideName) === '') {
                continue;
            }
            if (is_array($overrideValue)) {
                $values = [];
                foreach ($overrideValue as $item) {
                    if ($item === null) { continue; }
                    $values[] = (string)$item;
                }
                if (!empty($values)) {
                    $overrides[$overrideName] = $values;
                }
            } elseif ($overrideValue !== null) {
                $overrides[$overrideName] = [(string)$overrideValue];
            }
        }
        $formId = $form instanceof DOMElement ? (string)$form->getAttribute('id') : '';
        // pick all controls under the container that are not inside another form
        $nodeList = $xp->query('.//input[not(ancestor::form)] | .//textarea[not(ancestor::form)] | .//select[not(ancestor::form)]', $container);
        if ($nodeList) {
            foreach ($nodeList as $node) {
                if (!$node instanceof DOMElement) { continue; }
                // If element uses HTML5 form attribute, ensure it targets our form (if provided)
                $attrForm = trim((string)$node->getAttribute('form'));
                if ($attrForm !== '') {
                    if ($formId === '' || $attrForm !== $formId) { continue; }
                }
                $info = pp_crowd_deep_classify_field($node, $xp);
                $name = trim((string)$node->getAttribute('name'));
                if ($info['role'] === 'captcha') { $recaptchaPresent = true; }
                if ($name !== '' && !in_array($info['role'], ['captcha','file','honeypot'], true) && array_key_exists($name, $overrides)) {
                    $overrideValues = $overrides[$name];
                    if (!empty($overrideValues)) {
                        $fields[$name] = $overrideValues;
                        if (!$hasComment) {
                            if ($info['role'] === 'comment') {
                                $hasComment = true;
                                $commentFieldName = $name;
                            } elseif (count($overrideValues) === 1 && isset($identity['message'])) {
                                $originalBody = (string)$identity['message'];
                                $candidate = (string)$overrideValues[0];
                                if ($originalBody !== '' && $candidate !== '') {
                                    $origNorm = function_exists('mb_strtolower') ? mb_strtolower($originalBody, 'UTF-8') : strtolower($originalBody);
                                    $candNorm = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
                                    if ($origNorm === $candNorm || strpos($origNorm, $candNorm) !== false || strpos($candNorm, $origNorm) !== false) {
                                        $hasComment = true;
                                        $commentFieldName = $name;
                                    }
                                }
                            }
                        }
                        continue;
                    }
                }
                if ($info['role'] === 'hidden') {
                    if ($name !== '') { $fields[$name][] = $node->getAttribute('value'); }
                    continue;
                }
                if ($info['role'] === 'file') { $issues['file'] = true; continue; }
                if ($info['role'] === 'honeypot') { if ($name !== '') { $fields[$name][] = ''; } $issues['honeypot'] = true; continue; }
                if ($name === '') { if ($info['role'] === 'comment') { $issues['comment_missing_name'] = true; } continue; }
                $value = null;
                switch ($info['role']) {
                    case 'comment': $value = $identity['message']; $hasComment = true; $commentFieldName = $name; break;
                    case 'name': $value = $identity['name']; break;
                    case 'email': $value = $identity['email']; break;
                    case 'url': $value = $identity['website']; break;
                    case 'phone': $value = $identity['phone']; break;
                    case 'password': $value = $identity['password']; break;
                    case 'subject': $value = sprintf('PromoPilot %s', $identity['token']); break;
                    case 'company': $value = $identity['company']; break;
                    case 'accept':
                    case 'checkbox': $value = $node->hasAttribute('value') ? $node->getAttribute('value') : 'on'; break;
                    case 'rating': $value = '5'; break;
                    case 'radio':
                        $group = $name;
                        if (!isset($radioGroups[$group])) { $radioGroups[$group] = true; $value = $node->hasAttribute('value') ? $node->getAttribute('value') : '1'; }
                        else { continue 2; }
                        break;
                    default:
                        if ($info['required']) { $value = $identity['fallback']; }
                        else { $value = $node->getAttribute('value'); if ($value === '') { continue 2; } }
                        break;
                }
                if ($info['role'] === 'captcha') { $issues['captcha'] = true; continue; }
                if ($node->tagName === 'select') {
                    $selected = null;
                    foreach ($node->getElementsByTagName('option') as $option) {
                        $optValue = $option->getAttribute('value');
                        $optText = trim((string)$option->textContent);
                        $disabled = $option->hasAttribute('disabled');
                        if ($option->hasAttribute('selected') && !$disabled && $optValue !== '') { $selected = $optValue; break; }
                        if ($selected === null && !$disabled && ($optValue !== '' || $optText !== '')) { $selected = $optValue !== '' ? $optValue : $optText; }
                    }
                    if ($selected === null) { if ($info['required']) { $issues['select'] = true; } continue; }
                    $value = $selected;
                }
                if ($node->tagName === 'textarea') { $value = $value ?? $node->textContent; }
                if ($node->tagName === 'input') { $type = strtolower($node->getAttribute('type')); if ($type === 'number' && !is_numeric($value)) { $value = '5'; } }
                if ($info['role'] === 'checkbox' || $info['role'] === 'accept') { $fields[$name][] = (string)$value; }
                else { $fields[$name] = [$value !== null ? (string)$value : '']; }
            }
        }
        if ($recaptchaPresent) { $issues['captcha'] = true; }
        return [
            'fields' => $fields,
            'issues' => $issues,
            'has_comment' => $hasComment,
            'comment_field' => $commentFieldName,
        ];
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
        // Fallback: if form has no controls/comment due to malformed markup (controls outside form), try nearest container
        if ((!$plan['has_comment'] || empty($plan['fields'])) && empty($plan['issues']['captcha']) && empty($plan['issues']['file'])) {
            $container = $form->parentNode;
            $chosen = null;
            $preferredTags = ['div','table','section','article','main','body'];
            while ($container && $container instanceof DOMElement) {
                // Use nodeName (available on DOMNode) to satisfy static analysis tools
                $tag = strtolower($container->nodeName);
                if (in_array($tag, $preferredTags, true)) { $chosen = $container; break; }
                $container = $container->parentNode;
            }
            if ($chosen instanceof DOMElement) {
                $fallback = pp_crowd_deep_prepare_fields_from_container($chosen, $xp, $identity, $form);
                if ($fallback['has_comment'] && !empty($fallback['fields'])) {
                    $plan = $fallback;
                }
            }
        }
        if (!empty($plan['issues']['captcha'])) {
            return ['ok' => false, 'reason' => 'captcha'];
        }
        if (!empty($plan['issues']['file'])) {
            return ['ok' => false, 'reason' => 'file'];
        }
        if (!$plan['has_comment']) {
            return ['ok' => false, 'reason' => 'no_comment'];
        }
        if (empty($plan['fields'])) {
            return ['ok' => false, 'reason' => 'no_fields'];
        }
        $payload = [];
        foreach ($plan['fields'] as $name => $values) {
            if (count($values) === 1) {
                $payload[$name] = $values[0];
            } else {
                $payload[$name] = $values;
            }
        }
        return [
            'ok' => true,
            'method' => $method,
            'action' => $action,
            'payload' => $payload,
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
        // Use a realistic browser UA to reduce WAF/antispam blocks
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
        $defaultHeaders = [
            'User-Agent: ' . $ua,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
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
                // Add an Origin header for CSRF protections if not already present
                $origin = '';
                $p = @parse_url($url);
                if (is_array($p) && !empty($p['scheme']) && !empty($p['host'])) {
                    $origin = $p['scheme'] . '://' . $p['host'];
                }
                if ($origin !== '') {
                    $hasOrigin = false;
                    foreach ($headers as $h) { if (stripos($h, 'origin:') === 0) { $hasOrigin = true; break; } }
                    if (!$hasOrigin) { $headers[] = 'Origin: ' . $origin; }
                }
                $options[CURLOPT_HTTPHEADER] = $headers;
            } elseif ($method !== 'GET') {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                if (is_array($data)) {
                    $options[CURLOPT_POSTFIELDS] = http_build_query($data);
                } elseif ($data !== null) {
                    $options[CURLOPT_POSTFIELDS] = (string)$data;
                }
                // For non-GET requests, also attach Origin
                $origin = '';
                $p = @parse_url($url);
                if (is_array($p) && !empty($p['scheme']) && !empty($p['host'])) {
                    $origin = $p['scheme'] . '://' . $p['host'];
                }
                if ($origin !== '') {
                    $hasOrigin = false;
                    foreach ($headers as $h) { if (stripos($h, 'origin:') === 0) { $hasOrigin = true; break; } }
                    if (!$hasOrigin) { $headers[] = 'Origin: ' . $origin; }
                }
                $options[CURLOPT_HTTPHEADER] = $headers;
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
        $startAll = microtime(true);
        $fetch = pp_crowd_deep_http_request($url, [
            'method' => 'GET',
            'timeout' => 25,
            'cookieFile' => $cookieFile,
        ]);
        if ($fetch['error'] !== '') {
            $result['error'] = $fetch['error'];
            $result['status'] = 'failed';
            @unlink($cookieFile);
            return $result;
        }
        $body = (string)($fetch['body'] ?? '');
        if ($body === '') {
            $result['error'] = __('Не получен HTML контент страницы.');
            $result['status'] = 'failed';
            @unlink($cookieFile);
            return $result;
        }
        $doc = pp_html_dom($body);
        if (!$doc) {
            $result['error'] = __('Не удалось разобрать HTML.');
            $result['status'] = 'failed';
            @unlink($cookieFile);
            return $result;
        }
        $forms = $doc->getElementsByTagName('form');
        $plan = null;
        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) { continue; }
            $candidate = pp_crowd_deep_build_plan($form, $fetch['final_url'] ?? $url, $identity);
            if (!empty($candidate['ok'])) {
                $plan = $candidate;
                break;
            }
            if (!empty($candidate['reason']) && in_array($candidate['reason'], ['captcha', 'file'], true)) {
                $result['status'] = $candidate['reason'] === 'captcha' ? 'blocked' : 'skipped';
                $result['error'] = $candidate['reason'] === 'captcha' ? __('Обнаружена CAPTCHA, автоматическая отправка невозможна.') : __('Форма требует загрузку файла.');
                @unlink($cookieFile);
                return $result;
            }
        }
        if (!$plan) {
            $result['status'] = 'no_form';
            $result['error'] = __('На странице не найдена форма с полем комментария.');
            @unlink($cookieFile);
            return $result;
        }
        $result['request_payload'] = pp_crowd_deep_clip(is_array($plan['payload']) ? http_build_query($plan['payload']) : (string)$plan['payload'], 800);
        $submit = pp_crowd_deep_http_request($plan['action'], [
            'method' => $plan['method'],
            'timeout' => 25,
            'cookieFile' => $cookieFile,
            'referer' => $url,
            'data' => $plan['payload'],
        ]);
        $durationMs = (int)round((microtime(true) - $startAll) * 1000);
        $result['duration_ms'] = $durationMs;
        $result['http_status'] = (int)($submit['status'] ?? 0);
        $result['evidence_url'] = (string)($submit['final_url'] ?? $plan['action']);
        $responseBody = (string)($submit['body'] ?? '');
        if ($submit['error'] !== '') {
            $result['error'] = $submit['error'];
            $result['status'] = 'failed';
            @unlink($cookieFile);
            return $result;
        }
        $token = $identity['token'];
        $excerpt = pp_crowd_deep_extract_excerpt($responseBody, $token);
        if ($excerpt !== '') {
            $result['status'] = 'success';
            $result['response_excerpt'] = pp_crowd_deep_clip($excerpt, 400);
        } else {
            if (pp_crowd_deep_detect_success_keywords($responseBody)) {
                $result['status'] = 'partial';
                $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
                $result['error'] = __('Сообщение не найдено автоматически, требуется ручная проверка.');
            } elseif (pp_crowd_deep_detect_failure_keywords($responseBody)) {
                $result['status'] = 'failed';
                $result['error'] = __('Сайт отверг отправку комментария.');
            } elseif ($result['http_status'] >= 200 && $result['http_status'] < 400) {
                $result['status'] = 'partial';
                $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
                $result['error'] = __('Статус успешный, но сообщение не найдено.');
            } else {
                $result['status'] = 'failed';
                $result['error'] = __('Не удалось подтвердить публикацию сообщения.');
            }
        }
        if ($result['response_excerpt'] === '' && $responseBody !== '') {
            $result['response_excerpt'] = pp_crowd_deep_clip(substr($responseBody, 0, 400), 400);
        }
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
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
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
            $conn->close();
            return;
        }
        $status = (string)($runRow['status'] ?? '');
        if (!in_array($status, ['queued', 'running'], true)) {
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
            $conn->close();
            return;
        }
        $options = pp_crowd_deep_merge_options($runRow);
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
            pp_crowd_links_log('Deep worker finished with cancellation', ['runId' => $runId]);
            return;
        }
        $errorSum = $counts['failed'];
        $finalStatus = ($counts['processed'] === count($links) && $errorSum === 0 && $counts['partial'] === 0) ? 'success' : 'completed';
        pp_crowd_deep_update_run_counts($conn, $runId, $counts, $finalStatus);
        @$conn->query("UPDATE crowd_deep_runs SET finished_at=CURRENT_TIMESTAMP WHERE id=" . (int)$runId . " LIMIT 1");
        $conn->close();
        pp_crowd_links_log('Deep worker finished', ['runId' => $runId, 'processed' => $counts['processed'], 'failed' => $counts['failed'], 'partial' => $counts['partial']]);
    }
}
