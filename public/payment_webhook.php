<?php
require_once __DIR__ . '/../includes/init.php';

$gatewayCode = strtolower(trim((string)($_GET['gateway'] ?? '')));
if ($gatewayCode === '') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'gateway_missing'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
    if ($_POST) {
        $payload = $_POST;
    }
}

$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
$result = pp_payment_handle_webhook($gatewayCode, $payload, $headers, $rawBody);
$statusCode = (int)($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
if ($statusCode < 100 || $statusCode > 599) {
    $statusCode = !empty($result['ok']) ? 200 : 400;
}
http_response_code($statusCode);
header('Content-Type: application/json; charset=utf-8');
$responseBody = [
    'ok' => !empty($result['ok']),
];
if (!empty($result['error'])) {
    $responseBody['error'] = (string)$result['error'];
}
if (!empty($result['message'])) {
    $responseBody['message'] = (string)$result['message'];
}

echo json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
