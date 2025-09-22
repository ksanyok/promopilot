<?php
// Centralized bootstrap for PromoPilot
// Starts session, loads functions, and defines URL helpers

// Scheme and host for cookie security (учёт обратного прокси)
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($forwardedProto === 'https');

if (session_status() === PHP_SESSION_NONE) {
    // Unique session name to avoid collisions
    @ini_set('session.name', 'PPSESSID');

    // Всегда используем локальную директорию для сессий, чтобы избежать проблем с правами
    $rootAttempt = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
    $primarySess = $rootAttempt . '/config/sessions';
    $sessPath = '';

    // try project dir
    if (!is_dir($primarySess)) { @mkdir($primarySess, 0777, true); }
    if (!is_writable($primarySess)) { @chmod($primarySess, 0777); }
    if (is_dir($primarySess) && is_writable($primarySess)) {
        $sessPath = $primarySess;
    }

    // fallback to system temp
    if ($sessPath === '') {
        $tmpSess = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pp_sessions';
        if (!is_dir($tmpSess)) { @mkdir($tmpSess, 0777, true); }
        if (!is_writable($tmpSess)) { @chmod($tmpSess, 0777); }
        if (is_dir($tmpSess) && is_writable($tmpSess)) {
            $sessPath = $tmpSess;
        }
    }

    if ($sessPath !== '') {
        ini_set('session.save_path', $sessPath);
    }

    // Базовые безопасные флаги
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_path', '/');
    // Important: do not force Secure flag to avoid cookie drop on HTTP during setup/local dev
    ini_set('session.cookie_secure', '0');

    // Запускаем сессию с настройками по умолчанию PHP
    session_start();
}

// Запрет кэширования динамических страниц, чтобы не кэшировались CSRF токены
if (!headers_sent() && (PHP_SAPI !== 'cli')) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Absolute filesystem paths
define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));

// Compute base path (URL path) relative to web server document root
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$rootFs   = str_replace('\\', '/', PP_ROOT_PATH);
$basePath = '/' . ltrim(trim(str_replace($docRoot, '', $rootFs), '/'), '/');

// Robust fallback when DOCUMENT_ROOT mapping fails (e.g., docroot is /public)
$needFallback = ($basePath === '' || $basePath === '/' || strpos($basePath, '..') !== false);
if ($needFallback) {
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $scriptDirUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $scriptFs = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rootFs   = str_replace('\\', '/', PP_ROOT_PATH);
    $depth = 0;
    if ($scriptFs && strpos($scriptFs, $rootFs) === 0) {
        $rel = trim(substr($scriptFs, strlen($rootFs)), '/');
        $depth = $rel === '' ? 0 : substr_count($rel, '/') + 1;
    }
    $bp = $scriptDirUrl === '' ? '/' : $scriptDirUrl;
    for ($i = 0; $i < $depth; $i++) {
        $bp = rtrim(dirname($bp), '/');
        if ($bp === '') { $bp = '/'; break; }
    }
    if ($bp === '') { $bp = '/'; }
    $basePath = $bp;
}

if ($basePath === '//') { $basePath = '/'; }

// Public base PATH (URL path, no scheme/host)
if (!defined('PP_BASE_PATH')) {
    $ppBasePath = rtrim($basePath, '/');
    if ($ppBasePath === '') { $ppBasePath = '/'; }
    define('PP_BASE_PATH', $ppBasePath);
}

// Scheme and host
$scheme = $https ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Public base URL (project root URL)
if (!defined('PP_BASE_URL')) {
    // Ensure no trailing slash
    $bp = PP_BASE_PATH === '/' ? '' : PP_BASE_PATH;
    define('PP_BASE_URL', rtrim($scheme . '://' . $host . $bp, '/'));
}

// Convenience helpers
if (!function_exists('pp_url')) {
    function pp_url(string $path = ''): string {
        $path = '/' . ltrim($path, '/');
        return PP_BASE_URL . $path;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path = ''): string {
        return pp_url('assets/' . ltrim($path, '/'));
    }
}

// Load core functions (with robust relative includes)
require_once __DIR__ . '/functions.php';
