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

// Add a small helper to check index existence for older MySQL versions (no IF NOT EXISTS support)
if (!function_exists('pp_mysql_index_exists')) {
    function pp_mysql_index_exists(mysqli $conn, string $table, string $index): bool {
        $table = preg_replace('~[^a-zA-Z0-9_]+~', '', $table);
        $index = preg_replace('~[^a-zA-Z0-9_]+~', '', $index);
        $sql = "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'";
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            $exists = $res->num_rows > 0;
            $res->close();
            return $exists;
        }
        return false;
    }
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
            `domain_host` VARCHAR(190) NULL,
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
        // New: domain restriction host
        if (!isset($projectsCols['domain_host'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `domain_host` VARCHAR(190) NULL AFTER `wishes`");
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
        // Google OAuth fields
        if (!isset($usersCols['google_id'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(64) NULL AFTER `avatar`");
        }
        if (!isset($usersCols['google_picture'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `google_picture` VARCHAR(255) NULL AFTER `google_id`");
        }
        // Ensure unique index on google_id (compat with MySQL 5.7: no IF NOT EXISTS)
        if (pp_mysql_index_exists($conn, 'users', 'uniq_users_google_id') === false) {
            // Only create index when column exists
            $usersCols2 = $getCols('users');
            if (isset($usersCols2['google_id'])) {
                @$conn->query("CREATE UNIQUE INDEX `uniq_users_google_id` ON `users`(`google_id`)");
            }
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

    // New: page metadata storage (microdata extracted for links)
    $pmCols = $getCols('page_meta');
    if (empty($pmCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `page_meta` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `url_hash` CHAR(64) NOT NULL,
            `page_url` TEXT NOT NULL,
            `final_url` TEXT NULL,
            `lang` VARCHAR(16) NULL,
            `region` VARCHAR(16) NULL,
            `title` VARCHAR(512) NULL,
            `description` TEXT NULL,
            `canonical` TEXT NULL,
            `published_time` VARCHAR(64) NULL,
            `modified_time` VARCHAR(64) NULL,
            `hreflang_json` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_page_meta_proj_hash` (`project_id`, `url_hash`),
            INDEX (`project_id`),
            CONSTRAINT `fk_page_meta_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // Ensure critical columns exist (best-effort; ignore errors)
        foreach ([
            'url_hash' => "ADD COLUMN `url_hash` CHAR(64) NOT NULL AFTER `project_id`",
            'page_url' => "ADD COLUMN `page_url` TEXT NOT NULL AFTER `url_hash`",
            'final_url' => "ADD COLUMN `final_url` TEXT NULL AFTER `page_url`",
            'lang' => "ADD COLUMN `lang` VARCHAR(16) NULL AFTER `final_url`",
            'region' => "ADD COLUMN `region` VARCHAR(16) NULL AFTER `lang`",
            'title' => "ADD COLUMN `title` VARCHAR(512) NULL AFTER `region`",
            'description' => "ADD COLUMN `description` TEXT NULL AFTER `title`",
            'canonical' => "ADD COLUMN `canonical` TEXT NULL AFTER `description`",
            'published_time' => "ADD COLUMN `published_time` VARCHAR(64) NULL AFTER `canonical`",
            'modified_time' => "ADD COLUMN `modified_time` VARCHAR(64) NULL AFTER `published_time`",
            'hreflang_json' => "ADD COLUMN `hreflang_json` TEXT NULL AFTER `modified_time`",
            'created_at' => "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ] as $field => $ddl) {
            if (!isset($pmCols[$field])) { @($conn->query("ALTER TABLE `page_meta` {$ddl}")); }
        }
        // Ensure unique index if missing
        if (pp_mysql_index_exists($conn, 'page_meta', 'uniq_page_meta_proj_hash') === false) {
            @($conn->query("CREATE UNIQUE INDEX `uniq_page_meta_proj_hash` ON `page_meta`(`project_id`,`url_hash`)"));
        }
    }

    // Settings table optional—skip if missing

    @$conn->close();

    try {
        pp_refresh_networks(false);
    } catch (Throwable $e) {
        // ignore auto refresh errors during bootstrap
    }
}

// ---- Version and update helpers ----
if (!function_exists('get_version')) {
    function get_version(): string {
        static $v = null;
        if ($v !== null) return $v;
        $v = '0.0.0';
        $file = PP_ROOT_PATH . '/config/version.php';
        if (is_file($file) && is_readable($file)) {
            try {
                $version = null; // defined in included file
                /** @noinspection PhpIncludeInspection */
                include $file;
                if (isset($version) && is_string($version) && $version !== '') {
                    $v = trim($version);
                }
            } catch (Throwable $e) { /* ignore */ }
        }
        return $v;
    }
}

if (!function_exists('get_update_status')) {
    function get_update_status(): array {
        $current = get_version();
        $cacheDir = PP_ROOT_PATH . '/.cache';
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        $cacheFile = $cacheDir . '/update_status.json';
        $now = time();
        $cacheTtl = 6 * 3600; // 6 hours

        // Read cache
        $cached = null;
        if (is_file($cacheFile) && is_readable($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['fetched_at']) && ($now - (int)$data['fetched_at'] < $cacheTtl)) {
                $cached = $data;
            }
        }
        if ($cached) {
            $latest = (string)($cached['latest'] ?? $current);
            $publishedAt = (string)($cached['published_at'] ?? '');
            $isNew = version_compare($latest, $current, '>');
            return [
                'current' => $current,
                'latest' => $latest,
                'published_at' => $publishedAt,
                'is_new' => $isNew,
                'source' => 'cache',
            ];
        }

        // Fetch from GitHub releases API (latest)
        $latest = $current;
        $publishedAt = '';
        $ok = false; $err = '';
        $url = 'https://api.github.com/repos/ksanyok/promopilot/releases/latest';
        $ua = 'PromoPilot/UpdateChecker (+https://github.com/ksanyok/promopilot)';
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                ],
            ]);
            $resp = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($resp !== '' && $code >= 200 && $code < 300) { $ok = true; }
            else { $err = 'HTTP ' . $code; }
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'header' => [
                        'User-Agent: ' . $ua,
                        'Accept: application/vnd.github+json',
                    ],
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            $ok = $resp !== false && $resp !== '';
        }
        if ($ok) {
            $json = json_decode($resp, true);
            if (is_array($json)) {
                $tag = (string)($json['tag_name'] ?? '');
                $name = (string)($json['name'] ?? '');
                $publishedAt = (string)($json['published_at'] ?? '');
                $tag = ltrim($tag, 'vV');
                $name = ltrim($name, 'vV');
                $candidate = $tag ?: $name;
                if ($candidate !== '') { $latest = $candidate; }
            }
        }

        // Persist cache (best-effort)
        $payload = [
            'fetched_at' => $now,
            'latest' => $latest,
            'published_at' => $publishedAt,
            'ok' => $ok,
            'error' => $ok ? '' : $err,
        ];
        @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return [
            'current' => $current,
            'latest' => $latest,
            'published_at' => $publishedAt,
            'is_new' => version_compare($latest, $current, '>'),
            'source' => 'remote',
        ];
    }
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

// Auth helpers
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return is_logged_in() && (($_SESSION['role'] ?? '') === 'admin');
    }
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

// Save a remote avatar image locally and return relative path (uploads/avatars/u{ID}.ext)
// Returns null on failure
function pp_save_remote_avatar(string $url, int $userId): ?string {
    $url = trim($url);
    if ($url === '') return null;
    $pu = @parse_url($url);
    if (!$pu || !in_array(strtolower($pu['scheme'] ?? ''), ['http','https'], true)) return null;

    $dir = PP_ROOT_PATH . '/uploads/avatars';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir) || !is_writable($dir)) return null;

    $data = null; $ctype = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PromoPilot/1.0',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($resp, 0, $headerSize);
            $data = substr($resp, $headerSize);
            if (preg_match('~^Content-Type:\s*([^\r\n]+)~im', (string)$headers, $m)) {
                $ctype = trim($m[1]);
            }
        }
        curl_close($ch);
    }
    if ($data === null) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: PromoPilot/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        // Cannot easily get content-type with stream wrapper; leave empty
    }

    if (!$data || strlen($data) < 128) return null; // too small to be real avatar
    if (strlen($data) > 5 * 1024 * 1024) return null; // limit 5MB

    // Detect mime
    if (function_exists('finfo_buffer')) {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $det = $f->buffer($data) ?: '';
        if ($det) { $ctype = $det; }
    }
    $ext = 'jpg';
    if (stripos($ctype, 'png') !== false) $ext = 'png';
    elseif (stripos($ctype, 'webp') !== false) $ext = 'webp';
    elseif (stripos($ctype, 'jpeg') !== false) $ext = 'jpg';

    $file = $dir . '/u' . $userId . '.' . $ext;
    $ok = @file_put_contents($file, $data) !== false;
    if (!$ok) return null;
    @chmod($file, 0664);
    $rel = 'uploads/avatars/' . basename($file);
    return $rel;
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

function pp_check_node_binary(string $bin, int $timeoutSeconds = 3): array {
    $descriptor = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $cmd = escapeshellarg($bin) . ' -v';
    $proc = @proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) { return ['ok'=>false, 'error'=>'PROC_OPEN_FAILED']; }
    if (isset($pipes[0]) && is_resource($pipes[0])) { @fclose($pipes[0]); }
    $stdout = '';
    $stderr = '';
    $start = time();
    if (isset($pipes[1]) && is_resource($pipes[1])) { @stream_set_blocking($pipes[1], false); }
    if (isset($pipes[2]) && is_resource($pipes[2])) { @stream_set_blocking($pipes[2], false); }
    while (true) {
        $status = @proc_get_status($proc);
        if (!$status || !$status['running']) { break; }
        if ((time() - $start) >= $timeoutSeconds) { @proc_terminate($proc, 9); break; }
        usleep(100000);
    }
    if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); @fclose($pipes[1]); }
    if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); @fclose($pipes[2]); }
    $exit = @proc_close($proc);
    $ver = trim($stdout);
    $ok = ($exit === 0) && preg_match('~^v?(\d+\.\d+\.\d+)~', $ver);
    return ['ok' => (bool)$ok, 'version' => $ver, 'exit_code' => $exit, 'stderr' => trim($stderr)];
}

function pp_collect_node_candidates(): array {
    $candidates = [];
    $setting = trim((string)get_setting('node_binary', ''));
    if ($setting !== '') { $candidates[] = $setting; }
    $env = getenv('NODE_BINARY');
    if ($env && trim($env) !== '') { $candidates[] = trim($env); }

    if (function_exists('shell_exec')) {
        $whichNode = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($whichNode !== '') { $candidates[] = $whichNode; }
        $whichNodeJs = trim((string)@shell_exec('command -v nodejs 2>/dev/null'));
        if ($whichNodeJs !== '') { $candidates[] = $whichNodeJs; }

        $bashPaths = [
            "/bin/bash -lc 'command -v node' 2>/dev/null",
            "/bin/bash -lc 'which node' 2>/dev/null",
            "/bin/bash -lc 'command -v nodejs' 2>/dev/null",
            "/bin/bash -lc 'which nodejs' 2>/dev/null",
            "/bin/bash -lc 'whereis -b node' 2>/dev/null",
        ];
        foreach ($bashPaths as $cmd) {
            $out = trim((string)@shell_exec($cmd));
            if ($out === '') { continue; }
            $parts = preg_split('~[\s]+~', $out);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '' || strpos($part, '/') === false) { continue; }
                $candidates[] = $part;
            }
        }

        $bashLists = [
            "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/bin/node 2>/dev/null'",
            "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/usr/bin/node 2>/dev/null'",
            "/bin/bash -lc 'ls -1 /opt/alt/nodejs*/root/usr/bin/node 2>/dev/null'",
            "/bin/bash -lc 'ls -1 /opt/nodejs*/bin/node 2>/dev/null'",
            "/bin/bash -lc 'ls -1 \$HOME/.nodebrew/current/bin/node 2>/dev/null'",
            "/bin/bash -lc 'ls -1 \$HOME/.nvm/versions/node/*/bin/node 2>/dev/null'",
        ];
        foreach ($bashLists as $cmd) {
            $out = trim((string)@shell_exec($cmd));
            if ($out === '') { continue; }
            foreach (preg_split('~[\r\n]+~', $out) as $line) {
                $line = trim($line);
                if ($line !== '') { $candidates[] = $line; }
            }
        }
    }

    $pathEnv = (string)($_SERVER['PATH'] ?? getenv('PATH') ?? '');
    if ($pathEnv !== '') {
        $parts = preg_split('~' . preg_quote(PATH_SEPARATOR, '~') . '~', $pathEnv);
        foreach ($parts as $dir) {
            $dir = trim($dir);
            if ($dir === '') { continue; }
            $dir = rtrim($dir, '/\\');
            $candidates[] = $dir . '/node';
            $candidates[] = $dir . '/nodejs';
        }
    }

    $home = getenv('HOME') ?: ((isset($_SERVER['HOME']) && $_SERVER['HOME']) ? $_SERVER['HOME'] : '');
    if ($home) {
        $home = rtrim($home, '/');
        $candidates[] = $home . '/.local/bin/node';
        $candidates[] = $home . '/bin/node';
        foreach (@glob($home . '/.nvm/versions/node/*/bin/node') ?: [] as $path) { $candidates[] = $path; }
        foreach (@glob($home . '/.asdf/installs/nodejs/*/bin/node') ?: [] as $path) { $candidates[] = $path; }
    }

    $globPaths = [
        '/usr/local/bin/node',
        '/usr/bin/node',
        '/bin/node',
        '/usr/local/node/bin/node',
        '/usr/local/share/node/bin/node',
        '/opt/homebrew/bin/node',
        '/opt/local/bin/node',
        '/snap/bin/node',
    ];
    foreach (['/opt/node*/bin/node','/opt/nodejs*/bin/node','/opt/alt/*/bin/node','/opt/alt/*/usr/bin/node','/opt/alt/*/root/usr/bin/node'] as $pattern) {
        foreach (@glob($pattern) ?: [] as $path) { $globPaths[] = $path; }
    }
    foreach ($globPaths as $path) { $candidates[] = $path; }

    $candidates[] = 'node';
    $candidates[] = 'nodejs';

    $result = [];
    foreach ($candidates as $cand) {
        $cand = trim((string)$cand);
        if ($cand === '') { continue; }
        if (!isset($result[$cand])) { $result[$cand] = $cand; }
    }
    return array_values($result);
}

function pp_resolve_node_binary(int $timeoutSeconds = 3, bool $persist = true): ?array {
    $candidates = pp_collect_node_candidates();
    foreach ($candidates as $cand) {
        $check = pp_check_node_binary($cand, $timeoutSeconds);
        if ($check['ok']) {
            if ($persist) {
                $current = trim((string)get_setting('node_binary', ''));
                if ($current !== $cand) {
                    set_setting('node_binary', $cand);
                }
            }
            return ['path' => $cand, 'version' => $check['version'], 'diagnostics' => $check];
        }
    }
    return null;
}

function pp_get_node_binary(): string {
    $resolved = pp_resolve_node_binary(3, true);
    if ($resolved) { return $resolved['path']; }
    return 'node';
}

function pp_run_node_script(string $script, array $job, int $timeoutSeconds = 480): array {
    if (!is_file($script) || !is_readable($script)) {
        return ['ok' => false, 'error' => 'SCRIPT_NOT_FOUND'];
    }
    $resolved = pp_resolve_node_binary(3, true);
    $nodeCandidates = pp_collect_node_candidates();
    if (!$resolved) {
        return [
            'ok' => false,
            'error' => 'NODE_BINARY_NOT_FOUND',
            'details' => 'Node.js is not available for the PHP process. Настройте путь в админке или переменную NODE_BINARY.',
            'candidates' => $nodeCandidates,
        ];
    }
    $node = $resolved['path'];
    $nodeVer = $resolved['version'] ?? '';

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
    if ($puppeteerExec === '') {
        $autoChrome = pp_resolve_chrome_path();
        if ($autoChrome) { $puppeteerExec = $autoChrome; }
    }
    $puppeteerArgs = trim((string)get_setting('puppeteer_args', ''));

    $env = array_merge($_ENV, $_SERVER, [
        'PP_JOB' => json_encode($job, JSON_UNESCAPED_UNICODE),
        'NODE_NO_WARNINGS' => '1',
        'PP_LOG_DIR' => $logDir,
        'PP_LOG_FILE' => $logDir . '/network-' . basename($script, '.js') . '-' . date('Ymd-His') . '-' . getmypid() . '.log',
        'HOME' => $homeDir,
    ]);
    if ($puppeteerExec !== '') {
        $env['PUPPETEER_EXECUTABLE_PATH'] = $puppeteerExec;
        $env['PP_CHROME_PATH'] = $puppeteerExec;
        $env['GOOGLE_CHROME_BIN'] = $puppeteerExec;
        $env['CHROME_PATH'] = $puppeteerExec;
        $env['CHROME_BIN'] = $puppeteerExec;
    }
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
            if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
            if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
            break;
        }
        $read = [];
        if (isset($pipes[1]) && is_resource($pipes[1]) && !@feof($pipes[1])) { $read[] = $pipes[1]; }
        if (isset($pipes[2]) && is_resource($pipes[2]) && !@feof($pipes[2])) { $read[] = $pipes[2]; }
        if (!$read) { break; }
        $remaining = max(1, $timeoutSeconds - (time() - $start));
        $write = null; $except = null;
        $ready = @stream_select($read, $write, $except, $remaining, 0);
        if ($ready === false) { break; }
        if ($ready === 0) {
            @proc_terminate($process, 9);
            if (isset($pipes[1]) && is_resource($pipes[1])) { $stdout .= (string)@stream_get_contents($pipes[1]); }
            if (isset($pipes[2]) && is_resource($pipes[2])) { $stderr .= (string)@stream_get_contents($pipes[2]); }
            if (isset($pipes) && is_array($pipes)) { foreach ($pipes as &$p) { if (is_resource($p)) { @fclose($p); } $p = null; } unset($p); }
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
            if (isset($pipes) && is_array($pipes)) { foreach ($pipes as &$p) { if (is_resource($p)) { @fclose($p); } $p = null; } unset($p); }
            @proc_close($process);
            return ['ok' => false, 'error' => 'NODE_TIMEOUT', 'stderr' => trim($stderr)];
        }
    }

    if (isset($pipes) && is_array($pipes)) { foreach ($pipes as &$p) { if (is_resource($p)) { @fclose($p); } $p = null; } unset($p); }
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
    if (strlen($stderr) > 0) { $response['stderr'] = trim($stderr); }
    $response['exit_code'] = $exitCode;
    // Hint if nothing returned and exit 127 (command not found)
    if (($response['error'] ?? '') === 'NODE_RETURN_EMPTY' && (int)$exitCode === 127) {
        $response['error'] = 'NODE_BINARY_NOT_FOUND';
        $response['details'] = 'Node.js command not found by PHP. Set settings.node_binary or NODE_BINARY env to full path (e.g. /opt/homebrew/bin/node).';
    }
    // Attach detected node version if we had it
    if (!empty($nodeVer)) { $response['node_version'] = $nodeVer; }
    return $response;
}

function pp_publish_via_network(array $network, array $job, int $timeoutSeconds = 480): array {
    $type = strtolower((string)($network['handler_type'] ?? ''));
    if ($type !== 'node') {
        return ['ok' => false, 'error' => 'UNSUPPORTED_HANDLER'];
    }
    return pp_run_node_script($network['handler_abs'], $job, $timeoutSeconds);
}

function pp_collect_chrome_candidates(): array {
    $candidates = [];

    // 1) From settings and env
    $setting = trim((string)get_setting('puppeteer_executable_path', ''));
    if ($setting !== '') { $candidates[] = $setting; }
    $envVars = ['PUPPETEER_EXECUTABLE_PATH','PP_CHROME_PATH','GOOGLE_CHROME_BIN','CHROME_PATH','CHROME_BIN'];
    foreach ($envVars as $k) {
        $v = getenv($k);
        if ($v && trim($v) !== '') { $candidates[] = trim($v); }
    }

    // 2) Common system locations
    $common = [
        '/usr/local/bin/google-chrome',
        '/usr/local/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/bin/google-chrome',
        '/bin/chromium',
        '/opt/google/chrome/google-chrome',
        '/opt/google/chrome/chrome',
        '/opt/chrome/chrome',
        '/snap/bin/chromium',
        // macOS common locations
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
    ];
    foreach ($common as $p) { $candidates[] = $p; }

    // macOS per-user Applications folder
    $home = getenv('HOME') ?: ((isset($_SERVER['HOME']) && $_SERVER['HOME']) ? $_SERVER['HOME'] : '');
    if ($home) {
        foreach ([
            $home . '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            $home . '/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary',
            $home . '/Applications/Chromium.app/Contents/MacOS/Chromium',
            $home . '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            $home . '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        ] as $p) { $candidates[] = $p; }
    }

    // 3) Project-local portable Chrome
    $base = rtrim(PP_ROOT_PATH, '/');
    $projectLocal = [
        $base . '/node_runtime/chrome/chrome',
        $base . '/node_runtime/chrome/chrome-linux64/chrome',
    ];
    foreach ($projectLocal as $p) { $candidates[] = $p; }

    // 4) Glob project-local versions (e.g., linux-xxx)
    foreach (@glob($base . '/node_runtime/chrome/*/chrome-linux64/chrome') ?: [] as $p) { $candidates[] = $p; }

    // 5) PATH lookups via shell, if available
    if (function_exists('shell_exec')) {
        $cmds = [
            "command -v google-chrome 2>/dev/null",
            "command -v google-chrome-stable 2>/dev/null",
            "command -v chromium 2>/dev/null",
            "command -v chromium-browser 2>/dev/null",
        ];
        foreach ($cmds as $cmd) {
            $out = trim((string)@shell_exec($cmd));
            if ($out !== '' && strpos($out, '/') !== false) { $candidates[] = $out; }
        }
        // macOS Spotlight bundle searches (best-effort)
        $mdfinds = [
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.google.Chrome' 2>/dev/null",
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.google.Chrome.canary' 2>/dev/null",
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==org.chromium.Chromium' 2>/dev/null",
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.microsoft.edgemac' 2>/dev/null",
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.microsoft.Edgemac' 2>/dev/null",
            "/usr/bin/mdfind 'kMDItemCFBundleIdentifier==com.brave.Browser' 2>/dev/null",
        ];
        foreach ($mdfinds as $cmd) {
            $out = trim((string)@shell_exec($cmd));
            if ($out !== '') {
                foreach (preg_split('~[\r\n]+~', $out) as $appPath) {
                    $appPath = trim($appPath);
                    if ($appPath === '' || strpos($appPath, '.app') === false) continue;
                    // Derive binary path inside bundle
                    $bin = $appPath . '/Contents/MacOS/'
                        . (stripos($appPath, 'Edge') !== false ? 'Microsoft Edge'
                        : (stripos($appPath, 'Chromium') !== false ? 'Chromium'
                        : (stripos($appPath, 'Canary') !== false ? 'Google Chrome Canary'
                        : (stripos($appPath, 'Brave') !== false ? 'Brave Browser'
                        : 'Google Chrome'))));
                    $candidates[] = $bin;
                }
            }
        }
    }

    // Deduplicate
    $map = [];
    foreach ($candidates as $cand) {
        $cand = trim((string)$cand);
        if ($cand === '') continue;
        if (!isset($map[$cand])) $map[$cand] = $cand;
    }
    return array_values($map);
}

function pp_resolve_chrome_path(): ?string {
    $cands = pp_collect_chrome_candidates();
    foreach ($cands as $cand) {
        if (@is_file($cand) && @is_executable($cand)) {
            return $cand;
        }
    }
    return null;
}

function pp_guess_base_url(): string {
    if (defined('PP_BASE_URL') && PP_BASE_URL) return rtrim(PP_BASE_URL, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $dir = rtrim(str_replace(['\\','//'], '/', dirname($script)), '/');
    // Project root is one level up from current script for admin/public/auth pages
    $base = $scheme . '://' . $host . ($dir ? $dir : '');
    // If ends with /admin or /public or /auth, strip it
    $base = preg_replace('~/(admin|public|auth)$~', '', $base);
    return rtrim($base, '/');
}

function pp_google_redirect_url(): string {
    $base = pp_guess_base_url();
    return $base . '/public/google_oauth_callback.php';
}

// Helpers for page metadata
if (!function_exists('pp_url_hash')) {
    function pp_url_hash(string $url): string {
        return hash('sha256', strtolower(trim($url)));
    }
}
if (!function_exists('pp_save_page_meta')) {
    function pp_save_page_meta(int $projectId, string $pageUrl, array $data): bool {
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; }
        if (!$conn) return false;

        $urlHash = pp_url_hash($pageUrl);
        $finalUrl = (string)($data['final_url'] ?? '');
        $lang = (string)($data['lang'] ?? '');
        $region = (string)($data['region'] ?? '');
        $title = (string)($data['title'] ?? '');
        $description = (string)($data['description'] ?? '');
        $canonical = (string)($data['canonical'] ?? '');
        $published = (string)($data['published_time'] ?? '');
        $modified = (string)($data['modified_time'] ?? '');
        $hreflang = $data['hreflang'] ?? null;
        $hreflangJson = is_string($hreflang) ? $hreflang : json_encode($hreflang, JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO page_meta (project_id, url_hash, page_url, final_url, lang, region, title, description, canonical, published_time, modified_time, hreflang_json, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE final_url=VALUES(final_url), lang=VALUES(lang), region=VALUES(region), title=VALUES(title), description=VALUES(description), canonical=VALUES(canonical), published_time=VALUES(published_time), modified_time=VALUES(modified_time), hreflang_json=VALUES(hreflang_json), updated_at=CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param(
            'isssssssssss',
            $projectId,
            $urlHash,
            $pageUrl,
            $finalUrl,
            $lang,
            $region,
            $title,
            $description,
            $canonical,
            $published,
            $modified,
            $hreflangJson
        );
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        return (bool)$ok;
    }
}
if (!function_exists('pp_get_page_meta')) {
    function pp_get_page_meta(int $projectId, string $pageUrl): ?array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return null; }
        if (!$conn) return null;
        $hash = pp_url_hash($pageUrl);
        $stmt = $conn->prepare("SELECT page_url, final_url, lang, region, title, description, canonical, published_time, modified_time, hreflang_json FROM page_meta WHERE project_id = ? AND url_hash = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return null; }
        $stmt->bind_param('is', $projectId, $hash);
        $stmt->execute();
        $stmt->bind_result($page_url, $final_url, $lang, $region, $title, $description, $canonical, $published, $modified, $hreflang_json);
        $data = null;
        if ($stmt->fetch()) {
            $data = [
                'page_url' => (string)$page_url,
                'final_url' => (string)$final_url,
                'lang' => (string)$lang,
                'region' => (string)$region,
                'title' => (string)$title,
                'description' => (string)$description,
                'canonical' => (string)$canonical,
                'published_time' => (string)$published,
                'modified_time' => (string)$modified,
                'hreflang' => $hreflang_json ? (json_decode($hreflang_json, true) ?: []) : [],
            ];
        }
        $stmt->close();
        $conn->close();
        return $data;
    }
}

// -------- URL analysis utilities (microdata/meta extraction) --------
function pp_http_fetch(string $url, int $timeout = 12): array {
    $headers = [];
    $status = 0; $body = ''; $finalUrl = $url;
    $ua = 'PromoPilotBot/1.0 (+https://github.com/ksanyok/promopilot)';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ru,en;q=0.8'
            ],
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($resp, 0, $headerSize);
            $body = substr($resp, $headerSize);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            // Parse headers (multiple response headers possible on redirects; take the last block)
            $blocks = preg_split("/\r?\n\r?\n/", trim($rawHeaders));
            $last = end($blocks);
            foreach (preg_split("/\r?\n/", (string)$last) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 6,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . $ua,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: ru,en;q=0.8',
                ],
            ],
            'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $body = $resp !== false ? (string)$resp : '';
        $status = 0;
        $finalUrl = $url;
        global $http_response_header;
        if (is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
                elseif (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'final_url' => $finalUrl];
}

function pp_html_dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (stripos($html, '<meta') === false) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return null;
    return $doc;
}

function pp_xpath(DOMDocument $doc): DOMXPath { return new DOMXPath($doc); }
function pp_text(?DOMNode $n): string { return trim($n ? $n->textContent : ''); }
function pp_attr(?DOMElement $n, string $name): string { return trim($n ? (string)$n->getAttribute($name) : ''); }

function pp_abs_url(string $href, string $base): string {
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
    $scheme = $bp['scheme'];
    $host = $bp['host'];
    $port = isset($bp['port']) ? (':' . $bp['port']) : '';
    $path = $bp['path'] ?? '/';
    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $port . $href;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = array_filter(explode('/', $dir));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') { array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

function pp_analyze_url_data(string $url): ?array {
    $fetch = pp_http_fetch($url, 12);
    if (($fetch['status'] ?? 0) >= 400 || ($fetch['body'] ?? '') === '') {
        return null;
    }
    $finalUrl = $fetch['final_url'] ?: $url;
    $headers = $fetch['headers'] ?? [];
    $body = (string)$fetch['body'];
    $doc = pp_html_dom($body);
    if (!$doc) { return null; }
    $xp = pp_xpath($doc);

    $baseHref = '';
    $baseEl = $xp->query('//base[@href]')->item(0);
    if ($baseEl instanceof DOMElement) { $baseHref = pp_attr($baseEl, 'href'); }
    $base = $baseHref !== '' ? $baseHref : $finalUrl;

    $title = '';
    $titleEl = $xp->query('//title')->item(0);
    if ($titleEl) { $title = pp_text($titleEl); }
    $ogTitle = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content | //meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content')->item(0);
    if ($ogTitle && !$title) { $title = trim($ogTitle->nodeValue ?? ''); }

    $desc = '';
    $metaDesc = $xp->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
    if ($metaDesc) { $desc = trim($metaDesc->nodeValue ?? ''); }
    if ($desc === '') {
        $ogDesc = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0);
        if ($ogDesc) { $desc = trim($ogDesc->nodeValue ?? ''); }
    }

    $canonical = '';
    $canonEl = $xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href')->item(0);
    if ($canonEl) { $canonical = pp_abs_url(trim($canonEl->nodeValue ?? ''), $base); }

    $lang = '';
    $region = '';
    $htmlEl = $xp->query('//html')->item(0);
    if ($htmlEl instanceof DOMElement) {
        $langAttr = trim($htmlEl->getAttribute('lang'));
        if ($langAttr) {
            $parts = preg_split('~[-_]~', $langAttr);
            $lang = strtolower($parts[0] ?? '');
            if (isset($parts[1])) { $region = strtoupper($parts[1]); }
        }
    }

    $hreflangs = [];
    foreach ($xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]') as $lnk) {
        if (!($lnk instanceof DOMElement)) continue;
        $hl = trim($lnk->getAttribute('hreflang'));
        $href = pp_abs_url(trim($lnk->getAttribute('href')), $base);
        if ($hl && $href) { $hreflangs[] = ['hreflang' => $hl, 'href' => $href]; }
    }
    if (!$lang && !empty($hreflangs)) {
        $hl0 = $hreflangs[0]['hreflang'];
        $parts = preg_split('~[-_]~', $hl0);
        $lang = strtolower($parts[0] ?? '');
        if (isset($parts[1])) { $region = strtoupper($parts[1]); }
    }

    if (!$lang) {
        $contentLang = $xp->query('//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-language"]/@content')->item(0);
        if ($contentLang) {
            $v = trim($contentLang->nodeValue ?? '');
            if ($v) {
                $parts = preg_split('~[,;\s]+~', $v);
                $p0 = $parts[0] ?? '';
                $pp = preg_split('~[-_]~', $p0);
                $lang = strtolower($pp[0] ?? '');
                if (isset($pp[1])) { $region = strtoupper($pp[1]); }
            }
        }
    }

    $published = '';
    $modified = '';
    $q = function(string $xpath) use ($xp): ?string { $n = $xp->query($xpath)->item(0); return $n ? trim($n->nodeValue ?? '') : null; };
    $published = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="datePublished"]/@content') ?: $q('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="pubdate"]/@content');
    $modified = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:modified_time"]/@content') ?: $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:updated_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dateModified"]/@content');

    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $json = trim($script->textContent ?? '');
        if ($json === '') continue;
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json2 = preg_replace('/,\s*([}\]])/', '$1', $json);
            $data = json_decode($json2, true);
        }
        if (is_array($data)) {
            $stack = [$data];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($cur['datePublished']) && !$published) { $published = (string)$cur['datePublished']; }
                if (isset($cur['dateModified']) && !$modified) { $modified = (string)$cur['dateModified']; }
                foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; }
            }
        }
        if ($published && $modified) break;
    }

    if (!$modified && !empty($headers['last-modified'])) { $modified = $headers['last-modified']; }

    return [
        'final_url' => $finalUrl,
        'lang' => $lang,
        'region' => $region,
        'title' => $title,
        'description' => $desc,
        'canonical' => $canonical,
        'published_time' => $published,
        'modified_time' => $modified,
        'hreflang' => $hreflangs,
    ];
}

?>
