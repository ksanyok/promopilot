<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN', 'message' => __('Доступ запрещён.')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => __('Неверный метод запроса.')], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support JSON payload (optional) by mirroring keys into $_POST for verify_csrf()
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === 0) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (!isset($_POST['csrf_token']) && isset($decoded['csrf_token'])) {
            $_POST['csrf_token'] = (string)$decoded['csrf_token'];
        }
        if (!isset($_POST['payload']) && isset($decoded['payload'])) {
            $_POST['payload'] = $decoded['payload'];
        }
    }
}

if (!verify_csrf()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF', 'message' => __('Сессия устарела. Обновите страницу.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$payloadRaw = $_POST['payload'] ?? '';
$itemsRaw = [];
if (is_array($payloadRaw)) {
    $itemsRaw = $payloadRaw;
} elseif (is_string($payloadRaw) && $payloadRaw !== '') {
    $decodedPayload = json_decode($payloadRaw, true);
    if (is_array($decodedPayload)) {
        $itemsRaw = $decodedPayload;
    }
}

if (empty($itemsRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'EMPTY_PAYLOAD', 'message' => __('Нет данных для сохранения.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
foreach ($itemsRaw as $item) {
    if (!is_array($item)) { continue; }
    $slug = pp_normalize_slug((string)($item['slug'] ?? ''));
    if ($slug === '') { continue; }
    $entry = ['slug' => $slug];
    if (array_key_exists('enabled', $item)) {
        $entry['enabled'] = (int)(!empty($item['enabled']));
    }
    if (array_key_exists('priority', $item)) {
        $entry['priority'] = (int)$item['priority'];
    }
    if (array_key_exists('levels', $item)) {
        $entry['level'] = $item['levels'];
    } elseif (array_key_exists('level', $item)) {
        $entry['level'] = $item['level'];
    }
    $items[] = $entry;
}

if (empty($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'EMPTY_NORMALIZED', 'message' => __('Не удалось подготовить данные для сохранения.')], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = pp_update_network_settings($items);
$updated = $result['updated'] ?? [];
$failed = $result['failed'] ?? [];

$response = [
    'ok' => !empty($updated) && empty($failed),
    'updated' => $updated,
    'failed' => $failed,
];

if (!empty($failed) && !empty($updated)) {
    $response['ok'] = true;
    $response['partial'] = true;
}

if (empty($updated) && empty($failed)) {
    $response['ok'] = false;
    $response['error'] = 'NO_CHANGES';
    $response['message'] = __('Не удалось сохранить изменения сетей.');
}

if (!empty($failed)) {
    $response['errors'] = $failed;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
