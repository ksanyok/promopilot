<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN']);
    exit;
}

$key = trim((string)($_POST['key'] ?? ''));
if ($key === '') {
    echo json_encode(['ok' => false, 'error' => 'EMPTY_KEY']);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'error' => 'NO_CURL']);
    exit;
}

$ch = curl_init('https://api.openai.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'ok' => false,
        'error' => 'REQUEST_FAILED',
        'details' => $curlErr ?: 'UNKNOWN_ERROR',
    ]);
    exit;
}

if ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

if ($httpCode >= 400) {
    echo json_encode([
        'ok' => false,
        'error' => 'API_ERROR',
        'status' => $httpCode,
    ]);
    exit;
}

$payload = json_decode($response, true);
$modelCount = 0;
if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
    $modelCount = count($payload['data']);
}

echo json_encode([
    'ok' => true,
    'status' => 'VALID',
    'models' => $modelCount,
]);
exit;

