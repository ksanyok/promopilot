<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

$response = static function(array $data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!is_logged_in()) {
    $response(['ok' => false, 'error' => 'FORBIDDEN'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

if (!verify_csrf()) {
    $response(['ok' => false, 'error' => 'CSRF_FAILED'], 400);
}

$url = trim((string)($_POST['url'] ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    $response(['ok' => false, 'error' => 'INVALID_URL'], 400);
}

$analysis = pp_project_brief_analyze_site($url);
if (!$analysis) {
    $response(['ok' => false, 'error' => 'ANALYSIS_FAILED'], 502);
}

$result = pp_project_brief_prepare_initial($url);

$domainHost = pp_project_brief_extract_domain($url);
$meta = $result['meta'] ?? [];

if (is_array($meta) && function_exists('pp_save_page_meta')) {
    try {
        // project_id unknown yet; use 0 meaning "global" cache
        @pp_save_page_meta(0, $url, $meta + ['domain_host' => $domainHost]);
    } catch (Throwable $e) { /* ignore */ }
}

$response([
    'ok' => true,
    'data' => [
        'name' => $result['name'],
        'description' => $result['description'],
        'language' => $result['language'],
        'domain_host' => $domainHost,
        'meta' => $meta,
        'ai' => $result['ai'],
    ],
]);
