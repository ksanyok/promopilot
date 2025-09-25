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

    // Networks registry tracks available publication handlers
    $netCols = $getCols('networks');
    if (empty($netCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `networks` (
            `slug` VARCHAR(120) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `handler` VARCHAR(255) NOT NULL,
            `handler_type` VARCHAR(50) NOT NULL DEFAULT 'node',
            `meta` TEXT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `is_missing` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAdd = function(string $field, string $definition) use ($netCols, $conn) {
            if (!isset($netCols[$field])) {
                @$conn->query("ALTER TABLE `networks` ADD COLUMN {$definition}");
            }
        };
        $maybeAdd('description', "`description` TEXT NULL AFTER `title`");
        $maybeAdd('handler', "`handler` VARCHAR(255) NOT NULL DEFAULT '' AFTER `description`");
        $maybeAdd('handler_type', "`handler_type` VARCHAR(50) NOT NULL DEFAULT 'node' AFTER `handler`");
        $maybeAdd('meta', "`meta` TEXT NULL AFTER `handler_type`");
        $maybeAdd('enabled', "`enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `meta`");
        $maybeAdd('is_missing', "`is_missing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `enabled`");
        $maybeAdd('created_at', "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_missing`");
        $maybeAdd('updated_at', "`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // Settings table optional—skip if missing

    @$conn->close();

    try {
        pp_refresh_networks(false);
    } catch (Throwable $e) {
        // ignore auto refresh errors during bootstrap
    }
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
    // Allow non-POST methods without CSRF
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') return true;
    // Validate token from POST against session
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '') return false;
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '') return false;
    return hash_equals($sessionToken, $token);
}

function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
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
        $GLOBALS['pp_settings_cache'] = &$cache;
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, $value): bool {
    return set_settings([$key => $value]);
}

function set_settings(array $pairs): bool {
    if (empty($pairs)) { return true; }
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return false;
    }
    if (!$conn) { return false; }
    $stmt = $conn->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) { $conn->close(); return false; }
    foreach ($pairs as $k => $v) {
        $ks = (string)$k;
        $vs = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param('ss', $ks, $vs);
        $stmt->execute();
    }
    $stmt->close();
    $conn->close();

    if (isset($GLOBALS['pp_settings_cache']) && is_array($GLOBALS['pp_settings_cache'])) {
        foreach ($pairs as $k => $v) {
            $GLOBALS['pp_settings_cache'][(string)$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
    }
    return true;
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

// ---------- Publication networks helpers ----------

function pp_networks_dir(): string {
    $dir = PP_ROOT_PATH . '/networks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function pp_normalize_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    $slug = preg_replace('~[^a-z0-9_\-]+~', '-', $slug);
    return trim($slug, '-_');
}

function pp_path_to_relative(string $path): string {
    $path = str_replace(['\\', '\\'], '/', $path);
    $root = str_replace(['\\', '\\'], '/', PP_ROOT_PATH);
    if (strpos($path, $root) === 0) {
        $rel = ltrim(substr($path, strlen($root)), '/');
        return $rel === '' ? '.' : $rel;
    }
    return $path;
}

function pp_network_descriptor_from_file(string $file): ?array {
    if (!is_file($file)) { return null; }
    try {
        /** @noinspection PhpIncludeInspection */
        $descriptor = @include $file;
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($descriptor)) { return null; }
    $descriptor['slug'] = pp_normalize_slug((string)($descriptor['slug'] ?? ''));
    if ($descriptor['slug'] === '') { return null; }
    $descriptor['title'] = trim((string)($descriptor['title'] ?? ucfirst($descriptor['slug'])));
    $descriptor['description'] = trim((string)($descriptor['description'] ?? ''));
    $descriptor['handler'] = trim((string)($descriptor['handler'] ?? ''));
    if ($descriptor['handler'] === '') { return null; }

    // Resolve handler path
    $handler = $descriptor['handler'];
    $isAbsolute = preg_match('~^([a-zA-Z]:[\\/]|/)~', $handler) === 1;
    if (!$isAbsolute) {
        $handler = realpath(dirname($file) . '/' . $handler) ?: (dirname($file) . '/' . $handler);
    }
    $handlerAbs = realpath($handler) ?: $handler;

    $descriptor['handler_type'] = strtolower(trim((string)($descriptor['handler_type'] ?? 'node')));
    $descriptor['enabled'] = isset($descriptor['enabled']) ? (bool)$descriptor['enabled'] : true;
    $descriptor['meta'] = is_array($descriptor['meta'] ?? null) ? $descriptor['meta'] : [];
    $descriptor['source_file'] = $file;
    $descriptor['handler_abs'] = $handlerAbs;
    $descriptor['handler_rel'] = pp_path_to_relative($handlerAbs);

    return $descriptor;
}

function pp_refresh_networks(bool $force = false): array {
    if (!$force) {
        $last = (int)get_setting('networks_last_refresh', 0);
        if ($last && (time() - $last) < 300) {
            return pp_get_networks(false, true);
        }
    }

    $dir = pp_networks_dir();
    $files = glob($dir . '/*.php') ?: [];
    $descriptors = [];
    foreach ($files as $file) {
        $descriptor = pp_network_descriptor_from_file($file);
        if ($descriptor) {
            $descriptors[$descriptor['slug']] = $descriptor;
        }
    }

    $conn = null;
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        $conn = null;
    }
    if (!$conn) { return array_values($descriptors); }

    // snapshot existing enabled flags
    $existing = [];
    if ($res = @$conn->query("SELECT slug, enabled FROM networks")) {
        while ($row = $res->fetch_assoc()) {
            $existing[$row['slug']] = (int)$row['enabled'];
        }
        $res->free();
    }

    $stmt = $conn->prepare("INSERT INTO networks (slug, title, description, handler, handler_type, meta, enabled, is_missing) VALUES (?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), handler = VALUES(handler), handler_type = VALUES(handler_type), meta = VALUES(meta), is_missing = 0, updated_at = CURRENT_TIMESTAMP");
    if ($stmt) {
        foreach ($descriptors as $slug => $descriptor) {
            $enabled = $descriptor['enabled'] ? 1 : 0;
            if (array_key_exists($slug, $existing)) {
                $enabled = $existing[$slug];
            }
            $metaJson = json_encode($descriptor['meta'], JSON_UNESCAPED_UNICODE);
            $stmt->bind_param(
                'ssssssi',
                $descriptor['slug'],
                $descriptor['title'],
                $descriptor['description'],
                $descriptor['handler_rel'],
                $descriptor['handler_type'],
                $metaJson,
                $enabled
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    $knownSlugs = array_keys($descriptors);
    if (!empty($knownSlugs)) {
        $placeholders = implode(',', array_fill(0, count($knownSlugs), '?'));
        $query = $conn->prepare("UPDATE networks SET is_missing = 1, enabled = 0 WHERE slug NOT IN ($placeholders)");
        if ($query) {
            $types = str_repeat('s', count($knownSlugs));
            $query->bind_param($types, ...$knownSlugs);
            $query->execute();
            $query->close();
        }
    } else {
        @$conn->query("UPDATE networks SET is_missing = 1, enabled = 0");
    }

    $conn->close();
    set_setting('networks_last_refresh', (string)time());

    return array_values($descriptors);
}

function pp_get_networks(bool $onlyEnabled = false, bool $includeMissing = false): array {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return [];
    }
    if (!$conn) { return []; }

    $where = [];
    if ($onlyEnabled) { $where[] = "enabled = 1"; }
    if (!$includeMissing) { $where[] = "is_missing = 0"; }
    $sql = "SELECT slug, title, description, handler, handler_type, meta, enabled, is_missing, created_at, updated_at FROM networks";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY title ASC';
    $rows = [];
    if ($res = @$conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rel = (string)$row['handler'];
            $isAbsolute = preg_match('~^([a-zA-Z]:[\\/]|/)~', $rel) === 1;
            if ($rel === '.') {
                $abs = PP_ROOT_PATH;
            } elseif ($isAbsolute) {
                $abs = $rel;
            } else {
                $abs = PP_ROOT_PATH . '/' . ltrim($rel, '/');
            }
            $absReal = realpath($abs);
            if ($absReal) { $abs = $absReal; }
            $rows[] = [
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'description' => (string)$row['description'],
                'handler' => $rel,
                'handler_abs' => $abs,
                'handler_type' => (string)$row['handler_type'],
                'meta' => $row['meta'] ? json_decode($row['meta'], true) ?: [] : [],
                'enabled' => (bool)$row['enabled'],
                'is_missing' => (bool)$row['is_missing'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        $res->free();
    }

    $conn->close();
    return $rows;
}

function pp_get_network(string $slug): ?array {
    $slug = pp_normalize_slug($slug);
    $all = pp_get_networks(false, true);
    foreach ($all as $network) {
        if ($network['slug'] === $slug) { return $network; }
    }
    return null;
}

function pp_pick_network(): ?array {
    $nets = pp_get_networks(true, false);
    return $nets[0] ?? null;
}

function pp_set_networks_enabled(array $slugsToEnable): bool {
    $map = [];
    foreach ($slugsToEnable as $slug) {
        $norm = pp_normalize_slug((string)$slug);
        if ($norm !== '') { $map[$norm] = true; }
    }
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return false;
    }
    if (!$conn) { return false; }

    $allSlugs = [];
    if ($res = @$conn->query("SELECT slug FROM networks")) {
        while ($row = $res->fetch_assoc()) { $allSlugs[] = (string)$row['slug']; }
        $res->free();
    }

    $stmt = $conn->prepare("UPDATE networks SET enabled = ? WHERE slug = ?");
    if ($stmt) {
        foreach ($allSlugs as $slug) {
            $enabled = isset($map[$slug]) ? 1 : 0;
            $stmt->bind_param('is', $enabled, $slug);
            $stmt->execute();
        }
        $stmt->close();
    }
    $conn->close();
    return true;
}

function pp_get_node_binary(): string {
    $fromSetting = trim((string)get_setting('node_binary', ''));
    if ($fromSetting !== '') { return $fromSetting; }
    $env = getenv('NODE_BINARY');
    if ($env && trim($env) !== '') { return trim($env); }
    return 'node';
}

function pp_run_node_script(string $script, array $job, int $timeoutSeconds = 480): array {
    if (!is_file($script) || !is_readable($script)) {
        return ['ok' => false, 'error' => 'SCRIPT_NOT_FOUND'];
    }
    $node = pp_get_node_binary();
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Prepare writable paths for logs and caches
    $logDir = PP_ROOT_PATH . '/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
    if (!is_writable($logDir)) { @chmod($logDir, 0775); }
    $homeDir = PP_ROOT_PATH . '/.cache';
    if (!is_dir($homeDir)) { @mkdir($homeDir, 0775, true); }

    // Optional puppeteer settings from app settings
    $puppeteerExec = trim((string)get_setting('puppeteer_executable_path', ''));
    $puppeteerArgs = trim((string)get_setting('puppeteer_args', ''));

    $env = array_merge($_ENV, $_SERVER, [
        'PP_JOB' => json_encode($job, JSON_UNESCAPED_UNICODE),
        'NODE_NO_WARNINGS' => '1',
        'PP_LOG_DIR' => $logDir,
        // hint a log file name (script may use it or its default inside PP_LOG_DIR)
        'PP_LOG_FILE' => $logDir . '/network-' . basename($script, '.js') . '-' . date('Ymd-His') . '-' . getmypid() . '.log',
        'HOME' => $homeDir,
    ]);
    if ($puppeteerExec !== '') { $env['PUPPETEER_EXECUTABLE_PATH'] = $puppeteerExec; }
    if ($puppeteerArgs !== '') { $env['PUPPETEER_ARGS'] = $puppeteerArgs; }

    $cmd = $node . ' ' . escapeshellarg($script);
    $process = @proc_open($cmd, $descriptorSpec, $pipes, PP_ROOT_PATH, $env);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'PROC_OPEN_FAILED'];
    }

    // Close STDIN of the child if exists to avoid hanging
    if (isset($pipes[0]) && is_resource($pipes[0])) { @fclose($pipes[0]); $pipes[0] = null; }
    if (isset($pipes[1]) && is_resource($pipes[1])) { @stream_set_blocking($pipes[1], false); }
    if (isset($pipes[2]) && is_resource($pipes[2])) { @stream_set_blocking($pipes[2], false); }

    $stdout = '';
    $stderr = '';
    $start = time();

    while (true) {
        $status = @proc_get_status($process);
        if (!$status || !$status['running']) {
            // Read any remaining data
            if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
            if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
            break;
        }

        $read = [];
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            if (!@feof($pipes[1])) { $read[] = $pipes[1]; }
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            if (!@feof($pipes[2])) { $read[] = $pipes[2]; }
        }
        if (!$read) { break; }
        $remaining = max(1, $timeoutSeconds - (time() - $start));
        $write = null; $except = null;
        $ready = @stream_select($read, $write, $except, $remaining, 0);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            // Timeout: terminate process
            @proc_terminate($process, 9);
            if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
            if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
            if (isset($pipes) && is_array($pipes)) {
                foreach ($pipes as &$pipe) { if (is_resource($pipe)) { @fclose($pipe); } $pipe = null; }
                unset($pipe);
            }
            @proc_close($process);
            return ['ok' => false, 'error' => 'NODE_TIMEOUT', 'stderr' => trim($stderr)];
        }
        foreach ($read as $stream) {
            if (is_resource($stream)) {
                $chunk = @stream_get_contents($stream);
                if ($stream === ($pipes[1] ?? null)) { $stdout .= (string)$chunk; }
                else { $stderr .= (string)$chunk; }
            }
        }
        if ((time() - $start) >= $timeoutSeconds) {
            @proc_terminate($process, 9);
            if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
            if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
            if (isset($pipes) && is_array($pipes)) {
                foreach ($pipes as &$pipe) { if (is_resource($pipe)) { @fclose($pipe); } $pipe = null; }
                unset($pipe);
            }
            @proc_close($process);
            return ['ok' => false, 'error' => 'NODE_TIMEOUT', 'stderr' => trim($stderr)];
        }
    }

    if (isset($pipes) && is_array($pipes)) {
        foreach ($pipes as &$pipe) { if (is_resource($pipe)) { @fclose($pipe); } $pipe = null; }
        unset($pipe);
    }
    $exitCode = @proc_close($process);

    $response = ['ok' => false, 'error' => 'NODE_RETURN_EMPTY'];
    $stdoutTrim = trim($stdout);
    if ($stdoutTrim !== '') {
        $pos = strrpos($stdoutTrim, "\n");
        $lastLine = trim($pos === false ? $stdoutTrim : substr($stdoutTrim, $pos + 1));
        $decoded = json_decode($lastLine, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $response = $decoded;
        } else {
            $response = ['ok' => false, 'error' => 'INVALID_JSON', 'raw' => $stdoutTrim];
        }
    }
    if (strlen($stderr) > 0) {
        $response['stderr'] = trim($stderr);
    }
    $response['exit_code'] = $exitCode;
    return $response;
}

function pp_publish_via_network(array $network, array $job, int $timeoutSeconds = 480): array {
    $type = strtolower((string)($network['handler_type'] ?? ''));
    if ($type !== 'node') {
        return ['ok' => false, 'error' => 'UNSUPPORTED_HANDLER'];
    }
    return pp_run_node_script($network['handler_abs'], $job, $timeoutSeconds);
}

?>
