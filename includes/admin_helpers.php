<?php
// Helper routines for admin panel actions to keep admin.php lean

if (!function_exists('pp_admin_allowed_currencies')) {
    function pp_admin_allowed_currencies(): array
    {
        return ['RUB','USD','EUR','GBP','UAH'];
    }
}

if (!function_exists('pp_admin_setting_keys')) {
    function pp_admin_setting_keys(): array
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }

        $keys = [
            'currency',
            'openai_api_key',
            'telegram_token',
            'telegram_channel',
            'ai_provider',
            'openai_model',
            'google_oauth_enabled',
            'google_client_id',
            'google_client_secret',
            'captcha_provider',
            'captcha_api_key',
            'captcha_fallback_provider',
            'captcha_fallback_api_key',
            'mail_enabled',
            'notifications_email_enabled',
            'mail_disable_all',
            'mail_from_name',
            'mail_from_email',
            'mail_reply_to',
            'mail_transport',
            'mail_smtp_host',
            'mail_smtp_port',
            'mail_smtp_username',
            'mail_smtp_password',
            'mail_smtp_encryption',
            'promotion_price_per_link',
            'promotion_level1_count',
            'promotion_level2_per_level1',
            'promotion_level3_per_level2',
            'promotion_crowd_per_article',
            'promotion_crowd_max_parallel_runs',
            'max_concurrent_jobs',
            'promotion_level1_enabled',
            'promotion_level2_enabled',
            'promotion_level3_enabled',
            'promotion_crowd_enabled',
            'promotion_max_active_runs_per_project',
            'publication_max_jobs_per_project',
            // Referral program
            'referral_enabled',
            'referral_default_percent',
            'referral_cookie_days',
        ];

        return $keys;
    }
}

if (!function_exists('pp_admin_handle_settings_submit')) {
    function pp_admin_handle_settings_submit(mysqli $conn, array $post, array $allowedCurrencies): string
    {
        $currency = strtoupper(trim((string)($post['currency'] ?? 'RUB')));
        if (!in_array($currency, $allowedCurrencies, true)) { $currency = 'RUB'; }

        $openai = trim((string)($post['openai_api_key'] ?? ''));
        $openaiModel = trim((string)($post['openai_model'] ?? 'gpt-3.5-turbo'));
        $tgToken = trim((string)($post['telegram_token'] ?? ''));
        $tgChannel = trim((string)($post['telegram_channel'] ?? ''));
        $aiProvider = in_array(($post['ai_provider'] ?? 'openai'), ['openai','byoa'], true) ? $post['ai_provider'] : 'openai';
        $googleEnabled = isset($post['google_oauth_enabled']) ? '1' : '0';
        $googleClientId = trim((string)($post['google_client_id'] ?? ''));
        $googleClientSecret = trim((string)($post['google_client_secret'] ?? ''));

        $captchaProvider = in_array(($post['captcha_provider'] ?? 'none'), ['none','2captcha','anti-captcha','capsolver'], true) ? $post['captcha_provider'] : 'none';
        $captchaApiKey = trim((string)($post['captcha_api_key'] ?? ''));
        $captchaFallbackProvider = in_array(($post['captcha_fallback_provider'] ?? 'none'), ['none','2captcha','anti-captcha','capsolver'], true) ? $post['captcha_fallback_provider'] : 'none';
        $captchaFallbackApiKey = trim((string)($post['captcha_fallback_api_key'] ?? ''));

        $priceRaw = str_replace(',', '.', (string)($post['promotion_price_per_link'] ?? '0'));
        $promotionPrice = max(0, round((float)$priceRaw, 2));
        $level1Count = max(1, min(500, (int)($post['promotion_level1_count'] ?? 5)));
        $level2PerLevel1 = max(1, min(500, (int)($post['promotion_level2_per_level1'] ?? 10)));
        $level3PerLevel2 = max(1, min(500, (int)($post['promotion_level3_per_level2'] ?? 5)));
        $crowdPerArticle = max(0, min(5000, (int)($post['promotion_crowd_per_article'] ?? 100)));
        $maxConcurrentJobs = max(1, min(20, (int)($post['max_concurrent_jobs'] ?? pp_get_max_concurrent_jobs())));
        $crowdMaxParallel = max(1, min($maxConcurrentJobs, (int)($post['promotion_crowd_max_parallel_runs'] ?? 3)));
        $promotionMaxRuns = max(1, min($maxConcurrentJobs, (int)($post['promotion_max_active_runs_per_project'] ?? 1)));
        $publicationMaxPerProject = max(1, min($maxConcurrentJobs, (int)($post['publication_max_jobs_per_project'] ?? 1)));

        $pairs = [
            ['currency', $currency],
            ['openai_api_key', $openai],
            ['openai_model', $openaiModel],
            ['telegram_token', $tgToken],
            ['telegram_channel', $tgChannel],
            ['ai_provider', $aiProvider],
            ['google_oauth_enabled', $googleEnabled],
            ['google_client_id', $googleClientId],
            ['google_client_secret', $googleClientSecret],
            ['captcha_provider', $captchaProvider],
            ['captcha_api_key', $captchaApiKey],
            ['captcha_fallback_provider', $captchaFallbackProvider],
            ['captcha_fallback_api_key', $captchaFallbackApiKey],
            ['promotion_price_per_link', number_format($promotionPrice, 2, '.', '')],
            ['promotion_level1_count', (string)$level1Count],
            ['promotion_level2_per_level1', (string)$level2PerLevel1],
            ['promotion_level3_per_level2', (string)$level3PerLevel2],
            ['promotion_crowd_per_article', (string)$crowdPerArticle],
            ['promotion_crowd_max_parallel_runs', (string)$crowdMaxParallel],
            ['max_concurrent_jobs', (string)$maxConcurrentJobs],
            ['promotion_level1_enabled', isset($post['promotion_level1_enabled']) ? '1' : '0'],
            ['promotion_level2_enabled', isset($post['promotion_level2_enabled']) ? '1' : '0'],
            ['promotion_level3_enabled', isset($post['promotion_level3_enabled']) ? '1' : '0'],
            ['promotion_crowd_enabled', isset($post['promotion_crowd_enabled']) ? '1' : '0'],
            ['promotion_max_active_runs_per_project', (string)$promotionMaxRuns],
            ['publication_max_jobs_per_project', (string)$publicationMaxPerProject],
            // Referral program settings
            ['referral_enabled', isset($post['referral_enabled']) ? '1' : '0'],
            ['referral_default_percent', number_format(max(0, min(100, (float)str_replace(',', '.', (string)($post['referral_default_percent'] ?? '5')))), 2, '.', '')],
            ['referral_cookie_days', (string)max(1, min(365, (int)($post['referral_cookie_days'] ?? 30)))],
        ];

        $stmt = $conn->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP");
        if (!$stmt) {
            return __('Ошибка сохранения настроек.');
        }
        foreach ($pairs as [$k, $v]) {
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();

        return __('Настройки сохранены.');
    }
}

if (!function_exists('pp_admin_handle_mail_settings_submit')) {
    function pp_admin_handle_mail_settings_submit(mysqli $conn, array $post): string
    {
        $mailEnabled = isset($post['mail_enabled']);
        $notificationsEnabled = isset($post['notifications_email_enabled']);
        $mailDisableAll = isset($post['mail_disable_all']);

        $fromName = trim((string)($post['mail_from_name'] ?? ''));
        if ($fromName !== '' && function_exists('mb_detect_encoding') && mb_detect_encoding($fromName, 'UTF-8', true) === false) {
            $fromName = trim((string)$post['mail_from_name'] ?? '');
        }
        if (function_exists('mb_substr')) {
            $fromName = mb_substr($fromName, 0, 191);
        } else {
            $fromName = substr($fromName, 0, 191);
        }

        $fromEmail = trim((string)($post['mail_from_email'] ?? ''));
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return __('Введите корректный email отправителя.');
        }

        $replyTo = trim((string)($post['mail_reply_to'] ?? ''));
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return __('Введите корректный адрес для ответа.');
        }

        $transport = strtolower(trim((string)($post['mail_transport'] ?? 'native')));
        if (!in_array($transport, ['native', 'smtp'], true)) {
            $transport = 'native';
        }

        $smtpHost = trim((string)($post['mail_smtp_host'] ?? ''));
        $smtpPort = (int)($post['mail_smtp_port'] ?? 587);
        if ($smtpPort <= 0 || $smtpPort > 65535) {
            $smtpPort = 587;
        }

        $smtpUser = trim((string)($post['mail_smtp_username'] ?? ''));
        $smtpPass = (string)($post['mail_smtp_password'] ?? '');
        if (function_exists('mb_substr')) {
            $smtpPass = mb_substr($smtpPass, 0, 255);
        } else {
            $smtpPass = substr($smtpPass, 0, 255);
        }

        $encryption = strtolower(trim((string)($post['mail_smtp_encryption'] ?? 'tls')));
        if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }

        $pairs = [
            ['mail_enabled', $mailEnabled ? '1' : '0'],
            ['notifications_email_enabled', $notificationsEnabled ? '1' : '0'],
            ['mail_disable_all', $mailDisableAll ? '1' : '0'],
            ['mail_from_name', $fromName],
            ['mail_from_email', $fromEmail],
            ['mail_reply_to', $replyTo],
            ['mail_transport', $transport],
            ['mail_smtp_host', $smtpHost],
            ['mail_smtp_port', (string)$smtpPort],
            ['mail_smtp_username', $smtpUser],
            ['mail_smtp_password', $smtpPass],
            ['mail_smtp_encryption', $encryption],
        ];

        $stmt = $conn->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP");
        if (!$stmt) {
            return __('Не удалось сохранить настройки почты.');
        }

        foreach ($pairs as [$k, $v]) {
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();

        return __('Настройки почты сохранены.');
    }
}

if (!function_exists('pp_admin_handle_network_refresh')) {
    function pp_admin_handle_network_refresh(): string
    {
        try {
            pp_refresh_networks(true);
            return __('Список сетей обновлён.');
        } catch (Throwable $e) {
            return __('Ошибка обновления списка сетей.');
        }
    }
}

if (!function_exists('pp_admin_handle_network_delete')) {
    function pp_admin_handle_network_delete(string $slugNorm): string
    {
        if ($slugNorm === '') {
            return __('Не удалось удалить сеть.');
        }
        return pp_delete_network($slugNorm)
            ? __('Сеть удалена из списка.')
            : __('Не удалось удалить сеть.');
    }
}

if (!function_exists('pp_admin_get_network_defaults')) {
    function pp_admin_get_network_defaults(): array
    {
        $priority = (int)get_setting('network_default_priority', 10);
        if ($priority < 0) { $priority = 0; }
        if ($priority > 999) { $priority = 999; }
        $levelsRaw = (string)get_setting('network_default_levels', '');
        $levels = pp_normalize_network_levels($levelsRaw);
        $levelsList = $levels !== '' ? explode(',', $levels) : [];
        return [$priority, $levels, $levelsList];
    }
}

if (!function_exists('pp_admin_handle_networks_submit')) {
    function pp_admin_handle_networks_submit(array $post, int &$networkDefaultPriority, string &$networkDefaultLevels, array &$networkDefaultLevelsList): string
    {
        $enabledSlugs = [];
        if (!empty($post['enable']) && is_array($post['enable'])) {
            $enabledSlugs = array_keys($post['enable']);
        }

        $priorityInput = $post['priority'] ?? [];
        $priorityMap = [];
        if (is_array($priorityInput)) {
            foreach ($priorityInput as $slug => $value) {
                $slugNorm = pp_normalize_slug((string)$slug);
                if ($slugNorm === '') { continue; }
                $priorityMap[$slugNorm] = (int)$value;
            }
        }

        $levelInput = $post['level'] ?? [];
        $levelMap = [];
        if (is_array($levelInput)) {
            foreach ($levelInput as $slug => $value) {
                $slugNorm = pp_normalize_slug((string)$slug);
                if ($slugNorm === '') { continue; }
                $levelMap[$slugNorm] = $value;
            }
        }

        $defaultPriority = isset($post['default_priority']) ? (int)$post['default_priority'] : $networkDefaultPriority;
        $defaultPriority = max(0, min(999, $defaultPriority));
        $defaultLevelsRaw = $post['default_levels'] ?? [];
        if (!is_array($defaultLevelsRaw)) { $defaultLevelsRaw = [$defaultLevelsRaw]; }
        $defaultLevelsValue = pp_normalize_network_levels($defaultLevelsRaw);

        $nodeBinaryNew = trim((string)($post['node_binary'] ?? ''));
        $puppeteerExecNew = trim((string)($post['puppeteer_executable_path'] ?? ''));
        $puppeteerArgsNew = trim((string)($post['puppeteer_args'] ?? ''));

        set_settings([
            'node_binary' => $nodeBinaryNew,
            'puppeteer_executable_path' => $puppeteerExecNew,
            'puppeteer_args' => $puppeteerArgsNew,
            'network_default_priority' => $defaultPriority,
            'network_default_levels' => $defaultLevelsValue,
        ]);

        $networkDefaultPriority = $defaultPriority;
        $networkDefaultLevels = $defaultLevelsValue;
        $networkDefaultLevelsList = $networkDefaultLevels !== '' ? explode(',', $networkDefaultLevels) : [];

        return pp_set_networks_enabled($enabledSlugs, $priorityMap, $levelMap)
            ? __('Параметры сетей сохранены.')
            : __('Не удалось обновить параметры сетей.');
    }
}

if (!function_exists('pp_admin_handle_node_detection')) {
    function pp_admin_handle_node_detection(): string
    {
        $resolved = pp_resolve_node_binary(5, true);
        if ($resolved) {
            $path = $resolved['path'];
            $ver = $resolved['version'] ?? __('Неизвестно');
            return sprintf(__('Node.js найден: %s (версия %s).'), $path, $ver);
        }

        $candidates = pp_collect_node_candidates();
        $msg = __('Не удалось автоматически определить Node.js.');
        if (!empty($candidates)) {
            $msg .= ' ' . __('Проверенные пути:') . ' ' . implode(', ', array_slice($candidates, 0, 10));
        }
        return $msg;
    }
}

if (!function_exists('pp_admin_handle_crowd_import')) {
    function pp_admin_handle_crowd_import(array $files): array
    {
        try {
            $summary = pp_crowd_links_import_files($files);
        } catch (Throwable $e) {
            return [
                'message' => __('Не удалось выполнить импорт ссылок.'),
                'summary' => ['errors' => [$e->getMessage()]],
            ];
        }

        if (!empty($summary['ok'])) {
            $message = sprintf(
                __('Импорт завершён. Добавлено: %1$d, дубликатов: %2$d, пропущено: %3$d.'),
                (int)($summary['imported'] ?? 0),
                (int)($summary['duplicates'] ?? 0),
                (int)($summary['invalid'] ?? 0)
            );
        } else {
            $errors = (array)($summary['errors'] ?? []);
            $message = !empty($errors) ? implode(' ', $errors) : __('Не удалось выполнить импорт ссылок.');
        }

        return ['message' => $message, 'summary' => $summary];
    }
}

if (!function_exists('pp_admin_handle_crowd_action')) {
    function pp_admin_handle_crowd_action(string $action, array $payload = []): string
    {
        switch ($action) {
            case 'clear_all':
                $deleted = pp_crowd_links_delete_all();
                return $deleted > 0
                    ? sprintf(__('Удалено %d ссылок.'), $deleted)
                    : __('Ссылки не были удалены.');
            case 'delete_errors':
                $deleted = pp_crowd_links_delete_errors();
                return $deleted > 0
                    ? sprintf(__('Удалено ссылок с ошибками: %d.'), $deleted)
                    : __('Не найдено ссылок с ошибками.');
            case 'delete_selected':
                $selected = $payload['selected'] ?? [];
                if (!is_array($selected)) { $selected = [$selected]; }
                $deleted = pp_crowd_links_delete_selected($selected);
                return $deleted > 0
                    ? sprintf(__('Удалено выбранных ссылок: %d.'), $deleted)
                    : __('Не удалось удалить выбранные ссылки.');
            default:
                return __('Неизвестное действие.');
        }
    }
}

if (!function_exists('pp_admin_handle_detect_chrome')) {
    function pp_admin_handle_detect_chrome(): string
    {
        $found = pp_resolve_chrome_path();
        if ($found) {
            set_setting('puppeteer_executable_path', $found);
            return sprintf(__('Chrome найден: %s. Путь сохранён в настройках.'), $found);
        }
        return __('Не удалось автоматически определить Chrome. Проверьте подсказки ниже и установите браузер в корне проекта.');
    }
}
