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

if (!function_exists('pp_project_preview_url')) {
    function pp_project_preview_url(array $project, ?string $fallbackUrl = null, array $options = []): ?string {
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

?>
