<?php
// Publications queue helpers: limits, claiming jobs, processing, worker loop

if (!function_exists('pp_get_max_concurrent_jobs')) {
    function pp_get_max_concurrent_jobs(): int { $n = (int)get_setting('max_concurrent_jobs', 5); if ($n < 1) { $n = 1; } if ($n > 20) { $n = 20; } return $n; }
}
if (!function_exists('pp_get_min_job_spacing_ms')) {
    function pp_get_min_job_spacing_ms(): int { $ms = (int) get_setting('min_job_spacing_ms', 0); if ($ms < 0) $ms = 0; if ($ms > 60000) $ms = 60000; return $ms; }
}
if (!function_exists('pp_get_publication_timeout_seconds')) {
    function pp_get_publication_timeout_seconds(): int {
        $fallback = 900;
        $value = (int)get_setting('publication_timeout_seconds', (string)$fallback);
        if ($value < 300) { $value = 300; }
        if ($value > 7200) { $value = 7200; }
        return $value;
    }
}
if (!function_exists('pp_get_publication_max_attempts')) {
    function pp_get_publication_max_attempts(): int {
        $fallback = 3;
        $value = (int)get_setting('publication_max_attempts', (string)$fallback);
        if ($value < 1) { $value = 1; }
        if ($value > 10) { $value = 10; }
        return $value;
    }
}
if (!function_exists('pp_get_publication_max_per_project')) {
    function pp_get_publication_max_per_project(): int {
        $global = pp_get_max_concurrent_jobs();
        $default = max(1, min($global, 2));
        $value = (int)get_setting('publication_max_jobs_per_project', (string)$default);
        if ($value < 1) { $value = 1; }
        if ($value > $global) { $value = $global; }
        return $value;
    }
}
if (!function_exists('pp_release_stuck_publications')) {
    function pp_release_stuck_publications(?mysqli $sharedConn = null): array {
        $stats = ['released' => 0, 'failed' => 0, 'checked' => 0];
        try { $conn = $sharedConn instanceof mysqli ? $sharedConn : @connect_db(); } catch (Throwable $e) { return $stats; }
        if (!$conn) { return $stats; }
        $closeConn = !($sharedConn instanceof mysqli);
        $timeoutSeconds = pp_get_publication_timeout_seconds();
        $maxAttempts = pp_get_publication_max_attempts();
        $threshold = date('Y-m-d H:i:s', time() - $timeoutSeconds);
        $stmt = $conn->prepare("SELECT id, uuid, project_id, page_url, enqueued_by_user_id, attempts, started_at FROM publications WHERE status = 'running' AND started_at IS NOT NULL AND started_at < ? ORDER BY started_at ASC LIMIT 200");
        if ($stmt) {
            $stmt->bind_param('s', $threshold);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $stats['checked']++;
                    $pubId = (int)($row['id'] ?? 0);
                    if ($pubId <= 0) { continue; }
                    $uuid = trim((string)($row['uuid'] ?? ''));
                    if ($uuid === '') {
                        $uuid = pp_generate_uuid_v4();
                        $fix = $conn->prepare('UPDATE publications SET uuid = ? WHERE id = ? LIMIT 1');
                        if ($fix) { $fix->bind_param('si', $uuid, $pubId); @$fix->execute(); $fix->close(); }
                    }
                    $attempts = (int)($row['attempts'] ?? 0);
                    $startedAt = (string)($row['started_at'] ?? '');
                    $projectId = (int)($row['project_id'] ?? 0);
                    $pageUrl = (string)($row['page_url'] ?? '');
                    $userId = (int)($row['enqueued_by_user_id'] ?? 0);
                    if ($attempts >= $maxAttempts) {
                        $err = 'TIMEOUT_MAX_ATTEMPTS';
                        $failStmt = $conn->prepare("UPDATE publications SET status='failed', error=?, finished_at=CURRENT_TIMESTAMP, pid=NULL WHERE id = ? LIMIT 1");
                        if ($failStmt) { $failStmt->bind_param('si', $err, $pubId); $failStmt->execute(); $failStmt->close(); }
                        $queueFailStmt = $conn->prepare("UPDATE publication_queue SET status='failed', scheduled_at=NULL, job_uuid=? WHERE publication_id = ? LIMIT 1");
                        if ($queueFailStmt) {
                            $queueFailStmt->bind_param('si', $uuid, $pubId);
                            $queueFailStmt->execute();
                            $affected = $queueFailStmt->affected_rows;
                            $queueFailStmt->close();
                        } else {
                            $affected = 0;
                        }
                        if ($affected === 0) {
                            $insFail = $conn->prepare("INSERT INTO publication_queue (job_uuid, publication_id, project_id, user_id, page_url, status) VALUES (?, ?, ?, ?, ?, 'failed')");
                            if ($insFail) { $insFail->bind_param('siiis', $uuid, $pubId, $projectId, $userId, $pageUrl); @$insFail->execute(); $insFail->close(); }
                        }
                        $stats['failed']++;
                        pp_promotion_log('promotion.publication_timeout_failed', [
                            'publication_id' => $pubId,
                            'publication_uuid' => $uuid,
                            'project_id' => $projectId,
                            'attempts' => $attempts,
                            'max_attempts' => $maxAttempts,
                            'started_at' => $startedAt,
                            'timeout_seconds' => $timeoutSeconds,
                        ]);
                        continue;
                    }
                    $retryStmt = $conn->prepare("UPDATE publications SET status='queued', error=NULL, started_at=NULL, finished_at=NULL, pid=NULL WHERE id = ? LIMIT 1");
                    if ($retryStmt) { $retryStmt->bind_param('i', $pubId); $retryStmt->execute(); $retryStmt->close(); }
                    $queueStmt = $conn->prepare("UPDATE publication_queue SET status='queued', scheduled_at=NULL, job_uuid=? WHERE publication_id = ? LIMIT 1");
                    if ($queueStmt) {
                        $queueStmt->bind_param('si', $uuid, $pubId);
                        $queueStmt->execute();
                        $affected = $queueStmt->affected_rows;
                        $queueStmt->close();
                    } else {
                        $affected = 0;
                    }
                    if ($affected === 0) {
                        $insQueue = $conn->prepare("INSERT INTO publication_queue (job_uuid, publication_id, project_id, user_id, page_url, status) VALUES (?, ?, ?, ?, ?, 'queued')");
                        if ($insQueue) { $insQueue->bind_param('siiis', $uuid, $pubId, $projectId, $userId, $pageUrl); @$insQueue->execute(); $insQueue->close(); }
                    }
                    $stats['released']++;
                    pp_promotion_log('promotion.publication_timeout_requeued', [
                        'publication_id' => $pubId,
                        'publication_uuid' => $uuid,
                        'project_id' => $projectId,
                        'attempts' => $attempts,
                        'max_attempts' => $maxAttempts,
                        'started_at' => $startedAt,
                        'timeout_seconds' => $timeoutSeconds,
                    ]);
                }
                $res->free();
            }
            $stmt->close();
        }
        if ($closeConn) { $conn->close(); }
        return $stats;
    }
}
if (!function_exists('pp_count_running_jobs')) {
    function pp_count_running_jobs(): int {
        try { $conn = @connect_db(); } catch (Throwable $e) { return 0; }
        if (!$conn) return 0; $cnt = 0; if ($res = @$conn->query("SELECT COUNT(*) AS c FROM publications WHERE status = 'running'")) { if ($row = $res->fetch_assoc()) { $cnt = (int) $row['c']; } $res->free(); }
        $conn->close(); return $cnt;
    }
}
if (!function_exists('pp_claim_next_publication_job')) {
    function pp_claim_next_publication_job(): ?int {
        try { $conn = @connect_db(); } catch (Throwable $e) { return null; }
        if (!$conn) return null;
        $jobId = null;
        $selected = null;
        $maxPerProject = max(1, pp_get_publication_max_per_project());
        $runningCounts = [];
        if ($res = @$conn->query("SELECT project_id, COUNT(*) AS c FROM publications WHERE status = 'running' GROUP BY project_id")) {
            while ($row = $res->fetch_assoc()) {
                $runningCounts[(int)($row['project_id'] ?? 0)] = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
        $queuedSql = "SELECT id, project_id FROM publications WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP) ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC LIMIT 50";
        $skipped = [];
        if ($res = @$conn->query($queuedSql)) {
            while ($row = $res->fetch_assoc()) {
                $projectId = (int)($row['project_id'] ?? 0);
                if ($projectId > 0) {
                    $runningForProject = $runningCounts[$projectId] ?? 0;
                    if ($runningForProject >= $maxPerProject) {
                        $skipped[$projectId] = $runningForProject;
                        continue;
                    }
                    $runningCounts[$projectId] = $runningForProject + 1;
                }
                $selected = $row;
                break;
            }
            $res->free();
        }
        if (!$selected) {
            if (!empty($skipped)) {
                static $lastCapacityLog = 0;
                $now = time();
                if ($now - $lastCapacityLog > 30) {
                    $lastCapacityLog = $now;
                    pp_promotion_log('promotion.pubqueue.capacity_wait', [
                        'projects' => array_keys($skipped),
                        'max_per_project' => $maxPerProject,
                    ]);
                }
            }
            $conn->close();
            return null;
        }
        $id = (int)$selected['id'];
        $stmt = $conn->prepare("UPDATE publications SET status = 'running', started_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = ? AND status = 'queued' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $jobId = $id;
                @$conn->query('UPDATE publication_queue SET status=\'running\' WHERE publication_id = ' . (int)$jobId);
            }
            $stmt->close();
        }
        $conn->close();
        return $jobId;
    }
}

if (!function_exists('pp_process_publication_job')) {
    function pp_process_publication_job(int $pubId): void {
        if (function_exists('session_write_close')) { @session_write_close(); }
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) return;
            static $hasJobPayloadColumn = null;
            if ($hasJobPayloadColumn === null) {
                $hasJobPayloadColumn = false;
                if ($res = @$conn->query("SHOW COLUMNS FROM publications LIKE 'job_payload'")) {
                    if ($res->num_rows > 0) { $hasJobPayloadColumn = true; }
                    $res->free();
                }
            }
            $selectSql = 'SELECT p.id, p.uuid, p.project_id, p.page_url, p.anchor, p.network, p.post_url, p.status, pr.name AS project_name, pr.language AS project_language, pr.wishes AS project_wish';
            if ($hasJobPayloadColumn) { $selectSql .= ', p.job_payload'; }
            $selectSql .= ' FROM publications p JOIN projects pr ON pr.id = p.project_id WHERE p.id = ? LIMIT 1';
            $stmt = $conn->prepare($selectSql);
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $pubId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); if (!$row) { $conn->close(); return; }
        if (!in_array($row['status'], ['queued','running'], true)) { $conn->close(); return; }
        $normalizeLanguage = static function($value) {
            $candidate = trim((string)$value);
            if ($candidate === '') { return ''; }
            if (function_exists('pp_promotion_normalize_language_code')) {
                return pp_promotion_normalize_language_code($candidate, $candidate);
            }
            $candidate = strtolower(str_replace('_', '-', $candidate));
            if (strpos($candidate, '-') !== false) { $candidate = strtok($candidate, '-'); }
            $candidate = preg_replace('~[^a-z]~', '', $candidate) ?: $candidate;
            if (strlen($candidate) > 3) { $candidate = substr($candidate, 0, 3); }
            return $candidate;
        };

        $publicationUuid = trim((string)($row['uuid'] ?? ''));
        if ($publicationUuid === '') {
            if (function_exists('pp_generate_uuid_v4')) {
                $publicationUuid = pp_generate_uuid_v4();
                if ($publicationUuid !== '') {
                    if ($upd = $conn->prepare('UPDATE publications SET uuid = ? WHERE id = ? LIMIT 1')) {
                        $upd->bind_param('si', $publicationUuid, $pubId);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }
        }
        $projectId = (int)$row['project_id']; $url = (string)$row['page_url']; $anchor = trim((string)$row['anchor'] ?? ''); $networkSlug = trim((string)$row['network'] ?? '');
        $projectLanguageRaw = (string)($row['project_language'] ?? 'ru');
        $projectLanguageNorm = $normalizeLanguage($projectLanguageRaw);
        if ($projectLanguageNorm === '') { $projectLanguageNorm = 'ru'; }
        $projectWish = trim((string)($row['project_wish'] ?? '')); $projectName = trim((string)($row['project_name'] ?? ''));
        $linkLanguage = $projectLanguageNorm; $linkWish = $projectWish;
        if ($pl = $conn->prepare('SELECT anchor, language, wish FROM project_links WHERE project_id = ? AND url = ? LIMIT 1')) {
            $pl->bind_param('is', $projectId, $url); if ($pl->execute()) { $r = $pl->get_result()->fetch_assoc(); if ($r) { $a = trim((string)($r['anchor'] ?? '')); $l = trim((string)($r['language'] ?? '')); $w = trim((string)($r['wish'] ?? '')); if ($a !== '') { $anchor = $anchor !== '' ? $anchor : $a; } if ($l !== '') { $linkLanguage = $normalizeLanguage($l); } if ($w !== '') { $linkWish = $w; } } } $pl->close(); }
        if ($linkLanguage === '') { $linkLanguage = $projectLanguageNorm; }
        if ($linkLanguage === '') { $linkLanguage = 'ru'; }
        $linkLanguageInitial = $linkLanguage;
        $network = $networkSlug !== '' ? pp_get_network($networkSlug) : pp_pick_network(); if (!$network) { $err = 'NO_ENABLED_NETWORKS'; $up = $conn->prepare("UPDATE publications SET status='failed', finished_at=CURRENT_TIMESTAMP, error=? WHERE id = ? LIMIT 1"); if ($up) { $up->bind_param('si', $err, $pubId); $up->execute(); $up->close(); } $conn->close(); return; }
        $aiProvider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';
        $openaiKey = trim((string)get_setting('openai_api_key', '')); $openaiModel = trim((string)get_setting('openai_model', 'gpt-3.5-turbo')) ?: 'gpt-3.5-turbo';
        if ($aiProvider === 'openai' && $openaiKey === '') { $err = 'MISSING_OPENAI_KEY'; $up = $conn->prepare("UPDATE publications SET status='failed', finished_at=CURRENT_TIMESTAMP, error=? WHERE id = ? LIMIT 1"); if ($up) { $up->bind_param('si', $err, $pubId); $up->execute(); $up->close(); } $conn->close(); return; }
        $pageMeta = null; if ($pm = $conn->prepare('SELECT lang, region, title, description FROM page_meta WHERE project_id = ? AND (page_url = ? OR final_url = ?) ORDER BY updated_at DESC, id DESC LIMIT 1')) { $pm->bind_param('iss', $projectId, $url, $url); if ($pm->execute()) { $m = $pm->get_result()->fetch_assoc(); if ($m) { $pageMeta = ['lang' => (string)($m['lang'] ?? ''), 'region' => (string)($m['region'] ?? ''), 'title' => (string)($m['title'] ?? ''), 'description' => (string)($m['description'] ?? ''), ]; } } $pm->close(); }
        $jobPayload = [];
        if ($hasJobPayloadColumn && !empty($row['job_payload'])) {
            $decodedPayload = json_decode((string)$row['job_payload'], true);
            if (is_array($decodedPayload)) { $jobPayload = $decodedPayload; }
        }

        $payloadLanguage = '';
        if (is_array($jobPayload) && !empty($jobPayload)) {
            $languageSources = [];
            if (isset($jobPayload['target']['language'])) { $languageSources[] = $jobPayload['target']['language']; }
            if (isset($jobPayload['language'])) { $languageSources[] = $jobPayload['language']; }
            if (isset($jobPayload['project']['resolvedLanguage'])) { $languageSources[] = $jobPayload['project']['resolvedLanguage']; }
            if (isset($jobPayload['project']['language'])) { $languageSources[] = $jobPayload['project']['language']; }
            if (isset($jobPayload['article']['language'])) { $languageSources[] = $jobPayload['article']['language']; }
            foreach ($languageSources as $sourceLang) {
                $normalized = $normalizeLanguage($sourceLang);
                if ($normalized !== '') { $payloadLanguage = $normalized; break; }
            }
        }
        $pageMetaLanguage = '';
        if (is_array($pageMeta) && !empty($pageMeta)) {
            $pageMetaLanguage = $normalizeLanguage($pageMeta['lang'] ?? '');
        }
        if ($payloadLanguage === '' && $pageMetaLanguage !== '') {
            $payloadLanguage = $pageMetaLanguage;
        }
        if ($payloadLanguage !== '') { $linkLanguage = $payloadLanguage; }

        $jobBase = [
            'url' => $url,
            'pageUrl' => $url,
            'anchor' => $anchor,
            'language' => $linkLanguage,
            'wish' => $linkWish,
            'projectId' => $projectId,
            'publicationUuid' => $publicationUuid,
            'projectName' => $projectName,
            'openaiApiKey' => $openaiKey,
            'openaiModel' => $openaiModel,
            'aiProvider' => $aiProvider,
            'waitBetweenCallsMs' => 5000,
            'pubId' => $pubId,
            'captcha' => [
                'provider' => (string)get_setting('captcha_provider', 'none'),
                'apiKey' => (string)get_setting('captcha_api_key', ''),
                'fallback' => [
                    'provider' => (string)get_setting('captcha_fallback_provider', 'none'),
                    'apiKey' => (string)get_setting('captcha_fallback_api_key', ''),
                ],
            ],
        ];

        $job = is_array($jobPayload) ? $jobPayload : [];
        $job = array_replace_recursive($jobBase, $job);
        if (!isset($job['page_meta']) || empty($job['page_meta'])) { $job['page_meta'] = $pageMeta; }
        if (!isset($job['meta']) || empty($job['meta'])) { $job['meta'] = $pageMeta; }

        $jobLanguage = $normalizeLanguage($job['language'] ?? '');
        if ($jobLanguage === '') { $jobLanguage = $linkLanguage; }
        if ($jobLanguage === '') { $jobLanguage = 'ru'; }
        $job['language'] = $jobLanguage;

        if (isset($job['page_meta']) && is_array($job['page_meta'])) {
            $job['page_meta']['lang'] = $jobLanguage;
        }
        if (isset($job['meta']) && is_array($job['meta'])) {
            $job['meta']['lang'] = $jobLanguage;
        }

        if (isset($job['target']) && is_array($job['target'])) {
            $targetLang = $normalizeLanguage($job['target']['language'] ?? '');
            $job['target']['language'] = $targetLang !== '' ? $targetLang : $jobLanguage;
        }
        if (isset($job['project']) && is_array($job['project'])) {
            $projLang = $normalizeLanguage($job['project']['language'] ?? '');
            $job['project']['language'] = $projLang !== '' ? $projLang : $jobLanguage;
            $resolvedLang = $normalizeLanguage($job['project']['resolvedLanguage'] ?? '');
            $job['project']['resolvedLanguage'] = $resolvedLang !== '' ? $resolvedLang : $jobLanguage;
        }
        if (isset($job['article']) && is_array($job['article'])) {
            $articleLang = $normalizeLanguage($job['article']['language'] ?? '');
            $job['article']['language'] = $articleLang !== '' ? $articleLang : $jobLanguage;
        }
        if (isset($job['preparedArticle']) && is_array($job['preparedArticle']) && !empty($job['preparedArticle'])) {
            $prepLang = $normalizeLanguage($job['preparedArticle']['language'] ?? '');
            $job['preparedArticle']['language'] = $prepLang !== '' ? $prepLang : $jobLanguage;
        }
        $jobPayloadPresent = !empty($jobPayload);
        if (function_exists('pp_promotion_log')) {
            $logPayload = [
                'pub_id' => $pubId,
                'project_id' => $projectId,
                'page_url' => $url,
                'network_slug' => $networkSlug,
                'network_level' => isset($job['network']['level']) ? (int)$job['network']['level'] : null,
                'project_language_raw' => $projectLanguageRaw,
                'project_language_norm' => $projectLanguageNorm,
                'link_language_initial' => $linkLanguageInitial,
                'payload_language' => $payloadLanguage ?: null,
                'page_meta_language' => $pageMetaLanguage ?: null,
                'job_language' => $jobLanguage,
                'target_language' => $job['target']['language'] ?? null,
                'article_language' => $job['article']['language'] ?? null,
                'project_language' => $job['project']['language'] ?? null,
                'project_resolved_language' => $job['project']['resolvedLanguage'] ?? null,
                'prepared_article_language' => $job['preparedArticle']['language'] ?? null,
                'job_payload_column' => $hasJobPayloadColumn,
                'job_payload_present' => $jobPayloadPresent,
            ];
            pp_promotion_log('promotion.publication_job_language', $logPayload);
        }
        $result = pp_publish_via_network($network, $job, 480);
        $logRelative = null;
        $logDetails = null;
        if (is_array($result)) {
            $logCandidate = null;
            if (!empty($result['logFile'])) { $logCandidate = (string)$result['logFile']; }
            elseif (!empty($result['logfile'])) { $logCandidate = (string)$result['logfile']; }
            $logDirCandidate = $result['logDir'] ?? ($result['log_directory'] ?? null);
            $logRelative = pp_promotion_store_log_path($logCandidate, $logDirCandidate);
            if ($logRelative !== null) {
                $logDetails = pp_promotion_expand_log_path($logRelative);
            }
        }
        if (!is_array($result) || empty($result['ok']) || empty($result['publishedUrl'])) {
            $errText = 'NETWORK_ERROR'; $details = ''; if (is_array($result)) { $details = (string)($result['details'] ?? ($result['error'] ?? ($result['stderr'] ?? ''))); }
            $msg = trim($errText . ($details !== '' ? (': ' . $details) : ''));
            $up = $conn->prepare("UPDATE publications SET status=IF(cancel_requested=1,'cancelled','failed'), finished_at=CURRENT_TIMESTAMP, error=?, log_file=?, pid=NULL WHERE id = ? LIMIT 1");
            if ($up) { $logParam = $logRelative; $up->bind_param('ssi', $msg, $logParam, $pubId); $up->execute(); $up->close(); }
            @$conn->query("UPDATE publication_queue SET status=IF((SELECT cancel_requested FROM publications WHERE id=".(int)$pubId.")=1,'cancelled','failed') WHERE publication_id = " . (int)$pubId);
            @$conn->query('DELETE FROM publication_queue WHERE publication_id = ' . (int)$pubId);
            if (function_exists('pp_promotion_handle_publication_update')) {
                $resultContext = is_array($result) ? $result : [];
                if (is_array($resultContext)) {
                    if ($logRelative !== null) {
                        $resultContext['_log_relative'] = $logRelative;
                        if ($logDetails) {
                            $resultContext['_log_absolute'] = $logDetails['absolute'] ?? null;
                            $resultContext['_log_exists'] = $logDetails['exists'] ?? null;
                        }
                    }
                }
                pp_promotion_handle_publication_update($pubId, 'failed', null, $msg, $resultContext ?: null);
            }
            $conn->close(); return;
        }
        $publishedUrl = trim((string)$result['publishedUrl']); $publishedBy = 'system'; $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) { $userStmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1'); if ($userStmt) { $userStmt->bind_param('i', $uid); $userStmt->execute(); $userStmt->bind_result($uName); if ($userStmt->fetch()) { $publishedBy = (string)$uName; } $userStmt->close(); } }
        $netSlug = (string)($network['slug'] ?? $networkSlug);
        $verificationPayload = is_array($result['verification'] ?? null) ? $result['verification'] : [];
        $verificationResult = pp_verify_published_content($publishedUrl, $verificationPayload, $job);
        $verificationStatus = (string)($verificationResult['status'] ?? 'skipped');
        $verificationAttempts = 1;
        $transientReasons = ['FETCH_FAILED', 'LINK_MISSING', 'FETCH_ERROR', 'FETCH_TIMEOUT'];
        $reasonCode = strtoupper((string)($verificationResult['reason'] ?? ''));
        if (in_array($verificationStatus, ['failed', 'error'], true) && in_array($reasonCode, $transientReasons, true)) {
            $verificationAttempts++;
            if (function_exists('usleep')) { @usleep(500000); }
            $jobRetry = $job;
            $jobRetry['_verification_retry'] = true;
            $retryResult = pp_verify_published_content($publishedUrl, $verificationPayload, $jobRetry);
            if (is_array($retryResult) && !empty($retryResult)) {
                $verificationResult = $retryResult;
                $verificationStatus = (string)($verificationResult['status'] ?? $verificationStatus);
                $reasonCode = strtoupper((string)($verificationResult['reason'] ?? $reasonCode));
            }
            if (function_exists('pp_promotion_log')) {
                pp_promotion_log('promotion.publication_verification_retry', [
                    'publication_id' => $pubId,
                    'project_id' => $projectId,
                    'attempts' => $verificationAttempts,
                    'status' => $verificationStatus,
                    'reason' => $reasonCode,
                ]);
            }
        }
        $transientFailure = in_array($verificationStatus, ['failed', 'error'], true) && in_array($reasonCode, $transientReasons, true);
        $expectedLink = trim((string)($verificationPayload['linkUrl'] ?? $job['url'] ?? ''));
        $expectedSampleRaw = (string)($verificationPayload['textSample'] ?? '');
        $expectedSample = $expectedSampleRaw !== '' ? (function_exists('mb_substr') ? mb_substr($expectedSampleRaw, 0, 320, 'UTF-8') : substr($expectedSampleRaw, 0, 320)) : '';
        $verificationStore = [
            'result' => $verificationResult,
            'expected' => [
                'link' => $expectedLink,
                'anchor' => $verificationPayload['anchorText'] ?? $job['anchor'] ?? '',
                'supports_link' => $verificationResult['supports_link'] ?? ($verificationPayload['supportsLinkCheck'] ?? true),
                'supports_text' => $verificationResult['supports_text'] ?? ($verificationPayload['supportsTextCheck'] ?? ($expectedSample !== '')),
                'text_sample' => $expectedSample,
            ],
            'checked_at' => gmdate('c'),
            'attempts' => $verificationAttempts,
        ];
        if ($transientFailure) {
            $verificationStore['transient'] = true;
            $verificationStore['reason'] = $reasonCode;
        }
        $verificationJson = json_encode($verificationStore, JSON_UNESCAPED_UNICODE); if ($verificationJson === false) { $verificationJson = '{}'; }
        $finalStatus = 'success'; $errorMsg = null;
        switch ($verificationStatus) {
            case 'success':
                $finalStatus = 'success';
                break;
            case 'partial':
                $finalStatus = 'partial';
                break;
            case 'failed':
            case 'error':
                $finalStatus = 'failed';
                $reasonCode = $reasonCode !== '' ? $reasonCode : 'FAILED';
                $errorMsg = 'VERIFICATION_' . $reasonCode;
                break;
            case 'skipped':
            default:
                $finalStatus = 'success';
                break;
        }
        if ($transientFailure && $finalStatus === 'failed') {
            $finalStatus = 'partial';
            if (function_exists('pp_promotion_log')) {
                pp_promotion_log('promotion.publication_verification_transient', [
                    'publication_id' => $pubId,
                    'project_id' => $projectId,
                    'reason' => $reasonCode,
                    'attempts' => $verificationAttempts,
                ]);
            }
        }
        $up = $conn->prepare('UPDATE publications SET post_url = ?, network = ?, published_by = ?, status = ?, error = ?, log_file = ?, finished_at=CURRENT_TIMESTAMP, cancel_requested=0, pid=NULL, verification_status = ?, verification_checked_at = CURRENT_TIMESTAMP, verification_details = ? WHERE id = ? LIMIT 1');
        if ($up) {
            $statusParam = $finalStatus;
            $errorParam = $errorMsg;
            $verificationStatusParam = $verificationStatus;
            $detailsParam = $verificationJson;
            $logParam = $logRelative;
            $up->bind_param('ssssssssi', $publishedUrl, $netSlug, $publishedBy, $statusParam, $errorParam, $logParam, $verificationStatusParam, $detailsParam, $pubId);
            $up->execute();
            $up->close();
        }
        if ($finalStatus === 'failed') { @$conn->query("UPDATE publication_queue SET status='failed' WHERE publication_id = " . (int)$pubId); @$conn->query('DELETE FROM publication_queue WHERE publication_id = ' . (int)$pubId); }
        else { $queueStatus = ($finalStatus === 'partial') ? 'partial' : 'success'; @$conn->query("UPDATE publication_queue SET status='" . $queueStatus . "' WHERE publication_id = " . (int)$pubId); @$conn->query('DELETE FROM publication_queue WHERE publication_id = ' . (int)$pubId); }
        if (function_exists('pp_promotion_handle_publication_update')) {
            if (is_array($result)) {
                if ($logRelative !== null) {
                    $result['_log_relative'] = $logRelative;
                    if ($logDetails) {
                        $result['_log_absolute'] = $logDetails['absolute'] ?? null;
                        $result['_log_exists'] = $logDetails['exists'] ?? null;
                    }
                }
            }
            pp_promotion_handle_publication_update($pubId, $finalStatus, $publishedUrl, $errorMsg, $result);
        }
        $conn->close();
    }
}

if (!function_exists('pp_run_queue_worker')) {
    function pp_run_queue_worker(int $maxJobs = 1): void {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); } else { if (function_exists('session_write_close')) { @session_write_close(); } @ignore_user_abort(true); }
        $releaseStats = pp_release_stuck_publications();
        if (($releaseStats['released'] ?? 0) > 0 || ($releaseStats['failed'] ?? 0) > 0) {
            pp_promotion_log('promotion.pubqueue.watchdog_summary', $releaseStats);
        }
        $maxJobs = max(1, $maxJobs); $processed = 0; $spacingMs = function_exists('pp_get_min_job_spacing_ms') ? pp_get_min_job_spacing_ms() : 0;
        while ($processed < $maxJobs) { $running = pp_count_running_jobs(); if ($running >= pp_get_max_concurrent_jobs()) { break; } $jobId = pp_claim_next_publication_job(); if (!$jobId) { break; } pp_process_publication_job($jobId); $processed++; if ($spacingMs > 0) { @usleep($spacingMs * 1000); } }
    }
}

?>
