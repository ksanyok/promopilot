<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'UNAUTHORIZED']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED']); exit;
}

$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$url = trim((string)($_GET['url'] ?? $_POST['url'] ?? ''));
if ($project_id <= 0 || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok'=>false,'error'=>'BAD_INPUT']); exit;
}

// Access check: owner or admin
$conn = connect_db();
$stmt = $conn->prepare('SELECT user_id FROM projects WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $project_id);
$stmt->execute();
$stmt->bind_result($ownerId);
if (!$stmt->fetch()) { $stmt->close(); $conn->close(); echo json_encode(['ok'=>false,'error'=>'PROJECT_NOT_FOUND']); exit; }
$stmt->close();
if (!is_admin() && (int)$ownerId !== (int)($_SESSION['user_id'] ?? 0)) { $conn->close(); echo json_encode(['ok'=>false,'error'=>'FORBIDDEN']); exit; }

// Read publication status
$st = $conn->prepare('SELECT id, post_url, status, network, error FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1');
$st->bind_param('is', $project_id, $url);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
$conn->close();

if (!$row) { echo json_encode(['ok'=>true,'status'=>'not_published']); exit; }

$status = (string)($row['status'] ?? '');
$postUrl = (string)($row['post_url'] ?? '');
$network = (string)($row['network'] ?? '');
$error = (string)($row['error'] ?? '');

if ($status === 'partial') {
    echo json_encode(['ok'=>true,'status'=>'manual_review','post_url'=>$postUrl,'network'=>$network]); exit;
}
if ($postUrl !== '' || $status === 'success') {
    echo json_encode(['ok'=>true,'status'=>'published','post_url'=>$postUrl,'network'=>$network]); exit;
}
if ($status === '' && $postUrl === '') { $status = 'queued'; }
if ($status === 'failed') { echo json_encode(['ok'=>true,'status'=>'failed','error'=>$error]); exit; }
if ($status === 'cancelled') { echo json_encode(['ok'=>true,'status'=>'not_published']); exit; }

// default queued/running
echo json_encode(['ok'=>true,'status'=>'pending','network'=>$network]);
