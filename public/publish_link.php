<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'UNAUTHORIZED']);
    exit;
}

if (!verify_csrf()) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF']);
    exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
$url = trim($_POST['url'] ?? '');
$action = trim($_POST['action'] ?? '');

if (!$project_id || !$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok'=>false,'error'=>'BAD_INPUT']);
    exit;
}

$conn = connect_db();
// Проверка прав
$stmt = $conn->prepare("SELECT user_id, links FROM projects WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $project_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo json_encode(['ok'=>false,'error'=>'PROJECT_NOT_FOUND']);
    $stmt->close(); $conn->close(); exit;
}
$proj = $res->fetch_assoc();
$stmt->close();
if (!is_admin() && (int)$proj['user_id'] !== (int)$_SESSION['user_id']) {
    echo json_encode(['ok'=>false,'error'=>'FORBIDDEN']);
    $conn->close(); exit;
}

// Ищем анкор, язык, пожелание из структуры links
$anchor='';
$links = json_decode($proj['links'] ?? '[]', true) ?: [];
if (is_array($links)) {
    foreach ($links as $lnk) {
        if (is_array($lnk) && isset($lnk['url']) && trim($lnk['url']) === $url) {
            $anchor = trim($lnk['anchor'] ?? '');
            break;
        }
        if (is_string($lnk) && $lnk === $url) { $anchor=''; break; }
    }
}

if ($action === 'publish') {
    // Уже есть?
    $stmt = $conn->prepare("SELECT id, post_url FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        if (!empty($row['post_url'])) {
            echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
            $conn->close(); exit;
        }
        // уже pending
        echo json_encode(['ok'=>true,'status'=>'pending']);
        $conn->close(); exit;
    }
    $stmt = $conn->prepare("INSERT INTO publications (project_id, page_url, anchor) VALUES (?,?,?)");
    $stmt->bind_param('iss', $project_id, $url, $anchor);
    if ($stmt->execute()) {
        echo json_encode(['ok'=>true,'status'=>'pending']);
    } else {
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
    }
    $stmt->close();
    $conn->close();
    exit;
} elseif ($action === 'cancel') {
    // Можно отменить только если не опубликована (нет post_url)
    $stmt = $conn->prepare("SELECT id, post_url FROM publications WHERE project_id = ? AND page_url = ? LIMIT 1");
    $stmt->bind_param('is', $project_id, $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'NOT_PENDING']);
        $conn->close(); exit;
    }
    if (!empty($row['post_url'])) {
        echo json_encode(['ok'=>false,'error'=>'ALREADY_PUBLISHED']);
        $conn->close(); exit;
    }
    $stmt = $conn->prepare("DELETE FROM publications WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $row['id']);
    if ($stmt->execute()) {
        echo json_encode(['ok'=>true,'status'=>'not_published']);
    } else {
        echo json_encode(['ok'=>false,'error'=>'DB_ERROR']);
    }
    $stmt->close();
    $conn->close();
    exit;
} else {
    echo json_encode(['ok'=>false,'error'=>'BAD_ACTION']);
    $conn->close();
    exit;
}
