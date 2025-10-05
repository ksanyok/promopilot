<?php
// Project brief helpers: metadata analysis + AI-assisted name/description generation

if (!function_exists('pp_project_brief_log_truncate')) {
    function pp_project_brief_log_truncate(string $value, int $limit = 1200): string {
        if ($limit <= 0 || $value === '') { return $value; }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) { return $value; }
            return mb_substr($value, 0, $limit, 'UTF-8') . '…';
        }
        if (strlen($value) <= $limit) { return $value; }
        return substr($value, 0, $limit) . '…';
    }
}

if (!function_exists('pp_project_brief_log_event')) {
    function pp_project_brief_log_event(string $stage, array $data = []): void {
        if (!defined('PP_ROOT_PATH')) { return; }
        $logDir = PP_ROOT_PATH . '/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        if (!is_writable($logDir)) { @chmod($logDir, 0775); }
        $entry = [
            'time' => date('c'),
            'stage' => $stage,
            'data' => $data,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = sprintf(
                '{"time":"%s","stage":"%s","error":"json_encode_failed"}',
                date('c'),
                addslashes($stage)
            );
        }
        @file_put_contents($logDir . '/project_brief.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('pp_project_brief_truncate')) {
    function pp_project_brief_truncate(string $value, int $limit): string {
        $value = trim($value);
        if ($value === '' || $limit <= 0) { return ''; }
        if (function_exists('mb_substr')) {
            return trim(mb_substr($value, 0, $limit, 'UTF-8'));
        }
        return trim(substr($value, 0, $limit));
    }
}

if (!function_exists('pp_project_brief_extract_json_object')) {
    function pp_project_brief_extract_json_object(string $text): ?array {
        $text = trim($text);
        if ($text === '') { return null; }
        $length = strlen($text);
        $offset = strpos($text, '{');
        while ($offset !== false) {
            $depth = 0;
            $inString = false;
            $prev = '';
            for ($i = $offset; $i < $length; $i++) {
                $char = $text[$i];
                if ($char === '"' && $prev !== '\\') {
                    $inString = !$inString;
                }
                if ($inString) {
                    $prev = $char;
                    continue;
                }
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($text, $offset, $i - $offset + 1);
                        $decoded = json_decode($candidate, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            return $decoded;
                        }
                        break;
                    }
                }
                $prev = $char;
            }
            $offset = strpos($text, '{', $offset + 1);
        }
        return null;
    }
}

if (!function_exists('pp_normalize_locale_code')) {
    function pp_normalize_locale_code(?string $code): string {
        $code = strtolower(trim((string)$code));
        if ($code === '') { return 'ru'; }
        $parts = preg_split('~[-_]~', $code);
        $lang = $parts[0] ?? 'ru';
        return $lang !== '' ? $lang : 'ru';
    }
}

if (!function_exists('pp_detect_language_from_meta')) {
    function pp_detect_language_from_meta(array $meta): string {
        $lang = trim((string)($meta['lang'] ?? ''));
        if ($lang !== '') { return pp_normalize_locale_code($lang); }
        $hreflang = $meta['hreflang'] ?? [];
        if (is_array($hreflang) && !empty($hreflang)) {
            $first = $hreflang[0]['hreflang'] ?? '';
            if ($first !== '') { return pp_normalize_locale_code($first); }
        }
        return 'ru';
    }
}

if (!function_exists('pp_project_brief_prepare_ai_payload')) {
    function pp_project_brief_prepare_ai_payload(array $options): array {
        $url = trim((string)($options['url'] ?? ''));
        $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
        $lang = pp_detect_language_from_meta($meta);
        $langLabel = strtoupper($lang ?: 'RU');

        $title = trim((string)($meta['title'] ?? ''));
        $desc = trim((string)($meta['description'] ?? ''));
        $region = trim((string)($meta['region'] ?? ''));
        $finalUrl = trim((string)($meta['final_url'] ?? $url));

        $promptPieces = [];
        if ($finalUrl !== '') { $promptPieces[] = 'URL: ' . $finalUrl; }
        if ($title !== '') { $promptPieces[] = 'Meta title: ' . $title; }
        if ($desc !== '') { $promptPieces[] = 'Meta description: ' . $desc; }
        if (!empty($meta['hreflang']) && is_array($meta['hreflang'])) {
            $variants = array_slice(array_filter(array_map(static function ($item) {
                if (!is_array($item)) { return null; }
                $code = trim((string)($item['hreflang'] ?? $item['lang'] ?? ''));
                $href = trim((string)($item['href'] ?? ''));
                if ($code === '' || $href === '') { return null; }
                return strtoupper($code) . ' → ' . $href;
            }, $meta['hreflang'])), 0, 6);
            if (!empty($variants)) {
                $promptPieces[] = 'Hreflang: ' . implode('; ', $variants);
            }
        }
        if ($region !== '') { $promptPieces[] = 'Region hint: ' . strtoupper($region); }
        $prompt = implode("\n", $promptPieces);

        $systemPrompt = 'You are PromoPilot assistant. You craft ultra-short project names and concise briefs for marketing managers.';
    $userPrompt = "Проанализируй сайт и подготовь предложение на языке {$langLabel}. Сформируй новое, ёмкое название проекта (до 20 символов, без кавычек, эмодзи и технических приставок), которое чётко отражает тематику сайта и его основное предложение. Добавь одно краткое описание (до 200 символов), поясняющее, что именно пользователь найдёт на странице и для кого это полезно. Избегай рекламных лозунгов, формулировок про скидки и общих фраз, не пересказывай метатеги дословно.";
        if ($prompt !== '') { $userPrompt .= "\n\nДанные страницы:\n" . $prompt; }
    $userPrompt .= "\n\nВерни ответ строго в JSON без форматирования и комментариев с ключами: name, description. Ответ должен быть на языке {$langLabel}.";

        return [
            'language' => $lang,
            'system' => $systemPrompt,
            'prompt' => $userPrompt,
        ];
    }
}

if (!function_exists('pp_project_brief_normalize_text')) {
    function pp_project_brief_normalize_text(string $value): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = preg_replace('~["“”«»„‟’`]+~u', ' ', $value);
        $value = preg_replace('~[^a-z0-9а-яёїієґçäöüßáàâãéèêíìîñóòôõúùûçæœ\s]+~iu', ' ', $value);
        $value = preg_replace('~\s+~u', ' ', $value);
        return trim((string)$value);
    }
}

if (!function_exists('pp_project_brief_is_generic_name')) {
    function pp_project_brief_is_generic_name(string $value, array $keywords = []): bool {
        $normalized = pp_project_brief_normalize_text($value);
        if ($normalized === '') { return true; }
        $words = preg_split('~\s+~u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) { return true; }
        $generic = [
            'online','онлайн','сайт','сайты','проект','проекты','project','projects','платформа','платформы','platform','service','services','сервис','сервисы','магазин','shop','store','каталог','catalog','портал','portal','решение','решения','solution','solutions','digital','media'
        ];
        $hasSpecificWord = false;
        foreach ($words as $word) {
            if (!in_array($word, $generic, true)) {
                $hasSpecificWord = true;
                break;
            }
        }
        if ($hasSpecificWord) { return false; }

        if (!empty($keywords)) {
            $lookup = [];
            foreach ($keywords as $keyword) {
                $kw = pp_project_brief_normalize_text((string)$keyword);
                if ($kw !== '') { $lookup[$kw] = true; }
            }
            if (!empty($lookup)) {
                foreach ($words as $word) {
                    if (isset($lookup[$word])) { return false; }
                }
            }
        }

        return true;
    }
}

if (!function_exists('pp_project_brief_is_similar_to_source')) {
    function pp_project_brief_is_similar_to_source(string $candidate, string $source, float $threshold = 0.8): bool {
        if ($candidate === '' || $source === '') { return false; }
        $normalizedCandidate = pp_project_brief_normalize_text($candidate);
        $normalizedSource = pp_project_brief_normalize_text($source);
        if ($normalizedCandidate === '' || $normalizedSource === '') { return false; }
        if ($normalizedCandidate === $normalizedSource) { return true; }
        if (strpos($normalizedSource, $normalizedCandidate) !== false || strpos($normalizedCandidate, $normalizedSource) !== false) {
            return true;
        }
        $percent = 0.0;
        similar_text($normalizedCandidate, $normalizedSource, $percent);
        if ($percent >= $threshold * 100) { return true; }
        $candidateWords = array_filter(explode(' ', $normalizedCandidate));
        $sourceWords = array_filter(explode(' ', $normalizedSource));
        if (!empty($candidateWords) && !empty($sourceWords)) {
            $intersection = array_intersect($candidateWords, $sourceWords);
            if (!empty($intersection)) {
                $ratio = count($intersection) / max(1, count($candidateWords));
                if ($ratio >= $threshold) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('pp_project_brief_collect_validation_issues')) {
    function pp_project_brief_collect_validation_issues(string $name, string $description, string $metaTitle = '', string $metaDescription = '', array $keywords = []): array {
        $issues = [];

        $nameNormalized = pp_project_brief_normalize_text($name);
        $nameLength = $nameNormalized === '' ? 0 : (function_exists('mb_strlen') ? mb_strlen($nameNormalized, 'UTF-8') : strlen($nameNormalized));
        if ($nameLength < 3) {
            $issues[] = 'name_empty';
        }
        if ($nameNormalized !== '' && preg_match('~\b(название|sample|пример|demo|test)\b~iu', $nameNormalized)) {
            $issues[] = 'name_placeholder';
        }
        if ($name !== '' && pp_project_brief_is_generic_name($name, $keywords)) {
            $issues[] = 'name_generic';
        }
        if ($name !== '' && $metaTitle !== '' && pp_project_brief_is_similar_to_source($name, $metaTitle, 0.97)) {
            $issues[] = 'name_similar';
        }

        $descriptionNormalized = pp_project_brief_normalize_text($description);
        $descriptionLength = $descriptionNormalized === '' ? 0 : (function_exists('mb_strlen') ? mb_strlen($descriptionNormalized, 'UTF-8') : strlen($descriptionNormalized));
        if ($descriptionLength < 20) {
            $issues[] = 'description_short';
        }
        if ($descriptionNormalized !== '' && preg_match('~\b(описание|sample|пример|lorem)\b~iu', $descriptionNormalized)) {
            $issues[] = 'description_placeholder';
        }
        if ($description !== '' && $metaDescription !== '' && pp_project_brief_is_similar_to_source($description, $metaDescription, 0.98)) {
            $issues[] = 'description_similar';
        }

        return array_values(array_unique($issues));
    }
}

if (!function_exists('pp_project_brief_refine_name')) {
    function pp_project_brief_refine_name(string $candidate, string $metaTitle = '', string $url = ''): string {
        $candidate = trim(preg_replace('~\s+~u', ' ', $candidate));
        if ($candidate === '') { return ''; }
        $candidate = preg_replace('~["«»“”„‟]+~u', '', $candidate);
        $normalized = pp_project_brief_normalize_text($candidate);

        if ($normalized !== '' && $metaTitle !== '') {
            $metaNormalized = pp_project_brief_normalize_text($metaTitle);
            if ($metaNormalized !== '' && ($metaNormalized === $normalized || pp_project_brief_is_similar_to_source($candidate, $metaTitle, 0.98))) {
                $candidate = '';
            }
        }

        if ($candidate === '' && $url !== '') {
            $host = pp_project_brief_extract_domain($url);
            if ($host !== '') {
                $candidate = pp_project_brief_mb_ucfirst($host);
            }
        }

        return pp_project_brief_truncate($candidate, 20);
    }
}

if (!function_exists('pp_project_brief_refine_description')) {
    function pp_project_brief_refine_description(string $candidate, string $metaDescription = ''): string {
        $candidate = trim(preg_replace('~\s+~u', ' ', $candidate));
        if ($candidate === '') { return ''; }
        if ($metaDescription !== '') {
            $metaNormalized = pp_project_brief_normalize_text($metaDescription);
            $candidateNormalized = pp_project_brief_normalize_text($candidate);
            if ($metaNormalized !== '' && $candidateNormalized !== '' && ($candidateNormalized === $metaNormalized || pp_project_brief_is_similar_to_source($candidate, $metaDescription, 0.98))) {
                $candidate = '';
            }
        }

        return pp_project_brief_truncate($candidate, 240);
    }
}

if (!function_exists('pp_project_brief_mb_ucfirst')) {
    function pp_project_brief_mb_ucfirst(string $text): string {
        if ($text === '') { return ''; }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strlen')) {
            $first = mb_substr($text, 0, 1, 'UTF-8');
            $rest = mb_substr($text, 1, null, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') . $rest;
        }
        return ucfirst($text);
    }
}

if (!function_exists('pp_project_brief_extract_keywords')) {
    function pp_project_brief_extract_keywords(string $text, int $limit = 6, array $extraStopWords = []): array {
        if ($text === '') { return []; }
        $words = preg_split('~[^\p{L}\p{N}]+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) { return []; }
        $defaultStops = [
            'and','the','for','with','your','our','company','official','service','services','solution','solutions','team','business','online','project','promo','best','site','sites','buy','ready','website','platform','digital','marketing','подробнее','компания','сайт','сайта','ваш','ваша','наша','это','этот','эта','для','уже','готовый','готовые','готовая','лучшие','решение','решения','бизнес','команда','маркетинг','проект','сервисы','портал','сервис','онлайн'
        ];
        $stops = array_merge($defaultStops, $extraStopWords);
        $stopMap = [];
        foreach ($stops as $word) {
            $w = (string)$word;
            $w = function_exists('mb_strtolower') ? mb_strtolower($w, 'UTF-8') : strtolower($w);
            $w = trim($w);
            if ($w !== '') { $stopMap[$w] = true; }
        }
        $keywords = [];
        foreach ($words as $word) {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
            $lower = trim($lower);
            if ($lower === '' || isset($stopMap[$lower])) { continue; }
            if ((function_exists('mb_strlen') ? mb_strlen($lower, 'UTF-8') : strlen($lower)) < 3) { continue; }
            if (!isset($keywords[$lower])) {
                $keywords[$lower] = $word;
                if (count($keywords) >= $limit) { break; }
            }
        }
        return array_values($keywords);
    }
}

if (!function_exists('pp_project_brief_build_name_from_keywords')) {
    function pp_project_brief_build_name_from_keywords(array $keywords, string $language, string $fallback = ''): string {
        $lang = strtolower(substr($language, 0, 2));
        $candidate = '';
        if (count($keywords) >= 2) {
            $candidate = pp_project_brief_mb_ucfirst(trim($keywords[0])) . ' ' . pp_project_brief_mb_ucfirst(trim($keywords[1]));
        } elseif (count($keywords) === 1) {
            $candidate = pp_project_brief_mb_ucfirst(trim($keywords[0]));
        }
        if ($candidate === '' && $fallback !== '') {
            $candidate = $fallback;
        }
        if ($candidate === '') {
            $candidate = ($lang === 'ru') ? 'Новый проект' : 'New project';
        }
        return trim($candidate);
    }
}

if (!function_exists('pp_project_brief_build_description_from_keywords')) {
    function pp_project_brief_build_description_from_keywords(array $keywords, string $language, string $metaDescription = ''): string {
        $lang = strtolower(substr($language, 0, 2));
        $primary = array_values($keywords);
        $sentence = '';
        if ($lang === 'ru') {
            if (count($primary) >= 3) {
                $sentence = "Решение для {$primary[0]}: {$primary[1]} и {$primary[2]} без лишних усилий.";
            } elseif (count($primary) === 2) {
                $sentence = "Помогаем {$primary[0]} достигать {$primary[1]} быстрее.";
            } elseif (count($primary) === 1) {
                $sentence = "Предлагаем современный подход к {$primary[0]}";
            }
        } else {
            if (count($primary) >= 3) {
                $sentence = "Solution for {$primary[0]}: {$primary[1]} and {$primary[2]} made simple.";
            } elseif (count($primary) === 2) {
                $sentence = "Helping {$primary[0]} achieve {$primary[1]} faster.";
            } elseif (count($primary) === 1) {
                $sentence = "Modern approach to {$primary[0]}";
            }
        }
        if ($sentence === '' && $metaDescription !== '') {
            $sentence = $metaDescription;
        }
        if ($sentence === '') {
            $sentence = ($lang === 'ru') ? 'Краткое описание проекта для быстрого старта.' : 'Concise project summary for a fast launch.';
        }
        return pp_project_brief_mb_ucfirst(trim($sentence));
    }
}

if (!function_exists('pp_project_brief_generate_from_ai')) {
    function pp_project_brief_generate_from_ai(array $options): array {
        $job = pp_project_brief_prepare_ai_payload($options);

        $providerSetting = strtolower((string)get_setting('ai_provider', 'openai'));
        $provider = $providerSetting === 'byoa' ? 'byoa' : 'openai';
        $apiKey = trim((string)get_setting('openai_api_key', ''));

        if ($provider === 'openai' && $apiKey === '') {
            pp_project_brief_log_event('ai_skip_missing_key', [
                'provider' => $provider,
            ]);
            return [
                'name' => '',
                'description' => '',
                'used_ai' => false,
                'error' => 'missing_api_key',
                'provider' => $provider,
            ];
        }

        if (!function_exists('pp_run_ai_completion') || !defined('PP_ROOT_PATH')) {
            pp_project_brief_log_event('ai_client_unavailable', [
                'provider' => $provider,
            ]);
            return [
                'name' => '',
                'description' => '',
                'used_ai' => false,
                'error' => 'client_unavailable',
                'provider' => $provider,
            ];
        }

        $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
        $metaTitle = trim((string)($meta['title'] ?? ''));
        $metaDescription = trim((string)($meta['description'] ?? ''));
        $finalUrl = trim((string)($meta['final_url'] ?? ($options['url'] ?? '')));
        $language = $job['language'] ?? 'ru';

        $domainStops = [];
        if ($finalUrl !== '') {
            $host = parse_url($finalUrl, PHP_URL_HOST) ?: '';
            if ($host !== '') {
                $host = strtolower($host);
                if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
                $domainStops = array_filter(preg_split('~[\.-]+~', $host));
            }
        }

        $keywords = pp_project_brief_extract_keywords(trim($metaTitle . ' ' . $metaDescription), 8, $domainStops);
        $model = $provider === 'openai' ? trim((string)get_setting('openai_model', '')) : '';

        $contextLog = [
            'provider' => $provider,
            'language' => $language,
            'url' => $finalUrl,
            'meta_title' => pp_project_brief_log_truncate($metaTitle, 240),
            'meta_description' => pp_project_brief_log_truncate($metaDescription, 360),
            'keywords' => $keywords,
        ];
        if ($model !== '') { $contextLog['model'] = $model; }
        pp_project_brief_log_event('ai_context_prepared', $contextLog);

        $basePrompt = (string)($job['prompt'] ?? '');
        $systemPrompt = (string)($job['system'] ?? '');
        $temperature = 0.45;
        $promptSuffix = '';
        $maxAttempts = 2;

        $issueHints = [
            'name_empty' => 'Название должно содержать осмысленные слова, отражающие тематику проекта.',
            'name_placeholder' => 'Не используй шаблонные слова вроде «название», «sample» или «пример».',
            'name_generic' => 'Добавь конкретики: кто клиент или какой продукт, избегай общих слов вроде «онлайн» или «сайт».',
            'name_similar' => 'Переформулируй название, чтобы оно отличалось от исходного метатега страницы.',
            'description_short' => 'Описание должно быть одним развернутым предложением с выгодой или назначением проекта (не менее ~20 символов).',
            'description_placeholder' => 'Не используй заглушки вида «описание» или «lorem ipsum».',
            'description_similar' => 'Перепиши описание, чтобы оно отличалось от мета-описания страницы.',
        ];

        $attemptsInfo = [];
        $lastRaw = '';
        $lastIssues = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $requestPrompt = $basePrompt . $promptSuffix;
            $requestLog = [
                'attempt' => $attempt,
                'provider' => $provider,
                'temperature' => $temperature,
                'prompt' => pp_project_brief_log_truncate($requestPrompt),
            ];
            if ($model !== '') { $requestLog['model'] = $model; }
            pp_project_brief_log_event('ai_request', $requestLog);

            $aiPayload = [
                'provider' => $provider,
                'prompt' => $requestPrompt,
                'systemPrompt' => $systemPrompt,
                'temperature' => $temperature,
            ];
            if ($model !== '') { $aiPayload['model'] = $model; }
            $result = pp_run_ai_completion($aiPayload);

            if (!is_array($result)) {
                pp_project_brief_log_event('ai_response', [
                    'attempt' => $attempt,
                    'ok' => false,
                    'error' => 'invalid_response_type',
                    'raw_type' => gettype($result),
                ]);
                $response = [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => 'ai_call_failed',
                    'provider' => $provider,
                ];
                if ($model !== '') { $response['model'] = $model; }
                return $response;
            }

            $textRaw = trim((string)($result['text'] ?? ''));
            pp_project_brief_log_event('ai_response', [
                'attempt' => $attempt,
                'ok' => !empty($result['ok']),
                'error' => $result['error'] ?? null,
                'text' => pp_project_brief_log_truncate($textRaw),
            ]);

            if (empty($result['ok'])) {
                $errorCode = !empty($result['error']) ? (string)$result['error'] : 'ai_call_failed';
                $errorLog = [
                    'attempt' => $attempt,
                    'provider' => $provider,
                    'error' => $errorCode,
                ];
                if ($model !== '') { $errorLog['model'] = $model; }
                pp_project_brief_log_event('ai_error', $errorLog);
                $response = [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => $errorCode,
                    'provider' => $provider,
                ];
                if ($model !== '') { $response['model'] = $model; }
                return $response;
            }

            if ($textRaw === '') {
                $emptyLog = [
                    'attempt' => $attempt,
                    'provider' => $provider,
                ];
                if ($model !== '') { $emptyLog['model'] = $model; }
                pp_project_brief_log_event('ai_empty_text', $emptyLog);
                $response = [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => 'empty_response',
                    'provider' => $provider,
                ];
                if ($model !== '') { $response['model'] = $model; }
                return $response;
            }

            $lastRaw = $textRaw;
            $decoded = json_decode($textRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $decoded = pp_project_brief_extract_json_object($textRaw);
            }

            if (!is_array($decoded)) {
                $jsonLog = [
                    'attempt' => $attempt,
                    'provider' => $provider,
                    'text' => pp_project_brief_log_truncate($textRaw),
                    'json_error' => json_last_error_msg(),
                ];
                if ($model !== '') { $jsonLog['model'] = $model; }
                pp_project_brief_log_event('ai_json_error', $jsonLog);
                if ($attempt < $maxAttempts) {
                    $promptSuffix = "\n\nПредыдущий ответ содержал пояснения. Верни только JSON формата {\"name\":\"...\",\"description\":\"...\"} без Markdown.";
                    $temperature = min(0.75, $temperature + 0.1);
                    continue;
                }
                $response = [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => 'invalid_json',
                    'provider' => $provider,
                    'raw' => $textRaw,
                ];
                if ($model !== '') { $response['model'] = $model; }
                return $response;
            }

            $name = pp_project_brief_truncate(pp_project_brief_refine_name((string)($decoded['name'] ?? ''), $metaTitle, $finalUrl), 20);
            $description = pp_project_brief_truncate(pp_project_brief_refine_description((string)($decoded['description'] ?? ''), $metaDescription), 240);

            $issues = pp_project_brief_collect_validation_issues($name, $description, $metaTitle, $metaDescription, $keywords);
            $lastIssues = $issues;

            $attemptsInfo[] = [
                'attempt' => $attempt,
                'name' => $name,
                'description' => $description,
                'issues' => $issues,
                'temperature' => $temperature,
            ];

            if (empty($issues)) {
                $successLog = [
                    'attempt' => $attempt,
                    'provider' => $provider,
                    'name' => pp_project_brief_log_truncate($name),
                    'description' => pp_project_brief_log_truncate($description, 160),
                ];
                if ($model !== '') { $successLog['model'] = $model; }
                pp_project_brief_log_event('ai_success', $successLog);
                return [
                    'name' => $name,
                    'description' => $description,
                    'used_ai' => true,
                    'provider' => $provider,
                    'raw' => $lastRaw,
                    'attempts' => $attemptsInfo,
                ] + ($model !== '' ? ['model' => $model] : []);
            }

            $invalidLog = [
                'attempt' => $attempt,
                'provider' => $provider,
                'issues' => $issues,
            ];
            if ($model !== '') { $invalidLog['model'] = $model; }
            pp_project_brief_log_event('ai_invalid_payload', $invalidLog);

            if ($attempt < $maxAttempts) {
                $promptHints = [];
                foreach ($issues as $issue) {
                    if (isset($issueHints[$issue])) {
                        $promptHints[] = $issueHints[$issue];
                    }
                }
                $promptHints = array_values(array_unique($promptHints));
                $promptSuffix = !empty($promptHints)
                    ? "\n\n" . implode(' ', $promptHints)
                    : "\n\nПерезапиши ответ, оставив только корректный JSON с ключами name и description.";
                $temperature = min(0.85, $temperature + 0.15);
                continue;
            }
        }

        $fallbackName = pp_project_brief_build_name_from_keywords($keywords, $language, $metaTitle, $finalUrl);
        $fallbackDescription = pp_project_brief_build_description_from_keywords($keywords, $language, $metaDescription);

        $keywordLog = [
            'provider' => $provider,
            'name' => pp_project_brief_log_truncate($fallbackName),
            'description' => pp_project_brief_log_truncate($fallbackDescription, 160),
            'issues' => $lastIssues,
        ];
        if ($model !== '') { $keywordLog['model'] = $model; }
        pp_project_brief_log_event('ai_keywords_fallback', $keywordLog);

        $fallbackResponse = [
            'name' => $fallbackName,
            'description' => $fallbackDescription,
            'used_ai' => false,
            'provider' => $provider,
            'error' => 'ai_fallback',
            'fallback' => 'keywords',
            'raw' => $lastRaw,
            'attempts' => $attemptsInfo,
        ];
        if ($model !== '') { $fallbackResponse['model'] = $model; }
        return $fallbackResponse;
    }
}

if (!function_exists('pp_run_ai_completion')) {
    function pp_run_ai_completion(array $options): ?array {
        $provider = strtolower((string)($options['provider'] ?? 'openai'));
        $prompt = (string)($options['prompt'] ?? '');
        $systemPrompt = trim((string)($options['systemPrompt'] ?? ''));
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.3;
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 45;

        $node = pp_resolve_node_binary(3, true);
        if (!$node || empty($node['path'])) {
            return null;
        }
        $script = PP_ROOT_PATH . '/scripts/project_brief_cli.js';
        if (!is_file($script)) {
            if (!is_dir(PP_ROOT_PATH . '/scripts')) { @mkdir(PP_ROOT_PATH . '/scripts', 0775, true); }
            $content = <<<'JS'
const { generateText } = require('../networks/ai_client');
(async () => {
  try {
    const payload = JSON.parse(process.env.PP_JOB || '{}');
    const text = await generateText(payload.prompt || '', {
      provider: (payload.provider || 'openai').toLowerCase(),
      openaiApiKey: payload.openaiApiKey || process.env.OPENAI_API_KEY || '',
      model: payload.model || process.env.OPENAI_MODEL,
      temperature: typeof payload.temperature === 'number' ? payload.temperature : 0.3,
      systemPrompt: payload.systemPrompt || undefined,
      keepRaw: true,
    });
    process.stdout.write(JSON.stringify({ ok: true, text }) + '\n');
    process.exit(0);
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: error && error.message ? error.message : String(error) }) + '\n');
    process.exit(1);
  }
})();
JS;
            file_put_contents($script, $content);
        }

        $job = [
            'prompt' => $prompt,
            'systemPrompt' => $systemPrompt,
            'temperature' => $temperature,
            'provider' => $provider,
            'model' => trim((string)($options['model'] ?? get_setting('openai_model', ''))),
            'openaiApiKey' => trim((string)get_setting('openai_api_key', '')),
        ];
        $response = pp_run_node_script($script, $job, $timeout);
        if (!is_array($response) || empty($response['ok'])) {
            return null;
        }
        return $response;
    }
}

if (!function_exists('pp_project_brief_analyze_site')) {
    function pp_project_brief_analyze_site(string $url): ?array {
        if (!function_exists('pp_analyze_url_data')) { return null; }
        try { $meta = pp_analyze_url_data($url); } catch (Throwable $e) { return null; }
        if (!is_array($meta)) { return null; }
        $lang = pp_detect_language_from_meta($meta);
        $name = trim((string)($meta['title'] ?? ''));
        $description = trim((string)($meta['description'] ?? ''));
        return [
            'meta' => $meta,
            'lang' => $lang,
            'name' => $name,
            'description' => $description,
        ];
    }
}

if (!function_exists('pp_project_brief_prepare_initial')) {
    function pp_project_brief_prepare_initial(string $url): array {
        $analysis = pp_project_brief_analyze_site($url);
        $meta = $analysis['meta'] ?? [];
        $detectedLang = $analysis['lang'] ?? 'ru';
        $fallbackTitle = trim((string)($analysis['name'] ?? ($meta['title'] ?? '')));
        $fallbackDescription = trim((string)($analysis['description'] ?? ($meta['description'] ?? '')));

        $ai = pp_project_brief_generate_from_ai([
            'url' => $url,
            'meta' => $meta,
        ]);
        if (!is_array($ai)) {
            $ai = [
                'name' => '',
                'description' => '',
                'used_ai' => false,
                'error' => 'ai_unavailable',
            ];
        }

        $name = $ai['name'] ?? '';
        $description = $ai['description'] ?? '';

        if ($name === '') { $name = $fallbackTitle; }
        if ($description === '') { $description = $fallbackDescription; }

        $name = pp_project_brief_truncate($name, 20);
        $description = pp_project_brief_truncate($description, 240);

        if ($name === '' && $url !== '') {
            $host = pp_project_brief_extract_domain($url);
            if ($host !== '') { $name = pp_project_brief_truncate($host, 20); }
        }

        if (empty($ai['used_ai'] ?? false)) {
            $ai['name'] = $name;
            $ai['description'] = $description;
        }

        return [
            'name' => $name,
            'description' => $description,
            'language' => $detectedLang,
            'meta' => $meta,
            'ai' => $ai,
            'used_ai' => !empty($ai['used_ai']),
        ];
    }
}

if (!function_exists('pp_project_brief_extract_domain')) {
    function pp_project_brief_extract_domain(string $url): string {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = strtolower((string)$host);
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        return $host;
    }
}
