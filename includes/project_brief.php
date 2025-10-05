<?php
// Project brief helpers: metadata analysis + AI-assisted name/description generation

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
        $userPrompt = "Проанализируй сайт и подготовь предложение на языке {$langLabel}. Сформируй новое, ёмкое название проекта (до 20 символов, без кавычек, эмодзи и технических приставок) и одно краткое описание (до 200 символов). Не пересказывай метатеги дословно — придумай свежую формулировку, отражающую пользу проекта.";
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

if (!function_exists('pp_project_brief_refine_name')) {
    function pp_project_brief_refine_name(string $candidate, string $metaTitle = '', string $url = ''): string {
        $original = $candidate;
        $words = preg_split('~\s+~u', $candidate, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) { return $candidate; }
        $stopWords = [];
        if ($metaTitle !== '') {
            $stopWords = array_filter(explode(' ', pp_project_brief_normalize_text($metaTitle)));
        }
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            if ($host !== '') {
                $host = preg_replace('~^www\.~i', '', strtolower($host));
                $parts = array_filter(preg_split('~[\.-]+~', $host));
                $stopWords = array_merge($stopWords, $parts);
            }
        }
        $stopMap = array_flip(array_filter($stopWords));
        $filtered = [];
        foreach ($words as $word) {
            $normalized = pp_project_brief_normalize_text($word);
            if ($normalized === '' || isset($stopMap[$normalized])) { continue; }
            $filtered[] = $word;
        }
        if (!empty($filtered)) {
            $candidate = implode(' ', $filtered);
        }
        if ($candidate === '') { return $original; }
        if (function_exists('mb_substr') && mb_strlen($candidate, 'UTF-8') > 20) {
            $candidate = trim(mb_substr($candidate, 0, 20, 'UTF-8'));
        } elseif (strlen($candidate) > 20) {
            $candidate = trim(substr($candidate, 0, 20));
        }
        return $candidate !== '' ? $candidate : $original;
    }
}

if (!function_exists('pp_project_brief_refine_description')) {
    function pp_project_brief_refine_description(string $candidate, string $metaDescription = ''): string {
        $original = $candidate;
        if ($candidate === '' || $metaDescription === '') { return $candidate; }
        $normalizedMeta = pp_project_brief_normalize_text($metaDescription);
        if ($normalizedMeta === '') { return $candidate; }
        $candidateNorm = pp_project_brief_normalize_text($candidate);
        if ($candidateNorm === '') { return $candidate; }
        if (strpos($candidateNorm, $normalizedMeta) !== false) {
            $candidate = trim(str_ireplace($metaDescription, '', $candidate));
        }
        $sentences = preg_split('~[.!?]+\s*~u', $metaDescription, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($sentences as $sentence) {
            $sentNorm = pp_project_brief_normalize_text($sentence);
            if ($sentNorm === '') { continue; }
            if (strpos(pp_project_brief_normalize_text($candidate), $sentNorm) !== false) {
                $candidate = trim(str_ireplace($sentence, '', $candidate));
            }
        }
        $candidate = preg_replace('~\s+~u', ' ', trim($candidate));
        return $candidate !== '' ? $candidate : $original;
    }
}

if (!function_exists('pp_project_brief_generate_from_ai')) {
    function pp_project_brief_generate_from_ai(array $options): array {
        $job = pp_project_brief_prepare_ai_payload($options);
        $provider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';
        $key = trim((string)get_setting('openai_api_key', ''));
        if ($provider === 'openai' && $key === '') {
            return [
                'name' => '',
                'description' => '',
                'used_ai' => false,
                'error' => 'missing_api_key',
                'provider' => $provider,
            ];
        }
        if (!function_exists('pp_run_ai_completion')) {
            if (!defined('PP_ROOT_PATH')) {
                return [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => 'client_unavailable',
                    'provider' => $provider,
                ];
            }
            $clientPath = PP_ROOT_PATH . '/networks/ai_client.js';
            if (!is_file($clientPath)) {
                return [
                    'name' => '',
                    'description' => '',
                    'used_ai' => false,
                    'error' => 'client_missing',
                    'provider' => $provider,
                ];
            }
        }

        $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
    $metaTitle = trim((string)($meta['title'] ?? ''));
    $metaDescription = trim((string)($meta['description'] ?? ''));
    $finalUrl = trim((string)($meta['final_url'] ?? ($options['url'] ?? '')));

        $basePrompt = $job['prompt'];
        $prompt = $basePrompt;
        $temperature = 0.25;
        $maxAttempts = 2;
        $attemptsInfo = [];
        $lastRaw = null;

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $result = pp_run_ai_completion([
                    'provider' => $provider,
                    'prompt' => $prompt,
                    'systemPrompt' => $job['system'],
                    'temperature' => $temperature,
                    'model' => trim((string)get_setting('openai_model', '')),
                ]);
                if (!is_array($result) || empty($result['ok'])) {
                    return [
                        'name' => '',
                        'description' => '',
                        'used_ai' => false,
                        'error' => $result['error'] ?? 'ai_call_failed',
                        'provider' => $provider,
                        'attempts' => $attemptsInfo,
                    ];
                }

                $text = trim((string)($result['text'] ?? ''));
                if ($text === '') {
                    return [
                        'name' => '',
                        'description' => '',
                        'used_ai' => false,
                        'error' => 'empty_response',
                        'provider' => $provider,
                        'attempts' => $attemptsInfo,
                    ];
                }
                $lastRaw = $text;

                $json = json_decode($text, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    $matches = [];
                    if (preg_match('~\{.*\}~s', $text, $matches)) {
                        $json = json_decode($matches[0], true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                            return [
                                'name' => '',
                                'description' => '',
                                'used_ai' => false,
                                'error' => 'invalid_json',
                                'raw' => $text,
                                'provider' => $provider,
                                'attempts' => $attemptsInfo,
                            ];
                        }
                    } else {
                        return [
                            'name' => '',
                            'description' => '',
                            'used_ai' => false,
                            'error' => 'json_not_found',
                            'raw' => $text,
                            'provider' => $provider,
                            'attempts' => $attemptsInfo,
                        ];
                    }
                }

                $name = trim((string)($json['name'] ?? ''));
                $description = trim((string)($json['description'] ?? ''));

                $truncate = static function (string $value, int $limit): string {
                    if ($value === '') { return ''; }
                    if (function_exists('mb_substr')) {
                        return trim(mb_substr($value, 0, $limit, 'UTF-8'));
                    }
                    return trim(substr($value, 0, $limit));
                };

                $originalName = $name;
                $originalDescription = $description;

                if ($name !== '') {
                    $name = pp_project_brief_refine_name($name, $metaTitle, $finalUrl);
                }
                if ($description !== '') {
                    $description = pp_project_brief_refine_description($description, $metaDescription);
                }

                $name = $truncate($name, 20);
                $description = $truncate($description, 240);

                $wasRefined = ($name !== $originalName) || ($description !== $originalDescription);

                $nameSimilar = pp_project_brief_is_similar_to_source($name, $metaTitle, 0.9);
                $descriptionSimilar = pp_project_brief_is_similar_to_source($description, $metaDescription, 0.95);

                $attemptsInfo[] = [
                    'name' => $name,
                    'description' => $description,
                    'name_similar' => $nameSimilar,
                    'description_similar' => $descriptionSimilar,
                    'temperature' => $temperature,
                    'refined' => $wasRefined,
                ];

                if (($name !== '' || $description !== '') && !$nameSimilar && !$descriptionSimilar) {
                    return [
                        'name' => $name,
                        'description' => $description,
                        'used_ai' => true,
                        'provider' => $provider,
                        'model' => trim((string)get_setting('openai_model', '')),
                        'raw' => $text,
                        'attempts' => $attemptsInfo,
                    ];
                }

                if ($attempt < $maxAttempts) {
                    $prompt = $basePrompt . "\n\nПредыдущая версия получилась слишком похожей на исходные метатеги. Полностью переформулируй название и описание, сохрани требования по длине, не повторяй бренд из title и добавь больше конкретики о выгоде.";
                    $temperature = min(0.85, $temperature + 0.25);
                }
            }
        } catch (Throwable $e) {
            return [
                'name' => '',
                'description' => '',
                'used_ai' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'provider' => $provider,
                'attempts' => $attemptsInfo,
            ];
        }

        $lastAttempt = end($attemptsInfo) ?: ['name' => '', 'description' => ''];
        return [
            'name' => $lastAttempt['name'] ?? '',
            'description' => $lastAttempt['description'] ?? '',
            'used_ai' => false,
            'error' => 'too_similar',
            'provider' => $provider,
            'model' => trim((string)get_setting('openai_model', '')),
            'attempts' => $attemptsInfo,
            'raw' => $lastRaw,
        ];
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

        $truncate = static function (string $value, int $limit) {
            if ($value === '') { return ''; }
            if (function_exists('mb_substr')) {
                return trim(mb_substr($value, 0, $limit, 'UTF-8'));
            }
            return trim(substr($value, 0, $limit));
        };

        $name = $ai['name'] ?? '';
        $description = $ai['description'] ?? '';

        if ($name === '') { $name = $fallbackTitle; }
        if ($description === '') { $description = $fallbackDescription; }

        $name = $truncate($name, 20);
        $description = $truncate($description, 240);

        if ($name === '' && $url !== '') {
            $host = pp_project_brief_extract_domain($url);
            if ($host !== '') { $name = $truncate($host, 20); }
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
