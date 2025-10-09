<?php
require_once __DIR__ . '/../promotion_helpers.php';
require_once __DIR__ . '/../crowd_deep.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/utils.php';

if (!function_exists('pp_promotion_crowd_normalize_language')) {
    function pp_promotion_crowd_normalize_language(?string $language): string {
        $normalized = pp_promotion_normalize_language_code($language, 'ru');
        $supported = ['ru', 'en', 'uk'];
        if (!in_array($normalized, $supported, true)) {
            return 'ru';
        }
        return $normalized;
    }
}

if (!function_exists('pp_promotion_crowd_texts')) {
    function pp_promotion_crowd_texts(string $language): array {
        switch ($language) {
            case 'uk':
                return [
                    'subject_prefix' => 'Коментар',
                    'subject_default' => 'Коментар до статті',
                    'lead_with_anchor' => 'Колеги, ділюся корисним матеріалом «%s».',
                    'lead_without_anchor' => 'Колеги, ділюся корисним матеріалом.',
                    'link_prompt' => 'Перегляньте, будь ласка, посилання:',
                    'feedback' => 'Будемо вдячні за вашу думку!',
                    'author_default' => 'PromoPilot Автор',
                ];
            case 'en':
                return [
                    'subject_prefix' => 'Comment',
                    'subject_default' => 'Comment on the article',
                    'lead_with_anchor' => 'Sharing a helpful article “%s”.',
                    'lead_without_anchor' => 'Sharing a helpful article.',
                    'link_prompt' => 'Please take a look:',
                    'feedback' => 'We would appreciate your feedback!',
                    'author_default' => 'PromoPilot Author',
                ];
            case 'ru':
            default:
                return [
                    'subject_prefix' => 'Комментарий',
                    'subject_default' => 'Комментарий к статье',
                    'lead_with_anchor' => 'Коллеги, делюсь полезным материалом «%s».',
                    'lead_without_anchor' => 'Коллеги, делюсь полезным материалом.',
                    'link_prompt' => 'Поделитесь, пожалуйста, мнением по ссылке:',
                    'feedback' => 'Будем рады обратной связи!',
                    'author_default' => 'PromoPilot Автор',
                ];
        }
    }
}

if (!function_exists('pp_promotion_trigger_worker_inline')) {
    function pp_promotion_trigger_worker_inline(?int $runId = null, int $maxIterations = 5): void {
        static $guardActive = false;
        if ($guardActive) { return; }
        if (!function_exists('pp_promotion_worker')) { return; }
        $maxIterations = max(1, min(120, (int)$maxIterations));
        $guardActive = true;
        try {
            pp_promotion_worker($runId, $maxIterations);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.worker.inline_error', [
                'run_id' => $runId,
                'max_iterations' => $maxIterations,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $guardActive = false;
        }
    }
}

if (!function_exists('pp_promotion_launch_crowd_worker')) {
    function pp_promotion_launch_crowd_worker(?int $taskId = null, bool $allowFallback = true): bool {
        $script = PP_ROOT_PATH . '/scripts/promotion_crowd_worker.php';
        $inlineIterations = 0;
        if (!is_file($script)) {
            pp_promotion_log('promotion.crowd.worker_missing', ['script' => $script]);
            if ($allowFallback && function_exists('pp_promotion_crowd_worker')) {
                try {
                    $processed = pp_promotion_crowd_worker($taskId, 5);
                    pp_promotion_log('promotion.crowd.worker_fallback_used', [
                        'task_id' => $taskId,
                        'processed' => $processed,
                        'reason' => 'script_missing',
                    ]);
                    return true;
                } catch (Throwable $e) {
                    pp_promotion_log('promotion.crowd.worker_fallback_error', ['error' => $e->getMessage()]);
                }
            }
            return false;
        }
        $phpBinary = PHP_BINARY ?: 'php';
        $args = $taskId ? ' ' . (int)$taskId : '';
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $success = false;
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args;
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $success = true;
            }
        } else {
            $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args . ' > /dev/null 2>&1 &';
            if (function_exists('popen')) {
                $handle = @popen($cmd, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                    $success = true;
                }
            }
            if (!$success) {
                $output = [];
                $status = 1;
                @exec($cmd, $output, $status);
                if ($status === 0) {
                    $success = true;
                }
            }
        }

        if ($success) {
            pp_promotion_log('promotion.crowd.worker_launched', [
                'task_id' => $taskId,
                'script' => $script,
                'mode' => $isWindows ? 'windows_popen' : 'posix_background',
            ]);
            if ($allowFallback && function_exists('pp_promotion_crowd_worker')) {
                $initialTaskId = $taskId;
                $inlineIterations = 0;
                $batchSize = $taskId ? 1 : 12;
                $maxBatches = $taskId ? 1 : 10;
                $maxRuntime = $taskId ? 5.0 : 25.0;
                $start = microtime(true);
                $batchesRun = 0;
                $runtimeCapped = false;
                $finishedRequest = false;
                $pendingAfter = 0;
                try {
                    for ($batch = 0; $batch < $maxBatches; $batch++) {
                        $processed = pp_promotion_crowd_worker($taskId, $batchSize);
                        $inlineIterations += $processed;
                        $batchesRun++;
                        $taskId = null;
                        if ($processed === 0) {
                            break;
                        }
                        if (!$finishedRequest && function_exists('fastcgi_finish_request') && PHP_SAPI !== 'cli') {
                            @fastcgi_finish_request();
                            $finishedRequest = true;
                        }
                        if ($processed < $batchSize) {
                            break;
                        }
                        if ((microtime(true) - $start) >= $maxRuntime) {
                            $runtimeCapped = true;
                            break;
                        }
                        usleep(150000);
                    }
                    $pendingAfter = pp_promotion_crowd_pending_count();
                    $durationMs = (int)round((microtime(true) - $start) * 1000);
                    pp_promotion_log('promotion.crowd.worker_inline_assist', [
                        'task_id' => $initialTaskId,
                        'processed' => $inlineIterations,
                        'pending_after' => $pendingAfter,
                        'batches' => $batchesRun,
                        'duration_ms' => $durationMs,
                        'runtime_capped' => $runtimeCapped,
                    ]);
                    if ($pendingAfter > 0 || $inlineIterations > 0) {
                        pp_promotion_launch_worker();
                        if ($inlineIterations > 0) {
                            $inlineIterationsHint = $pendingAfter > 0 ? 3 : 8;
                            pp_promotion_trigger_worker_inline(null, $inlineIterationsHint);
                        }
                    }
                } catch (Throwable $e) {
                    pp_promotion_log('promotion.crowd.worker_inline_error', [
                        'task_id' => $initialTaskId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return true;
        }

        pp_promotion_log('promotion.crowd.worker_launch_failed', [
            'task_id' => $taskId,
            'script' => $script,
            'mode' => $isWindows ? 'windows_popen' : 'posix_background',
        ]);

        if ($allowFallback && function_exists('pp_promotion_crowd_worker')) {
            try {
                $processed = pp_promotion_crowd_worker($taskId, 5);
                pp_promotion_log('promotion.crowd.worker_fallback_used', [
                    'task_id' => $taskId,
                    'processed' => $processed,
                    'reason' => 'launch_failed',
                ]);
                return true;
            } catch (Throwable $e) {
                pp_promotion_log('promotion.crowd.worker_fallback_error', ['error' => $e->getMessage()]);
            }
        }

        return false;
    }
}

if (!function_exists('pp_promotion_crowd_claim_task')) {
    function pp_promotion_crowd_claim_task(mysqli $conn, ?int $specificTaskId = null): ?array {
        $taskId = null;
        if ($specificTaskId !== null && $specificTaskId > 0) {
            $taskId = $specificTaskId;
            $stmt = $conn->prepare("UPDATE promotion_crowd_tasks SET status='running', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('planned','queued','running') LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $taskId);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    return null;
                }
                $stmt->close();
            } else {
                return null;
            }
        } else {
            if ($res = @$conn->query("SELECT id FROM promotion_crowd_tasks WHERE status IN ('planned','queued') ORDER BY id ASC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) {
                    $taskId = (int)($row['id'] ?? 0);
                }
                $res->free();
            }
            if (!$taskId) {
                return null;
            }
            $stmt = $conn->prepare("UPDATE promotion_crowd_tasks SET status='running', updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('planned','queued') LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('i', $taskId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                return null;
            }
            $stmt->close();
        }

        $task = null;
        $stmt = $conn->prepare('SELECT * FROM promotion_crowd_tasks WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $taskId);
            if ($stmt->execute()) {
                $task = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }
        return $task ?: null;
    }
}

if (!function_exists('pp_promotion_crowd_process_task')) {
    function pp_promotion_crowd_process_task(mysqli $conn, array $task): void {
        $taskId = (int)($task['id'] ?? 0);
        $runId = (int)($task['run_id'] ?? 0);
        if ($taskId <= 0 || $runId <= 0) {
            return;
        }

        $payload = [];
        if (!empty($task['payload_json'])) {
            $decoded = json_decode((string)$task['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $variant = $payload;
        $rawBody = isset($variant['body']) ? pp_promotion_clean_text((string)$variant['body']) : '';
        $rawSubject = isset($variant['subject']) ? pp_promotion_clean_text((string)$variant['subject']) : '';
        $rawAuthorName = trim((string)($variant['author_name'] ?? ''));
        $rawToken = trim((string)($variant['token'] ?? ''));
        $articleUrl = trim((string)($variant['target_url'] ?? $task['target_url'] ?? ''));
        if ($articleUrl === '' && !empty($task['target_url'])) {
            $articleUrl = (string)$task['target_url'];
        }
        $anchor = trim((string)($variant['anchor'] ?? ''));

        $runRow = null;
        $stmtRun = $conn->prepare('SELECT project_id FROM promotion_runs WHERE id = ? LIMIT 1');
        if ($stmtRun) {
            $stmtRun->bind_param('i', $runId);
            if ($stmtRun->execute()) {
                $runRow = $stmtRun->get_result()->fetch_assoc();
            }
            $stmtRun->close();
        }
        $projectId = (int)($runRow['project_id'] ?? 0);
        $projectRow = $projectId > 0 ? pp_promotion_fetch_project($conn, $projectId) : null;
        $projectName = trim((string)($projectRow['name'] ?? 'PromoPilot'));
        if ($projectName === '') {
            $projectName = 'PromoPilot';
        }

        $language = pp_promotion_crowd_normalize_language($variant['language'] ?? $projectRow['language'] ?? null);
        $texts = pp_promotion_crowd_texts($language);
        $authorName = $rawAuthorName !== '' ? $rawAuthorName : ($projectName !== '' ? $projectName : $texts['author_default']);
        $authorName = pp_promotion_clean_text($authorName);
        if ($authorName === '') {
            $authorName = $texts['author_default'];
        }
        $token = $rawToken;
        if ($token === '') {
            try {
                $token = substr(bin2hex(random_bytes(8)), 0, 12);
            } catch (Throwable $e) {
                $token = substr(sha1($authorName . microtime(true)), 0, 12);
            }
        }
        $authorEmail = trim((string)($variant['author_email'] ?? ''));
        if ($authorEmail === '') {
            $emailSlug = pp_promotion_make_email_slug($authorName);
            if ($emailSlug === '') {
                $emailSlug = 'promopilot';
            }
            $authorEmail = $emailSlug . '+' . strtolower($token) . '@example.com';
        }
        $body = $rawBody;
        if ($body === '') {
            $linkForBody = $articleUrl !== '' ? $articleUrl : (string)($task['target_url'] ?? '');
            $lead = $anchor !== ''
                ? sprintf($texts['lead_with_anchor'], $anchor)
                : $texts['lead_without_anchor'];
            $bodyParts = [trim($lead)];
            if ($linkForBody !== '') {
                $bodyParts[] = trim($linkForBody);
            }
            $body = trim(implode(' ', array_filter($bodyParts)));
            if ($body === '') {
                $body = trim($texts['link_prompt'] . ' ' . $linkForBody);
            }
            if ($body === '') {
                $body = $texts['link_prompt'];
            }
            if (!empty($texts['feedback'])) {
                $body .= "\n\n" . $texts['feedback'];
            }
            $body = pp_promotion_clean_text($body);
        }
        $subject = $rawSubject;
        if ($subject === '') {
            $subject = $anchor !== '' ? ($texts['subject_prefix'] . ': ' . $anchor) : $texts['subject_default'];
            if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 120) {
                $subject = rtrim(mb_substr($subject, 0, 118, 'UTF-8')) . '…';
            } elseif (strlen($subject) > 120) {
                $subject = rtrim(substr($subject, 0, 118)) . '…';
            }
            $subject = pp_promotion_clean_text($subject);
        }
        $payload['language'] = $language;
        $payload['anchor'] = $anchor;

        $overrides = [];
        if (!empty($variant['form_values']) && is_array($variant['form_values'])) {
            foreach ($variant['form_values'] as $fieldName => $fieldValue) {
                if (!is_string($fieldName) || $fieldName === '') { continue; }
                if (is_array($fieldValue)) {
                    $values = [];
                    foreach ($fieldValue as $fv) {
                        if ($fv === null) { continue; }
                        $values[] = (string)$fv;
                    }
                    if (!empty($values)) {
                        $overrides[$fieldName] = $values;
                    }
                } elseif ($fieldValue !== null) {
                    $overrides[$fieldName] = [(string)$fieldValue];
                }
            }
        }

        $identity = [
            'token' => $token,
            'message' => $body,
            'email' => $authorEmail,
            'name' => $authorName,
            'website' => $articleUrl !== '' ? $articleUrl : 'https://example.com/',
            'phone' => pp_promotion_generate_fake_phone(),
            'password' => substr(sha1($authorEmail . microtime(true)), 0, 12),
            'company' => $projectName,
            'fallback' => $projectName,
            'overrides' => $overrides,
            'language' => $language,
        ];

        $linkUrl = isset($variant['crowd_link_url']) ? trim((string)$variant['crowd_link_url']) : '';
        $crowdLinkId = isset($task['crowd_link_id']) ? (int)$task['crowd_link_id'] : 0;
        if ($linkUrl === '' && $crowdLinkId > 0) {
            if ($resLink = @$conn->query('SELECT url FROM crowd_links WHERE id=' . $crowdLinkId . ' LIMIT 1')) {
                if ($rowLink = $resLink->fetch_assoc()) {
                    $linkUrl = trim((string)($rowLink['url'] ?? ''));
                }
                $resLink->free();
            }
        }
        if ($linkUrl === '') {
            $linkUrl = $articleUrl !== '' ? $articleUrl : (string)($task['target_url'] ?? '');
        }
        if ($linkUrl === '') {
            $statusFallback = 'manual';
            $payload['auto_result'] = [
                'status' => 'skipped',
                'error' => 'Missing crowd link URL',
                'completed_at' => gmdate('c'),
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
            $stmtUpdate = $conn->prepare('UPDATE promotion_crowd_tasks SET status=?, payload_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
            if ($stmtUpdate) {
                $stmtUpdate->bind_param('ssi', $statusFallback, $payloadJson, $taskId);
                $stmtUpdate->execute();
                $stmtUpdate->close();
            }
            pp_promotion_log('promotion.crowd.task_missing_url', ['task_id' => $taskId, 'run_id' => $runId]);
            return;
        }

        $result = pp_crowd_deep_handle_link(['id' => $crowdLinkId, 'url' => $linkUrl], $identity, []);

        $finalStatus = 'failed';
        $needsReview = false;
        switch ($result['status']) {
            case 'success':
                $finalStatus = 'completed';
                break;
            case 'partial':
                $finalStatus = 'completed';
                $needsReview = true;
                break;
            case 'blocked':
                $finalStatus = 'blocked';
                break;
            case 'no_form':
            case 'skipped':
                $finalStatus = 'manual';
                break;
            default:
                $finalStatus = 'failed';
                break;
        }

        if ($finalStatus === 'manual') {
            $payload['manual_fallback'] = true;
            if (empty($payload['fallback_reason'])) {
                $payload['fallback_reason'] = $result['status'];
            }
        } else {
            $payload['manual_fallback'] = false;
            if ($finalStatus === 'completed') {
                $payload['fallback_reason'] = null;
            }
        }

        $payload['body'] = $body;
        $payload['subject'] = $subject;
        $payload['author_name'] = $authorName;
        $payload['author_email'] = $authorEmail;
        $payload['token'] = $token;
        if (!empty($overrides)) {
            $payload['form_values'] = $overrides;
        }
        $payload['auto_result'] = [
            'status' => $result['status'],
            'http_status' => $result['http_status'] ?? null,
            'error' => $result['error'] ?? null,
            'message_excerpt' => $result['message_excerpt'] ?? null,
            'response_excerpt' => $result['response_excerpt'] ?? null,
            'evidence_url' => $result['evidence_url'] ?? null,
            'request_payload' => $result['request_payload'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'needs_review' => $needsReview,
            'completed_at' => gmdate('c'),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($payloadJson === false) {
            $payloadJson = '{}';
        }

        $resultUrl = trim((string)($result['evidence_url'] ?? ''));
        if ($resultUrl === '' && isset($payload['crowd_link_url'])) {
            $resultUrl = trim((string)$payload['crowd_link_url']);
        }
        if ($resultUrl === '') {
            $resultUrl = $linkUrl;
        }

        $stmt = $conn->prepare('UPDATE promotion_crowd_tasks SET status=?, result_url=?, payload_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($stmt) {
            $resultParam = $resultUrl !== '' ? $resultUrl : null;
            $stmt->bind_param('sssi', $finalStatus, $resultParam, $payloadJson, $taskId);
            $stmt->execute();
            $stmt->close();
        }

        pp_promotion_log('promotion.crowd.task_processed', [
            'task_id' => $taskId,
            'run_id' => $runId,
            'status' => $finalStatus,
            'result' => $result['status'],
            'needs_review' => $needsReview,
            'link_url' => $linkUrl,
        ]);
    }
}

if (!function_exists('pp_promotion_generate_fake_phone')) {
    function pp_promotion_generate_fake_phone(): string {
        $prefixes = ['+1', '+31', '+34', '+44', '+353', '+372', '+380', '+420', '+48'];
        try {
            $idx = random_int(0, count($prefixes) - 1);
        } catch (Throwable $e) {
            $idx = mt_rand(0, count($prefixes) - 1);
        }
        $prefix = $prefixes[$idx] ?? '+1';
        $digits = '';
        for ($i = 0; $i < 7; $i++) {
            try {
                $digits .= (string)random_int(0, 9);
            } catch (Throwable $e) {
                $digits .= (string)mt_rand(0, 9);
            }
        }
        return $prefix . ' ' . substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5);
    }
}

if (!function_exists('pp_promotion_crowd_required_per_article')) {
    function pp_promotion_crowd_required_per_article(array $run): int {
        $settings = pp_promotion_settings();
        $defaultPerArticle = (int)($settings['crowd_per_article'] ?? 0);
        $snapshotPerArticle = null;
        if (!empty($run['settings_snapshot'])) {
            $decoded = json_decode((string)$run['settings_snapshot'], true);
            if (is_array($decoded) && isset($decoded['crowd_per_article'])) {
                $snapshotPerArticle = (int)$decoded['crowd_per_article'];
            }
        }
        $value = $snapshotPerArticle !== null ? $snapshotPerArticle : $defaultPerArticle;
        if ($value < 0) { $value = 0; }
        if ($value > 10000) { $value = 10000; }
        return $value;
    }
}

if (!function_exists('pp_promotion_crowd_collect_nodes')) {
    function pp_promotion_crowd_collect_nodes(mysqli $conn, int $runId): array {
        $runId = (int)$runId;
        $result = ['nodes' => [], 'total' => 0];
        $sql = 'SELECT id, level, result_url, target_url, anchor_text, network_slug FROM promotion_nodes WHERE run_id=' . $runId . " AND status IN ('success','completed') ORDER BY level ASC, id ASC";
        $allNodes = [];
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $row['id'] = isset($row['id']) ? (int)$row['id'] : 0;
                $row['level'] = isset($row['level']) ? (int)$row['level'] : null;
                $row['result_url'] = isset($row['result_url']) ? (string)$row['result_url'] : '';
                $row['target_url'] = isset($row['target_url']) ? (string)$row['target_url'] : '';
                if ($row['result_url'] === '' && $row['target_url'] !== '') {
                    $row['result_url'] = $row['target_url'];
                }
                $allNodes[] = $row;
            }
            $res->free();
        }

        if (!empty($allNodes)) {
            $maxLevel = null;
            foreach ($allNodes as $row) {
                if ($row['level'] === null) { continue; }
                if ($maxLevel === null || $row['level'] > $maxLevel) {
                    $maxLevel = $row['level'];
                }
            }

            if ($maxLevel !== null) {
                foreach ($allNodes as $row) {
                    if ($row['level'] === $maxLevel) {
                        $result['nodes'][] = $row;
                    }
                }
            } else {
                $result['nodes'] = $allNodes;
            }
        }

        $result['total'] = count($result['nodes']);
        return $result;
    }
}

if (!function_exists('pp_promotion_crowd_queue_tasks')) {
    function pp_promotion_crowd_queue_tasks(mysqli $conn, array $run, array $project, array $linkRow, array $nodesNeeds, array $options = []): array {
        $summary = ['created' => 0, 'fallback' => 0, 'shortage' => false];
        if (empty($nodesNeeds)) {
            return $summary;
        }

        $runId = (int)($run['id'] ?? 0);
        if ($runId <= 0) {
            return $summary;
        }

        $existing = [];
        $busyLinks = [];
        if ($res = @$conn->query('SELECT node_id, status, crowd_link_id FROM promotion_crowd_tasks WHERE run_id=' . $runId)) {
            while ($row = $res->fetch_assoc()) {
                $nodeId = isset($row['node_id']) ? (int)$row['node_id'] : 0;
                $status = strtolower((string)($row['status'] ?? ''));
                $linkId = isset($row['crowd_link_id']) ? (int)$row['crowd_link_id'] : 0;
                if ($nodeId <= 0) { continue; }
                if (!isset($existing[$nodeId])) {
                    $existing[$nodeId] = ['completed' => 0, 'active' => 0];
                }
                if (in_array($status, ['completed','success','done','posted','published','ok'], true)) {
                    $existing[$nodeId]['completed']++;
                } elseif (in_array($status, ['planned','queued','running','pending','created'], true)) {
                    $existing[$nodeId]['active']++;
                }
                if ($linkId > 0) {
                    $busyLinks[$linkId] = true;
                }
            }
            $res->free();
        }

        $usedDomains = [];
        $domainLookupIds = array_keys($busyLinks);
        if (!empty($options['existing_link_map']) && is_array($options['existing_link_map'])) {
            foreach ($options['existing_link_map'] as $nodeMap) {
                if (!is_array($nodeMap)) { continue; }
                foreach ($nodeMap as $linkId) {
                    $domainLookupIds[] = (int)$linkId;
                }
            }
        }
        $domainLookupIds = array_values(array_unique(array_filter(array_map('intval', $domainLookupIds))));
        if (!empty($domainLookupIds)) {
            $idList = implode(',', $domainLookupIds);
            if ($resDomains = @$conn->query('SELECT id, domain FROM crowd_links WHERE id IN (' . $idList . ')')) {
                while ($rowDomain = $resDomains->fetch_assoc()) {
                    $domainNormalized = strtolower(trim((string)($rowDomain['domain'] ?? '')));
                    if ($domainNormalized === '') { continue; }
                    $usedDomains[$domainNormalized] = true;
                }
                $resDomains->free();
            }
        }

        $createPlan = [];
        $requiredTotal = 0;
        foreach ($nodesNeeds as $nodeId => $info) {
            $nodeId = (int)$nodeId;
            $needed = max(0, (int)($info['needed'] ?? 0));
            if ($nodeId <= 0 || $needed <= 0) { continue; }
            $haveActive = $existing[$nodeId]['active'] ?? 0;
            $remaining = max(0, $needed - $haveActive);
            if ($remaining <= 0) { continue; }
            $targetUrl = isset($info['target_url']) ? (string)$info['target_url'] : '';
            $createPlan[$nodeId] = ['target_url' => $targetUrl, 'amount' => $remaining];
            $requiredTotal += $remaining;
        }

        if ($requiredTotal <= 0) {
            return $summary;
        }

    $preferredLanguage = pp_promotion_crowd_normalize_language($linkRow['language'] ?? $project['language'] ?? null);
        $preferredRegion = strtoupper(trim((string)($project['region'] ?? '')));

        $excludeLinkIds = array_keys($busyLinks);
        if (!empty($options['existing_link_map']) && is_array($options['existing_link_map'])) {
            foreach ($options['existing_link_map'] as $nodeMap) {
                if (!is_array($nodeMap)) { continue; }
                foreach ($nodeMap as $linkId) {
                    $excludeLinkIds[] = (int)$linkId;
                }
            }
        }
        $excludeLinkIds = array_values(array_unique(array_filter($excludeLinkIds)));

        $availableLinks = pp_promotion_crowd_fetch_available_links($conn, $requiredTotal, $excludeLinkIds, [
            'preferred_language' => $preferredLanguage,
            'preferred_region' => $preferredRegion,
            'exclude_domains' => array_keys($usedDomains),
            'unique_domain' => true,
        ]);
        $reservedDomains = $usedDomains;
        $filteredLinks = [];
        foreach ($availableLinks as $linkCandidate) {
            $domainNormalized = strtolower(trim((string)($linkCandidate['domain'] ?? '')));
            if ($domainNormalized !== '' && isset($reservedDomains[$domainNormalized])) {
                continue;
            }
            $filteredLinks[] = $linkCandidate;
            if ($domainNormalized !== '') {
                $reservedDomains[$domainNormalized] = true;
            }
            if (count($filteredLinks) >= $requiredTotal) {
                break;
            }
        }
        if (count($filteredLinks) < $requiredTotal) {
            $summary['shortage'] = true;
        }
        $availableLinks = $filteredLinks;
        $availableCount = count($availableLinks);

        $nodeIds = array_keys($createPlan);
        $nodeMeta = [];
        if (!empty($nodeIds)) {
            $idList = implode(',', array_map('intval', $nodeIds));
            $sqlNodes = 'SELECT id, level, result_url, target_url, anchor_text, network_slug FROM promotion_nodes WHERE id IN (' . $idList . ')';
            if ($resNodes = @$conn->query($sqlNodes)) {
                while ($rowNode = $resNodes->fetch_assoc()) {
                    $nodeMeta[(int)$rowNode['id']] = $rowNode;
                }
                $resNodes->free();
            }
        }

        pp_promotion_ensure_crowd_payload_column($conn);

        $linkIndex = 0;
        foreach ($createPlan as $nodeId => $details) {
            $targetUrl = (string)$details['target_url'];
            $amount = (int)$details['amount'];
            $meta = $nodeMeta[$nodeId] ?? [];
            for ($i = 0; $i < $amount; $i++) {
                $crowdLink = null;
                if ($linkIndex < $availableCount) {
                    $crowdLink = $availableLinks[$linkIndex];
                    $linkIndex++;
                    if (isset($crowdLink['id'])) {
                        $busyLinks[(int)$crowdLink['id']] = true;
                    }
                    $domainNormalized = strtolower(trim((string)($crowdLink['domain'] ?? '')));
                    if ($domainNormalized !== '') {
                        $reservedDomains[$domainNormalized] = true;
                    }
                }
                [$payload, $manualFallback] = pp_promotion_crowd_build_payload($project, $linkRow, $meta, $crowdLink, [
                    'target_url' => $targetUrl,
                    'run' => $run,
                    'node_id' => $nodeId,
                ]);
                if ($manualFallback) {
                    $summary['fallback']++;
                }
                $status = $crowdLink ? 'queued' : 'planned';
                if (pp_promotion_crowd_insert_task($conn, $runId, $nodeId, $crowdLink, $targetUrl, $status, $payload)) {
                    $summary['created']++;
                } else {
                    $summary['shortage'] = true;
                }
            }
        }

        pp_promotion_log('promotion.crowd.tasks_queued', [
            'run_id' => $runId,
            'requested' => $requiredTotal,
            'created' => $summary['created'],
            'fallback' => $summary['fallback'],
            'shortage' => $summary['shortage'],
        ]);

        return $summary;
    }
}

if (!function_exists('pp_promotion_crowd_pending_count')) {
    function pp_promotion_crowd_pending_count(): int {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$conn) { return 0; }
        $count = 0;
        if ($res = @$conn->query("SELECT COUNT(*) AS c FROM promotion_crowd_tasks WHERE status IN ('planned','queued','running','pending','created')")) {
            if ($row = $res->fetch_assoc()) {
                $count = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
        $conn->close();
        return $count;
    }
}

if (!function_exists('pp_promotion_crowd_worker')) {
    function pp_promotion_crowd_worker(?int $specificTaskId = null, int $maxIterations = 30): int {
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_promotion_log('promotion.crowd.worker_db_error', ['error' => $e->getMessage()]);
            return 0;
        }
        if (!$conn) {
            return 0;
        }

        $processed = 0;
        $maxIterations = max(1, min(200, $maxIterations));
        for ($i = 0; $i < $maxIterations; $i++) {
            $task = pp_promotion_crowd_claim_task($conn, $specificTaskId);
            $specificTaskId = null;
            if (!$task) { break; }
            try {
                pp_promotion_crowd_process_task($conn, $task);
                $processed++;
            } catch (Throwable $e) {
                $taskId = (int)($task['id'] ?? 0);
                $status = 'failed';
                $payload = [];
                if (!empty($task['payload_json'])) {
                    $decoded = json_decode((string)$task['payload_json'], true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }
                $payload['manual_fallback'] = true;
                $payload['fallback_reason'] = 'exception';
                $payload['auto_result'] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'completed_at' => gmdate('c'),
                ];
                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
                $stmt = $conn->prepare('UPDATE promotion_crowd_tasks SET status=?, payload_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('ssi', $status, $payloadJson, $taskId);
                    $stmt->execute();
                    $stmt->close();
                }
                pp_promotion_log('promotion.crowd.task_exception', [
                    'task_id' => $taskId,
                    'error' => $e->getMessage(),
                ]);
            }
            usleep(150000);
        }
        $conn->close();
        return $processed;
    }
}

if (!function_exists('pp_promotion_crowd_fetch_available_links')) {
    function pp_promotion_crowd_fetch_available_links(mysqli $conn, int $limit, array $excludeIds = [], array $options = []): array {
        $limit = max(1, $limit);
        $excludeClause = '';
        if (!empty($excludeIds)) {
            $unique = array_values(array_unique(array_map('intval', $excludeIds)));
            if (!empty($unique)) {
                $excludeClause = ' AND id NOT IN (' . implode(',', $unique) . ')';
            }
        }
        $excludeDomainMap = [];
        if (!empty($options['exclude_domains']) && is_array($options['exclude_domains'])) {
            foreach ($options['exclude_domains'] as $domain) {
                $normalized = strtolower(trim((string)$domain));
                if ($normalized === '') { continue; }
                $excludeDomainMap[$normalized] = true;
            }
        }
        $domainExcludeClause = '';
        if (!empty($excludeDomainMap)) {
            $escapedDomains = [];
            foreach (array_keys($excludeDomainMap) as $domainValue) {
                $escapedDomains[] = "'" . $conn->real_escape_string($domainValue) . "'";
            }
            if (!empty($escapedDomains)) {
                $domainExcludeClause = ' AND domain NOT IN (' . implode(',', $escapedDomains) . ')';
            }
        }

        $fetchLimit = min(800, max($limit * 6, 60));
        $sql = "SELECT id, url, domain, status, language, region, form_required, deep_status, deep_checked_at FROM crowd_links WHERE status = 'ok' AND deep_status = 'success'"
            . $excludeClause . $domainExcludeClause
            . ' ORDER BY COALESCE(deep_checked_at, updated_at) DESC, id DESC LIMIT ' . $fetchLimit;
        $links = [];
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $links[] = $row;
            }
            $res->free();
        }
        if (empty($links)) {
            return [];
        }
        $preferredLanguage = strtolower(trim((string)($options['preferred_language'] ?? '')));
        $preferredRegion = strtoupper(trim((string)($options['preferred_region'] ?? '')));
        $uniqueDomain = !empty($options['unique_domain']);
        usort($links, static function(array $a, array $b) use ($preferredLanguage, $preferredRegion) {
            $scoreA = pp_promotion_crowd_score_link($a, $preferredLanguage, $preferredRegion);
            $scoreB = pp_promotion_crowd_score_link($b, $preferredLanguage, $preferredRegion);
            if ($scoreA === $scoreB) {
                $timeA = isset($a['deep_checked_at']) ? strtotime((string)$a['deep_checked_at']) : 0;
                $timeB = isset($b['deep_checked_at']) ? strtotime((string)$b['deep_checked_at']) : 0;
                if ($timeA !== $timeB) {
                    return $timeA > $timeB ? -1 : 1;
                }
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            }
            return $scoreA < $scoreB ? -1 : 1;
        });
        $filtered = [];
        $seenDomains = [];
        foreach ($links as $row) {
            $domainNormalized = strtolower(trim((string)($row['domain'] ?? '')));
            if ($domainNormalized !== '' && isset($excludeDomainMap[$domainNormalized])) {
                continue;
            }
            if (!empty($row['deep_status']) && strtolower((string)$row['deep_status']) !== 'success') {
                continue;
            }
            if ($uniqueDomain) {
                $domainKey = $domainNormalized !== '' ? $domainNormalized : ('__' . (int)($row['id'] ?? 0));
                if (isset($seenDomains[$domainKey])) {
                    continue;
                }
                $seenDomains[$domainKey] = true;
            }
            unset($row['deep_status'], $row['deep_checked_at']);
            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }
        return $filtered;
    }
}

if (!function_exists('pp_promotion_crowd_score_link')) {
    function pp_promotion_crowd_score_link(array $link, string $preferredLanguage, string $preferredRegion): int {
        $score = 0;
        $status = strtolower((string)($link['status'] ?? ''));
        if ($status === 'ok') { $score -= 1000; }
        if ($status !== 'ok') { $score += 200; }
        $lang = strtolower(trim((string)($link['language'] ?? '')));
        if ($preferredLanguage !== '' && $lang !== '') {
            if ($lang === $preferredLanguage) {
                $score -= 300;
            } elseif ($lang === 'en' && $preferredLanguage !== 'en') {
                $score -= 60;
            } else {
                $score += 80;
            }
        } elseif ($preferredLanguage !== '' && $lang === '') {
            $score -= 40;
        }
        $region = strtoupper(trim((string)($link['region'] ?? '')));
        if ($preferredRegion !== '' && $region !== '') {
            if ($region === $preferredRegion) {
                $score -= 200;
            } else {
                $score += 40;
            }
        }
        $score += ((int)($link['id'] ?? 0)) % 19;
        return $score;
    }
}

if (!function_exists('pp_promotion_crowd_build_payload')) {
    function pp_promotion_crowd_build_payload(array $project, array $linkRow, array $nodeMeta, ?array $crowdLink, array $options = []): array {
        $targetUrl = trim((string)($options['target_url'] ?? ''));
        if ($targetUrl === '' && !empty($nodeMeta['result_url'])) {
            $targetUrl = trim((string)$nodeMeta['result_url']);
        }
        if ($targetUrl === '' && !empty($nodeMeta['target_url'])) {
            $targetUrl = trim((string)$nodeMeta['target_url']);
        }
        $projectName = trim((string)($project['name'] ?? ''));
        $language = pp_promotion_crowd_normalize_language($linkRow['language'] ?? $project['language'] ?? null);
        $texts = pp_promotion_crowd_texts($language);
        $anchor = trim((string)($linkRow['anchor'] ?? ''));
        if ($anchor === '') {
            $anchor = $projectName !== '' ? $projectName : __('Материал');
        }
        $subject = $anchor !== '' ? ($texts['subject_prefix'] . ': ' . $anchor) : $texts['subject_default'];
        if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 120) {
            $subject = rtrim(mb_substr($subject, 0, 118, 'UTF-8')) . '…';
        } elseif (strlen($subject) > 120) {
            $subject = rtrim(substr($subject, 0, 118)) . '…';
        }
        $lead = $anchor !== ''
            ? sprintf($texts['lead_with_anchor'], $anchor)
            : $texts['lead_without_anchor'];
        $linkForBody = $targetUrl !== '' ? $targetUrl : (string)($options['run']['target_url'] ?? '');
        $bodyParts = [trim($lead)];
        if ($linkForBody !== '') {
            $bodyParts[] = $linkForBody;
        }
        $body = trim(implode(' ', array_filter($bodyParts)));
        if ($body === '') {
            $body = trim($texts['link_prompt'] . ' ' . $linkForBody);
        }
        if ($body === '') {
            $body = $texts['link_prompt'];
        }
        if (!empty($texts['feedback'])) {
            $body .= "\n\n" . $texts['feedback'];
        }

        $authorName = $projectName !== '' ? $projectName : $texts['author_default'];
        try {
            $token = substr(bin2hex(random_bytes(8)), 0, 12);
        } catch (Throwable $e) {
            $token = substr(sha1($body . microtime(true)), 0, 12);
        }
        $emailSlug = pp_promotion_make_email_slug($authorName);
        $authorEmail = $emailSlug . '+' . strtolower($token) . '@example.com';

        $payload = [
            'run_id' => (int)($options['run']['id'] ?? 0),
            'node_id' => (int)($options['node_id'] ?? 0),
            'target_url' => $targetUrl,
            'source_url' => isset($nodeMeta['result_url']) ? (string)$nodeMeta['result_url'] : null,
            'crowd_link_id' => isset($crowdLink['id']) ? (int)$crowdLink['id'] : null,
            'crowd_link_url' => isset($crowdLink['url']) ? (string)$crowdLink['url'] : null,
            'subject' => $subject,
            'body' => $body,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'token' => $token,
            'manual_fallback' => $crowdLink ? false : true,
            'fallback_reason' => $crowdLink ? null : 'crowd_link_unavailable',
            'language' => $language,
            'anchor' => $anchor,
        ];

        $identity = [
            'token' => $token,
            'message' => $body,
            'email' => $authorEmail,
            'name' => $authorName,
            'website' => $targetUrl !== '' ? $targetUrl : 'https://example.com/',
            'company' => $projectName !== '' ? $projectName : $authorName,
            'phone' => pp_promotion_generate_fake_phone(),
            'language' => $language,
        ];

        $payload['identity'] = $identity;

        return [$payload, !$crowdLink];
    }
}

if (!function_exists('pp_promotion_crowd_insert_task')) {
    function pp_promotion_crowd_insert_task(mysqli $conn, int $runId, int $nodeId, ?array $crowdLink, string $targetUrl, string $status, array $payload): bool {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($payloadJson === false) {
            $payloadJson = '{}';
        }
        $runIdSql = (int)$runId;
        $nodeIdSql = $nodeId > 0 ? (string)$nodeId : 'NULL';
        $crowdLinkId = isset($crowdLink['id']) ? (int)$crowdLink['id'] : 0;
        $crowdLinkSql = $crowdLinkId > 0 ? (string)$crowdLinkId : 'NULL';
        $targetUrlEsc = $conn->real_escape_string($targetUrl);
        $statusEsc = $conn->real_escape_string($status);
        $payloadEsc = $conn->real_escape_string($payloadJson);
        $sql = "INSERT INTO promotion_crowd_tasks (run_id, node_id, crowd_link_id, target_url, status, payload_json) VALUES (" . $runIdSql . ', ' . $nodeIdSql . ', ' . $crowdLinkSql . ", '" . $targetUrlEsc . "', '" . $statusEsc . "', '" . $payloadEsc . "')";
        $ok = @$conn->query($sql);
        if (!$ok) {
            pp_promotion_log('promotion.crowd.queue_insert_failed', [
                'run_id' => $runId,
                'node_id' => $nodeId,
                'crowd_link_id' => $crowdLinkId,
                'status' => $status,
                'error' => $conn->error,
            ]);
        }
        return (bool)$ok;
    }
}
