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
        $userPrompt = "Проанализируй сайт и подготовь предложение на языке {$langLabel}. Сформируй лаконичное название проекта (до 20 символов, без кавычек и эмодзи) и одно короткое описание (до 200 символов).";
        if ($prompt !== '') { $userPrompt .= "\n\nДанные страницы:\n" . $prompt; }
        $userPrompt .= "\n\nВерни ответ строго в JSON без форматирования и комментариев с ключами: name, description. Название и описание должны быть на языке {$langLabel}.";

        return [
            'language' => $lang,
            'system' => $systemPrompt,
            'prompt' => $userPrompt,
        ];
    }
}

if (!function_exists('pp_project_brief_generate_from_ai')) {
    function pp_project_brief_generate_from_ai(array $options): ?array {
        $job = pp_project_brief_prepare_ai_payload($options);
        $provider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';
        $key = trim((string)get_setting('openai_api_key', ''));
        if ($provider === 'openai' && $key === '') {
            return null;
        }
        if (!function_exists('pp_run_ai_completion')) {
            if (!defined('PP_ROOT_PATH')) { return null; }
            $clientPath = PP_ROOT_PATH . '/networks/ai_client.js';
            if (!is_file($clientPath)) { return null; }
        }
        try {
            $result = pp_run_ai_completion([
                'provider' => $provider,
                'prompt' => $job['prompt'],
                'systemPrompt' => $job['system'],
                'temperature' => 0.3,
            ]);
            if (!is_array($result) || empty($result['ok'])) { return null; }
            $text = trim((string)($result['text'] ?? ''));
            if ($text === '') { return null; }
            $json = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                $matches = [];
                if (preg_match('~\{.*\}~s', $text, $matches)) {
                    $json = json_decode($matches[0], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                        return null;
                    }
                } else {
                    return null;
                }
            }
            $name = trim((string)($json['name'] ?? ''));
            $description = trim((string)($json['description'] ?? ''));
            if ($name === '') { $name = $options['meta']['title'] ?? ''; }
            $truncate = static function (string $value, int $limit): string {
                if ($value === '') { return ''; }
                if (function_exists('mb_substr')) {
                    return trim(mb_substr($value, 0, $limit, 'UTF-8'));
                }
                return trim(substr($value, 0, $limit));
            };
            $name = $truncate($name, 20);
            $description = $truncate($description !== '' ? $description : ($options['meta']['description'] ?? ''), 240);
            return ['name' => $name, 'description' => $description];
        } catch (Throwable $e) {
            return null;
        }
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

        return [
            'name' => $name,
            'description' => $description,
            'language' => $detectedLang,
            'meta' => $meta,
            'ai' => $ai,
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
