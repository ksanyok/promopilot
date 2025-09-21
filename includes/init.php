<?php
// Centralized bootstrap for PromoPilot
// Starts session, loads functions, and defines URL helpers

// Scheme and host for cookie security
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie params
    if (function_exists('session_set_cookie_params')) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
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
