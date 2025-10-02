<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'export') {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAIL'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$conn) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAIL'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $filename = 'crowd-links-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
    $out = fopen('php://output', 'w');
    if ($out === false) {
        $conn->close();
        http_response_code(500);
        exit;
    }
    $headerRow = ['url','status','status_code','language','region','last_checked_at','created_at','updated_at','domain','error'];
    fputcsv($out, $headerRow);
    $sql = "SELECT url, status, status_code, language, region, last_checked_at, created_at, updated_at, domain, error FROM crowd_links ORDER BY id ASC";
    if ($res = @$conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $lastChecked = '';
            if (!empty($row['last_checked_at']) && $row['last_checked_at'] !== '0000-00-00 00:00:00') {
                $ts = strtotime((string)$row['last_checked_at']);
                $lastChecked = $ts ? date('c', $ts) : (string)$row['last_checked_at'];
            }
            $createdAt = '';
            if (!empty($row['created_at']) && $row['created_at'] !== '0000-00-00 00:00:00') {
                $ts = strtotime((string)$row['created_at']);
                $createdAt = $ts ? date('c', $ts) : (string)$row['created_at'];
            }
            $updatedAt = '';
            if (!empty($row['updated_at']) && $row['updated_at'] !== '0000-00-00 00:00:00') {
                $ts = strtotime((string)$row['updated_at']);
                $updatedAt = $ts ? date('c', $ts) : (string)$row['updated_at'];
            }
            $record = [
                (string)($row['url'] ?? ''),
                (string)($row['status'] ?? ''),
                (string)($row['status_code'] ?? ''),
                (string)($row['language'] ?? ''),
                (string)($row['region'] ?? ''),
                $lastChecked,
                $createdAt,
                $updatedAt,
                (string)($row['domain'] ?? ''),
                (string)($row['error'] ?? ''),
            ];
            fputcsv($out, $record);
        }
        $res->free();
    }
    fclose($out);
    $conn->close();
    exit;
}

header('Content-Type: application/json; charset=utf-8');

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
    $scope = (string)($_POST['scope'] ?? 'all');
    $selectedRaw = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
    $selected = [];
    foreach ($selectedRaw as $value) {
        $value = (int)$value;
        if ($value > 0) {
            $selected[] = $value;
        }
    }
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $result = pp_crowd_links_start_check($userId ?: null, $scope, $selected);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'status') {
    $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : null;
    $result = pp_crowd_links_get_status($runId);
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
    $result = pp_crowd_links_cancel($runId, $force);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION'], JSON_UNESCAPED_UNICODE);
exit;
