<?php
// Core helpers: i18n, auth, CSRF, URL utils, small filesystem utils

// Translation helper
if (!function_exists('__')) {
    function __($key) {
        global $current_lang, $lang;
        if ($current_lang == 'ru') {
            return $key;
        }
        return $lang[$key] ?? $key;
    }
}

// Sessions
if (!function_exists('pp_session_regenerate')) {
    function pp_session_regenerate() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

// CSRF helpers
if (!function_exists('get_csrf_token')) {
    function get_csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verify_csrf')) {
    function verify_csrf(): bool {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') return true;
        $token = (string)($_POST['csrf_token'] ?? '');
        if ($token === '') return false;
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($sessionToken === '') return false;
        return hash_equals($sessionToken, $token);
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}

// Auth helpers
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return is_logged_in() && (($_SESSION['role'] ?? '') === 'admin'); }
}
if (!function_exists('redirect')) {
    function redirect(string $path): void {
        $url = preg_match('~^https?://~i', $path) ? $path : pp_url($path);
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
        }
        exit;
    }
}

// One-off action token helpers (HMAC-based)
if (!function_exists('get_action_secret')) {
    function get_action_secret(): string {
        if (empty($_SESSION['action_secret'])) {
            $_SESSION['action_secret'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['action_secret'];
    }
}
if (!function_exists('action_token')) {
    function action_token(string $action, string $data = ''): string {
        return hash_hmac('sha256', $action . '|' . $data, get_action_secret());
    }
}
if (!function_exists('verify_action_token')) {
    function verify_action_token(string $token, string $action, string $data = ''): bool {
        if (!$token) return false;
        $calc = action_token($action, $data);
        return hash_equals($calc, $token);
    }
}

// Small utils
if (!function_exists('rmdir_recursive')) {
    function rmdir_recursive($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) { rmdir_recursive($path); }
            else { @unlink($path); }
        }
        @rmdir($dir);
    }
}

// Base URL helpers
if (!function_exists('pp_guess_base_url')) {
    function pp_guess_base_url(): string {
        if (defined('PP_BASE_URL') && PP_BASE_URL) return rtrim(PP_BASE_URL, '/');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $dir = rtrim(str_replace(['\\','//'], '/', dirname($script)), '/');
        $base = $scheme . '://' . $host . ($dir ? $dir : '');
        $base = preg_replace('~/(admin|public|auth)$~', '', $base);
        return rtrim($base, '/');
    }
}
if (!function_exists('pp_google_redirect_url')) {
    function pp_google_redirect_url(): string { return pp_guess_base_url() . '/public/google_oauth_callback.php'; }
}

if (!function_exists('pp_project_primary_url')) {
    function pp_project_primary_url(array $project, ?string $fallbackUrl = null): ?string {
        $ensureScheme = static function (?string $value): ?string {
            $value = trim((string)$value);
            if ($value === '') { return null; }
            if (!preg_match('~^https?://~i', $value)) {
                $value = 'https://' . ltrim($value, '/');
            }
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
        };

        $domainHost = trim((string)($project['domain_host'] ?? ''));
        if ($domainHost !== '') {
            $normalized = $ensureScheme($domainHost);
            if ($normalized) { return $normalized; }
            $normalized = $ensureScheme('https://' . $domainHost);
            if ($normalized) { return $normalized; }
        }

        $primary = $project['primary_url'] ?? $project['homepage_url'] ?? null;
        if ($primary === null && $fallbackUrl !== null) {
            $primary = $fallbackUrl;
        }
        if ($primary !== null) {
            $normalized = $ensureScheme($primary);
            if ($normalized) { return $normalized; }
        }

        if (!empty($project['domain'])) {
            $normalized = $ensureScheme($project['domain']);
            if ($normalized) { return $normalized; }
        }

        return null;
    }
}

if (!function_exists('pp_project_preview_storage')) {
    function pp_project_preview_storage(array $project): ?array {
        if (!defined('PP_ROOT_PATH')) { return null; }
        $projectId = (int)($project['id'] ?? 0);
        if ($projectId <= 0) { return null; }
        $dir = rtrim(PP_ROOT_PATH, '/') . '/public/media/previews';
        $filename = $projectId . '.webp';
        $path = $dir . '/' . $filename;
        $baseUrl = pp_guess_base_url() . '/public/media/previews';
        return [
            'id' => $projectId,
            'dir' => $dir,
            'filename' => $filename,
            'path' => $path,
            'url' => rtrim($baseUrl, '/') . '/' . rawurlencode($filename),
        ];
    }
}

if (!function_exists('pp_project_preview_descriptor')) {
    function pp_project_preview_descriptor(array $project): array {
        $storage = pp_project_preview_storage($project);
        if (!$storage) {
            return ['exists' => false];
        }
        clearstatcache(true, $storage['path']);
        $exists = is_file($storage['path']);
        $modifiedAt = $exists ? @filemtime($storage['path']) : null;
        $size = $exists ? (@filesize($storage['path']) ?: 0) : 0;
        return array_merge($storage, [
            'exists' => $exists,
            'modified_at' => $modifiedAt ?: null,
            'filesize' => $size,
        ]);
    }
}

if (!function_exists('pp_project_preview_is_stale')) {
    function pp_project_preview_is_stale(array $descriptor, int $maxAgeSeconds = 259200): bool {
        if (empty($descriptor['exists'])) { return true; }
        $modifiedAt = (int)($descriptor['modified_at'] ?? 0);
        if ($modifiedAt <= 0) { return true; }
        return (time() - $modifiedAt) > max(60, $maxAgeSeconds);
    }
}

if (!function_exists('pp_project_preview_url')) {
    function pp_project_preview_url(array $project, ?string $fallbackUrl = null, array $options = []): ?string {
        $descriptor = pp_project_preview_descriptor($project);
        $preferLocal = array_key_exists('prefer_local', $options) ? (bool)$options['prefer_local'] : true;
        $cacheBust = array_key_exists('cache_bust', $options) ? (bool)$options['cache_bust'] : true;

        if ($preferLocal && !empty($descriptor['exists'])) {
            $url = $descriptor['url'];
            if ($cacheBust && !empty($descriptor['modified_at'])) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . (int)$descriptor['modified_at'];
            }
            return $url;
        }

        $targetUrl = $options['force_url'] ?? pp_project_primary_url($project, $fallbackUrl);
        if (!$targetUrl) { return null; }

        $provider = $options['provider'] ?? (defined('PP_SITE_PREVIEW_PROVIDER') ? constant('PP_SITE_PREVIEW_PROVIDER') : (getenv('PP_SITE_PREVIEW_PROVIDER') ?: 'https://image.thum.io/get/noanimate/width/1100/crop/700'));
        if (!$provider) { return null; }
        if (is_string($provider) && strtolower(trim($provider)) === 'disabled') { return null; }

        $encoded = rawurlencode($targetUrl);
        if (is_string($provider) && strpos($provider, '{url}') !== false) {
            return str_replace('{url}', $encoded, $provider);
        }

        $provider = rtrim((string)$provider, '/');
        return $provider . '/' . $encoded;
    }
}

if (!function_exists('pp_capture_project_preview')) {
    function pp_capture_project_preview(array $project, array $options = []): array {
        $storage = pp_project_preview_storage($project);
        if (!$storage) {
            return ['ok' => false, 'error' => 'INVALID_PROJECT'];
        }

        if (!is_dir($storage['dir'])) {
            @mkdir($storage['dir'], 0775, true);
        }
        if (!is_dir($storage['dir']) || !is_writable($storage['dir'])) {
            return ['ok' => false, 'error' => 'STORAGE_NOT_WRITABLE'];
        }

        $targetUrl = $options['force_url'] ?? pp_project_primary_url($project, $options['fallback_url'] ?? null);
        if (!$targetUrl) {
            return ['ok' => false, 'error' => 'TARGET_URL_MISSING'];
        }

        if (!empty($options['force']) && is_file($storage['path'])) {
            @unlink($storage['path']);
        }

        $job = [
            'targetUrl' => $targetUrl,
            'outputPath' => $storage['path'],
            'viewport' => [
                'width' => (int)($options['width'] ?? 1280),
                'height' => (int)($options['height'] ?? 720),
                'deviceScaleFactor' => (float)($options['device_scale_factor'] ?? 1.2),
            ],
            'waitUntil' => $options['wait_until'] ?? 'networkidle2',
            'timeoutMs' => (int)($options['timeout_ms'] ?? 60000),
            'delayMs' => (int)($options['delay_ms'] ?? 1500),
            'fullPage' => (bool)($options['full_page'] ?? false),
            'omitBackground' => (bool)($options['omit_background'] ?? true),
            'imageType' => $options['image_type'] ?? 'webp',
            'quality' => (int)($options['quality'] ?? 82),
        ];

        $timeoutSeconds = (int)($options['timeout_seconds'] ?? 150);
        $script = rtrim(PP_ROOT_PATH, '/') . '/scripts/project_preview.js';
        $result = pp_run_node_script($script, $job, $timeoutSeconds);
        if (empty($result['ok'])) {
            return array_merge(['ok' => false], $result);
        }

        clearstatcache(true, $storage['path']);
        $descriptor = pp_project_preview_descriptor($project);
        if (empty($descriptor['exists'])) {
            return ['ok' => false, 'error' => 'PREVIEW_NOT_GENERATED'];
        }
        @chmod($descriptor['path'], 0644);

        return [
            'ok' => true,
            'path' => $descriptor['path'],
            'url' => pp_project_preview_url($project, $targetUrl, ['cache_bust' => true]),
            'modified_at' => $descriptor['modified_at'],
            'filesize' => $descriptor['filesize'],
            'result' => $result,
        ];
    }
}

?>
