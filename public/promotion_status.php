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

$method = $_SERVER['REQUEST_METHOD'];
$projectId = (int)(($method === 'POST' ? ($_POST['project_id'] ?? 0) : ($_GET['project_id'] ?? 0)) ?: 0);
$url = trim((string)($method === 'POST' ? ($_POST['url'] ?? '') : ($_GET['url'] ?? '')));
$runId = (int)(($method === 'POST' ? ($_POST['run_id'] ?? 0) : ($_GET['run_id'] ?? 0)) ?: 0);
$linkId = (int)(($method === 'POST' ? ($_POST['link_id'] ?? 0) : ($_GET['link_id'] ?? 0)) ?: 0);

if ($projectId <= 0 && $runId <= 0) {
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

if ($runId > 0) {
    $stmt = $conn->prepare('SELECT id, project_id, link_id, target_url FROM promotion_runs WHERE id = ? LIMIT 1');
    if (!$stmt) {
        $conn->close();
        echo json_encode(['ok' => false, 'error' => 'DB']);
        exit;
    }
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $conn->close();
        echo json_encode(['ok' => false, 'error' => 'NOT_FOUND']);
        exit;
    }
    $projectId = (int)$row['project_id'];
    if ($linkId <= 0 && isset($row['link_id'])) {
        $linkId = (int)$row['link_id'];
    }
    if ($url === '') {
        $url = (string)$row['target_url'];
    }
}

$ownerId = null;
$stmt = $conn->prepare('SELECT user_id FROM projects WHERE id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $ownerRow = $stmt->get_result()->fetch_assoc();
    if ($ownerRow) {
        $ownerId = (int)$ownerRow['user_id'];
    }
    $stmt->close();
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

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok' => false, 'error' => 'BAD_INPUT']);
    exit;
}

$status = pp_promotion_get_status($projectId, $url, $linkId > 0 ? $linkId : null);
if (empty($status['ok'])) {
    echo json_encode(['ok' => false, 'error' => $status['error'] ?? 'UNKNOWN']);
    exit;
}

$status['ok'] = true;
$status['project_id'] = $projectId;
$status['link_url'] = $url;
if ($linkId > 0) {
    $status['link_id'] = $linkId;
}

echo json_encode($status, JSON_UNESCAPED_UNICODE);
exit;
