<?php
// Multi-level promotion (cascade publications with reporting)

if (!function_exists('pp_promotion_log')) {
    function pp_promotion_log(string $message, array $context = []): void {
        try {
            $dir = PP_ROOT_PATH . '/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (!is_dir($dir) || !is_writable($dir)) { return; }
            $file = $dir . '/promotion.log';
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
            if (!empty($context)) {
                $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($json !== false) {
                    $line .= ' ' . $json;
                }
            }
            $line .= "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // swallow logging errors
        }
    }
}

if (!function_exists('pp_promotion_settings')) {
    function pp_promotion_settings(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $defaults = [
            'level1_enabled' => true,
            'level2_enabled' => true,
            'level3_enabled' => false,
            'crowd_enabled' => true,
            'level1_count' => 5,
            'level2_per_level1' => 10,
            'level1_min_len' => 2800,
            'level1_max_len' => 3400,
            'level2_min_len' => 1400,
            'level2_max_len' => 2100,
            'crowd_per_article' => 100,
            'network_repeat_limit' => 2,
            'price_per_link' => max(0, (float)str_replace(',', '.', (string)get_setting('promotion_price_per_link', '0'))),
        ];
        $map = [
            'promotion_level1_enabled' => 'level1_enabled',
            'promotion_level2_enabled' => 'level2_enabled',
            'promotion_level3_enabled' => 'level3_enabled',
            'promotion_crowd_enabled' => 'crowd_enabled',
        ];
        foreach ($map as $settingKey => $localKey) {
            $raw = get_setting($settingKey, $defaults[$localKey] ? '1' : '0');
            $cacheBool = !in_array(strtolower((string)$raw), ['0', 'false', 'no', 'off', ''], true);
            $defaults[$localKey] = $cacheBool;
        }
        // cache
        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('pp_promotion_is_level_enabled')) {
    function pp_promotion_is_level_enabled(int $level): bool {
        $settings = pp_promotion_settings();
        if ($level === 1) { return !empty($settings['level1_enabled']); }
        if ($level === 2) { return !empty($settings['level2_enabled']); }
        if ($level === 3) { return !empty($settings['level3_enabled']); }
        return false;
    }
}

if (!function_exists('pp_promotion_is_crowd_enabled')) {
    function pp_promotion_is_crowd_enabled(): bool {
        $settings = pp_promotion_settings();
        return !empty($settings['crowd_enabled']);
    }
}

if (!function_exists('pp_promotion_fetch_link_row')) {
    function pp_promotion_fetch_link_row(mysqli $conn, int $projectId, string $url): ?array {
        $stmt = $conn->prepare('SELECT id, url, anchor, language, wish FROM project_links WHERE project_id = ? AND url = ? LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_get_active_run')) {
    function pp_promotion_get_active_run(mysqli $conn, int $projectId, string $url): ?array {
        $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? AND status IN (\'queued\',\'running\',\'level1_active\',\'level2_active\',\'pending_level2\',\'pending_crowd\',\'crowd_ready\') ORDER BY id DESC LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_start_run')) {
    function pp_promotion_start_run(int $projectId, string $url, int $userId): array {
        if (!pp_promotion_is_level_enabled(1)) {
            return ['ok' => false, 'error' => 'LEVEL1_DISABLED'];
        }
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB'];
        }
        $conn->set_charset('utf8mb4');
        $linkRow = pp_promotion_fetch_link_row($conn, $projectId, $url);
        if (!$linkRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'URL_NOT_FOUND'];
        }
        $active = pp_promotion_get_active_run($conn, $projectId, $url);
        if ($active) {
            pp_promotion_log('promotion.run_reused', [
                'project_id' => $projectId,
                'target_url' => $url,
                'run_id' => (int)$active['id'],
                'status' => (string)($active['status'] ?? ''),
            ]);
            $conn->close();
            return ['ok' => true, 'status' => 'running', 'run_id' => (int)$active['id']];
        }
        $settings = pp_promotion_settings();
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
        $basePrice = max(0, (float)($settings['price_per_link'] ?? 0));

        $runId = 0;
        $chargedAmount = 0.0;
        $discountPercent = 0.0;
        try {
            $conn->begin_transaction();
            $userStmt = $conn->prepare('SELECT balance, promotion_discount FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$userStmt) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $userStmt->bind_param('i', $userId);
            if (!$userStmt->execute()) {
                $userStmt->close();
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $userRow = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if (!$userRow) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'USER_NOT_FOUND'];
            }

            $balance = (float)$userRow['balance'];
            $discountPercent = max(0.0, min(100.0, (float)($userRow['promotion_discount'] ?? 0)));
            $chargedAmount = max(0.0, round($basePrice * (1 - $discountPercent / 100), 2));

            if ($chargedAmount > 0 && ($balance + 1e-6) < $chargedAmount) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'INSUFFICIENT_FUNDS'];
            }

            if ($chargedAmount > 0) {
                $upd = $conn->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
                if (!$upd) {
                    $conn->rollback();
                    $conn->close();
                    return ['ok' => false, 'error' => 'DB'];
                }
                $upd->bind_param('di', $chargedAmount, $userId);
                if (!$upd->execute()) {
                    $upd->close();
                    $conn->rollback();
                    $conn->close();
                    return ['ok' => false, 'error' => 'DB'];
                }
                $upd->close();
            }

            $stmt = $conn->prepare('INSERT INTO promotion_runs (project_id, link_id, target_url, status, stage, initiated_by, settings_snapshot, charged_amount, discount_percent) VALUES (?, ?, ?, \'queued\', \'pending_level1\', ?, ?, ?, ?)');
            if (!$stmt) {
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $linkId = (int)$linkRow['id'];
            $stmt->bind_param('iisisdd', $projectId, $linkId, $url, $userId, $settingsJson, $chargedAmount, $discountPercent);
            if (!$stmt->execute()) {
                $stmt->close();
                $conn->rollback();
                $conn->close();
                return ['ok' => false, 'error' => 'DB'];
            }
            $runId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $rollbackIgnored) {}
            $conn->close();
            return ['ok' => false, 'error' => 'DB'];
        }
        $conn->close();
        if ($runId > 0) {
            pp_promotion_log('promotion.run_created', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'link_id' => (int)$linkRow['id'],
                'target_url' => $url,
                'settings' => [
                    'level1_count' => (int)($settings['level1_count'] ?? 0),
                    'network_repeat_limit' => (int)($settings['network_repeat_limit'] ?? 0),
                    'level2_enabled' => !empty($settings['level2_enabled']),
                    'crowd_enabled' => !empty($settings['crowd_enabled']),
                ],
                'charged' => $chargedAmount,
                'discount_percent' => $discountPercent,
            ]);
        }
        $launched = pp_promotion_launch_worker($runId);
        if (!$launched) {
            pp_promotion_log('promotion.worker.launch_failed', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'target_url' => $url,
            ]);
        }
        // Ensure immediate processing even if background launch is not supported in the environment
        try {
            pp_promotion_worker($runId, 10);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.worker.inline_error', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
        return ['ok' => true, 'status' => 'queued', 'run_id' => $runId, 'charged' => $chargedAmount, 'discount' => $discountPercent];
    }
}

if (!function_exists('pp_promotion_cancel_run')) {
    function pp_promotion_cancel_run(int $projectId, string $url, int $userId): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $run = null;
        $stmt = $conn->prepare('SELECT id, status FROM promotion_runs WHERE project_id = ? AND target_url = ? AND status NOT IN (\'completed\',\'failed\',\'cancelled\') ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('is', $projectId, $url);
            if ($stmt->execute()) {
                $run = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }
        if (!$run) {
            $conn->close();
            return ['ok' => false, 'error' => 'NOT_FOUND'];
        }
        $runId = (int)$run['id'];
        @$conn->query("UPDATE promotion_runs SET status='cancelled', stage='cancelled', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
        @$conn->query("UPDATE promotion_nodes SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . $runId . " AND status IN ('queued','running')");
        // Request cancellation for publications
        @$conn->query("UPDATE publications p JOIN promotion_nodes pn ON pn.publication_id = p.id SET p.cancel_requested = 1 WHERE pn.run_id=" . $runId . " AND p.status IN ('queued','running')");
        $conn->close();
        return ['ok' => true, 'status' => 'cancelled'];
    }
}

if (!function_exists('pp_promotion_launch_worker')) {
    function pp_promotion_launch_worker(?int $runId = null): bool {
        $script = PP_ROOT_PATH . '/scripts/promotion_worker.php';
        if (!is_file($script)) { return false; }
        $phpBinary = PHP_BINARY ?: 'php';
        $args = $runId ? ' ' . (int)$runId : '';
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $success = false;
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args;
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $success = true;
            }
            return $success;
        }
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . $args . ' > /dev/null 2>&1 &';
        if (function_exists('popen')) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $success = true;
            }
        }
        if (!$success) {
            $execResult = @exec($cmd, $output, $status);
            if ($status === 0) {
                $success = true;
            }
        }
        return $success;
    }
}

if (!function_exists('pp_promotion_get_level_requirements')) {
    function pp_promotion_get_level_requirements(): array {
        $settings = pp_promotion_settings();
        return [
            1 => ['count' => max(1, (int)$settings['level1_count']), 'min_len' => (int)$settings['level1_min_len'], 'max_len' => (int)$settings['level1_max_len']],
            2 => ['per_parent' => max(1, (int)$settings['level2_per_level1']), 'min_len' => (int)$settings['level2_min_len'], 'max_len' => (int)$settings['level2_max_len']],
        ];
    }
}

if (!function_exists('pp_promotion_fetch_project')) {
    function pp_promotion_fetch_project(mysqli $conn, int $projectId): ?array {
        $stmt = $conn->prepare('SELECT id, name, language, wishes, region, topic FROM projects WHERE id = ? LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('i', $projectId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_pick_networks')) {
    function pp_promotion_pick_networks(int $level, int $count, array $project, array &$usage): array {
        $count = (int)$count;
        if ($count <= 0) { return []; }

        $networks = pp_get_networks(true, false);
        $region = strtolower(trim((string)($project['region'] ?? '')));
        $topic = strtolower(trim((string)($project['topic'] ?? '')));
        $levelStr = (string)$level;
        $usageLimit = (int)(pp_promotion_settings()['network_repeat_limit'] ?? 0);
        if ($usageLimit < 0) { $usageLimit = 0; }

        $catalog = [];
        foreach ($networks as $net) {
            $levelsRaw = (string)($net['level'] ?? '');
            if ($levelsRaw !== '') {
                $levelsList = array_filter(array_map('trim', explode(',', $levelsRaw)));
                if (!in_array($levelStr, $levelsList, true)) { continue; }
            } elseif ($level !== 1) {
                // networks without explicit level only suitable for level 1 by default
                continue;
            }
            $slug = (string)($net['slug'] ?? '');
            if ($slug === '') { continue; }
            if (!isset($usage[$slug])) { $usage[$slug] = 0; }
            $baseScore = (int)($net['priority'] ?? 0);
            $metaRegions = [];
            if (!empty($net['regions']) && is_array($net['regions'])) {
                $metaRegions = array_map(static function($item) { return strtolower(trim((string)$item)); }, $net['regions']);
            }
            $metaTopics = [];
            if (!empty($net['topics']) && is_array($net['topics'])) {
                $metaTopics = array_map(static function($item) { return strtolower(trim((string)$item)); }, $net['topics']);
            }
            if ($region !== '') {
                if (in_array($region, $metaRegions, true)) { $baseScore += 2000; }
                elseif (in_array('global', $metaRegions, true)) { $baseScore += 500; }
            }
            if ($topic !== '') {
                if (in_array($topic, $metaTopics, true)) { $baseScore += 1200; }
            }
            $catalog[$slug] = [
                'network' => $net,
                'baseScore' => $baseScore,
                'regions' => $metaRegions,
                'topics' => $metaTopics,
            ];
        }

        $selected = [];
        for ($i = 0; $i < $count; $i++) {
            $candidates = [];
            foreach ($catalog as $slug => $meta) {
                $used = (int)($usage[$slug] ?? 0);
                if ($usageLimit > 0 && $used >= $usageLimit) { continue; }
                $score = $meta['baseScore'];
                if ($used > 0) { $score -= $used * 250; }
                try {
                    $score += random_int(0, 250);
                } catch (Throwable $e) {
                    $score += mt_rand(0, 250);
                }
                $candidates[] = ['slug' => $slug, 'score' => $score, 'network' => $meta['network']];
            }
            if (empty($candidates)) { break; }
            usort($candidates, static function(array $a, array $b) {
                if ($a['score'] === $b['score']) { return strcmp($a['slug'], $b['slug']); }
                return $a['score'] < $b['score'] ? 1 : -1;
            });
            $choice = $candidates[0];
            $selected[] = $choice['network'];
            $usage[$choice['slug']] = (int)($usage[$choice['slug']] ?? 0) + 1;
        }

        return $selected;
    }
}

if (!function_exists('pp_promotion_update_progress')) {
    function pp_promotion_update_progress(mysqli $conn, int $runId): void {
        $levelCounters = [];
        if ($res = @$conn->query('SELECT level, status, COUNT(*) AS c FROM promotion_nodes WHERE run_id = ' . (int)$runId . ' GROUP BY level, status')) {
            while ($row = $res->fetch_assoc()) {
                $level = (int)($row['level'] ?? 0);
                $status = (string)($row['status'] ?? '');
                $count = (int)($row['c'] ?? 0);
                if (!isset($levelCounters[$level])) {
                    $levelCounters[$level] = ['attempted' => 0, 'success' => 0, 'failed' => 0];
                }
                $levelCounters[$level]['attempted'] += $count;
                if (in_array($status, ['success','completed'], true)) {
                    $levelCounters[$level]['success'] += $count;
                } elseif (in_array($status, ['failed','cancelled'], true)) {
                    $levelCounters[$level]['failed'] += $count;
                }
            }
            $res->free();
        }

        $level1Success = (int)($levelCounters[1]['success'] ?? 0);
        $level1Required = null;

        if ($resSettings = @$conn->query('SELECT settings_snapshot FROM promotion_runs WHERE id = ' . (int)$runId . ' LIMIT 1')) {
            if ($row = $resSettings->fetch_assoc()) {
                $snapshot = [];
                if (!empty($row['settings_snapshot'])) {
                    $decoded = json_decode((string)$row['settings_snapshot'], true);
                    if (is_array($decoded)) { $snapshot = $decoded; }
                }
                $level1Required = isset($snapshot['level1_count']) ? (int)$snapshot['level1_count'] : null;
            }
            $resSettings->free();
        }

        if ($level1Required === null || $level1Required <= 0) {
            $defaults = pp_promotion_get_level_requirements();
            $level1Required = (int)($defaults[1]['count'] ?? 5);
        }

        $progressDone = min($level1Success, $level1Required);
        $progressTotal = $level1Required;

        @$conn->query('UPDATE promotion_runs SET progress_total=' . (int)$progressTotal . ', progress_done=' . (int)$progressDone . ', updated_at=CURRENT_TIMESTAMP WHERE id=' . (int)$runId . ' LIMIT 1');
    }
}

if (!function_exists('pp_promotion_enqueue_publication')) {
    function pp_promotion_enqueue_publication(mysqli $conn, array $node, array $project, array $linkRow, array $requirements): bool {
        $runId = (int)$node['run_id'];
        $projectId = (int)$project['id'];
        $targetUrl = (string)$node['target_url'];
        $networkSlug = (string)$node['network_slug'];
        $anchor = (string)$node['anchor_text'];
        $language = (string)($linkRow['language'] ?? $project['language'] ?? 'ru');
        $wish = (string)($linkRow['wish'] ?? $project['wishes'] ?? '');
        $jobPayload = [
            'article' => [
                'minLength' => $requirements['min_len'] ?? 2000,
                'maxLength' => $requirements['max_len'] ?? 3200,
                'level' => (int)$node['level'],
                'parentUrl' => $node['parent_url'] ?? null,
                'projectName' => (string)($project['name'] ?? ''),
            ],
            'target' => [
                'url' => $targetUrl,
                'anchor' => $anchor,
                'language' => $language,
                'wish' => $wish,
            ],
            'project' => [
                'id' => $projectId,
                'region' => $project['region'] ?? null,
                'topic' => $project['topic'] ?? null,
                'language' => $project['language'] ?? null,
            ],
            'network' => [
                'slug' => $networkSlug,
                'level' => (int)$node['level'],
            ],
        ];
        $payloadJson = json_encode($jobPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($payloadJson === false) { $payloadJson = '{}'; }
        static $hasJobPayloadColumn = null;
        if ($hasJobPayloadColumn === null) {
            $hasJobPayloadColumn = false;
            if ($res = @$conn->query("SHOW COLUMNS FROM publications LIKE 'job_payload'")) {
                if ($res->num_rows > 0) { $hasJobPayloadColumn = true; }
                $res->free();
            }
            pp_promotion_log('promotion.publications.job_payload_column', ['present' => $hasJobPayloadColumn]);
        }
        if ($hasJobPayloadColumn) {
            $stmt = $conn->prepare('INSERT INTO publications (project_id, page_url, anchor, network, status, enqueued_by_user_id, job_payload) VALUES (?, ?, ?, ?, \'queued\', ?, ?)');
        } else {
            $stmt = $conn->prepare('INSERT INTO publications (project_id, page_url, anchor, network, status, enqueued_by_user_id) VALUES (?, ?, ?, ?, \'queued\', ?)');
        }
        if (!$stmt) { return false; }
        $userId = (int)$node['initiated_by'];
        if ($hasJobPayloadColumn) {
            $stmt->bind_param('isssis', $projectId, $targetUrl, $anchor, $networkSlug, $userId, $payloadJson);
        } else {
            $stmt->bind_param('isssi', $projectId, $targetUrl, $anchor, $networkSlug, $userId);
        }
        if (!$stmt->execute()) {
            pp_promotion_log('promotion.publication_queue_failed', [
                'run_id' => $runId,
                'node_id' => (int)$node['id'],
                'level' => (int)$node['level'],
                'network' => $networkSlug,
                'target_url' => $targetUrl,
                'error' => 'DB_INSERT_FAILED',
            ]);
            $stmt->close();
            return false;
        }
        $publicationId = (int)$conn->insert_id;
        $stmt->close();
        $update = $conn->prepare('UPDATE promotion_nodes SET publication_id=?, status=\'queued\', queued_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($update) {
            $nodeId = (int)$node['id'];
            $update->bind_param('ii', $publicationId, $nodeId);
            $update->execute();
            $update->close();
        }
        pp_promotion_log('promotion.publication_queued', [
            'run_id' => $runId,
            'project_id' => $projectId,
            'node_id' => (int)$node['id'],
            'publication_id' => $publicationId,
            'level' => (int)$node['level'],
            'network' => $networkSlug,
            'target_url' => $targetUrl,
            'anchor' => $anchor,
            'language' => $language,
            'requirements' => [
                'min_length' => $requirements['min_len'] ?? null,
                'max_length' => $requirements['max_len'] ?? null,
            ],
        ]);
        if (function_exists('pp_run_queue_worker')) {
            try {
                @pp_run_queue_worker(1);
            } catch (Throwable $e) {
                pp_promotion_log('promotion.queue_worker_error', [
                    'run_id' => $runId,
                    'publication_id' => $publicationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        try {
            $conn2 = @connect_db();
            if ($conn2) {
                $insQ = $conn2->prepare('INSERT INTO publication_queue (publication_id, project_id, user_id, page_url, status) VALUES (?, ?, ?, ?, \'queued\')');
                if ($insQ) {
                    $insQ->bind_param('iiis', $publicationId, $projectId, $userId, $targetUrl);
                    @$insQ->execute();
                    $insQ->close();
                }
                $conn2->close();
            }
        } catch (Throwable $e) {
            // ignore queue errors
        }
        return true;
    }
}

if (!function_exists('pp_promotion_generate_anchor')) {
    function pp_promotion_generate_anchor(string $baseAnchor): string {
        $base = trim($baseAnchor);
        if ($base === '') { return __('Подробнее'); }
        $suffixes = ['обзор', 'подробнее', 'инструкция', 'руководство', 'разбор'];
        try { $suffix = $suffixes[random_int(0, count($suffixes)-1)]; } catch (Throwable $e) { $suffix = $suffixes[0]; }
        if (mb_strlen($base, 'UTF-8') > 40) {
            $base = trim(mb_substr($base, 0, 35, 'UTF-8'));
        }
        return $base . ' — ' . $suffix;
    }
}

if (!function_exists('pp_promotion_process_run')) {
    function pp_promotion_process_run(mysqli $conn, array $run): void {
        $runId = (int)$run['id'];
        $projectId = (int)$run['project_id'];
        $stage = (string)$run['stage'];
        $status = (string)$run['status'];
        if ($status === 'cancelled' || $status === 'failed' || $status === 'completed') { return; }
        $project = pp_promotion_fetch_project($conn, $projectId);
        if (!$project) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='PROJECT_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        $requirements = pp_promotion_get_level_requirements();
        // Load link row
        $linkRow = null;
        $stmt = $conn->prepare('SELECT * FROM project_links WHERE id = ? LIMIT 1');
        if ($stmt) {
            $linkId = (int)$run['link_id'];
            $stmt->bind_param('i', $linkId);
            if ($stmt->execute()) { $linkRow = $stmt->get_result()->fetch_assoc(); }
            $stmt->close();
        }
        if (!$linkRow) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LINK_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_level1') {
            $req = $requirements[1];
            $count = (int)$req['count'];
            $usage = [];
            $nets = pp_promotion_pick_networks(1, $count, $project, $usage);
            if (empty($nets)) {
                pp_promotion_log('promotion.level1.networks_missing', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'requested' => $count,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='NO_NETWORKS_L1', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $selectedSlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
            $usageSnapshot = [];
            foreach ($selectedSlugs as $slug) {
                if ($slug === '') { continue; }
                $usageSnapshot[$slug] = (int)($usage[$slug] ?? 0);
            }
            pp_promotion_log('promotion.level1.networks_selected', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'target_url' => $run['target_url'],
                'requested' => $count,
                'selected' => $selectedSlugs,
                'usage' => $usageSnapshot,
                'region' => $project['region'] ?? null,
                'topic' => $project['topic'] ?? null,
            ]);
            $created = 0;
            foreach ($nets as $net) {
                $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                if ($stmt) {
                    $anchor = (string)($linkRow['anchor'] ?? '');
                    if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                    $initiated = (int)$run['initiated_by'];
                    $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                    if ($stmt->execute()) { $created++; }
                    $stmt->close();
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level1_active', status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . $runId . " LIMIT 1");
            $res = @$conn->query('SELECT * FROM promotion_nodes WHERE run_id = ' . $runId . ' AND level = 1 AND status = \'pending\'');
            if ($res) {
                while ($node = $res->fetch_assoc()) {
                    $node['parent_url'] = $run['target_url'];
                    $node['initiated_by'] = $run['initiated_by'];
                    $node['level'] = 1;
                    $node['target_url'] = $run['target_url'];
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, ['min_len' => $requirements[1]['min_len'], 'max_len' => $requirements[1]['max_len'], 'level' => 1]);
                }
                $res->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level1_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            $requiredLevel1 = max(1, (int)($requirements[1]['count'] ?? 1));
            if ($success < $requiredLevel1) {
                $needed = $requiredLevel1 - $success;
                $usage = [];
                if ($usageRes = @$conn->query('SELECT network_slug, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY network_slug')) {
                    while ($u = $usageRes->fetch_assoc()) {
                        $slug = (string)($u['network_slug'] ?? '');
                        if ($slug === '') { continue; }
                        $usage[$slug] = (int)($u['c'] ?? 0);
                    }
                    $usageRes->free();
                }
                $netsRetry = pp_promotion_pick_networks(1, $needed, $project, $usage);
                if (empty($netsRetry)) {
                    pp_promotion_log('promotion.level1.retry_exhausted', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'target_url' => $run['target_url'],
                        'needed' => $needed,
                        'success' => $success,
                        'failed' => $failed,
                    ]);
                    @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSUFFICIENT_SUCCESS', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    return;
                }
                $retrySlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $netsRetry);
                pp_promotion_log('promotion.level1.retry_scheduled', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'needed' => $needed,
                    'selected' => $retrySlugs,
                ]);
                $newNodeIds = [];
                foreach ($netsRetry as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = (string)($linkRow['anchor'] ?? '');
                        if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) {
                            $newNodeIds[] = (int)$conn->insert_id;
                        }
                        $stmt->close();
                    }
                }
                if (!empty($newNodeIds)) {
                    $idsList = implode(',', array_map('intval', $newNodeIds));
                    if ($idsList !== '') {
                        $sql = 'SELECT * FROM promotion_nodes WHERE id IN (' . $idsList . ')';
                        if ($resNew = @$conn->query($sql)) {
                            while ($node = $resNew->fetch_assoc()) {
                                $node['parent_url'] = $run['target_url'];
                                $node['initiated_by'] = $run['initiated_by'];
                                $node['level'] = 1;
                                $node['target_url'] = $run['target_url'];
                                pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, ['min_len' => $requirements[1]['min_len'], 'max_len' => $requirements[1]['max_len'], 'level' => 1]);
                            }
                            $resNew->free();
                        }
                    }
                    pp_promotion_update_progress($conn, $runId);
                    return;
                }
                // fallback if no nodes were created
                pp_promotion_log('promotion.level1.retry_insert_failed', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'needed' => $needed,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSERT_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if (!pp_promotion_is_level_enabled(2)) {
                @$conn->query("UPDATE promotion_runs SET stage='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_level2' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_level2') {
            $perParent = (int)$requirements[2]['per_parent'];
            $nodesL1 = [];
            $res = @$conn->query('SELECT id, result_url, anchor_text FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 AND status IN (\'success\',\'completed\')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $nodesL1[] = $row; }
                }
                $res->free();
            }
            if (empty($nodesL1)) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_NO_URL', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $usage = [];
            $created = 0;
            foreach ($nodesL1 as $parentNode) {
                $nets = pp_promotion_pick_networks(2, $perParent, $project, $usage);
                if (empty($nets)) { continue; }
                $selectedSlugsL2 = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
                $usageSnapshotL2 = [];
                foreach ($selectedSlugsL2 as $slug) {
                    if ($slug === '') { continue; }
                    $usageSnapshotL2[$slug] = (int)($usage[$slug] ?? 0);
                }
                pp_promotion_log('promotion.level2.networks_selected', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'parent_node_id' => (int)$parentNode['id'],
                    'target_url' => $parentNode['result_url'],
                    'requested' => $perParent,
                    'selected' => $selectedSlugsL2,
                    'usage' => $usageSnapshotL2,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                foreach ($nets as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 2, ?, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = pp_promotion_generate_anchor((string)$linkRow['anchor']);
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentNode['id'], $parentNode['result_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) { $created++; }
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level2_active' WHERE id=" . $runId . " LIMIT 1");
            $res2 = @$conn->query('SELECT n.*, p.result_url AS parent_url FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=2 AND n.status=\'pending\'');
            if ($res2) {
                while ($node = $res2->fetch_assoc()) {
                    $node['initiated_by'] = $run['initiated_by'];
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, ['min_len' => $requirements[2]['min_len'], 'max_len' => $requirements[2]['max_len'], 'level' => 2, 'parent_url' => $node['parent_url']]);
                }
                $res2->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level2_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL2_FAILED', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_crowd') {
            if (!pp_promotion_is_crowd_enabled()) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdPerArticle = (int)pp_promotion_settings()['crowd_per_article'];
            if ($crowdPerArticle <= 0) { $crowdPerArticle = 100; }
            $level2 = [];
            if ($res = @$conn->query('SELECT id, result_url FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 AND status IN (\'success\',\'completed\')')) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $level2[] = $row; }
                }
                $res->free();
            }
            if (empty($level2)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready' WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            try {
                $connCrowd = @connect_db();
            } catch (Throwable $e) { $connCrowd = null; }
            $crowdIds = [];
            if ($connCrowd) {
                $sql = "SELECT id, url FROM crowd_links WHERE deep_status='success' ORDER BY RAND() LIMIT " . max(1000, $crowdPerArticle * count($level2));
                if ($res = @$connCrowd->query($sql)) {
                    while ($row = $res->fetch_assoc()) {
                        $crowdIds[] = $row;
                    }
                    $res->free();
                }
                $connCrowd->close();
            }
            $index = 0; $totalLinks = count($crowdIds);
            foreach ($level2 as $node) {
                for ($i = 0; $i < $crowdPerArticle; $i++) {
                    if ($totalLinks === 0) { break; }
                    $chosen = $crowdIds[$index % $totalLinks];
                    $index++;
                    $stmt = $conn->prepare('INSERT INTO promotion_crowd_tasks (run_id, node_id, crowd_link_id, target_url, status) VALUES (?, ?, ?, ?, \'planned\')');
                    if ($stmt) {
                        $stmt->bind_param('iiis', $runId, $node['id'], $chosen['id'], $node['result_url']);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='crowd_ready' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'crowd_ready') {
            @$conn->query("UPDATE promotion_runs SET stage='report_ready' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'report_ready') {
            $report = pp_promotion_build_report($conn, $runId);
            $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($reportJson === false) { $reportJson = '{}'; }
            @$conn->query("UPDATE promotion_runs SET status='completed', stage='completed', report_json='" . $conn->real_escape_string($reportJson) . "', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
    }
}

if (!function_exists('pp_promotion_worker')) {
    function pp_promotion_worker(?int $specificRunId = null, int $maxIterations = 20): void {
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        pp_promotion_log('promotion.worker.start', [
            'specific_run_id' => $specificRunId,
            'max_iterations' => $maxIterations,
        ]);
        try { $conn = @connect_db(); } catch (Throwable $e) {
            pp_promotion_log('promotion.worker.db_error', ['error' => $e->getMessage()]);
            return;
        }
        if (!$conn) {
            pp_promotion_log('promotion.worker.db_unavailable', []);
            return;
        }
        for ($i = 0; $i < $maxIterations; $i++) {
            $run = null;
            if ($specificRunId) {
                $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $specificRunId);
                    if ($stmt->execute()) { $run = $stmt->get_result()->fetch_assoc(); }
                    $stmt->close();
                }
                $specificRunId = null;
            } else {
                $sql = "SELECT * FROM promotion_runs WHERE status IN ('queued','running','level1_active','level2_active','pending_level2','pending_crowd','crowd_ready','report_ready') ORDER BY id ASC LIMIT 1";
                if ($res = @$conn->query($sql)) {
                    $run = $res->fetch_assoc();
                    $res->free();
                }
            }
            if (!$run) { break; }
            pp_promotion_process_run($conn, $run);
            pp_promotion_update_progress($conn, (int)$run['id']);
            usleep(200000);
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_handle_publication_update')) {
    function pp_promotion_handle_publication_update(int $publicationId, string $status, ?string $postUrl, ?string $error): void {
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
        $stmt = $conn->prepare('SELECT run_id, id FROM promotion_nodes WHERE publication_id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $publicationId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return; }
        $node = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$node) { $conn->close(); return; }
        $nodeId = (int)$node['id'];
        $runId = (int)$node['run_id'];
        $now = date('Y-m-d H:i:s');
        $statusUpdate = in_array($status, ['success','partial'], true) ? 'success' : ($status === 'failed' ? 'failed' : $status);
        $stmt2 = $conn->prepare('UPDATE promotion_nodes SET status=?, result_url=?, error=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($stmt2) {
            $url = $postUrl ?: '';
            $err = $error ?: null;
            $stmt2->bind_param('sssi', $statusUpdate, $url, $err, $nodeId);
            $stmt2->execute();
            $stmt2->close();
        }
        pp_promotion_update_progress($conn, $runId);
        pp_promotion_log('promotion.publication_update', [
            'run_id' => $runId,
            'node_id' => $nodeId,
            'publication_id' => $publicationId,
            'new_status' => $statusUpdate,
            'original_status' => $status,
            'post_url' => $postUrl,
            'error' => $error,
        ]);
        $conn->close();
    }
}

if (!function_exists('pp_promotion_get_status')) {
    function pp_promotion_get_status(int $projectId, string $url): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $stmt->bind_param('is', $projectId, $url);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $run = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$run) { $conn->close(); return ['ok' => true, 'status' => 'idle']; }
        $runId = (int)$run['id'];

        $settingsSnapshot = [];
        if (!empty($run['settings_snapshot'])) {
            $decoded = json_decode((string)$run['settings_snapshot'], true);
            if (is_array($decoded)) { $settingsSnapshot = $decoded; }
        }
        $requirements = pp_promotion_get_level_requirements();
        $level1Required = isset($settingsSnapshot['level1_count']) ? (int)$settingsSnapshot['level1_count'] : (int)($requirements[1]['count'] ?? 5);
        if ($level1Required <= 0) { $level1Required = (int)($requirements[1]['count'] ?? 5); }
        $level2PerParent = isset($settingsSnapshot['level2_per_level1']) ? (int)$settingsSnapshot['level2_per_level1'] : (int)($requirements[2]['per_parent'] ?? 0);
        if ($level2PerParent < 0) { $level2PerParent = 0; }

        $levels = [
            1 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $level1Required],
            2 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0],
        ];
        if ($res = @$conn->query('SELECT level, status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' GROUP BY level, status')) {
            while ($row = $res->fetch_assoc()) {
                $lvl = (int)$row['level'];
                if (!isset($levels[$lvl])) {
                    $levels[$lvl] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0];
                }
                $count = (int)$row['c'];
                $levels[$lvl]['attempted'] += $count;
                $statusNode = (string)$row['status'];
                if (in_array($statusNode, ['success','completed'], true)) { $levels[$lvl]['success'] += $count; }
                elseif (in_array($statusNode, ['failed','cancelled'], true)) { $levels[$lvl]['failed'] += $count; }
            }
            $res->free();
        }
        $level1Success = (int)($levels[1]['success'] ?? 0);
        $levels[1]['total'] = $level1Success;
        if (!isset($levels[1]['required']) || $levels[1]['required'] <= 0) {
            $levels[1]['required'] = $level1Required;
        }
        $expectedLevel2 = 0;
        if ($level2PerParent > 0 && $level1Success > 0) {
            $expectedLevel2 = $level2PerParent * $level1Success;
        }
        if (!isset($levels[2])) {
            $levels[2] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $expectedLevel2];
        }
        $levels[2]['total'] = (int)($levels[2]['success'] ?? 0);
        $levels[2]['required'] = $expectedLevel2;
        foreach ($levels as $lvl => &$info) {
            if (!isset($info['attempted'])) { $info['attempted'] = $info['success'] + $info['failed']; }
            if (!isset($info['required']) || $info['required'] < 0) { $info['required'] = 0; }
            $info['failed'] = (int)$info['failed'];
            $info['success'] = (int)$info['success'];
            $info['total'] = (int)$info['total'];
            $info['attempted'] = (int)$info['attempted'];
        }
        unset($info);
        $crowdCount = 0;
        if ($res = @$conn->query('SELECT COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=' . $runId)) {
            if ($row = $res->fetch_assoc()) { $crowdCount = (int)$row['c']; }
            $res->free();
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$run['status'],
            'stage' => (string)$run['stage'],
            'progress' => ['done' => (int)$run['progress_done'], 'total' => (int)$run['progress_total'], 'target' => $level1Required],
            'levels' => $levels,
            'crowd' => ['planned' => $crowdCount],
            'run_id' => $runId,
            'report_ready' => !empty($run['report_json']) || $run['status'] === 'completed',
            'charge' => [
                'amount' => (float)$run['charged_amount'],
                'discount_percent' => (float)$run['discount_percent'],
            ],
            'charged_amount' => (float)$run['charged_amount'],
            'discount_percent' => (float)$run['discount_percent'],
        ];
    }
}

if (!function_exists('pp_promotion_build_report')) {
    function pp_promotion_build_report(mysqli $conn, int $runId): array {
        $report = ['level1' => [], 'level2' => [], 'crowd' => []];
        if ($res = @$conn->query('SELECT id, parent_id, level, network_slug, result_url, status, anchor_text, target_url FROM promotion_nodes WHERE run_id=' . $runId . ' ORDER BY level ASC, id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)($row['status'] ?? '');
                if (!in_array($status, ['success', 'completed'], true)) {
                    continue;
                }
                $entry = [
                    'id' => (int)$row['id'],
                    'parent_id' => isset($row['parent_id']) ? (int)$row['parent_id'] : null,
                    'network' => (string)$row['network_slug'],
                    'url' => (string)$row['result_url'],
                    'status' => $status,
                    'anchor' => (string)$row['anchor_text'],
                    'target_url' => (string)$row['target_url'],
                ];
                if ((int)$row['level'] === 1) { $report['level1'][] = $entry; }
                elseif ((int)$row['level'] === 2) { $report['level2'][] = $entry; }
            }
            $res->free();
        }
        if ($res = @$conn->query('SELECT crowd_link_id, target_url FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' ORDER BY id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $report['crowd'][] = ['crowd_link_id' => (int)$row['crowd_link_id'], 'target_url' => (string)$row['target_url']];
            }
            $res->free();
        }
        return $report;
    }
}

if (!function_exists('pp_promotion_get_report')) {
    function pp_promotion_get_report(int $runId): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $stmt = $conn->prepare('SELECT project_id, target_url, status, report_json FROM promotion_runs WHERE id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $stmt->bind_param('i', $runId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { $conn->close(); return ['ok' => false, 'error' => 'NOT_FOUND']; }
        $report = [];
        if (!empty($row['report_json'])) {
            $decoded = json_decode((string)$row['report_json'], true);
            if (is_array($decoded)) { $report = $decoded; }
        }
        if (empty($report)) {
            $report = pp_promotion_build_report($conn, $runId);
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$row['status'],
            'project_id' => (int)$row['project_id'],
            'target_url' => (string)$row['target_url'],
            'report' => $report,
        ];
    }
}

?>
