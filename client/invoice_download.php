<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

$transactionId = (int)($_GET['txn'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
if ($transactionId <= 0 || $token === '') {
    http_response_code(404);
    exit(__('Рахунок не знайдено.'));
}

$transaction = pp_payment_transaction_get($transactionId);
if (!$transaction || strtolower((string)($transaction['gateway_code'] ?? '')) !== 'invoice') {
    http_response_code(404);
    exit(__('Рахунок не знайдено.'));
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    http_response_code(403);
    exit(__('Авторизуйтесь, щоб продовжити.'));
}

$isAdmin = is_admin();
if (!$isAdmin && $currentUserId !== (int)$transaction['user_id']) {
    http_response_code(403);
    exit(__('Доступ заборонено.'));
}

$customerPayload = is_array($transaction['customer_payload']) ? $transaction['customer_payload'] : [];
$storedToken = (string)($customerPayload['invoice_download_token'] ?? '');
if ($storedToken === '' || !hash_equals($storedToken, $token)) {
    http_response_code(403);
    exit(__('Доступ заборонено.'));
}

$providerPayload = is_array($transaction['provider_payload']) ? $transaction['provider_payload'] : [];
$pdfUrl = (string)($providerPayload['invoice_pdf_url'] ?? ($providerPayload['response']['pdf_url'] ?? ''));
if ($pdfUrl === '') {
    http_response_code(404);
    exit(__('Файл інвойсу недоступний.'));
}

$filename = (string)($customerPayload['download_filename'] ?? '');
if ($filename === '') {
    $filename = (string)($transaction['provider_reference'] ?? 'invoice');
    if ($filename === '') {
        $filename = 'invoice';
    }
    $filename .= '.pdf';
}

$ch = curl_init($pdfUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$err = $body === false ? curl_error($ch) : null;
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $httpCode >= 400) {
    http_response_code(502);
    if ($err) {
        exit(__('Не вдалося отримати PDF: ') . $err);
    }
    exit(__('Не вдалося отримати PDF.'));
}

if (stripos($contentType, 'pdf') === false) {
    $contentType = 'application/pdf';
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . strlen($body));
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo $body;
exit;
