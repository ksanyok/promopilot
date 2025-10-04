<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!is_logged_in()) {
    $respond(['ok' => false, 'error' => 'FORBIDDEN']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $respond(['ok' => false, 'error' => 'BAD_REQUEST']);
}

$projectId = (int)($_POST['project_id'] ?? 0);
$force = isset($_POST['force']) && (string)$_POST['force'] !== '' && (string)$_POST['force'] !== '0';

if ($projectId <= 0) {
    $respond(['ok' => false, 'error' => 'INVALID_PROJECT']);
}

$conn = connect_db();
if (!$conn) {
    $respond(['ok' => false, 'error' => 'DB_CONNECTION_FAILED']);
}

$stmt = $conn->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    $conn->close();
    $respond(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']);
}

if (!is_admin() && (int)$project['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
    $conn->close();
    $respond(['ok' => false, 'error' => 'FORBIDDEN']);
}
$conn->close();

$primaryUrl = pp_project_primary_url($project, $project['primary_url'] ?? null);
if (!$primaryUrl) {
    $respond(['ok' => false, 'error' => 'TARGET_URL_MISSING']);
}

try {
    $capture = pp_capture_project_preview($project, ['force' => $force, 'fallback_url' => $primaryUrl]);
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'CAPTURE_EXCEPTION', 'details' => $e->getMessage()]);
}

if (empty($capture['ok'])) {
    $response = ['ok' => false, 'error' => $capture['error'] ?? 'CAPTURE_FAILED'];
    if (!empty($capture['stderr'])) { $response['stderr'] = $capture['stderr']; }
    if (!empty($capture['details'])) { $response['details'] = $capture['details']; }
    if (!empty($capture['result']) && is_array($capture['result'])) { $response['result'] = $capture['result']; }
    $respond($response);
}

$modifiedAt = (int)($capture['modified_at'] ?? time());
$descriptor = pp_project_preview_descriptor($project);
$previewUrl = $capture['url'] ?? ($descriptor['exists'] ? pp_project_preview_url($project, $primaryUrl, ['cache_bust' => true]) : null);

$respond([
    'ok' => true,
    'preview_url' => $previewUrl,
    'modified_at' => $modifiedAt,
    'modified_human' => $modifiedAt ? date('d.m.Y H:i', $modifiedAt) : null,
]);
