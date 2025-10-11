<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'AUTH_REQUIRED']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'AUTH_REQUIRED']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    if ($limit <= 0) { $limit = 10; }
    if ($limit > 50) { $limit = 50; }

    $items = function_exists('pp_notification_fetch_recent') ? pp_notification_fetch_recent($userId, $limit, true) : [];
    $unread = function_exists('pp_notification_count_unread') ? pp_notification_count_unread($userId) : 0;

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'unread_count' => (int)$unread,
        'server_time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'CSRF_FAILED']);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'mark_read') {
        $idsRaw = trim((string)($_POST['ids'] ?? ''));
        $idParts = preg_split('~[\s,]+~', $idsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        foreach ($idParts as $part) {
            $value = (int)$part;
            if ($value > 0) {
                $ids[] = $value;
            }
        }
        if (!empty($ids) && function_exists('pp_notification_mark_read')) {
            pp_notification_mark_read($userId, $ids);
        }
        $unread = function_exists('pp_notification_count_unread') ? pp_notification_count_unread($userId) : 0;
        echo json_encode([
            'ok' => true,
            'unread_count' => (int)$unread,
        ]);
        exit;
    }

    if ($action === 'mark_all') {
        if (function_exists('pp_notification_mark_all_read')) {
            pp_notification_mark_all_read($userId);
        }
        $unread = function_exists('pp_notification_count_unread') ? pp_notification_count_unread($userId) : 0;
        echo json_encode([
            'ok' => true,
            'unread_count' => (int)$unread,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
