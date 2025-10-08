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
$linkId = (int)($_POST['link_id'] ?? 0);
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
$conn->close();
if (!$projectRow) {
    echo json_encode(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']);
    exit;
}
if (!is_admin() && (int)$projectRow['user_id'] !== (int)$_SESSION['user_id']) {
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN']);
    exit;
}

$result = pp_promotion_cancel_run($projectId, $url, (int)$_SESSION['user_id'], $linkId);
if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'UNKNOWN']);
    exit;
}

$status = pp_promotion_get_status($projectId, $url, $linkId > 0 ? $linkId : null);
$response = [
    'ok' => true,
    'status' => $result['status'] ?? 'cancelled',
    'promotion' => $status,
    'link_id' => $linkId,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
