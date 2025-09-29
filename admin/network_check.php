<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'start') {
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
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $slug = isset($_POST['slug']) ? (string)$_POST['slug'] : '';
    $requestedMode = strtolower(trim((string)($_POST['mode'] ?? '')));
    $selectionRaw = isset($_POST['slugs']) ? (array)$_POST['slugs'] : [];
    $selectionMap = [];
    foreach ($selectionRaw as $sel) {
        $normalized = pp_normalize_slug((string)$sel);
        if ($normalized !== '') {
            $selectionMap[$normalized] = true;
        }
    }
    $selection = array_keys($selectionMap);
    $mode = 'bulk';
    if ($requestedMode === 'single') {
        $mode = 'single';
    } elseif ($requestedMode === 'selection') {
        $mode = 'selection';
    } elseif ($slug !== '') {
        $mode = 'single';
    }
    if ($mode === 'single' && $slug === '') {
        $mode = 'bulk';
    }
    if ($mode === 'selection' && empty($selection)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'MISSING_SLUG'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = pp_network_check_start($userId ?: null, $mode, $slug, $selection);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel') {
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
    $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : null;
    $force = !empty($_POST['force']);
    $result = pp_network_check_cancel($runId ?: null, $force);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'status') {
    $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : null;
    $result = pp_network_check_get_status($runId);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION'], JSON_UNESCAPED_UNICODE);
exit;
