<?php
// Database, settings and money helpers

if (!function_exists('connect_db')) {
    function connect_db() {
        $candidatePaths = [];
        $envConfig = trim((string)getenv('PP_CONFIG_PATH'));
        if ($envConfig !== '') {
            if ($envConfig[0] !== DIRECTORY_SEPARATOR) {
                $envConfig = PP_ROOT_PATH . '/' . ltrim($envConfig, '/');
            }
            $candidatePaths[] = $envConfig;
        }
        $candidatePaths[] = PP_ROOT_PATH . '/config/config.php';
        $candidatePaths[] = PP_ROOT_PATH . '/config/config.local.php';
        $candidatePaths[] = PP_ROOT_PATH . '/config/config.dist.php';

        $configLoaded = false;
        foreach ($candidatePaths as $path) {
            if ($path && file_exists($path)) {
                include $path;
                $configLoaded = true;
                break;
            }
        }

        if (!$configLoaded) {
            $db_host = getenv('PP_DB_HOST') ?: null;
            $db_user = getenv('PP_DB_USER') ?: null;
            $db_pass = getenv('PP_DB_PASS') ?: null;
            $db_name = getenv('PP_DB_NAME') ?: null;
        }

        if (!isset($db_host, $db_user, $db_pass, $db_name) || $db_host === null || $db_user === null || $db_name === null) {
            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('Database configuration is missing. Provide config/config.php or PP_DB_* environment variables.');
            }
            $installer = (defined('PP_BASE_URL') ? pp_url('installer.php') : '/installer.php');
            if (!headers_sent()) { header('Location: ' . $installer, true, 302); }
            exit('Config file not found. Please run the installer: <a href="' . htmlspecialchars($installer) . '">installer</a> or configure PP_DB_* environment variables.');
        }

        if (!class_exists('mysqli')) { exit('PHP mysqli extension is not available. Please enable it to continue.'); }
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) { exit('Ошибка подключения к БД: ' . $conn->connect_error); }
        if (method_exists($conn, 'set_charset')) { @$conn->set_charset('utf8mb4'); }
        return $conn;
    }
}

if (!function_exists('pp_mysql_index_exists')) {
    function pp_mysql_index_exists(mysqli $conn, string $table, string $index): bool {
        $table = preg_replace('~[^a-zA-Z0-9_]+~', '', $table);
        $index = preg_replace('~[^a-zA-Z0-9_]+~', '', $index);
        $sql = "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'";
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) { $exists = $res->num_rows > 0; $res->close(); return $exists; }
        return false;
    }
}

// Settings helpers (cached)
if (!function_exists('get_setting')) {
    function get_setting(string $key, $default = null) {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $conn = @connect_db();
                if ($conn) {
                    $res = @$conn->query('SELECT k, v FROM settings');
                    if ($res) { while ($row = $res->fetch_assoc()) { $cache[$row['k']] = $row['v']; } }
                    $conn->close();
                }
            } catch (Throwable $e) { /* ignore */ }
            $GLOBALS['pp_settings_cache'] = &$cache;
        }
        return $cache[$key] ?? $default;
    }
}
if (!function_exists('set_setting')) {
    function set_setting(string $key, $value): bool { return set_settings([$key => $value]); }
}
if (!function_exists('set_settings')) {
    function set_settings(array $pairs): bool {
        if (empty($pairs)) { return true; }
        try { $conn = @connect_db(); } catch (Throwable $e) { return false; }
        if (!$conn) { return false; }
        $stmt = $conn->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP');
        if (!$stmt) { $conn->close(); return false; }
        foreach ($pairs as $k => $v) {
            $ks = (string)$k; $vs = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('ss', $ks, $vs); $stmt->execute();
        }
        $stmt->close(); $conn->close();
        if (isset($GLOBALS['pp_settings_cache']) && is_array($GLOBALS['pp_settings_cache'])) {
            foreach ($pairs as $k => $v) { $GLOBALS['pp_settings_cache'][(string)$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE); }
        }
        return true;
    }
}

// User helpers
if (!function_exists('get_user_balance')) {
    function get_user_balance(int $userId): ?float {
        $conn = @connect_db(); if (!$conn) return null;
        $stmt = $conn->prepare('SELECT balance FROM users WHERE id = ?'); if (!$stmt) { $conn->close(); return null; }
        $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->bind_result($balance);
        if ($stmt->fetch()) { $stmt->close(); $conn->close(); return (float)$balance; }
        $stmt->close(); $conn->close(); return null;
    }
}
if (!function_exists('get_current_user_balance')) {
    function get_current_user_balance(): ?float { if (!is_logged_in()) return null; return get_user_balance((int)($_SESSION['user_id'] ?? 0)); }
}

// Currency/money
if (!function_exists('get_currency_code')) {
    function get_currency_code(): string {
        $cur = strtoupper((string)get_setting('currency', 'RUB'));
        $allowed = ['RUB','USD','EUR','GBP','UAH'];
        if (!in_array($cur, $allowed, true)) { $cur = 'RUB'; }
        return $cur;
    }
}
if (!function_exists('format_currency')) {
    function format_currency($amount): string {
        $code = get_currency_code();
        $num = is_numeric($amount) ? number_format((float)$amount, 2, '.', ' ') : (string)$amount;
        return $num . ' ' . $code;
    }
}

// Avatar helper
if (!function_exists('pp_save_remote_avatar')) {
    function pp_save_remote_avatar(string $url, int $userId): ?string {
        $url = trim($url); if ($url === '') return null;
        $pu = @parse_url($url); if (!$pu || !in_array(strtolower($pu['scheme'] ?? ''), ['http','https'], true)) return null;
        $dir = PP_ROOT_PATH . '/uploads/avatars'; if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (!is_dir($dir) || !is_writable($dir)) return null;

        $data = null; $ctype = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'PromoPilot/1.0',
                CURLOPT_MAXREDIRS => 5, CURLOPT_HEADER => true,
            ]);
            $resp = curl_exec($ch);
            if ($resp !== false) {
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($resp, 0, $headerSize); $data = substr($resp, $headerSize);
                if (preg_match('~^Content-Type:\s*([^\r\n]+)~im', (string)$headers, $m)) { $ctype = trim($m[1]); }
            }
            curl_close($ch);
        }
        if ($data === null) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: PromoPilot/1.0\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $data = @file_get_contents($url, false, $ctx);
        }
        if (!$data || strlen($data) < 128) return null; if (strlen($data) > 5 * 1024 * 1024) return null;

        if (function_exists('finfo_buffer')) { $f = new finfo(FILEINFO_MIME_TYPE); $det = $f->buffer($data) ?: ''; if ($det) { $ctype = $det; } }
        $ext = 'jpg'; if (stripos($ctype, 'png') !== false) $ext = 'png'; elseif (stripos($ctype, 'webp') !== false) $ext = 'webp'; elseif (stripos($ctype, 'jpeg') !== false) $ext = 'jpg';
        $file = $dir . '/u' . $userId . '.' . $ext; $ok = @file_put_contents($file, $data) !== false; if (!$ok) return null; @chmod($file, 0664);
        return 'uploads/avatars/' . basename($file);
    }
}

?>
