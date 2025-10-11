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

if (!function_exists('pp_promotion_crowd_message_templates')) {
    function pp_promotion_crowd_message_templates(string $language): array {
        switch ($language) {
            case 'uk':
                return [
                    'intro_with_anchor' => [
                        'Колеги, натрапив на детальний матеріал «%s» — здається, він відповідає на наші останні питання.',
                        'Поділюся статтею «%s», яку сьогодні обговорювали на зустрічі — в ній є практичні приклади.',
                    ],
                    'intro_generic' => [
                        'Колеги, знайшов корисну аналітику по темі — ділюся посиланням.',
                        'Привіт! Під рукою виявився свіжий матеріал, який може стане у пригоді.',
                    ],
                    'value' => [
                        'Автор просто пояснює складні моменти й наводить робочі кейси.',
                        'Цінний блок про практичні кроки, як підсилити результат.',
                        'Є короткий чекліст, який можна використати в роботі вже зараз.',
                    ],
                    'cta' => [
                        'Кому цікаво — гляньте, будь ласка, нижче.',
                        'Буду вдячний за ваші думки щодо цього матеріалу.',
                    ],
                    'closing' => [
                        'Якщо виникнуть ідеї чи зауваження — діліться, обговоримо.',
                        'Сподіваюся, буде корисно для наших задач.',
                    ],
                ];
            case 'en':
                return [
                    'intro_with_anchor' => [
                        'Team, I came across “%s” today — it gives a clear breakdown of the topic we touched on.',
                        'Sharing the article “%s”; it matches the questions we recently discussed.',
                    ],
                    'intro_generic' => [
                        'Hi everyone! Found a solid write-up worth bookmarking.',
                        'Passing along a fresh piece that might save us some research time.',
                    ],
                    'value' => [
                        'The author focuses on practical steps and includes a couple of concise checklists.',
                        'There is a handy section covering typical pitfalls and how to avoid them.',
                        'What I liked most is the real-world example toward the middle.',
                    ],
                    'cta' => [
                        'Take a look when you have a minute — link below.',
                        'Curious to hear what you think once you skim through it.',
                    ],
                    'closing' => [
                        'If you spot anything we can reuse, let’s sync.',
                        'Hope it brings a few good ideas for our next sprint.',
                    ],
                ];
            case 'ru':
            default:
                return [
                    'intro_with_anchor' => [
                        'Коллеги, наткнулся на материал «%s» — как раз по нашим последним вопросам.',
                        'Делюсь статьёй «%s»: автор разбирает тему простым языком.',
                    ],
                    'intro_generic' => [
                        'Коллеги, нашёл толковую статью по теме — решил сразу поделиться.',
                        'Привет! Попалась на глаза свежая заметка, выглядит полезной.',
                    ],
                    'value' => [
                        'Особенно понравился блок с практическими шагами и примерами.',
                        'Есть список частых ошибок и подсказки, как их избежать.',
                        'Подборка кейсов ближе к концу помогает быстрее разобраться.',
                    ],
                    'cta' => [
                        'Кому актуально — посмотрите, пожалуйста, ссылку ниже.',
                        'Буду рад, если отпишетесь, что думаете.',
                    ],
                    'closing' => [
                        'Если появятся идеи, как применить, напишите — обсудим.',
                        'Надеюсь, пригодится в ближайших задачах.',
                    ],
                ];
        }
    }
}

if (!function_exists('pp_promotion_crowd_generate_persona')) {
    function pp_promotion_crowd_generate_persona(string $language): string {
        $personas = [
            'ru' => [
                'Анна Ковалева', 'Ирина Маркова', 'Никита Сорокин', 'Павел Назаров', 'Светлана Орлова',
            ],
            'uk' => [
                'Олена Кравчук', 'Марія Іваненко', 'Андрій Поліщук', 'Тетяна Левчук', 'Сергій Мельник',
            ],
            'en' => [
                'Emily Harper', 'Liam Brooks', 'Olivia Turner', 'Noah Collins', 'Grace Mitchell',
            ],
        ];
        if (!isset($personas[$language]) || empty($personas[$language])) {
            $language = 'ru';
        }
        try {
            $idx = random_int(0, count($personas[$language]) - 1);
        } catch (Throwable $e) {
            $idx = mt_rand(0, count($personas[$language]) - 1);
        }
        return $personas[$language][$idx] ?? 'PromoPilot Team';
    }
}

if (!function_exists('pp_promotion_crowd_pick_email_domain')) {
    function pp_promotion_crowd_pick_email_domain(string $language): string {
        $domains = [
            'ru' => ['gmail.com', 'yandex.ru', 'mail.ru', 'bk.ru', 'icloud.com'],
            'uk' => ['gmail.com', 'ukr.net', 'i.ua', 'outlook.com', 'icloud.com'],
            'en' => ['gmail.com', 'outlook.com', 'yahoo.com', 'hotmail.com', 'protonmail.com'],
        ];
        if (!isset($domains[$language]) || empty($domains[$language])) {
            $domains[$language] = ['gmail.com', 'outlook.com', 'protonmail.com'];
        }
        try {
            $idx = random_int(0, count($domains[$language]) - 1);
        } catch (Throwable $e) {
            $idx = mt_rand(0, count($domains[$language]) - 1);
        }
        return $domains[$language][$idx] ?? 'gmail.com';
    }
}

if (!function_exists('pp_promotion_crowd_generate_ai_messages')) {
    function pp_promotion_crowd_generate_ai_messages(array $context, int $count = 10): array {
        if (!defined('PP_ROOT_PATH')) {
            return [];
        }
        $count = max(1, min(50, (int)$count));
        $language = pp_promotion_crowd_normalize_language($context['language'] ?? null);
        $languageName = $language === 'en' ? 'English' : ($language === 'uk' ? 'Ukrainian' : 'Russian');
        $targetUrl = trim((string)($context['target_url'] ?? ''));
        $anchor = trim((string)($context['anchor'] ?? ''));
        $projectName = trim((string)($context['project_name'] ?? ''));
        $topic = trim((string)($context['topic'] ?? ''));
        $runId = isset($context['run_id']) ? (int)$context['run_id'] : 0;
        $providerSetting = strtolower((string)get_setting('ai_provider', 'openai'));
        $provider = $providerSetting === 'byoa' ? 'byoa' : 'openai';
        $apiKey = trim((string)get_setting('openai_api_key', ''));
        if ($provider === 'openai' && $apiKey === '') {
            return [];
        }
        if (!function_exists('pp_resolve_node_binary') || !function_exists('pp_run_node_script')) {
            return [];
        }
        $node = pp_resolve_node_binary(3, true);
        if (!$node || empty($node['path'])) {
            return [];
        }
        $script = PP_ROOT_PATH . '/scripts/crowd_messages_cli.js';
        if (!is_file($script)) {
            return [];
        }
        $model = trim((string)get_setting('openai_model', ''));
        $anchorForPrompt = $anchor !== '' ? $anchor : ($projectName !== '' ? $projectName : ($context['link_title'] ?? 'материал'));
        $topicPrompt = $topic !== '' ? $topic : ($projectName !== '' ? $projectName : 'target audience');
        $resolvedUrl = $targetUrl !== '' ? $targetUrl : ($context['fallback_url'] ?? '');
        $urlForPrompt = $resolvedUrl !== '' ? $resolvedUrl : 'https://example.com';
        $systemPrompt = 'You are PromoPilot assistant creating natural crowd-comments for link promotion. Focus on being human, concise, and trustworthy.';
        $promptParts = [];
        $promptParts[] = 'Language: ' . $languageName;
        $promptParts[] = 'Goal: share a useful comment that recommends checking the article.';
        if ($projectName !== '') {
            $promptParts[] = 'Project name: ' . $projectName;
        }
        $promptParts[] = 'Topic / niche: ' . $topicPrompt;
        $promptParts[] = 'Article title or hook: ' . $anchorForPrompt;
        $promptParts[] = 'Article URL: ' . $urlForPrompt;
        $instructions = [];
        $instructions[] = 'Produce ' . $count . ' distinct, conversational forum-style replies.';
        $instructions[] = 'Each reply should sound like a real person recommending the article.';
        $instructions[] = 'Mention one tangible benefit, insight, or takeaway.';
        $instructions[] = 'Use 40-90 words, 1-2 short paragraphs.';
        $instructions[] = 'Include the article URL exactly once inside the body (plain text or in parentheses).';
        $instructions[] = 'Avoid repeating identical wording across replies.';
        $instructions[] = 'Do not add markdown lists or numbering.';
        $instructions[] = 'Return a JSON array. Each element must be an object with keys "subject" and "body".';
        $instructions[] = 'Subject is a short teaser (max 10 words). Body holds the full comment.';
        $instructions[] = 'Do not include explanations outside JSON.';
        if ($runId > 0) {
            $promptParts[] = 'Run ID: ' . $runId;
        }
        $prompt = implode("\n", $promptParts) . "\n\nRequirements:\n- " . implode("\n- ", $instructions);

        $job = [
            'prompt' => $prompt,
            'systemPrompt' => $systemPrompt,
            'provider' => $provider,
            'model' => $model,
            'openaiApiKey' => $apiKey,
            'temperature' => 0.65,
        ];

        $response = pp_run_node_script($script, $job, 75);
        if (!is_array($response) || empty($response['ok'])) {
            return [];
        }

        $parsed = $response['parsed'] ?? null;
        $rawText = (string)($response['text'] ?? ($response['raw'] ?? ''));
        if (!is_array($parsed)) {
            $candidate = null;
            if ($rawText !== '') {
                $candidate = json_decode($rawText, true);
                if (!is_array($candidate)) {
                    $start = strpos($rawText, '[');
                    $end = strrpos($rawText, ']');
                    if ($start !== false && $end !== false && $end > $start) {
                        $slice = substr($rawText, $start, $end - $start + 1);
                        $candidate = json_decode($slice, true);
                    }
                }
            }
            if (is_array($candidate)) {
                $parsed = $candidate;
            }
        }

        $results = [];
        $appendFallback = static function(array &$list, string $bodyValue) use ($targetUrl) {
            $body = trim($bodyValue);
            if ($body === '') { return; }
            if ($targetUrl !== '' && stripos($body, $targetUrl) === false) {
                $body .= "\n\n" . $targetUrl;
            }
            $list[] = [
                'subject' => '',
                'body' => $body,
            ];
        };

        if (is_array($parsed)) {
            foreach ($parsed as $entry) {
                $subject = '';
                $body = '';
                if (is_array($entry)) {
                    $subject = trim((string)($entry['subject'] ?? ($entry['title'] ?? '')));
                    $body = trim((string)($entry['body'] ?? ($entry['message'] ?? '')));
                    if ($body === '' && isset($entry[1])) { $body = trim((string)$entry[1]); }
                    if ($subject === '' && isset($entry[0]) && !is_array($entry[0])) { $subject = trim((string)$entry[0]); }
                } elseif (is_string($entry)) {
                    $body = trim($entry);
                }
                if ($body === '') { continue; }
                if ($targetUrl !== '' && stripos($body, $targetUrl) === false) {
                    $body .= "\n\n" . $targetUrl;
                }
                $results[] = [
                    'subject' => $subject,
                    'body' => $body,
                ];
                if (count($results) >= $count) {
                    break;
                }
            }
        }

        if (empty($results) && $rawText !== '') {
            $lines = preg_split('~\n+~', $rawText);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '') { continue; }
                    $appendFallback($results, $line);
                    if (count($results) >= $count) {
                        break;
                    }
                }
            }
        }

        $final = [];
        foreach ($results as $row) {
            $subject = trim((string)($row['subject'] ?? ''));
            $body = trim((string)($row['body'] ?? ''));
            if ($body === '') { continue; }
            if (function_exists('pp_promotion_clean_text')) {
                $subject = $subject !== '' ? pp_promotion_clean_text($subject) : $subject;
                $body = pp_promotion_clean_text($body);
            }
            if ($subject === '') {
                $subject = $anchor !== '' ? $anchor : ($projectName !== '' ? $projectName : __('Комментарий к статье'));
            }
            $final[] = [
                'subject' => $subject,
                'body' => $body,
                'language' => $language,
            ];
            if (count($final) >= $count) {
                break;
            }
        }

        return $final;
    }
}

if (!function_exists('pp_promotion_crowd_compose_message')) {
    function pp_promotion_crowd_compose_message(string $language, string $anchor, string $link): string {
        $templates = pp_promotion_crowd_message_templates($language);
        $introPool = $anchor !== ''
            ? ($templates['intro_with_anchor'] ?? [])
            : ($templates['intro_generic'] ?? []);
        if (empty($introPool)) {
            $introPool = $templates['intro_generic'] ?? ['Смотрите ссылку ниже — может пригодиться.'];
        }
        $valuePool = $templates['value'] ?? ['Нашёл полезные детали, которыми стоит поделиться.'];
        $ctaPool = $templates['cta'] ?? ['Ниже оставляю ссылку — буду рад обратной связи.'];
        $closingPool = $templates['closing'] ?? ['Напишите, если захотите обсудить.'];

        $pick = static function(array $pool, string $fallback) {
            if (empty($pool)) { return $fallback; }
            try {
                $idx = random_int(0, count($pool) - 1);
            } catch (Throwable $e) {
                $idx = mt_rand(0, count($pool) - 1);
            }
            return $pool[$idx] ?? $fallback;
        };

        $intro = $pick($introPool, 'Коллеги, делюсь ссылкой:');
        if ($anchor !== '') {
            $intro = sprintf($intro, $anchor);
        }
        $value = $pick($valuePool, 'Внутри собраны рабочие советы.');
        $cta = $pick($ctaPool, 'Гляньте, пожалуйста, по ссылке ниже.');
        $closing = $pick($closingPool, 'Пишите, если будет полезно!');

        $parts = [$intro . ' ' . $value];
        if ($cta !== '') {
            $parts[] = $cta;
        }
        if ($link !== '') {
            $parts[] = $link;
        }
        $parts[] = $closing;
        return trim(implode("\n\n", array_filter($parts, static fn($segment) => trim((string)$segment) !== '')));
    }
}

if (!function_exists('pp_promotion_crowd_prepare_message_pool')) {
    /**
     * Prepare a reusable set of unique crowd messages for a node before dispatch.
     *
     * @param array $project      Project row.
     * @param array $linkRow      Promotional link row.
     * @param array $nodeMeta     Promotion node metadata.
     * @param string $targetUrl   URL of the article (level 2 node result URL).
     * @param int $count          Number of messages to prepare.
     * @param array $existingHashes Already used message hashes for the node.
     * @return array<int,array{subject:string,body:string,hash:string,language:string}>
     */
    function pp_promotion_crowd_prepare_message_pool(array $project, array $linkRow, array $nodeMeta, string $targetUrl, int $count = 10, array $existingHashes = []): array {
        $prepared = [];
        $count = max(1, min(20, $count));
        $language = pp_promotion_crowd_normalize_language($linkRow['language'] ?? $project['language'] ?? null);
        $anchor = isset($nodeMeta['anchor_text']) ? trim((string)$nodeMeta['anchor_text']) : '';
        $texts = pp_promotion_crowd_texts($language);
        $used = [];
        foreach ($existingHashes as $hash) {
            if (is_string($hash) && $hash !== '') {
                $used[$hash] = true;
            }
        }

        $makeSubject = static function(string $anchorText, array $textsList): string {
            $subject = $anchorText !== '' ? ($textsList['subject_prefix'] . ': ' . $anchorText) : $textsList['subject_default'];
            if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 120) {
                return rtrim(mb_substr($subject, 0, 118, 'UTF-8')) . '…';
            }
            if (strlen($subject) > 120) {
                return rtrim(substr($subject, 0, 118)) . '…';
            }
            return $subject;
        };

        $aiContext = [
            'language' => $language,
            'anchor' => $anchor,
            'target_url' => $targetUrl,
            'project_name' => (string)($project['name'] ?? ''),
            'topic' => (string)($project['topic'] ?? ($project['category'] ?? '')),
            'link_title' => (string)($linkRow['title'] ?? ($linkRow['anchor'] ?? '')),
        ];
        $aiBatch = pp_promotion_crowd_generate_ai_messages($aiContext, $count);
        foreach ($aiBatch as $message) {
            $subjectCandidate = trim((string)($message['subject'] ?? ''));
            $body = trim((string)($message['body'] ?? ''));
            if ($body === '') { continue; }
            if ($targetUrl !== '' && stripos($body, $targetUrl) === false) {
                $body .= "\n\n" . $targetUrl;
            }
            if (function_exists('pp_promotion_clean_text')) {
                $body = pp_promotion_clean_text($body);
            }
            $hash = sha1($language . '|' . trim($body));
            if (isset($used[$hash])) {
                continue;
            }
            $subject = $subjectCandidate !== '' ? $subjectCandidate : $makeSubject($anchor, $texts);
            if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 120) {
                $subject = rtrim(mb_substr($subject, 0, 118, 'UTF-8')) . '…';
            } elseif (strlen($subject) > 120) {
                $subject = rtrim(substr($subject, 0, 118)) . '…';
            }
            if (function_exists('pp_promotion_clean_text')) {
                $subject = pp_promotion_clean_text($subject);
            }
            $prepared[] = [
                'subject' => $subject,
                'body' => $body,
                'hash' => $hash,
                'language' => $language,
            ];
            $used[$hash] = true;
            if (count($prepared) >= $count) {
                break;
            }
        }

        for ($i = count($prepared); $i < $count; $i++) {
            $body = pp_promotion_crowd_compose_message($language, $anchor, $targetUrl);
            if ($body === '') {
                continue;
            }
            if (function_exists('pp_promotion_clean_text')) {
                $body = pp_promotion_clean_text($body);
            }
            $hash = sha1($language . '|' . trim($body));
            if (isset($used[$hash])) {
                $body .= "\n\n" . strtoupper(substr(sha1($body . microtime(true) . $i), 0, 6));
                $hash = sha1($language . '|' . trim($body));
                if (isset($used[$hash])) { continue; }
            }
            $subject = $makeSubject($anchor, $texts);
            $subject = function_exists('pp_promotion_clean_text') ? pp_promotion_clean_text($subject) : $subject;
            $prepared[] = [
                'subject' => $subject,
                'body' => $body,
                'hash' => $hash,
                'language' => $language,
            ];
            $used[$hash] = true;
        }

        return $prepared;
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
                    $processed = pp_promotion_crowd_worker($taskId, 5, null);
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
                        $processed = pp_promotion_crowd_worker($taskId, $batchSize, null);
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
                $processed = pp_promotion_crowd_worker($taskId, 5, null);
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
    function pp_promotion_crowd_claim_task(mysqli $conn, ?int $specificTaskId = null, ?int $runId = null): ?array {
        $taskId = null;
        if ($specificTaskId !== null && $specificTaskId > 0) {
            $taskId = $specificTaskId;
            $bindRun = $runId !== null && $runId > 0;
            $sql = "UPDATE promotion_crowd_tasks SET status='running', updated_at=CURRENT_TIMESTAMP WHERE id=?"
                . ($bindRun ? " AND run_id=?" : "")
                . " AND status IN ('planned','queued','running') LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($bindRun) {
                    $stmt->bind_param('ii', $taskId, $runId);
                } else {
                    $stmt->bind_param('i', $taskId);
                }
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
            if ($runId !== null && $runId > 0) {
                $stmtSelect = $conn->prepare("SELECT id FROM promotion_crowd_tasks WHERE run_id=? AND status IN ('planned','queued') ORDER BY id ASC LIMIT 1");
                if ($stmtSelect) {
                    $stmtSelect->bind_param('i', $runId);
                    if ($stmtSelect->execute()) {
                        $res = $stmtSelect->get_result();
                        if ($row = $res->fetch_assoc()) {
                            $taskId = (int)($row['id'] ?? 0);
                        }
                        $res->free();
                    }
                    $stmtSelect->close();
                }
            } else {
            $stmtSelect = $conn->prepare(
                "SELECT pct.id
                 FROM promotion_crowd_tasks pct
                 LEFT JOIN promotion_runs pr ON pr.id = pct.run_id
                 LEFT JOIN projects pj ON pj.id = pr.project_id
                 WHERE pct.status IN ('planned','queued')
                 ORDER BY COALESCE(pr.project_id, pj.id) ASC, pct.id ASC
                 LIMIT 1"
            );
            if ($stmtSelect) {
                if ($stmtSelect->execute()) {
                    $res = $stmtSelect->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $taskId = (int)($row['id'] ?? 0);
                    }
                    $res->free();
                }
                $stmtSelect->close();
            }
            }
            if (!$taskId) {
                return null;
            }
            $bindRun = $runId !== null && $runId > 0;
            $sqlUpdate = "UPDATE promotion_crowd_tasks SET status='running', updated_at=CURRENT_TIMESTAMP WHERE id=?"
                . ($bindRun ? " AND run_id=?" : "")
                . " AND status IN ('planned','queued') LIMIT 1";
            $stmt = $conn->prepare($sqlUpdate);
            if (!$stmt) {
                return null;
            }
            if ($bindRun) {
                $stmt->bind_param('ii', $taskId, $runId);
            } else {
                $stmt->bind_param('i', $taskId);
            }
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
            $emailDomain = pp_promotion_crowd_pick_email_domain($language);
            $authorEmail = $emailSlug . '.' . strtolower(substr($token, 0, 6)) . '@' . $emailDomain;
        } else {
            $emailDomain = substr(strrchr($authorEmail, '@'), 1) ?: 'gmail.com';
        }
        $body = $rawBody;
        if ($body === '') {
            $linkForBody = $articleUrl !== '' ? $articleUrl : (string)($task['target_url'] ?? '');
            $body = pp_promotion_crowd_compose_message($language, $anchor, $linkForBody);
            if ($body === '') {
                $lead = $anchor !== ''
                    ? sprintf($texts['lead_with_anchor'], $anchor)
                    : $texts['lead_without_anchor'];
                $bodyParts = [trim($lead)];
                if ($linkForBody !== '') {
                    $bodyParts[] = trim($linkForBody);
                }
                $body = trim(implode(' ', array_filter($bodyParts)));
                if ($body === '') {
                    $body = trim(($texts['link_prompt'] ?? 'Посмотрите, пожалуйста:') . ' ' . $linkForBody);
                }
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

        $website = $articleUrl !== '' ? $articleUrl : (string)($task['target_url'] ?? '');
        if ($website === '' || !filter_var($website, FILTER_VALIDATE_URL)) {
            $website = 'https://' . $emailDomain;
        }

        $identity = [
            'token' => $token,
            'message' => $body,
            'email' => $authorEmail,
            'name' => $authorName,
            'website' => $website,
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
                $finalStatus = 'completed';
                $needsReview = true;
                break;
            default:
                $finalStatus = 'failed';
                break;
        }

        if (in_array($result['status'], ['no_form','skipped'], true)) {
            $payload['manual_fallback'] = true;
            $payload['fallback_reason'] = $result['status'];
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
        if (!empty($payload['body']) && empty($payload['message_hash'])) {
            $payload['message_hash'] = sha1($language . '|' . trim((string)$payload['body']));
        }
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

        if ($crowdLinkId > 0) {
            if ($result['status'] === 'no_form') {
                $stmtLink = $conn->prepare("UPDATE crowd_links SET status='no_form', deep_status='no_form', updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
                if ($stmtLink) {
                    $stmtLink->bind_param('i', $crowdLinkId);
                    $stmtLink->execute();
                    $stmtLink->close();
                }
            } elseif ($result['status'] === 'blocked') {
                $stmtLink = $conn->prepare("UPDATE crowd_links SET status='blocked', deep_status='blocked', updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
                if ($stmtLink) {
                    $stmtLink->bind_param('i', $crowdLinkId);
                    $stmtLink->execute();
                    $stmtLink->close();
                }
            } elseif ($result['status'] === 'skipped') {
                $stmtLink = $conn->prepare("UPDATE crowd_links SET status='checking', deep_status='skipped', updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
                if ($stmtLink) {
                    $stmtLink->bind_param('i', $crowdLinkId);
                    $stmtLink->execute();
                    $stmtLink->close();
                }
            }
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

if (!function_exists('pp_promotion_crowd_schedule_worker')) {
    function pp_promotion_crowd_schedule_worker(int $runId): ?array {
        $runId = (int)$runId;
        if ($runId <= 0) { return null; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return null; }
        if (!$conn) { return null; }
        $stmt = $conn->prepare("INSERT INTO promotion_crowd_workers (run_id, status, attempts, created_at, updated_at) VALUES (?, 'queued', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                status = IF(status='running', 'running', 'queued'),
                pid = IF(status='running', pid, NULL),
                started_at = IF(status='running', started_at, NULL),
                heartbeat_at = NULL,
                finished_at = NULL,
                last_error = IF(status='running', last_error, NULL),
                attempts = IF(status='running', attempts, attempts + 1),
                updated_at = CURRENT_TIMESTAMP");
        if ($stmt) {
            $stmt->bind_param('i', $runId);
            $stmt->execute();
            $stmt->close();
        }
        $row = null;
        $stmtSelect = $conn->prepare("SELECT id, status, pid, attempts, started_at, heartbeat_at, finished_at FROM promotion_crowd_workers WHERE run_id = ? LIMIT 1");
        if ($stmtSelect) {
            $stmtSelect->bind_param('i', $runId);
            if ($stmtSelect->execute()) {
                $row = $stmtSelect->get_result()->fetch_assoc();
            }
            $stmtSelect->close();
        }
        $conn->close();
        return $row;
    }
}

if (!function_exists('pp_promotion_crowd_worker_claim_slot')) {
    function pp_promotion_crowd_worker_claim_slot(int $runId, int $pid): ?array {
        $runId = (int)$runId;
        if ($runId <= 0) { return null; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return null; }
        if (!$conn) { return null; }
        $stmt = $conn->prepare("UPDATE promotion_crowd_workers SET status='running', pid=?, started_at=COALESCE(started_at, CURRENT_TIMESTAMP), heartbeat_at=CURRENT_TIMESTAMP, last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE run_id=? AND status IN ('queued','failed','stalled','pending') LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $pid, $runId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $stmtCheck = $conn->prepare("SELECT id, status, pid, started_at, heartbeat_at FROM promotion_crowd_workers WHERE run_id=? LIMIT 1");
                if ($stmtCheck) {
                    $stmtCheck->bind_param('i', $runId);
                    if ($stmtCheck->execute()) {
                        $row = $stmtCheck->get_result()->fetch_assoc();
                        $stmtCheck->close();
                        $conn->close();
                        return $row ?: null;
                    }
                    $stmtCheck->close();
                }
                $conn->close();
                return null;
            }
            $stmt->close();
        }
        $row = null;
        $stmtSelect = $conn->prepare("SELECT id, status, pid, started_at, heartbeat_at FROM promotion_crowd_workers WHERE run_id=? LIMIT 1");
        if ($stmtSelect) {
            $stmtSelect->bind_param('i', $runId);
            if ($stmtSelect->execute()) {
                $row = $stmtSelect->get_result()->fetch_assoc();
            }
            $stmtSelect->close();
        }
        $conn->close();
        return $row;
    }
}

if (!function_exists('pp_promotion_crowd_worker_heartbeat')) {
    function pp_promotion_crowd_worker_heartbeat(int $workerId): void {
        $workerId = (int)$workerId;
        if ($workerId <= 0) { return; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
        @$conn->query("UPDATE promotion_crowd_workers SET heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $workerId . " LIMIT 1");
        $conn->close();
    }
}

if (!function_exists('pp_promotion_crowd_worker_finish_slot')) {
    function pp_promotion_crowd_worker_finish_slot(int $workerId, string $status, ?string $error = null): void {
        $workerId = (int)$workerId;
        if ($workerId <= 0) { return; }
        $status = trim($status);
        if ($status === '') { $status = 'completed'; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
        $stmt = $conn->prepare("UPDATE promotion_crowd_workers SET status=?, last_error=?, finished_at=CURRENT_TIMESTAMP, heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $error, $workerId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_crowd_worker_finish_slot_by_run')) {
    function pp_promotion_crowd_worker_finish_slot_by_run(int $runId, string $status = 'completed', ?string $error = null): void {
        $runId = (int)$runId;
        if ($runId <= 0) { return; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
        $status = trim($status);
        if ($status === '') { $status = 'completed'; }
        $stmt = $conn->prepare("UPDATE promotion_crowd_workers SET status=?, last_error=?, finished_at=CURRENT_TIMESTAMP, heartbeat_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE run_id=? AND status <> 'completed' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $error, $runId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_crowd_has_pending_tasks')) {
    function pp_promotion_crowd_has_pending_tasks(int $runId): bool {
        $runId = (int)$runId;
        if ($runId <= 0) { return false; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; }
        if (!$conn) { return false; }
        $pending = false;
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=? AND status IN ('planned','queued','running','pending','created')");
        if ($stmt) {
            $stmt->bind_param('i', $runId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $pending = ((int)($row['c'] ?? 0)) > 0;
                }
            }
            $stmt->close();
        }
        $conn->close();
        return $pending;
    }
}

if (!function_exists('pp_promotion_crowd_launch_worker_for_run')) {
    function pp_promotion_crowd_launch_worker_for_run(int $runId, bool $allowFallback = true): bool {
        $runId = (int)$runId;
        if ($runId <= 0) { return false; }
        if (!pp_promotion_crowd_has_pending_tasks($runId)) {
            pp_promotion_crowd_worker_finish_slot_by_run($runId, 'completed', null);
            return false;
        }
        $workerRow = pp_promotion_crowd_schedule_worker($runId);
        if (!$workerRow) { return false; }
        if (!empty($workerRow['status']) && $workerRow['status'] === 'running' && !empty($workerRow['pid'])) {
            return false;
        }
        $maxParallel = pp_promotion_get_crowd_max_parallel_runs();
        $currentRunning = pp_promotion_crowd_count_running_workers();
        if ($currentRunning >= $maxParallel) {
            return false;
        }
        try { $conn = @connect_db(); } catch (Throwable $e) { $conn = null; }
        if ($conn) {
            $stmt = $conn->prepare("UPDATE promotion_crowd_workers SET status='pending', pid=NULL, heartbeat_at=NULL, started_at=NULL, finished_at=NULL, last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE run_id=? AND status IN ('queued','failed','stalled','pending') LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $runId);
                $stmt->execute();
                $stmt->close();
            }
            $conn->close();
        }
        $script = PP_ROOT_PATH . '/scripts/promotion_crowd_worker.php';
        if (!is_file($script)) {
            pp_promotion_log('promotion.crowd.worker_missing', ['script' => $script, 'run_id' => $runId]);
            return false;
        }
        $phpBinary = PHP_BINARY ?: 'php';
        $args = ' --run=' . $runId;
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
            pp_promotion_log('promotion.crowd.worker_run_launched', [
                'run_id' => $runId,
                'script' => $script,
                'mode' => $isWindows ? 'windows_popen' : 'posix_background',
                'slots' => [
                    'running' => $currentRunning + 1,
                    'max' => $maxParallel,
                ],
            ]);
            return true;
        }
        pp_promotion_log('promotion.crowd.worker_run_launch_failed', [
            'run_id' => $runId,
            'script' => $script,
            'mode' => $isWindows ? 'windows_popen' : 'posix_background',
        ]);
        return false;
    }
}

if (!function_exists('pp_promotion_launch_crowd_worker_for_run')) {
    function pp_promotion_launch_crowd_worker_for_run(int $runId, bool $allowFallback = true): bool {
        return pp_promotion_crowd_launch_worker_for_run($runId, $allowFallback);
    }
}

if (!function_exists('pp_promotion_crowd_prune_stale_workers')) {
    function pp_promotion_crowd_prune_stale_workers(int $timeoutSeconds = 180): array {
        $timeoutSeconds = max(60, $timeoutSeconds);
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['requeued' => 0]; }
        if (!$conn) { return ['requeued' => 0]; }
        $threshold = date('Y-m-d H:i:s', time() - $timeoutSeconds);
        $stmt = $conn->prepare("UPDATE promotion_crowd_workers
            SET status='queued', pid=NULL, heartbeat_at=NULL, started_at=NULL, finished_at=NULL, last_error='HEARTBEAT_TIMEOUT', updated_at=CURRENT_TIMESTAMP
            WHERE status='running' AND (
                (heartbeat_at IS NULL AND started_at IS NOT NULL AND started_at < ?)
                OR (heartbeat_at IS NOT NULL AND heartbeat_at < ?)
            )");
        $requeued = 0;
        if ($stmt) {
            $stmt->bind_param('ss', $threshold, $threshold);
            $stmt->execute();
            $requeued = $stmt->affected_rows;
            $stmt->close();
        }
        $conn->close();
        return ['requeued' => $requeued, 'threshold' => $threshold];
    }
}

if (!function_exists('pp_promotion_crowd_count_running_workers')) {
    function pp_promotion_crowd_count_running_workers(): int {
        try { $conn = @connect_db(); } catch (Throwable $e) { return 0; }
        if (!$conn) { return 0; }
        $count = 0;
        if ($res = @$conn->query("SELECT COUNT(*) AS c FROM promotion_crowd_workers WHERE status='running'")) {
            if ($row = $res->fetch_assoc()) {
                $count = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
        $conn->close();
        return $count;
    }
}

if (!function_exists('pp_promotion_crowd_collect_dispatch_queue')) {
    function pp_promotion_crowd_collect_dispatch_queue(int $limit = 10): array {
        $limit = max(1, $limit);
        try { $conn = @connect_db(); } catch (Throwable $e) { return []; }
        if (!$conn) { return []; }
        $runIds = [];
        $stmt = $conn->prepare("SELECT run_id FROM promotion_crowd_workers WHERE status IN ('queued','failed','stalled','pending') ORDER BY updated_at ASC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param('i', $limit);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $runId = (int)($row['run_id'] ?? 0);
                    if ($runId > 0) { $runIds[$runId] = $runId; }
                }
                $res->free();
            }
            $stmt->close();
        }
        $conn->close();
        return array_values($runIds);
    }
}

if (!function_exists('pp_promotion_crowd_sync_worker_rows')) {
    function pp_promotion_crowd_sync_worker_rows(int $limit = 30): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return []; }
        if (!$conn) { return []; }
        $runIds = [];
        $sql = "SELECT pr.id AS run_id
                FROM promotion_runs pr
                LEFT JOIN promotion_crowd_workers pcw ON pcw.run_id = pr.id
                WHERE pr.status IN ('crowd_ready','crowd_waiting')
                  AND (pcw.id IS NULL OR pcw.status IN ('completed','failed'))
                  AND EXISTS (
                      SELECT 1 FROM promotion_crowd_tasks pct
                      WHERE pct.run_id = pr.id
                        AND pct.status IN ('planned','queued','running','pending','created')
                  )
                ORDER BY pr.updated_at ASC
                LIMIT " . max(1, (int)$limit);
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $runId = (int)($row['run_id'] ?? 0);
                if ($runId > 0) {
                    $runIds[$runId] = $runId;
                }
            }
            $res->free();
        }
        $conn->close();
        foreach ($runIds as $runId) {
            pp_promotion_crowd_schedule_worker($runId);
        }
        return array_values($runIds);
    }
}

if (!function_exists('pp_promotion_crowd_recent_publications')) {
    function pp_promotion_crowd_recent_publications(mysqli $conn, int $runId, int $limit = 25): array {
        $limit = max(1, min(200, $limit));
        $result = [];
        $statuses = "'completed','success','done','posted','published','manual'";
        $sql = "SELECT id, node_id, crowd_link_id, target_url, result_url, status, payload_json, updated_at
                FROM promotion_crowd_tasks
                WHERE run_id = " . (int)$runId . " AND status IN ($statuses)
                ORDER BY updated_at DESC
                LIMIT " . $limit;
        if ($res = @$conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $payload = [];
                if (!empty($row['payload_json'])) {
                    $decoded = json_decode((string)$row['payload_json'], true);
                    if (is_array($decoded)) { $payload = $decoded; }
                }
                $result[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'node_id' => isset($row['node_id']) ? (int)$row['node_id'] : null,
                    'crowd_link_id' => isset($row['crowd_link_id']) ? (int)$row['crowd_link_id'] : null,
                    'status' => (string)($row['status'] ?? ''),
                    'result_url' => $row['result_url'] ?? null,
                    'target_url' => $row['target_url'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'manual_fallback' => !empty($payload['manual_fallback']),
                    'anchor' => $payload['anchor'] ?? null,
                    'message_preview' => isset($payload['body']) ? trim((string)$payload['body']) : null,
                    'author' => $payload['author_name'] ?? null,
                    'link_url' => $payload['crowd_link_url'] ?? null,
                ];
            }
            $res->free();
        }
        return $result;
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
    $summary = ['created' => 0, 'fallback' => 0, 'shortage' => false, 'stale_active' => []];
        if (empty($nodesNeeds)) {
            return $summary;
        }

        $runId = (int)($run['id'] ?? 0);
        if ($runId <= 0) {
            return $summary;
        }

        $existing = [];
        $existingLinksPerNode = [];
        $nodeDomainUsage = [];
        $nodeMessageHashes = [];
        $linkMessageHashes = [];
        $pendingStatuses = ['planned','queued','running','pending','created'];
        $successStatuses = ['completed','success','done','posted','published','ok','manual'];
        $stalePending = [];
        $staleThreshold = isset($options['stale_seconds']) ? max(60, (int)$options['stale_seconds']) : 900;
        $nowTs = time();
        if ($res = @$conn->query('SELECT pct.id, pct.node_id, pct.status, pct.crowd_link_id, pct.payload_json, pct.updated_at, pct.created_at, cl.domain FROM promotion_crowd_tasks pct LEFT JOIN crowd_links cl ON cl.id = pct.crowd_link_id WHERE pct.run_id=' . $runId)) {
            while ($row = $res->fetch_assoc()) {
                $nodeId = isset($row['node_id']) ? (int)$row['node_id'] : 0;
                $status = strtolower((string)($row['status'] ?? ''));
                $taskId = isset($row['id']) ? (int)$row['id'] : 0;
                $linkId = isset($row['crowd_link_id']) ? (int)$row['crowd_link_id'] : 0;
                if ($nodeId <= 0) { continue; }
                if (!isset($existing[$nodeId])) {
                    $existing[$nodeId] = ['completed' => 0, 'active' => 0];
                }
                if (in_array($status, $successStatuses, true)) {
                    $existing[$nodeId]['completed']++;
                } elseif (in_array($status, $pendingStatuses, true)) {
                    $updatedAtRaw = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
                    $createdAtRaw = isset($row['created_at']) ? (string)$row['created_at'] : '';
                    $updatedTs = null;
                    if ($updatedAtRaw !== '') {
                        $updatedTs = strtotime($updatedAtRaw);
                    }
                    if (($updatedTs === null || $updatedTs === false) && $createdAtRaw !== '') {
                        $updatedTs = strtotime($createdAtRaw);
                    }
                    if ($updatedTs === false) { $updatedTs = null; }
                    $isStale = false;
                    if ($updatedTs !== null) {
                        $isStale = ($nowTs - $updatedTs) > $staleThreshold;
                    } else {
                        $isStale = true;
                    }
                    if ($isStale) {
                        if (!isset($stalePending[$nodeId])) { $stalePending[$nodeId] = []; }
                        if ($taskId > 0) {
                            $stalePending[$nodeId][] = $taskId;
                        }
                    } else {
                        $existing[$nodeId]['active']++;
                    }
                }
                if ($linkId > 0) {
                    if (!isset($existingLinksPerNode[$nodeId])) { $existingLinksPerNode[$nodeId] = []; }
                    $existingLinksPerNode[$nodeId][$linkId] = ($existingLinksPerNode[$nodeId][$linkId] ?? 0) + 1;
                }
                $domainNormalized = strtolower(trim((string)($row['domain'] ?? '')));
                if ($domainNormalized !== '') {
                    if (!isset($nodeDomainUsage[$nodeId])) { $nodeDomainUsage[$nodeId] = []; }
                    $nodeDomainUsage[$nodeId][$domainNormalized] = true;
                }
                if (!empty($row['payload_json'])) {
                    $payloadExisting = json_decode((string)$row['payload_json'], true);
                    if (is_array($payloadExisting)) {
                        $existingBody = isset($payloadExisting['body']) && is_string($payloadExisting['body']) ? trim($payloadExisting['body']) : '';
                        $existingHash = null;
                        if (!empty($payloadExisting['message_hash'])) {
                            $existingHash = (string)$payloadExisting['message_hash'];
                        } elseif ($existingBody !== '') {
                            $existingHash = sha1($existingBody);
                        }
                        if ($existingHash !== null) {
                            if (!isset($nodeMessageHashes[$nodeId])) { $nodeMessageHashes[$nodeId] = []; }
                            $nodeMessageHashes[$nodeId][$existingHash] = true;
                            if ($linkId > 0) {
                                if (!isset($linkMessageHashes[$linkId])) { $linkMessageHashes[$linkId] = []; }
                                $linkMessageHashes[$linkId][$existingHash] = true;
                            }
                        }
                    }
                }
            }
            $res->free();
        }

        if (!empty($stalePending)) {
            $staleSummaryLog = [];
            foreach ($stalePending as $nodeId => $ids) {
                $staleSummaryLog[$nodeId] = count(array_unique(array_map('intval', $ids)));
            }
            pp_promotion_log('promotion.crowd.stale_pending_detected', [
                'run_id' => $runId,
                'stale_nodes' => $staleSummaryLog,
                'stale_seconds' => $staleThreshold,
            ]);
            $summary['stale_active'] = $staleSummaryLog;
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

        $availableLinks = pp_promotion_crowd_fetch_available_links($conn, $requiredTotal, [], [
            'preferred_language' => $preferredLanguage,
            'preferred_region' => $preferredRegion,
            'deep_statuses' => ['success', 'partial', 'manual', 'manual_review', 'needs_review'],
        ]);
        if (empty($availableLinks)) {
            $summary['shortage'] = true;
        }
        $availableCount = count($availableLinks);
        $linkCursor = 0;

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

        foreach ($createPlan as $nodeId => $details) {
            $targetUrl = (string)$details['target_url'];
            $amount = (int)$details['amount'];
            $meta = $nodeMeta[$nodeId] ?? [];
            $usedDomainsNode = $nodeDomainUsage[$nodeId] ?? [];
            $nodeHashes = $nodeMessageHashes[$nodeId] ?? [];
            $preMessagePool = pp_promotion_crowd_prepare_message_pool($project, $linkRow, $meta, $targetUrl, 10, array_keys($nodeHashes));
            $poolSize = count($preMessagePool);
            $poolIndex = 0;
            $assignedForNode = 0;
            $domainStrict = true;
            while ($assignedForNode < $amount) {
                $crowdLink = null;
                $linkFound = false;
                if ($availableCount > 0) {
                    for ($scan = 0; $scan < $availableCount; $scan++) {
                        $idx = ($linkCursor + $scan) % $availableCount;
                        $candidate = $availableLinks[$idx] ?? null;
                        if (!$candidate) { continue; }
                        $candidateId = isset($candidate['id']) ? (int)$candidate['id'] : 0;
                        if ($candidateId <= 0) { continue; }
                        $domainNormalized = strtolower(trim((string)($candidate['domain'] ?? '')));
                        if ($domainStrict && $domainNormalized !== '' && isset($usedDomainsNode[$domainNormalized])) {
                            continue;
                        }
                        $crowdLink = $candidate;
                        $linkCursor = ($idx + 1) % $availableCount;
                        $linkFound = true;
                        break;
                    }
                }
                if (!$linkFound) {
                    if ($domainStrict) {
                        $domainStrict = false;
                        continue;
                    }
                    $summary['shortage'] = true;
                    $crowdLink = null;
                }
                $linkIdCandidate = isset($crowdLink['id']) ? (int)$crowdLink['id'] : 0;
                $existingHashesLink = $linkMessageHashes[$linkIdCandidate] ?? [];
                $forcedMessage = null;
                if ($poolSize > 0) {
                    $maxAttempts = $poolSize;
                    $attempts = 0;
                    while ($attempts < $maxAttempts) {
                        $candidateIndex = $poolIndex % $poolSize;
                        $candidateMessage = $preMessagePool[$candidateIndex] ?? null;
                        $poolIndex++;
                        $attempts++;
                        if ($candidateMessage === null) { continue; }
                        $candidateHash = isset($candidateMessage['hash']) ? (string)$candidateMessage['hash'] : null;
                        if ($linkIdCandidate > 0 && $candidateHash !== null && isset($existingHashesLink[$candidateHash])) {
                            continue;
                        }
                        $forcedMessage = $candidateMessage;
                        break;
                    }
                    if ($forcedMessage === null && $poolSize > 0) {
                        $forcedMessage = $preMessagePool[$poolIndex % $poolSize] ?? null;
                    }
                }
                [$payload, $manualFallback] = pp_promotion_crowd_build_payload($project, $linkRow, $meta, $crowdLink, [
                    'target_url' => $targetUrl,
                    'run' => $run,
                    'node_id' => $nodeId,
                    'existing_hashes' => $existingHashesLink,
                    'forced_subject' => $forcedMessage['subject'] ?? null,
                    'forced_body' => $forcedMessage['body'] ?? null,
                    'forced_message_hash' => $forcedMessage['hash'] ?? null,
                    'forced_language' => $forcedMessage['language'] ?? null,
                ]);
                $messageHash = null;
                if (!empty($payload['message_hash'])) {
                    $messageHash = (string)$payload['message_hash'];
                } elseif (!empty($payload['body'])) {
                    $messageHash = sha1((string)$payload['body']);
                    $payload['message_hash'] = $messageHash;
                }
                if ($manualFallback || !$crowdLink) {
                    $payload['manual_fallback'] = true;
                    $payload['fallback_reason'] = $payload['fallback_reason'] ?? 'crowd_link_unavailable';
                    $payload['auto_result'] = [
                        'status' => 'manual',
                        'error' => $payload['fallback_reason'],
                        'completed_at' => gmdate('c'),
                    ];
                    $summary['fallback']++;
                }
                $status = $crowdLink ? 'queued' : 'manual';
                if (pp_promotion_crowd_insert_task($conn, $runId, $nodeId, $crowdLink, $targetUrl, $status, $payload)) {
                    $summary['created']++;
                    if ($linkIdCandidate > 0) {
                        if (!isset($existingLinksPerNode[$nodeId])) { $existingLinksPerNode[$nodeId] = []; }
                        $existingLinksPerNode[$nodeId][$linkIdCandidate] = ($existingLinksPerNode[$nodeId][$linkIdCandidate] ?? 0) + 1;
                        if (!isset($linkMessageHashes[$linkIdCandidate])) { $linkMessageHashes[$linkIdCandidate] = []; }
                        if ($messageHash !== null) {
                            $linkMessageHashes[$linkIdCandidate][$messageHash] = true;
                        }
                    }
                    if ($messageHash !== null) {
                        if (!isset($nodeMessageHashes[$nodeId])) { $nodeMessageHashes[$nodeId] = []; }
                        $nodeMessageHashes[$nodeId][$messageHash] = true;
                        $nodeHashes[$messageHash] = true;
                    }
                    $domainNormalized = strtolower(trim((string)($crowdLink['domain'] ?? '')));
                    if ($domainNormalized !== '') {
                        if (!isset($nodeDomainUsage[$nodeId])) { $nodeDomainUsage[$nodeId] = []; }
                        $nodeDomainUsage[$nodeId][$domainNormalized] = true;
                        $usedDomainsNode[$domainNormalized] = true;
                    }
                    $assignedForNode++;
                } else {
                    $summary['shortage'] = true;
                    break;
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
    function pp_promotion_crowd_worker(?int $specificTaskId = null, int $maxIterations = 30, ?int $runId = null, ?int $workerId = null): int {
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
        $maxIterations = max(1, min(500, $maxIterations));
        $startTime = microtime(true);
        for ($i = 0; $i < $maxIterations; $i++) {
            $task = pp_promotion_crowd_claim_task($conn, $specificTaskId, $runId);
            $specificTaskId = null;
            if (!$task) {
                $idleElapsed = microtime(true) - $startTime;
                if ($processed === 0 && $idleElapsed < 6.0) {
                    usleep(150000);
                    $task = pp_promotion_crowd_claim_task($conn, $specificTaskId, $runId);
                    if (!$task) { continue; }
                } else {
                    break;
                }
            }
            $startTime = microtime(true);
            try {
                pp_promotion_crowd_process_task($conn, $task);
                $processed++;
                if ($workerId !== null) {
                    pp_promotion_crowd_worker_heartbeat($workerId);
                }
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

if (!function_exists('pp_promotion_crowd_worker_run')) {
    function pp_promotion_crowd_worker_run(int $runId, int $maxIterations = 300, ?int $workerId = null, bool $interactiveWait = false): array {
        $runId = (int)$runId;
        if ($runId <= 0) { return ['processed' => 0, 'pending' => 0]; }
        $processed = pp_promotion_crowd_worker(null, $maxIterations, $runId, $workerId);
        $pending = 0;
        try { $conn = @connect_db(); } catch (Throwable $e) { $conn = null; }
        if ($conn) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=? AND status IN ('planned','queued','running','pending','created')");
            if ($stmt) {
                $stmt->bind_param('i', $runId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $pending = (int)($row['c'] ?? 0);
                    }
                }
                $stmt->close();
            }
            $conn->close();
        }
        if ($pending > 0) {
            pp_promotion_crowd_schedule_worker($runId);
        }
        return ['processed' => $processed, 'pending' => $pending];
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

        $allowedDeep = $options['deep_statuses'] ?? ['success', 'partial', 'manual', 'needs_review'];
        if (!is_array($allowedDeep) || empty($allowedDeep)) {
            $allowedDeep = ['success'];
        }
        $allowedDeepEsc = [];
        $allowedDeepNormalized = [];
        foreach ($allowedDeep as $statusValue) {
            $statusValue = trim((string)$statusValue);
            if ($statusValue === '') { continue; }
            $allowedDeepEsc[] = "'" . $conn->real_escape_string($statusValue) . "'";
            $allowedDeepNormalized[strtolower($statusValue)] = true;
        }
        if (empty($allowedDeepEsc)) {
            $allowedDeepEsc[] = "'success'";
            $allowedDeepNormalized['success'] = true;
        }
        $deepClause = ' AND deep_status IN (' . implode(',', $allowedDeepEsc) . ')';

        $fetchLimit = max($limit, min(20000, max($limit * 3, $limit + 500, 1200)));
        $sql = "SELECT id, url, domain, status, language, region, form_required, deep_status, deep_checked_at FROM crowd_links WHERE status = 'ok' {$deepClause}"
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
            $deepStatusNormalized = strtolower(trim((string)($row['deep_status'] ?? '')));
            if ($deepStatusNormalized !== '' && !isset($allowedDeepNormalized[$deepStatusNormalized])) {
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
        $forcedLanguageRaw = isset($options['forced_language']) ? trim((string)$options['forced_language']) : '';
        if ($forcedLanguageRaw !== '') {
            $language = pp_promotion_crowd_normalize_language($forcedLanguageRaw);
        }
        $texts = pp_promotion_crowd_texts($language);
        $anchor = trim((string)($linkRow['anchor'] ?? ''));
        if ($anchor === '') {
            $anchor = $projectName !== '' ? $projectName : __('Материал');
        }
        $existingHashes = [];
        if (!empty($options['existing_hashes']) && is_array($options['existing_hashes'])) {
            foreach ($options['existing_hashes'] as $hashKey => $present) {
                if (is_string($hashKey) && $hashKey !== '') {
                    $existingHashes[$hashKey] = true;
                } elseif (is_string($present) && $present !== '') {
                    $existingHashes[$present] = true;
                }
            }
        }
        $forcedSubjectRaw = isset($options['forced_subject']) ? trim((string)$options['forced_subject']) : '';
        $forcedBodyRaw = isset($options['forced_body']) ? trim((string)$options['forced_body']) : '';
        $forcedHashRaw = isset($options['forced_message_hash']) ? trim((string)$options['forced_message_hash']) : '';

        $defaultSubject = $anchor !== '' ? ($texts['subject_prefix'] . ': ' . $anchor) : $texts['subject_default'];
        $subject = $forcedSubjectRaw !== '' ? $forcedSubjectRaw : $defaultSubject;
        if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 120) {
            $subject = rtrim(mb_substr($subject, 0, 118, 'UTF-8')) . '…';
        } elseif (strlen($subject) > 120) {
            $subject = rtrim(substr($subject, 0, 118)) . '…';
        }
        if (function_exists('pp_promotion_clean_text')) {
            $subject = pp_promotion_clean_text($subject);
        }
        if ($subject === '') {
            $subject = $defaultSubject;
        }

        $linkForBody = $targetUrl !== '' ? $targetUrl : (string)($options['run']['target_url'] ?? '');
        $body = $forcedBodyRaw !== '' ? $forcedBodyRaw : pp_promotion_crowd_compose_message($language, $anchor, $linkForBody);
        if ($body === '') {
            $fallbackBody = trim(($texts['lead_without_anchor'] ?? 'Коллеги, делюсь ссылкой.') . "\n\n" . $linkForBody);
            if ($fallbackBody !== '') {
                $body = $fallbackBody;
            }
        }
        if ($body === '') {
            $body = $linkForBody !== '' ? $linkForBody : ($texts['feedback'] ?? '');
        }
        if (function_exists('pp_promotion_clean_text')) {
            $body = pp_promotion_clean_text($body);
        }

        $messageHash = null;
        if ($body !== '') {
            $messageHash = $forcedHashRaw !== '' ? $forcedHashRaw : sha1($language . '|' . trim($body));
            if (isset($existingHashes[$messageHash])) {
                $body .= "\n\n" . strtoupper(substr(sha1($body . microtime(true)), 0, 6));
                $messageHash = sha1($language . '|' . trim($body));
            }
        }

        $authorName = $projectName !== '' ? $projectName : $texts['author_default'];
        $needsPersona = ($authorName === '' || stripos($authorName, 'promopilot') !== false);
        if (!$needsPersona) {
            if (function_exists('mb_strlen')) {
                $needsPersona = mb_strlen($authorName, 'UTF-8') < 3;
            } else {
                $needsPersona = strlen($authorName) < 3;
            }
        }
        if ($needsPersona) {
            $authorName = pp_promotion_crowd_generate_persona($language);
        }
        try {
            $token = substr(bin2hex(random_bytes(8)), 0, 12);
        } catch (Throwable $e) {
            $token = substr(sha1(($body ?: (string)$targetUrl) . microtime(true)), 0, 12);
        }
        $emailSlug = pp_promotion_make_email_slug($authorName);
        if ($emailSlug === '') {
            $emailSlug = 'team';
        }
        $emailDomain = pp_promotion_crowd_pick_email_domain($language);
        $authorEmail = $emailSlug . '.' . strtolower(substr($token, 0, 6)) . '@' . $emailDomain;
        $websiteSource = $targetUrl !== '' ? $targetUrl : (string)($options['run']['target_url'] ?? '');
        if ($websiteSource === '' || !filter_var($websiteSource, FILTER_VALIDATE_URL)) {
            $websiteSource = 'https://' . $emailDomain;
        }

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
            'message_hash' => $messageHash,
        ];

        $identity = [
            'token' => $token,
            'message' => $body,
            'email' => $authorEmail,
            'name' => $authorName,
            'website' => $websiteSource,
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
