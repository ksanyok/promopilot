<?php
$current_lang = $_SESSION['lang'] ?? 'ru';

// Ensure root path constant exists for reliable includes
if (!defined('PP_ROOT_PATH')) {
    define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));
}

if ($current_lang != 'ru') {
    $langFile = PP_ROOT_PATH . '/lang/' . basename($current_lang) . '.php';
    if (file_exists($langFile)) {
        include $langFile;
    }
}

// Общие функции для PromoPilot

function connect_db() {
    $configPath = PP_ROOT_PATH . '/config/config.php';
    if (!file_exists($configPath)) {
        // Try to provide a helpful link if helpers available
        $installer = (defined('PP_BASE_URL') ? pp_url('installer.php') : '/installer.php');
        die('Config file not found. Please run the installer: <a href="' . $installer . '">installer</a>');
    }
    include $configPath;
    if (!isset($db_host, $db_user, $db_pass, $db_name)) {
        die('Database configuration variables are not set. Please check config/config.php');
    }
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Ошибка подключения к БД: " . $conn->connect_error);
    }
    return $conn;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}

function redirect($url) {
    // Build absolute URL if relative provided
    if (!preg_match('~^https?://~i', $url)) {
        if (defined('PP_BASE_URL')) {
            $url = rtrim(PP_BASE_URL, '/') . '/' . ltrim($url, '/');
        }
    }
    header("Location: $url");
    exit;
}

function get_version() {
    $version = '0.0.0';
    $verFile = PP_ROOT_PATH . '/config/version.php';
    if (file_exists($verFile)) {
        include $verFile; // sets $version
    }
    return $version;
}

function check_version() {
    $cacheFile = PP_ROOT_PATH . '/config/version_cache.json';
    $ttl = 6 * 60 * 60; // 6 hours
    $now = time();
    if (file_exists($cacheFile)) {
        $data = json_decode(@file_get_contents($cacheFile), true);
        if (!empty($data['ts']) && ($now - (int)$data['ts'] < $ttl) && isset($data['is_new'])) {
            return (bool)$data['is_new'];
        }
    }

    $url = 'https://api.github.com/repos/ksanyok/promopilot/releases/latest';
    $context = stream_context_create(['http' => ['header' => 'User-Agent: PHP']]);
    $isNew = false;
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        $latest = str_replace('v', '', ($data['tag_name'] ?? '1.0.0'));
        $current = get_version();
        $isNew = version_compare($latest, $current, '>');
    }
    @file_put_contents($cacheFile, json_encode(['ts' => $now, 'is_new' => $isNew]));
    return $isNew;
}

// Функция перевода
function __($key) {
    global $current_lang;
    if ($current_lang == 'ru') {
        return $key;
    }
    global $lang;
    return $lang[$key] ?? $key;
}

function pp_session_regenerate() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool {
    return true; // CSRF отключён по требованию
}

function csrf_field(): string {
    return ''; // не выводим токен
}

function get_action_secret(): string {
    if (empty($_SESSION['action_secret'])) {
        $_SESSION['action_secret'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['action_secret'];
}

function action_token(string $action, string $data = ''): string {
    return hash_hmac('sha256', $action . '|' . $data, get_action_secret());
}

function verify_action_token(string $token, string $action, string $data = ''): bool {
    if (!$token) return false;
    $calc = action_token($action, $data);
    return hash_equals($calc, $token);
}
?>