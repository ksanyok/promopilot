<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

$response = function(array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!is_logged_in()) {
    $response(['ok' => false, 'error' => 'FORBIDDEN']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $response(['ok' => false, 'error' => 'BAD_REQUEST']);
}

$projectId = (int)($_POST['project_id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));
$shouldSave = isset($_POST['save']) && (string)$_POST['save'] !== '0' && (string)$_POST['save'] !== '';
if (!$projectId || !$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    $response(['ok' => false, 'error' => 'INVALID_INPUT']);
}

// Fetch project and check permissions
$conn = connect_db();
$stmt = $conn->prepare('SELECT id, user_id, domain_host FROM projects WHERE id = ?');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$project) { $conn->close(); $response(['ok' => false, 'error' => 'PROJECT_NOT_FOUND']); }
if (!is_admin() && (int)$project['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
    $conn->close();
    $response(['ok' => false, 'error' => 'FORBIDDEN']);
}

// Enforce same-domain restriction
$normHost = function($h) { $h = strtolower((string)$h); return (strpos($h, 'www.') === 0) ? substr($h, 4) : $h; };
$projectHost = $normHost($project['domain_host'] ?? '');
$targetHost = $normHost(parse_url($url, PHP_URL_HOST) ?: '');
if ($projectHost && $targetHost && $projectHost !== $targetHost) {
    $conn->close();
    $response(['ok' => false, 'error' => 'DOMAIN_MISMATCH']);
}

$conn->close();

try {
    if (!function_exists('pp_analyze_url_data')) {
        $response(['ok' => false, 'error' => 'ANALYZER_MISSING']);
    }
    $data = pp_analyze_url_data($url);
    if (!$data) {
        $response(['ok' => false, 'error' => 'FETCH_OR_PARSE_FAILED']);
    }

    if ($shouldSave && function_exists('pp_save_page_meta')) {
        @pp_save_page_meta($projectId, $url, $data);
    }

    $response(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    $response(['ok' => false, 'error' => 'EXCEPTION', 'details' => $e->getMessage()]);
}
