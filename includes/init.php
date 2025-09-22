<?php
// Centralized bootstrap for PromoPilot
// Starts session, loads functions, and defines URL helpers

// Scheme and host for cookie security (учёт обратного прокси)
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($forwardedProto === 'https');

if (session_status() === PHP_SESSION_NONE) {
    // Резервный каталог для хранения сессий, если системный недоступен
    $rootAttempt = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
    $fallbackSess = $rootAttempt . '/config/sessions';
    $savePath = ini_get('session.save_path');
    $needFallback = true;
    if ($savePath) {
        $parts = explode(';', $savePath);
        $pathCandidate = end($parts);
        if (is_dir($pathCandidate) && is_writable($pathCandidate)) {
            $needFallback = false;
        }
    }
    if ($needFallback) {
        if (!is_dir($fallbackSess)) @mkdir($fallbackSess, 0700, true);
        if (is_dir($fallbackSess) && is_writable($fallbackSess)) {
            ini_set('session.save_path', $fallbackSess);
        }
    }

    // Базовые безопасные флаги
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '0'); // не требуем HTTPS, совместимо с прокси

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
if ($basePath === '//') { $basePath = '/'; }

// Scheme and host
$scheme = $https ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Public base URL (project root URL)
if (!defined('PP_BASE_URL')) {
    define('PP_BASE_URL', rtrim($scheme . '://' . $host . rtrim($basePath, '/'), '/'));
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
