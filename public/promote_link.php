<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'FATAL', 'details' => $e['message']]);
    }
});

require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

$projectId = (int)($_POST['project_id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));
if ($projectId <= 0 || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok' => false, 'error' => 'BAD_INPUT']);
    exit;
}

try {
    $conn = connect_db();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}
if (!$conn) {
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}

$stmt = $conn->prepare('SELECT user_id FROM projects WHERE id = ? LIMIT 1');
if (!$stmt) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}
$stmt->bind_param('i', $projectId);
$stmt->execute();
$res = $stmt->get_result();
$projectRow = $res->fetch_assoc();
$stmt->close();
if (!$projectRow) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']);
    exit;
}
if (!is_admin() && (int)$projectRow['user_id'] !== (int)$_SESSION['user_id']) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN']);
    exit;
}

// Check link belongs to project
$urlBelongs = false;
if ($chk = $conn->prepare('SELECT id FROM project_links WHERE project_id = ? AND url = ? LIMIT 1')) {
    $chk->bind_param('is', $projectId, $url);
    if ($chk->execute()) {
        $urlBelongs = $chk->get_result()->num_rows > 0;
    }
    $chk->close();
}
$conn->close();
if (!$urlBelongs) {
    echo json_encode(['ok' => false, 'error' => 'URL_NOT_IN_PROJECT']);
    exit;
}

if (function_exists('session_write_close')) { @session_write_close(); }

$result = pp_promotion_start_run($projectId, $url, (int)$_SESSION['user_id']);
if (empty($result['ok'])) {
    $map = [
        'LEVEL1_DISABLED' => 'LEVEL1_DISABLED',
        'URL_NOT_FOUND' => 'URL_NOT_IN_PROJECT',
        'DB' => 'DB',
        'INSUFFICIENT_FUNDS' => 'INSUFFICIENT_FUNDS',
        'USER_NOT_FOUND' => 'DB',
    ];
    $code = $result['error'] ?? 'UNKNOWN';
    $response = ['ok' => false, 'error' => $map[$code] ?? $code];
    foreach (['shortfall', 'required', 'balance', 'discount_percent'] as $key) {
        if (array_key_exists($key, $result)) {
            $response[$key] = is_numeric($result[$key]) ? (float)$result[$key] : $result[$key];
        }
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$status = pp_promotion_get_status($projectId, $url);
$response = [
    'ok' => true,
    'run_id' => (int)($result['run_id'] ?? ($status['run_id'] ?? 0)),
    'status' => $result['status'] ?? ($status['status'] ?? 'queued'),
    'stage' => $status['stage'] ?? 'pending_level1',
    'progress' => $status['progress'] ?? ['done' => 0, 'total' => 0],
    'promotion' => $status,
];

if (array_key_exists('charged', $result)) {
    $response['charged'] = (float)$result['charged'];
}
if (array_key_exists('discount', $result)) {
    $response['discount'] = (float)$result['discount'];
}
if (array_key_exists('balance_after', $result)) {
    $response['balance_after'] = (float)$result['balance_after'];
}
if (!empty($result['balance_after_formatted'])) {
    $response['balance_after_formatted'] = (string)$result['balance_after_formatted'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
