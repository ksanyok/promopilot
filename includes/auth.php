<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function env_load_array($path) {
    $env = [];
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"') {
                $v = stripcslashes(substr($v, 1, -1));
            }
            $env[$k] = $v;
        }
    }
    return $env;
}

function admin_is_logged_in(): bool {
    return !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function admin_login(string $login, string $password): bool {
    $env = env_load_array(__DIR__ . '/../.env');
    $adminLogin = $env['ADMIN_LOGIN'] ?? '';
    $adminHash  = $env['ADMIN_PASSWORD_HASH'] ?? '';
    if ($adminLogin === '' || $adminHash === '') {
        return false; // не настроено
    }
    if (hash_equals($adminLogin, $login) && password_verify($password, $adminHash)) {
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_login'] = $login;
        return true;
    }
    return false;
}

function admin_logout(): void {
    unset($_SESSION['is_admin'], $_SESSION['admin_login']);
}

function admin_require_auth(): void {
    if (!admin_is_logged_in()) {
        header('Location: /admin-login.php');
        exit;
    }
}
