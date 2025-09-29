<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slugRaw = (string)($_POST['slug'] ?? '');
$slug = pp_normalize_slug($slugRaw);
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_SLUG'], JSON_UNESCAPED_UNICODE);
    exit;
}

$network = pp_get_network($slug);
if (!$network) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'NETWORK_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

$noteRaw = (string)($_POST['note'] ?? '');
$note = trim($noteRaw);
if ($note !== '') {
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 2000, 'UTF-8');
    } else {
        $note = substr($note, 0, 2000);
    }
}

$ok = pp_set_network_note($slug, $note);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SAVE_FAILED'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'slug' => $slug,
    'note' => $note,
], JSON_UNESCAPED_UNICODE);
exit;
