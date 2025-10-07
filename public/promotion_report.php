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

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

$runId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
if ($runId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'BAD_INPUT']);
    exit;
}

$report = pp_promotion_get_report($runId);
if (empty($report['ok'])) {
    echo json_encode(['ok' => false, 'error' => $report['error'] ?? 'UNKNOWN']);
    exit;
}

$projectId = (int)($report['project_id'] ?? 0);
if ($projectId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']);
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
if ($stmt) {
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ownerId = $row ? (int)$row['user_id'] : null;
} else {
    $ownerId = null;
}
$conn->close();

if ($ownerId === null) {
    echo json_encode(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']);
    exit;
}
if (!is_admin() && $ownerId !== (int)$_SESSION['user_id']) {
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN']);
    exit;
}

$response = [
    'ok' => true,
    'project_id' => $projectId,
    'target_url' => $report['target_url'] ?? '',
    'status' => $report['status'] ?? 'completed',
    'report' => $report['report'] ?? [],
    'levels_enabled' => $report['levels_enabled'] ?? null,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
