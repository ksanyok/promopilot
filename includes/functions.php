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
        // Graceful redirect to installer if no config
        $installer = (defined('PP_BASE_URL') ? pp_url('installer.php') : '/installer.php');
        if (!headers_sent()) {
            header('Location: ' . $installer, true, 302);
        }
        exit('Config file not found. Please run the installer: <a href="' . htmlspecialchars($installer) . '">installer</a>');
    }

    // Ensure mysqli extension is available
    if (!class_exists('mysqli')) {
        exit('PHP mysqli extension is not available. Please enable it to continue.');
    }

    include $configPath;
    if (!isset($db_host, $db_user, $db_pass, $db_name)) {
        exit('Database configuration variables are not set. Please check config/config.php');
    }
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        exit("Ошибка подключения к БД: " . $conn->connect_error);
    }
    // Ensure proper charset
    if (method_exists($conn, 'set_charset')) {
        @$conn->set_charset('utf8mb4');
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

// Fetch latest version info from GitHub main branch
function fetch_latest_version_info(): ?array {
    // Get version from config/version.php in main branch
    $url = 'https://raw.githubusercontent.com/ksanyok/promopilot/main/config/version.php';
    $response = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 5],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]));
    if (!$response) return null;
    if (preg_match("/\\\$version\s*=\s*['\"]([^'\"]*)['\"]/", $response, $matches)) {
        $latest = $matches[1];
    } else {
        return null;
    }
    // Get date of last commit
    $commitUrl = 'https://api.github.com/repos/ksanyok/promopilot/commits/main';
    $commitResponse = @file_get_contents($commitUrl, false, stream_context_create([
        'http' => [
            'header' => "User-Agent: PromoPilot\r\nAccept: application/vnd.github+json",
            'timeout' => 5,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]));
    $published = '';
    if ($commitResponse) {
        $commitData = json_decode($commitResponse, true);
        if ($commitData && isset($commitData['commit']['committer']['date'])) {
            $ts = strtotime($commitData['commit']['committer']['date']);
            if ($ts) { $published = date('Y-m-d', $ts); }
        }
    }
    return [
        'version' => $latest,
        'published_at' => $published,
    ];
}

// Return update status with latest version and date
function get_update_status(): array {
    // Clean up obsolete cache file if it exists
    $oldCache = PP_ROOT_PATH . '/config/version_cache.json';
    if (is_file($oldCache)) { @unlink($oldCache); }

    $current = get_version();
    $info = fetch_latest_version_info();
    if (!$info) {
        return ['is_new' => false, 'latest' => null, 'published_at' => null];
    }
    $isNew = version_compare($info['version'], $current, '>');
    return ['is_new' => $isNew, 'latest' => $info['version'], 'published_at' => $info['published_at']];
}

// Back-compat: simple boolean check
function check_version(bool $force = false) {
    $st = get_update_status();
    return (bool)$st['is_new'];
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

function get_user_balance(int $userId): ?float {
    $conn = @connect_db();
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    if (!$stmt) { $conn->close(); return null; }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($balance);
    if ($stmt->fetch()) {
        $stmt->close();
        $conn->close();
        return (float)$balance;
    }
    $stmt->close();
    $conn->close();
    return null;
}

function get_current_user_balance(): ?float {
    if (!is_logged_in()) return null;
    return get_user_balance((int)$_SESSION['user_id']);
}

// Settings helpers
function get_setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        // Load all settings once; fail gracefully if table not found
        $cache = [];
        try {
            $conn = @connect_db();
            if ($conn) {
                $res = @$conn->query("SELECT k, v FROM settings");
                if ($res) {
                    while ($row = $res->fetch_assoc()) { $cache[$row['k']] = $row['v']; }
                }
                $conn->close();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return $cache[$key] ?? $default;
}

function get_currency_code(): string {
    $cur = strtoupper((string)get_setting('currency', 'RUB'));
    $allowed = ['RUB','USD','EUR','GBP','UAH'];
    if (!in_array($cur, $allowed, true)) { $cur = 'RUB'; }
    return $cur;
}

function format_currency($amount): string {
    $code = get_currency_code();
    $num = is_numeric($amount) ? number_format((float)$amount, 2, '.', ' ') : (string)$amount;
    // Keep it neutral (CODE). If you prefer symbols, switch mapping below.
    return $num . ' ' . $code;
    /* Symbol example:
    $map = ['RUB' => '₽','USD' => '$','EUR' => '€','GBP' => '£','UAH' => '₴'];
    $sym = $map[$code] ?? $code;
    return $sym . $num;
    */
}

function rmdir_recursive($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            rmdir_recursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

?>