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

// Ensure DB schema has required columns/tables
function ensure_schema(): void {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return; // cannot connect; installer will handle
    }
    if (!$conn) return;

    // Helper to get columns map
    $getCols = function(string $table) use ($conn): array {
        $cols = [];
        try {
            if ($res = @$conn->query("DESCRIBE `{$table}`")) {
                while ($row = $res->fetch_assoc()) {
                    $cols[$row['Field']] = $row;
                }
                $res->free();
            }
        } catch (Throwable $e) { /* ignore */ }
        return $cols;
    };

    // Projects table
    $projectsCols = $getCols('projects');
    if (empty($projectsCols)) {
        // Create minimal projects table if missing
        @$conn->query("CREATE TABLE IF NOT EXISTS `projects` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `links` TEXT NULL,
            `language` VARCHAR(10) NOT NULL DEFAULT 'ru',
            `wishes` TEXT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // Add missing columns
        if (!isset($projectsCols['links'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `links` TEXT NULL");
        }
        if (!isset($projectsCols['language'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'");
        } else {
            // Ensure language has NOT NULL and default 'ru'
            $lang = $projectsCols['language'];
            $needsFix = (strtoupper($lang['Null'] ?? '') === 'YES') || (($lang['Default'] ?? '') === null);
            if ($needsFix) {
                @$conn->query("ALTER TABLE `projects` MODIFY COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'");
            }
        }
        if (!isset($projectsCols['wishes'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `wishes` TEXT NULL");
        }
    }

    // Users table: ensure balance column exists
    $usersCols = $getCols('users');
    if (!empty($usersCols) && !isset($usersCols['balance'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `balance` DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    // Users table: add profile fields if missing
    if (!empty($usersCols)) {
        if (!isset($usersCols['full_name'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(255) NULL AFTER `username`");
        }
        if (!isset($usersCols['email'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) NULL AFTER `full_name`");
        }
        if (!isset($usersCols['phone'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(32) NULL AFTER `email`");
        }
        if (!isset($usersCols['avatar'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `phone`");
        }
        if (!isset($usersCols['newsletter_opt_in'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `newsletter_opt_in` TINYINT(1) NOT NULL DEFAULT 1 AFTER `avatar`");
        }
    }

    // Publications table for history
    $pubCols = $getCols('publications');
    if (empty($pubCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `publications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `page_url` TEXT NOT NULL,
            `anchor` VARCHAR(255) NULL,
            `network` VARCHAR(100) NULL,
            `published_by` VARCHAR(100) NULL,
            `post_url` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`project_id`),
            CONSTRAINT `fk_publications_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        if (!isset($pubCols['anchor'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `anchor` VARCHAR(255) NULL");
        }
        if (!isset($pubCols['network'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `network` VARCHAR(100) NULL");
        }
        if (!isset($pubCols['published_by'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `published_by` VARCHAR(100) NULL");
        }
        if (!isset($pubCols['post_url'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `post_url` TEXT NULL");
        }
        if (!isset($pubCols['created_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    // Settings table (k,v)
    $settingsCols = $getCols('settings');
    if (empty($settingsCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `settings` (
            `k` VARCHAR(191) NOT NULL PRIMARY KEY,
            `v` LONGTEXT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // Add updated_at if missing
        if (!isset($settingsCols['updated_at'])) {
            @$conn->query("ALTER TABLE `settings` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `v`");
        }
    }

    // Settings table optional—skip if missing

    @$conn->close();
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
    // Разрешаем GET без проверки
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    // Принимаем токен из POST (основное) или из GET как fallback
    $sent = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
    if (!$sessionToken || !$sent) return false;
    return hash_equals($sessionToken, $sent);
}

function csrf_field(): string {
    $t = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $t . '">';
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

// Settings helpers (single implementation with cache + invalidation flag)
function get_setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null || isset($GLOBALS['__pp_settings_cache_invalidate'])) {
        unset($GLOBALS['__pp_settings_cache_invalidate']);
        $cache = [];
        try {
            $conn = @connect_db();
            if ($conn) {
                if ($res = @$conn->query("SELECT k, v FROM settings")) {
                    while ($row = $res->fetch_assoc()) { $cache[$row['k']] = $row['v']; }
                }
                $conn->close();
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, $value): bool {
    try { $conn = connect_db(); } catch (Throwable $e) { return false; }
    $stmt = $conn->prepare("REPLACE INTO settings (k, v) VALUES (?, ?)");
    if (!$stmt) { $conn->close(); return false; }
    $val = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param('ss', $key, $val);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    $GLOBALS['__pp_settings_cache_invalidate'] = true; // mark cache dirty
    return $ok;
}

if (!function_exists('__settings_cache_reset')) {
    function __settings_cache_reset(): void { $GLOBALS['__pp_settings_cache_invalidate'] = true; }
}

// Active networks per user (stored in settings as JSON per user)
function get_active_network_slugs_for_user(int $uid): array {
    $raw = get_setting('networks_active_u' . $uid, '');
    if (!$raw) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? array_values(array_unique(array_filter($arr, 'is_string'))) : [];
}
function set_active_network_slugs_for_user(int $uid, array $slugs): bool {
    $clean = [];
    foreach ($slugs as $s) { $s = strtolower(preg_replace('~[^a-z0-9_\-]+~','',$s)); if ($s!=='') $clean[]=$s; }
    $clean = array_values(array_unique($clean));
    return set_setting('networks_active_u' . $uid, json_encode($clean, JSON_UNESCAPED_UNICODE));
}

// Active networks globally (stored in settings as JSON)
function get_global_active_network_slugs(): array {
    $raw = get_setting('networks_active_global', '');
    if (!$raw) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? array_values(array_unique(array_filter($arr, 'is_string'))) : [];
}
function set_global_active_network_slugs(array $slugs): bool {
    $clean = [];
    foreach ($slugs as $s) { $s = strtolower(preg_replace('~[^a-z0-9_\-]+~','',$s)); if ($s!=='') $clean[]=$s; }
    $clean = array_values(array_unique($clean));
    return set_setting('networks_active_global', json_encode($clean, JSON_UNESCAPED_UNICODE));
}

function get_openai_api_key(): string {
    return trim((string)get_setting('openai_api_key', ''));
}

// Add: generation mode getter ("local" for Наш ИИ or "openai")
function get_generation_mode(): string {
    $mode = strtolower((string)get_setting('generator_mode', 'local'));
    return in_array($mode, ['local','openai'], true) ? $mode : 'local';
}

// Add: validate OpenAI API key by calling OpenAI models endpoint
function validate_openai_api_key(string $apiKey, ?string &$error = null, int $timeout = 6): bool {
    $error = null;
    $apiKey = trim($apiKey);
    if ($apiKey === '') { $error = __('Ключ OpenAI пуст.'); return false; }
    // Basic format hint (not a strict validator)
    if (strpos($apiKey, 'sk-') !== 0) {
        // Not failing hard, but warn via error for user feedback
        $error = __('Ключ OpenAI обычно начинается с sk-. Проверьте правильность.');
        // Continue to try network validation
    }

    $url = 'https://api.openai.com/v1/models';
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        // User-Agent to avoid some hostings blocking
        'User-Agent: PromoPilot/1.0'
    ];

    $httpCode = 0; $body = '';

    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch) {
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, $timeout));
            @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $body = (string)@curl_exec($ch);
            $httpCode = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) { $body = ''; }
            @curl_close($ch);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) { $httpCode = (int)$m[1]; break; }
            }
        }
        if (!is_string($body)) { $body = ''; }
    }

    if ($httpCode === 200) { return true; }
    if ($httpCode === 401) { $error = __('Неверный OpenAI API ключ (401 Unauthorized).'); return false; }

    // Other codes: network or policy issues; return false with message
    if ($httpCode > 0) {
        $error = sprintf(__('Не удалось подтвердить ключ OpenAI (HTTP %d).'), $httpCode);
    } else {
        $error = __('Не удалось соединиться с OpenAI для проверки ключа.');
    }
    return false;
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
    return $sym + $num;
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

// ===== Global runtime helpers for headless browser automation =====
/**
 * Resolve Chrome/Chromium binary path from env/common locations.
 */
function pp_resolve_chrome_binary(): string {
    // Admin-configured override
    try {
        $cfg = trim((string)get_setting('chrome_binary_path', ''));
        if ($cfg && @is_file($cfg) && @is_executable($cfg)) return $cfg;
    } catch (Throwable $e) { /* ignore */ }

    $chromeBinary = getenv('CHROME_PATH') ?: getenv('CHROME_BIN') ?: getenv('PUPPETEER_EXECUTABLE_PATH') ?: '';
    if ($chromeBinary && @is_file($chromeBinary)) return $chromeBinary;

    // Look for Chromium bundled by Puppeteer within node_runtime
    $base = PP_ROOT_PATH . '/node_runtime/node_modules/puppeteer';
    $globs = [
        $base . '/.local-chromium/*/chrome-linux/chrome',
        $base . '/.local-chromium/*/chrome-linux64/chrome',
        $base . '/.cache/puppeteer/*/*/chrome',
        $base . '/.cache/puppeteer/chrome/*/chrome-linux*/chrome',
    ];
    foreach ($globs as $pattern) {
        foreach ((array)glob($pattern) as $cand) {
            if (@is_file($cand) && @is_executable($cand)) return $cand;
        }
    }

    // Common candidates
    $candidates = [
        '/usr/bin/google-chrome', '/usr/local/bin/google-chrome',
        '/usr/bin/chromium', '/usr/local/bin/chromium', '/usr/bin/chromium-browser', '/usr/local/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/opt/homebrew/bin/google-chrome', '/opt/homebrew/bin/chromium',
        // Portable in repo (if user uploaded)
        PP_ROOT_PATH . '/node_runtime/chrome/chrome',
        PP_ROOT_PATH . '/node_runtime/chromium/chrome',
    ];
    foreach ($candidates as $p) {
        if (@is_file($p) && @is_executable($p)) return $p;
    }
    return '';
}

/**
 * Resolve Node.js binary path from env/common locations (shared hosting friendly).
 */
function pp_resolve_node_binary(): string {
    // Admin-configured override first
    try {
        $cfg = trim((string)get_setting('node_binary_path', ''));
        if ($cfg && @is_file($cfg) && @is_executable($cfg)) return $cfg;
    } catch (Throwable $e) { /* ignore */ }

    // Local portable node inside project
    $localNode = PP_ROOT_PATH . '/node_runtime/bin/node';
    if (@is_file($localNode) && @is_executable($localNode)) {
        $out = @shell_exec(escapeshellarg($localNode) . ' --version 2>&1');
        if (is_string($out) && preg_match('~v\d+\.\d+\.\d+~', $out)) return $localNode;
    }

    $node = getenv('NODE_BINARY') ?: '';
    $tryNames = [];
    if ($node !== '') { $tryNames[] = $node; }
    // Common executable names on various hostings
    $tryNames = array_merge($tryNames, ['node', 'nodejs', 'node20', 'node18', 'node16']);

    // Common absolute paths
    $candidates = [
        '/usr/local/bin/node','/usr/bin/node','/bin/node',
        '/usr/local/bin/nodejs','/usr/bin/nodejs',
        // CloudLinux alt-nodejs
        '/opt/alt/alt-nodejs16/root/usr/bin/node',
        '/opt/alt/alt-nodejs18/root/usr/bin/node',
        '/opt/alt/alt-nodejs20/root/usr/bin/node',
        // cPanel EA
        '/opt/cpanel/ea-nodejs16/bin/node',
        '/opt/cpanel/ea-nodejs18/bin/node',
        '/opt/cpanel/ea-nodejs20/bin/node',
        // Hosting-provided path example (whereis node -> /usr/local/node23/bin/node)
        '/usr/local/node23/bin/node',
    ];

    foreach (array_merge($tryNames, $candidates) as $cand) {
        if ($cand === '') continue;
        $cmd = escapeshellcmd($cand) . ' --version 2>&1';
        $out = @shell_exec($cmd);
        if (is_string($out) && preg_match('~v\d+\.\d+\.\d+~', $out)) {
            return $cand; // works
        }
    }

    // Try glob patterns
    foreach (['/opt/alt/*nodejs*/root/usr/bin/node','/opt/cpanel/ea-nodejs*/bin/node'] as $pattern) {
        foreach ((array)glob($pattern) as $path) {
            if (@is_file($path) && @is_executable($path)) {
                $out = @shell_exec(escapeshellarg($path) . ' --version 2>&1');
                if (is_string($out) && preg_match('~v\d+\.\d+\.\d+~', $out)) return $path;
            }
        }
    }
    return '';
}

/**
 * Resolve npm binary path from settings/env/common locations.
 */
function pp_resolve_npm_binary(): string {
    // Admin-configured override first
    try {
        $cfg = trim((string)get_setting('npm_binary_path', ''));
        if ($cfg && @is_file($cfg) && @is_executable($cfg)) return $cfg;
    } catch (Throwable $e) { /* ignore */ }

    $npm = getenv('NPM_BINARY') ?: '';
    $try = [];
    if ($npm) $try[] = $npm;

    // Try sibling to detected Node binary (common on shared hosting)
    $nodeBin = function_exists('pp_resolve_node_binary') ? pp_resolve_node_binary() : '';
    if ($nodeBin) {
        $dir = dirname($nodeBin);
        foreach ([$dir . '/npm', $dir . '/npm-cli', $dir . '/npm.cmd'] as $cand) {
            $out = @shell_exec(escapeshellcmd($cand) . ' --version 2>&1');
            if (is_string($out) && preg_match('~^\d+\.\d+\.\d+~', trim($out))) {
                return $cand;
            }
        }
    }

    $try = array_merge($try, ['npm','pnpm','yarn']);
    $candidates = [
        '/usr/local/bin/npm','/usr/bin/npm','/bin/npm',
        '/opt/cpanel/ea-nodejs16/bin/npm','/opt/cpanel/ea-nodejs18/bin/npm','/opt/cpanel/ea-nodejs20/bin/npm',
        '/opt/alt/alt-nodejs16/root/usr/bin/npm','/opt/alt/alt-nodejs18/root/usr/bin/npm','/opt/alt/alt-nodejs20/root/usr/bin/npm',
    ];
    foreach (array_merge($try,$candidates) as $bin) {
        if ($bin==='') continue;
        $out = @shell_exec(escapeshellcmd($bin).' --version 2>&1');
        // Accept only if it looks like a version, e.g. 10.9.0
        if (is_string($out) && preg_match('~^\d+\.\d+\.\d+~', trim($out))) return $bin;
    }
    return '';
}

/**
 * Auto-detect Node, npm, Chrome paths and persist to settings if found.
 */
function pp_autodetect_and_save_binaries(): array {
    $detected = [
        'node' => '',
        'npm' => '',
        'chrome' => '',
        'saved' => [],
    ];

    try { $node = pp_resolve_node_binary(); } catch (Throwable $e) { $node = ''; }
    try { $npm = pp_resolve_npm_binary(); } catch (Throwable $e) { $npm = ''; }
    try { $chrome = pp_resolve_chrome_binary(); } catch (Throwable $e) { $chrome = ''; }

    $detected['node'] = $node; $detected['npm'] = $npm; $detected['chrome'] = $chrome;

    if ($node) { if (set_setting('node_binary_path', $node)) { $detected['saved'][] = 'node_binary_path'; } }
    if ($npm)  { if (set_setting('npm_binary_path', $npm))   { $detected['saved'][] = 'npm_binary_path'; } }
    if ($chrome){ if (set_setting('chrome_binary_path', $chrome)) { $detected['saved'][] = 'chrome_binary_path'; } }

    return $detected;
}

/**
 * Ensure node_runtime dependencies installed. Attempts to run npm install if missing.
 */
function pp_ensure_node_runtime_installed(): bool {
    $root = PP_ROOT_PATH . '/node_runtime';
    if (!is_dir($root)) { @mkdir($root, 0777, true); }
    $pkg = $root . '/package.json';
    $mods = $root . '/node_modules';
    $hasPuppeteer = is_dir($mods . '/puppeteer') || is_dir($mods . '/puppeteer-core');
    if ($hasPuppeteer) return true;
    if (!is_file($pkg)) return false;

    $npm = pp_resolve_npm_binary();
    if ($npm === '') return false;

    $desc = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $cmd = [$npm, '--prefix', $root, 'install', '--no-audit', '--no-fund', '--omit=dev'];
    $proc = @proc_open($cmd, $desc, $pipes, PP_ROOT_PATH);
    if (!is_resource($proc)) return false;
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out=''; $err=''; $start=microtime(true); $timeout=300; // 5 min
    while (true) {
        $status = proc_get_status($proc);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if ((microtime(true)-$start) > $timeout) { proc_terminate($proc, 9); break; }
        usleep(200000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    if (function_exists('autopost_log')) {
        if (trim($err)!=='') autopost_log('npm install stderr: ' . trim($err));
        if ($code !== 0) autopost_log('npm install exit code: ' . $code);
    }
    clearstatcache();
    return is_dir($mods . '/puppeteer') || is_dir($mods . '/puppeteer-core');
}

/**
 * Run a small Node.js script (e.g., Puppeteer) with env and timeout.
 * Returns array [exitCode|null, stdout, stderr].
 */
function pp_run_puppeteer(string $js, array $env = [], int $timeout = 60): array {
    $nodeBin = pp_resolve_node_binary();
    $stdout = '';
    $stderr = '';
    $exitCode = null;

    // Prepare working directory and NODE_PATH so require('puppeteer') can resolve
    $runtimeDir = PP_ROOT_PATH . '/node_runtime';
    if (!is_dir($runtimeDir)) { @mkdir($runtimeDir, 0777, true); }
    $nodeModules = $runtimeDir . '/node_modules';

    // Write JS to a temp file INSIDE runtimeDir (so Node resolves modules relative to it)
    $tmpFile = @tempnam($runtimeDir, 'pp_js_');
    if ($tmpFile === false) { return [null, '', 'tempnam failed']; }
    @file_put_contents($tmpFile, $js);

    // Merge env: start from current, then custom values
    $baseEnv = [];
    foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
        if (is_string($k) && is_scalar($v)) { $baseEnv[$k] = (string)$v; }
    }

    // Ensure Puppeteer cache dir exists
    $ppCacheDir = $runtimeDir . '/.cache/puppeteer';
    if (!is_dir($ppCacheDir)) { @mkdir($ppCacheDir, 0777, true); }

    // Augment NODE_PATH
    $existingNodePath = $env['NODE_PATH'] ?? getenv('NODE_PATH') ?: '';
    $mergedNodePath = $nodeModules . ($existingNodePath ? PATH_SEPARATOR . $existingNodePath : '');

    // Provide Chrome path to Puppeteer if we know it
    $chromeBin = pp_resolve_chrome_binary();

    $envFinal = array_merge($baseEnv, $env, [
        'NODE_PATH' => $mergedNodePath,
        // In case hosting uses Puppeteer env variable
        'PUPPETEER_CACHE_DIR' => isset($baseEnv['PUPPETEER_CACHE_DIR']) ? $baseEnv['PUPPETEER_CACHE_DIR'] : $ppCacheDir,
    ]);
    if ($chromeBin && empty($envFinal['PUPPETEER_EXECUTABLE_PATH'])) {
        $envFinal['PUPPETEER_EXECUTABLE_PATH'] = $chromeBin;
    }

    // Prefer proc_open for control over timeout
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    if ($nodeBin !== '' && function_exists('proc_open')) {
        $cmd = [$nodeBin, $tmpFile];
        // Pass working directory AND environment to the process
        $proc = @proc_open($cmd, $descriptors, $pipes, $runtimeDir, $envFinal);
        if (is_resource($proc)) {
            @fclose($pipes[0]);
            @stream_set_blocking($pipes[1], false);
            @stream_set_blocking($pipes[2], false);
            $start = microtime(true);
            while (true) {
                $status = @proc_get_status($proc);
                $stdout .= (string)@stream_get_contents($pipes[1]);
                $stderr .= (string)@stream_get_contents($pipes[2]);
                if (!$status['running']) { break; }
                if ((microtime(true) - $start) > $timeout) { @proc_terminate($proc, 9); break; }
                usleep(200000);
            }
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            $exitCode = @proc_close($proc);
        } else {
            $stderr .= 'proc_open failed';
        }
    } elseif ($nodeBin !== '' && function_exists('shell_exec')) {
        // Fallback: best-effort without reliable exit code or timeout
        $envExport = '';
        foreach ($envFinal as $k => $v) {
            if ($k !== '' && strpos($k, '=') === false) {
                $envExport .= $k . '=' . escapeshellarg((string)$v) . ' ';
            }
        }
        $cmd = $envExport . escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile) . ' 2>&1';
        $stdout = (string)@shell_exec('cd ' . escapeshellarg($runtimeDir) . ' && ' . $cmd);
        $exitCode = null; // unknown in this mode
    } else {
        $stderr .= 'Node binary not found or no execution functions available';
    }

    @unlink($tmpFile);
    return [$exitCode, (string)$stdout, (string)$stderr];
}

?>