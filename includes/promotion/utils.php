<?php
require_once __DIR__ . '/../promotion_helpers.php';
require_once __DIR__ . '/../notifications.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../publication_queue.php';

require_once __DIR__ . '/settings.php';

if (!function_exists('pp_promotion_normalize_language_code')) {
    function pp_promotion_normalize_language_code(?string $code, string $fallback = 'ru'): string {
        $fallback = strtolower(trim($fallback));
        if ($fallback === '' || $fallback === 'auto') { $fallback = 'ru'; }

        $normalized = strtolower(trim((string)$code));
        if ($normalized === '' || $normalized === 'auto') {
            return $fallback;
        }

        $normalized = str_replace('_', '-', $normalized);
        if (strpos($normalized, '-') !== false) {
            $normalized = strtok($normalized, '-');
        }

        $normalized = preg_replace('~[^a-z]~', '', $normalized ?? '') ?? '';
        if ($normalized === '') {
            return $fallback;
        }

        if (strlen($normalized) < 2 || strlen($normalized) > 3) {
            $normalized = substr($normalized, 0, 3);
        }

        if ($normalized === '') {
            return $fallback;
        }

        return $normalized;
    }
}

if (!function_exists('pp_promotion_resolve_language')) {
    function pp_promotion_resolve_language(array $linkRow, array $project, string $fallback = 'ru'): string {
        $projectLang = pp_promotion_normalize_language_code($project['language'] ?? null, $fallback);
        return pp_promotion_normalize_language_code($linkRow['language'] ?? null, $projectLang);
    }
}

if (!function_exists('pp_promotion_get_max_active_runs_per_project')) {
    function pp_promotion_get_max_active_runs_per_project(): int {
        $default = 1;
        $configured = (int)get_setting('promotion_max_active_runs_per_project', (string)$default);
        if ($configured < 1) { $configured = 1; }
        $global = pp_get_max_concurrent_jobs();
        if ($configured > $global) { $configured = $global; }
        return $configured;
    }
}

if (!function_exists('pp_promotion_launch_worker')) {
    function pp_promotion_launch_worker(?int $runId = null, bool $allowFallback = true): bool {
        $script = PP_ROOT_PATH . '/scripts/promotion_worker.php';
        $inlineIterations = $runId ? 45 : 18;

        if (!is_file($script)) {
            pp_promotion_log('promotion.worker.script_missing', ['script' => $script, 'run_id' => $runId]);
            if ($allowFallback && function_exists('pp_promotion_trigger_worker_inline')) {
                try {
                    pp_promotion_trigger_worker_inline($runId, $inlineIterations);
                    pp_promotion_log('promotion.worker.inline_fallback_used', [
                        'run_id' => $runId,
                        'iterations' => $inlineIterations,
                        'reason' => 'script_missing',
                    ]);
                    return true;
                } catch (Throwable $e) {
                    pp_promotion_log('promotion.worker.inline_fallback_error', [
                        'run_id' => $runId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return false;
        }

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
            pp_promotion_log('promotion.worker.launched', [
                'run_id' => $runId,
                'script' => $script,
                'mode' => $isWindows ? 'windows_popen' : 'posix_background',
            ]);
            if ($allowFallback && function_exists('pp_promotion_trigger_worker_inline')) {
                try {
                    pp_promotion_trigger_worker_inline($runId, max(6, (int)ceil($inlineIterations / 2)));
                } catch (Throwable $e) {
                    pp_promotion_log('promotion.worker.inline_assist_error', [
                        'run_id' => $runId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return true;
        }

        pp_promotion_log('promotion.worker.launch_failed', [
            'run_id' => $runId,
            'script' => $script,
            'mode' => $isWindows ? 'windows_popen' : 'posix_background',
        ]);

        if ($allowFallback && function_exists('pp_promotion_trigger_worker_inline')) {
            try {
                pp_promotion_trigger_worker_inline($runId, $inlineIterations);
                pp_promotion_log('promotion.worker.inline_fallback_used', [
                    'run_id' => $runId,
                    'iterations' => $inlineIterations,
                    'reason' => 'launch_failed',
                ]);
                return true;
            } catch (Throwable $e) {
                pp_promotion_log('promotion.worker.inline_fallback_error', [
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }
}

if (!function_exists('pp_promotion_fetch_project')) {
    function pp_promotion_fetch_project(mysqli $conn, int $projectId): ?array {
        $stmt = $conn->prepare('SELECT id, user_id, name, language, wishes, region, topic FROM projects WHERE id = ? LIMIT 1');
        if (!$stmt) { return null; }
        $stmt->bind_param('i', $projectId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('pp_promotion_send_completion_notification')) {
    function pp_promotion_send_completion_notification(mysqli $conn, array $run, array $project, array $linkRow, array $report = []): void {
        $userId = isset($project['user_id']) ? (int)$project['user_id'] : 0;
        if ($userId <= 0) {
            return;
        }

        if (!function_exists('pp_notification_user_allows') || !pp_notification_user_allows($userId, 'promotion_completed')) {
            pp_promotion_log('promotion.notify.skipped_pref', [
                'run_id' => $run['id'] ?? null,
                'user_id' => $userId,
            ]);
            return;
        }

        $userStmt = $conn->prepare('SELECT id, email, full_name, username FROM users WHERE id = ? LIMIT 1');
        if (!$userStmt) {
            pp_promotion_log('promotion.notify.skipped_user_stmt', [
                'run_id' => $run['id'] ?? null,
                'user_id' => $userId,
                'error' => $conn->error,
            ]);
            return;
        }
        $userStmt->bind_param('i', $userId);
        if (!$userStmt->execute()) {
            pp_promotion_log('promotion.notify.skipped_user_exec', [
                'run_id' => $run['id'] ?? null,
                'user_id' => $userId,
                'error' => $userStmt->error,
            ]);
            $userStmt->close();
            return;
        }
        $userRow = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!$userRow) {
            pp_promotion_log('promotion.notify.skipped_user_missing', [
                'run_id' => $run['id'] ?? null,
                'user_id' => $userId,
            ]);
            return;
        }

        $email = trim((string)($userRow['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pp_promotion_log('promotion.notify.skipped_invalid_email', [
                'run_id' => $run['id'] ?? null,
                'user_id' => $userId,
                'email' => $userRow['email'] ?? null,
            ]);
            return;
        }

        $name = trim((string)($userRow['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($userRow['username'] ?? ''));
        }
        if ($name === '') {
            $name = __('клиент');
        }

        $projectName = trim((string)($project['name'] ?? ''));
        if ($projectName === '') {
            $projectName = __('Ваш проект');
        }
        $projectNameSafe = htmlspecialchars($projectName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $linkUrl = trim((string)($run['target_url'] ?? ($linkRow['url'] ?? '')));
        $linkDisplay = $linkUrl !== '' ? $linkUrl : __('ссылка не указана');
        $linkDisplaySafe = htmlspecialchars($linkDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkAnchor = trim((string)($linkRow['anchor'] ?? ''));
        $linkAnchorSafe = htmlspecialchars($linkAnchor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $projectId = isset($project['id']) ? (int)$project['id'] : 0;
        $runId = isset($run['id']) ? (int)$run['id'] : 0;
        $linkId = isset($run['link_id']) ? (int)$run['link_id'] : (isset($linkRow['id']) ? (int)$linkRow['id'] : 0);

        $projectUrl = pp_url('client/project.php?id=' . $projectId);
        $reportUrl = $projectUrl;
        if ($linkId > 0) {
            $reportUrl .= '#link-' . $linkId;
        }
        $reportUrlSafe = htmlspecialchars($reportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $notificationsUrl = pp_url('client/settings.php#notifications-settings');
        $notificationsUrlSafe = htmlspecialchars($notificationsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $supportEmail = trim((string)get_setting('support_email', 'support@' . pp_mail_default_domain()));
        if (!filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $supportEmail = 'support@' . pp_mail_default_domain();
        }
        $supportEmailSafe = htmlspecialchars($supportEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $greeting = sprintf(__('Здравствуйте, %s!'), htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $levelSummaries = [];
        $reportLevels = [
            1 => __('Уровень 1'),
            2 => __('Уровень 2'),
            3 => __('Уровень 3'),
        ];
        foreach ($reportLevels as $level => $label) {
            $items = $report['level' . $level] ?? [];
            if (!is_array($items)) { $items = []; }
            $count = count($items);
            if ($count > 0) {
                $levelSummaries[] = htmlspecialchars(sprintf('%s — %d', $label, $count), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $crowdCount = 0;
        if (isset($report['crowd']) && is_array($report['crowd'])) {
            $crowdCount = count($report['crowd']);
            if ($crowdCount > 0) {
                $levelSummaries[] = htmlspecialchars(sprintf(__('Крауд-задачи — %d'), $crowdCount), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        if (empty($levelSummaries)) {
            $levelSummaries[] = htmlspecialchars(__('Задачи: успешно завершены.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $summaryList = '<ul style="margin:16px 0 0;padding-left:20px;color:#0f172a;font-size:14px;line-height:1.6;">'
            . '<li>' . htmlspecialchars(__('Проект:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' <strong>' . $projectNameSafe . '</strong></li>';
        if ($linkUrl !== '') {
            $summaryList .= '<li>' . htmlspecialchars(__('Ссылка:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' <a href="' . htmlspecialchars($linkUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color:#2563eb;">' . $linkDisplaySafe . '</a></li>';
        } else {
            $summaryList .= '<li>' . htmlspecialchars(__('Ссылка:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' ' . $linkDisplaySafe . '</li>';
        }
        if ($linkAnchor !== '') {
            $summaryList .= '<li>' . htmlspecialchars(__('Анкор:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' <strong>' . $linkAnchorSafe . '</strong></li>';
        }
        foreach ($levelSummaries as $entry) {
            $summaryList .= '<li>' . $entry . '</li>';
        }
        $summaryList .= '</ul>';

        $logoSrc = pp_url('assets/img/logo.svg');
        $logoPath = defined('PP_ROOT_PATH') ? PP_ROOT_PATH . '/assets/img/logo.svg' : null;
        if ($logoPath && is_readable($logoPath)) {
            $logoContent = @file_get_contents($logoPath);
            if ($logoContent !== false && $logoContent !== '') {
                $encodedLogo = base64_encode($logoContent);
                if ($encodedLogo !== '') {
                    $logoSrc = 'data:image/svg+xml;base64,' . $encodedLogo;
                }
            }
        }
        $logoSrcSafe = htmlspecialchars($logoSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $intro = __('Все уровни задач успешно выполнены. Отчет готов — можете просмотреть размещения и крауд-задачи.');
        $introSafe = htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $subject = '🚀 ' . sprintf(__('Продвижение завершено — %s'), $projectName);

        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>'
            . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<div style="padding:32px 0;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(15,23,42,0.12);">'
            . '<tr><td style="padding:28px 32px;background:#0f172a;">'
            . '<div style="display:flex;align-items:center;gap:16px;">'
            . '<img src="' . $logoSrcSafe . '" alt="PromoPilot" style="height:36px;">'
            . '<div style="color:#e2e8f0;font-size:18px;font-weight:600;">PromoPilot</div>'
            . '</div>'
            . '<div style="margin-top:24px;color:#cbd5f5;font-size:14px;line-height:1.6;">'
            . htmlspecialchars(__('Продвижение завершено успешно.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:32px 36px;">'
            . '<p style="margin:0 0 16px;color:#0f172a;font-size:16px;font-weight:600;">' . $greeting . '</p>'
            . '<p style="margin:0 0 24px;color:#1f2937;font-size:15px;line-height:1.7;">' . $introSafe . '</p>'
            . $summaryList
            . '<div style="margin-top:28px;text-align:center;">'
            . '<a href="' . $reportUrlSafe . '" style="display:inline-block;padding:14px 28px;background:#2563eb;color:#ffffff;font-weight:600;font-size:14px;border-radius:12px;text-decoration:none;">'
            . htmlspecialchars(__('Открыть отчет'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a>'
            . '</div>'
            . '<p style="margin:32px 0 0;color:#475569;font-size:13px;line-height:1.6;">'
            . htmlspecialchars(__('Не хотите получать такие письма?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' <a href="' . $notificationsUrlSafe . '" style="color:#2563eb;font-weight:600;text-decoration:none;">'
            . htmlspecialchars(__('Настроить уведомления'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</a></p>'
            . '<p style="margin:18px 0 0;color:#475569;font-size:13px;line-height:1.6;">'
            . htmlspecialchars(__('Вопросы? Напишите нам:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' <a href="mailto:' . $supportEmailSafe . '" style="color:#2563eb;font-weight:600;text-decoration:none;">'
            . $supportEmailSafe . '</a></p>'
            . '<p style="margin:24px 0 0;color:#111827;font-size:13px;font-weight:600;">'
            . htmlspecialchars(__('Команда PromoPilot ✈️'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>'
            . '</td></tr>'
            . '</table></div></body></html>';

        try {
            $sent = pp_mail_send($email, $subject, $html, null, ['user_id' => $userId]);
            pp_promotion_log($sent ? 'promotion.notify.sent' : 'promotion.notify.failed', [
                'run_id' => $runId,
                'user_id' => $userId,
                'email' => $email,
            ]);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.notify.exception', [
                'run_id' => $runId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

if (!function_exists('pp_promotion_normalize_target_key')) {
    function pp_promotion_normalize_target_key(?string $url): string {
        $candidate = trim((string)$url);
        if ($candidate === '') { return ''; }
        $candidate = preg_replace('~[#].*$~u', '', $candidate ?? '') ?? $candidate;
        $candidate = preg_replace('~[\s\r\n\t]+~u', ' ', $candidate ?? '') ?? $candidate;
        return strtolower($candidate);
    }
}

if (!function_exists('pp_promotion_pick_networks')) {
    function pp_promotion_pick_networks(int $level, int $count, array $project, array &$usage, ?string $currentTarget = null): array {
        $count = (int)$count;
        if ($count <= 0) { return []; }

        $networks = pp_get_networks(true, false);
        $region = strtolower(trim((string)($project['region'] ?? '')));
        $topic = strtolower(trim((string)($project['topic'] ?? '')));
        $levelStr = (string)$level;
        $usageLimit = (int)(pp_promotion_settings()['network_repeat_limit'] ?? 0);
        if ($usageLimit < 0) { $usageLimit = 0; }

        $targetKey = pp_promotion_normalize_target_key($currentTarget);
        if (!isset($usage['__targets']) || !is_array($usage['__targets'])) {
            $usage['__targets'] = [];
        }
        if ($targetKey !== '' && !isset($usage['__targets'][$targetKey])) {
            $usage['__targets'][$targetKey] = [];
        }

        $catalog = [];
        foreach ($networks as $net) {
            $levelsRaw = (string)($net['level'] ?? '');
            if ($levelsRaw !== '') {
                $levelsList = array_filter(array_map('trim', explode(',', $levelsRaw)));
                if (!in_array($levelStr, $levelsList, true)) { continue; }
            } elseif ($level !== 1) {
                continue;
            }
            $slug = (string)($net['slug'] ?? '');
            if ($slug === '') { continue; }
            if ($slug === '__targets') { continue; }
            if (!isset($usage[$slug]) || !is_numeric($usage[$slug])) { $usage[$slug] = 0; }
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
        $selectedSlugs = [];
        $allowRepeats = false;
        for ($i = 0; $i < $count; $i++) {
            $candidates = [];
            foreach ($catalog as $slug => $meta) {
                $used = (int)($usage[$slug] ?? 0);
                $perTargetUsed = 0;
                if ($targetKey !== '' && isset($usage['__targets'][$targetKey]) && is_array($usage['__targets'][$targetKey])) {
                    $perTargetUsed = (int)($usage['__targets'][$targetKey][$slug] ?? 0);
                }
                if ($usageLimit > 0 && $perTargetUsed >= $usageLimit) { continue; }
                if (!$allowRepeats && $usageLimit > 0 && $used >= $usageLimit) { continue; }
                if (!$allowRepeats && isset($selectedSlugs[$slug])) { continue; }
                $score = $meta['baseScore'];
                if ($used > 0) { $score -= $used * 250; }
                if ($allowRepeats && $usageLimit > 0 && $used >= $usageLimit) {
                    $score -= 1200 + ($used * 150);
                }
                try {
                    $score += random_int(0, 250);
                } catch (Throwable $e) {
                    $score += mt_rand(0, 250);
                }
                $candidates[] = ['slug' => $slug, 'score' => $score, 'network' => $meta['network']];
            }
            if (empty($candidates)) {
                if (!$allowRepeats && $usageLimit > 0) {
                    $allowRepeats = true;
                    $i--;
                    continue;
                }
                break;
            }
            usort($candidates, static function(array $a, array $b) {
                if ($a['score'] === $b['score']) { return strcmp($a['slug'], $b['slug']); }
                return $a['score'] < $b['score'] ? 1 : -1;
            });
            $choice = $candidates[0];
            $selected[] = $choice['network'];
            $selectedSlugs[$choice['slug']] = true;
            $usage[$choice['slug']] = (int)($usage[$choice['slug']] ?? 0) + 1;
            if ($targetKey !== '') {
                if (!isset($usage['__targets'][$targetKey][$choice['slug']])) {
                    $usage['__targets'][$targetKey][$choice['slug']] = 0;
                }
                $usage['__targets'][$targetKey][$choice['slug']]++;
            }
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
        $level = isset($node['level']) ? (int)$node['level'] : 1;
        $language = pp_promotion_resolve_language($linkRow, $project);
        $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
        $failNode = static function(string $reason, array $extra = []) use ($conn, $runId, $projectId, $nodeId, $networkSlug, $targetUrl, $node) : bool {
            if ($nodeId > 0) {
                $failStmt = $conn->prepare("UPDATE promotion_nodes SET status='failed', error=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
                if ($failStmt) {
                    $failStmt->bind_param('si', $reason, $nodeId);
                    $failStmt->execute();
                    $failStmt->close();
                }
            }
            $payload = [
                'run_id' => $runId,
                'project_id' => $projectId,
                'node_id' => $nodeId ?: null,
                'level' => isset($node['level']) ? (int)$node['level'] : null,
                'network' => $networkSlug,
                'target_url' => $targetUrl,
                'error' => $reason,
            ];
            if (!empty($extra)) { $payload = array_merge($payload, $extra); }
            pp_promotion_log('promotion.publication_queue_failed', $payload);
            return false;
        };
        if ($level >= 2) {
            static $genericAnchorUsage = [];
            $usageKey = $projectId . '|' . $targetUrl;
            $avoidAnchors = $genericAnchorUsage[$usageKey] ?? [];
            if ($anchor !== '') { $avoidAnchors[] = $anchor; }
            $genericAnchor = pp_promotion_pick_generic_anchor($language, $avoidAnchors);
            if ($genericAnchor !== '') {
                $anchor = $genericAnchor;
                $genericAnchorUsage[$usageKey][] = $genericAnchor;
                if (!empty($node['id'])) {
                    $updateAnchorStmt = $conn->prepare('UPDATE promotion_nodes SET anchor_text=? WHERE id=? LIMIT 1');
                    if ($updateAnchorStmt) {
                        $nodeIdUpdate = (int)$node['id'];
                        $updateAnchorStmt->bind_param('si', $anchor, $nodeIdUpdate);
                        $updateAnchorStmt->execute();
                        $updateAnchorStmt->close();
                    }
                }
            }
        }
        $wish = (string)($linkRow['wish'] ?? $project['wishes'] ?? '');
        $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
        $jobPayload = [
            'article' => [
                'minLength' => $requirements['min_len'] ?? 2000,
                'maxLength' => $requirements['max_len'] ?? 3200,
                'level' => (int)$node['level'],
                'parentUrl' => $requirements['parent_url'] ?? ($node['parent_url'] ?? null),
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
                'language' => pp_promotion_normalize_language_code($project['language'] ?? null, $language),
                'resolvedLanguage' => $language,
            ],
            'network' => [
                'slug' => $networkSlug,
                'level' => (int)$node['level'],
            ],
        ];
        if ($nodeId > 0) {
            $jobPayload['article']['nodeId'] = $nodeId;
        }
        if (empty($jobPayload['article']['language'])) {
            $jobPayload['article']['language'] = $language;
        }
        $parentContext = $requirements['parent_context'] ?? null;
        if ($parentContext) {
            $parentContextCompact = pp_promotion_compact_context($parentContext);
            if ($parentContextCompact) {
                $jobPayload['article']['parentContext'] = $parentContextCompact;
            }
        }
        $ancestorTrailRaw = $requirements['ancestor_trail'] ?? [];
        if (is_array($ancestorTrailRaw) && !empty($ancestorTrailRaw)) {
            $trail = [];
            foreach ($ancestorTrailRaw as $item) {
                $compact = pp_promotion_compact_context($item);
                if ($compact) { $trail[] = $compact; }
                if (count($trail) >= 6) { break; }
            }
            if (!empty($trail)) {
                $jobPayload['article']['ancestorTrail'] = $trail;
            }
        }
        if (!empty($requirements['article_meta']) && is_array($requirements['article_meta'])) {
            foreach ($requirements['article_meta'] as $metaKey => $metaValue) {
                if (is_string($metaKey) && $metaKey !== '') {
                    $jobPayload['article'][$metaKey] = $metaValue;
                }
            }
        }
        if (!empty($requirements['prepared_language'])) {
            $preparedLanguage = pp_promotion_normalize_language_code($requirements['prepared_language'], $language);
            if ($preparedLanguage !== '') {
                $jobPayload['target']['language'] = $preparedLanguage;
                if (empty($jobPayload['article']['language'])) {
                    $jobPayload['article']['language'] = $preparedLanguage;
                }
            }
        }
        if (!empty($requirements['prepared_article']) && is_array($requirements['prepared_article'])) {
            $prepared = $requirements['prepared_article'];
            $preparedPayload = [
                'title' => (string)($prepared['title'] ?? ''),
                'htmlContent' => (string)($prepared['htmlContent'] ?? ''),
                'language' => (string)($prepared['language'] ?? ($jobPayload['target']['language'] ?? $language)),
            ];
            if (!empty($prepared['plainText'])) { $preparedPayload['plainText'] = (string)$prepared['plainText']; }
            if (!empty($prepared['linkStats']) && is_array($prepared['linkStats'])) { $preparedPayload['linkStats'] = $prepared['linkStats']; }
            if (!empty($prepared['author'])) { $preparedPayload['author'] = (string)$prepared['author']; }
            if (!empty($prepared['verificationSample'])) { $preparedPayload['verificationSample'] = (string)$prepared['verificationSample']; }
            $jobPayload['preparedArticle'] = $preparedPayload;
        }
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
        $publicationUuid = pp_generate_uuid_v4();
        if ($hasJobPayloadColumn) {
            $stmt = $conn->prepare("INSERT INTO publications (uuid, project_id, page_url, anchor, network, status, enqueued_by_user_id, job_payload) VALUES (?, ?, ?, ?, ?, 'queued', ?, ?)");
        } else {
            $stmt = $conn->prepare("INSERT INTO publications (uuid, project_id, page_url, anchor, network, status, enqueued_by_user_id) VALUES (?, ?, ?, ?, ?, 'queued', ?)");
        }
        if (!$stmt) {
            return $failNode('PUBLICATION_PREPARE_FAILED', ['db_error' => $conn->error]);
        }
        $userId = (int)$node['initiated_by'];
        if ($hasJobPayloadColumn) {
            $stmt->bind_param('sisssis', $publicationUuid, $projectId, $targetUrl, $anchor, $networkSlug, $userId, $payloadJson);
        } else {
            $stmt->bind_param('sisssi', $publicationUuid, $projectId, $targetUrl, $anchor, $networkSlug, $userId);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return $failNode('DB_INSERT_FAILED', ['db_error' => $conn->error]);
        }
        $publicationId = (int)$conn->insert_id;
        $stmt->close();
        $update = $conn->prepare("UPDATE promotion_nodes SET publication_id=?, status='queued', queued_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if (!$update) {
            return $failNode('NODE_LINK_PREPARE_FAILED', [
                'publication_id' => $publicationId,
                'db_error' => $conn->error,
            ]);
        }
        $update->bind_param('ii', $publicationId, $nodeId);
        if (!$update->execute()) {
            $update->close();
            return $failNode('NODE_LINK_UPDATE_FAILED', [
                'publication_id' => $publicationId,
                'db_error' => $update->error,
            ]);
        }
        $update->close();
        $preparedArticleLanguage = null;
        if (!empty($requirements['prepared_article']) && is_array($requirements['prepared_article'])) {
            if (isset($requirements['prepared_article']['language']) && $requirements['prepared_article']['language'] !== '') {
                $preparedArticleLanguage = (string)$requirements['prepared_article']['language'];
            }
        } elseif (!empty($jobPayload['preparedArticle']) && is_array($jobPayload['preparedArticle'])) {
            if (!empty($jobPayload['preparedArticle']['language'])) {
                $preparedArticleLanguage = (string)$jobPayload['preparedArticle']['language'];
            }
        }
        $languageDetail = [
            'resolved' => $language,
            'target' => $jobPayload['target']['language'] ?? null,
            'article' => $jobPayload['article']['language'] ?? null,
            'project' => $jobPayload['project']['language'] ?? null,
            'project_resolved' => $jobPayload['project']['resolvedLanguage'] ?? null,
            'prepared_language' => $requirements['prepared_language'] ?? null,
            'prepared_article_language' => $preparedArticleLanguage,
        ];
        pp_promotion_log('promotion.publication_queued', [
            'run_id' => $runId,
            'project_id' => $projectId,
            'node_id' => (int)$node['id'],
            'publication_id' => $publicationId,
            'publication_uuid' => $publicationUuid,
            'level' => (int)$node['level'],
            'network' => $networkSlug,
            'target_url' => $targetUrl,
            'anchor' => $anchor,
            'language' => $language,
            'language_detail' => $languageDetail,
            'job_payload_column' => $hasJobPayloadColumn,
            'requirements' => [
                'min_length' => $requirements['min_len'] ?? null,
                'max_length' => $requirements['max_len'] ?? null,
                'prepared_article' => !empty($requirements['prepared_article']),
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
                $insQ = $conn2->prepare("INSERT INTO publication_queue (job_uuid, publication_id, project_id, user_id, page_url, status) VALUES (?, ?, ?, ?, ?, 'queued')");
                if ($insQ) {
                    $insQ->bind_param('siiis', $publicationUuid, $publicationId, $projectId, $userId, $targetUrl);
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

if (!function_exists('pp_promotion_generate_contextual_anchor')) {
    function pp_promotion_generate_contextual_anchor(?array $context, string $fallbackAnchor): string {
        $prepareCandidate = static function($text, int $maxWords = 4): string {
            $clean = trim((string)$text);
            if ($clean === '') { return ''; }
            $clean = strip_tags($clean);
            $clean = preg_replace('~[«»“”„"‹›<>]+~u', '', $clean ?? '');
            $clean = preg_replace('~\s+~u', ' ', $clean ?? '');
            $clean = trim((string)$clean, " \t\n\r\0\x0B-–—:,.;!?");
            if ($clean === '') { return ''; }
            $wordsRaw = preg_split('~\s+~u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($wordsRaw) || empty($wordsRaw)) { return ''; }
            $words = [];
            foreach ($wordsRaw as $word) {
                $trimmed = preg_replace('~^[\p{P}\p{S}]+|[\p{P}\p{S}]+$~u', '', (string)$word);
                if ($trimmed === '') { continue; }
                $words[] = $trimmed;
            }
            if (!is_array($words) || empty($words)) { return ''; }
            $limit = max(1, min($maxWords, count($words)));
            if ($limit < 2 && count($words) >= 2) {
                $limit = min(2, count($words));
            }
            $slice = array_slice($words, 0, $limit);
            $candidate = implode(' ', $slice);
            if ($candidate === '') { return ''; }
            if (function_exists('mb_strlen')) {
                if (mb_strlen($candidate, 'UTF-8') > 64) {
                    $candidate = rtrim(mb_substr($candidate, 0, 64, 'UTF-8')) . '…';
                }
            } elseif (strlen($candidate) > 64) {
                $candidate = rtrim(substr($candidate, 0, 64)) . '…';
            }
            return $candidate;
        };

        $candidates = [];
        if (is_array($context)) {
            if (!empty($context['title'])) {
                $candidate = $prepareCandidate($context['title'], 4);
                if ($candidate !== '') { $candidates[] = $candidate; }
            }
            if (!empty($context['headings']) && is_array($context['headings'])) {
                foreach ($context['headings'] as $heading) {
                    $candidate = $prepareCandidate($heading, 4);
                    if ($candidate !== '') { $candidates[] = $candidate; }
                }
            }
            if (!empty($context['keywords']) && is_array($context['keywords'])) {
                $keywordChunk = $prepareCandidate(implode(' ', array_slice(array_map('strval', $context['keywords']), 0, 3)), 3);
                if ($keywordChunk !== '') { $candidates[] = $keywordChunk; }
                foreach (array_slice($context['keywords'], 0, 4) as $keyword) {
                    $candidate = $prepareCandidate($keyword, 3);
                    if ($candidate !== '') { $candidates[] = $candidate; }
                }
            }
            if (!empty($context['summary'])) {
                $sentences = preg_split('~(?<=[.!?])\s+~u', (string)$context['summary'], -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($sentences)) {
                    foreach ($sentences as $sentence) {
                        $candidate = $prepareCandidate($sentence, 4);
                        if ($candidate !== '') {
                            $candidates[] = $candidate;
                            break;
                        }
                    }
                }
            }
            if (!empty($context['excerpt'])) {
                $candidate = $prepareCandidate($context['excerpt'], 4);
                if ($candidate !== '') { $candidates[] = $candidate; }
            }
        }

        $fallbackAnchor = trim($fallbackAnchor);
        if ($fallbackAnchor !== '') {
            $fallbackCandidate = $prepareCandidate($fallbackAnchor, 3);
            $candidates[] = $fallbackCandidate !== '' ? $fallbackCandidate : $fallbackAnchor;
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if (empty($candidates)) {
            return __('Подробнее');
        }

        foreach ($candidates as $candidate) {
            $wordCount = count(preg_split('~\s+~u', $candidate, -1, PREG_SPLIT_NO_EMPTY));
            if ($wordCount >= 2 && $wordCount <= 5) {
                return $candidate;
            }
        }

        $choice = pp_promotion_random_choice($candidates, $candidates[0]);
        return $choice !== '' ? $choice : __('Подробнее');
    }
}

if (!function_exists('pp_promotion_generate_anchor')) {
    function pp_promotion_generate_anchor(string $baseAnchor): string {
        $base = trim($baseAnchor);
        if ($base === '') { return __('Подробнее'); }
        $suffixes = ['обзор', 'подробнее', 'инструкция', 'руководство', 'разбор'];
        try {
            $suffix = $suffixes[random_int(0, count($suffixes) - 1)];
        } catch (Throwable $e) {
            $suffix = $suffixes[0];
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($base, 'UTF-8') > 40) {
                $base = trim(mb_substr($base, 0, 35, 'UTF-8'));
            }
        } elseif (strlen($base) > 40) {
            $base = trim(substr($base, 0, 35));
        }
        return $base . ' — ' . $suffix;
    }
}

if (!function_exists('pp_promotion_random_choice')) {
    function pp_promotion_random_choice(array $items, $default = null) {
        if (empty($items)) {
            return $default;
        }
        $maxIndex = count($items) - 1;
        if ($maxIndex <= 0) {
            return reset($items);
        }
        try {
            $idx = random_int(0, $maxIndex);
        } catch (Throwable $e) {
            $idx = array_rand($items);
        }
        return $items[$idx] ?? $default;
    }
}

if (!function_exists('pp_promotion_make_email_slug')) {
    function pp_promotion_make_email_slug(string $name): string {
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($slug === false) {
            $slug = $name;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('~[^a-z0-9]+~', '.', $slug ?? '');
        $slug = trim((string)$slug, '.');
        if ($slug === '') {
            $slug = 'promo';
        }
        return preg_replace('~\.+~', '.', $slug);
    }
}
