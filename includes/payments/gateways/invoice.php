<?php
// Invoice payment gateway integration for PromoPilot

if (!function_exists('pp_payment_gateway_invoice_definition')) {
    function pp_payment_gateway_invoice_definition(): array {
        return [
            'code' => 'invoice',
            'title' => 'Інвойс (банківський переказ)',
            'currency' => 'USD',
            'sort_order' => 40,
            'config_defaults' => [
                'api_base_url' => 'https://invoice.buyreadysite.com',
                'api_key' => '',
                'service_name' => 'PromoPilot',
            ],
        ];
    }
}

if (!function_exists('pp_payment_invoice_api_url')) {
    function pp_payment_invoice_api_url(array $gateway): string {
        $config = $gateway['config'] ?? [];
        $base = trim((string)($config['api_base_url'] ?? ''));
        if ($base === '') {
            $definition = pp_payment_gateway_invoice_definition();
            if (is_array($definition) && !empty($definition['config_defaults']['api_base_url'])) {
                $base = trim((string)$definition['config_defaults']['api_base_url']);
            }
        }
        if ($base === '') {
            return '';
        }
        return rtrim($base, '/') . '/api/create_invoice.php';
    }
}

if (!function_exists('pp_payment_invoice_get_user_context')) {
    function pp_payment_invoice_get_user_context(int $userId): array {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return ['id' => 0, 'username' => '', 'full_name' => '', 'email' => ''];
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return ['id' => $userId, 'username' => '', 'full_name' => '', 'email' => ''];
        }
        if (!$conn) {
            return ['id' => $userId, 'username' => '', 'full_name' => '', 'email' => ''];
        }
        $stmt = $conn->prepare('SELECT username, full_name, email FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $conn->close();
            return ['id' => $userId, 'username' => '', 'full_name' => '', 'email' => ''];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        $conn->close();
        if (!$row) {
            return ['id' => $userId, 'username' => '', 'full_name' => '', 'email' => ''];
        }
        return [
            'id' => $userId,
            'username' => (string)($row['username'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
        ];
    }
}

if (!function_exists('pp_payment_invoice_apply_template')) {
    function pp_payment_invoice_apply_template(string $template, array $placeholders): string {
        if ($template === '') {
            return '';
        }
        $search = array_keys($placeholders);
        $replace = array_values($placeholders);
        return str_replace($search, $replace, $template);
    }
}

if (!function_exists('pp_payment_invoice_request')) {
    function pp_payment_invoice_request(array $gateway, array $payload): array {
        $url = pp_payment_invoice_api_url($gateway);
        if ($url === '') {
            return ['ok' => false, 'error' => 'api_base_url_missing'];
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'json_encode_failed'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $err = $body === false ? curl_error($ch) : null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => $err ?? 'curl_failed'];
        }
        $bodyClean = $body;
        if (strpos($bodyClean, "\xEF\xBB\xBF") === 0) {
            $bodyClean = substr($bodyClean, 3);
        }
        $bodyClean = trim($bodyClean);

        $decoded = json_decode($bodyClean, true);
        $jsonError = json_last_error();
        if ((!is_array($decoded) || $jsonError !== JSON_ERROR_NONE) && $bodyClean !== '') {
            if (preg_match('/({[\s\S]*})/m', $bodyClean, $match)) {
                $fallbackJson = json_decode(trim($match[1]), true);
                if (is_array($fallbackJson)) {
                    $decoded = $fallbackJson;
                    $jsonError = JSON_ERROR_NONE;
                }
            }
        }
        if ($code < 200 || $code >= 300) {
            $errorText = is_array($decoded) ? ($decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
            return ['ok' => false, 'error' => $errorText, 'status_code' => $code, 'body' => $body, 'decoded' => is_array($decoded) ? $decoded : null];
        }
        if (!is_array($decoded)) {
            $errorInfo = 'invalid_response';
            if ($jsonError !== JSON_ERROR_NONE) {
                $errorInfo .= ': ' . json_last_error_msg();
            }
            $snippet = $bodyClean !== '' ? preg_replace('/\s+/', ' ', mb_substr($bodyClean, 0, 160)) : '';
            if ($snippet !== '') {
                $errorInfo .= ' (' . $snippet . '...)';
            }
            return ['ok' => false, 'error' => $errorInfo, 'body' => $body];
        }
        if (array_key_exists('success', $decoded) && !$decoded['success']) {
            $errorText = (string)($decoded['error'] ?? $decoded['message'] ?? 'invoice_request_failed');
            return ['ok' => false, 'error' => $errorText, 'status_code' => $code, 'body' => $body, 'decoded' => $decoded];
        }
        return ['ok' => true, 'decoded' => $decoded, 'status_code' => $code, 'body' => $body];
    }
}

if (!function_exists('pp_payment_gateway_initiate_invoice')) {
    function pp_payment_gateway_initiate_invoice(array $gateway, array $transaction, array $options = []): array {
        $configRaw = is_array($gateway['config'] ?? null) ? $gateway['config'] : [];
        $definition = pp_payment_gateway_invoice_definition();
        $configDefaults = isset($definition['config_defaults']) && is_array($definition['config_defaults']) ? $definition['config_defaults'] : [];
        $config = array_merge($configDefaults, $configRaw);
        if (empty(trim((string)$config['api_base_url']))) {
            $config['api_base_url'] = $configDefaults['api_base_url'] ?? 'https://invoice.buyreadysite.com';
        }
        $apiKey = trim((string)($config['api_key'] ?? ''));
        if ($apiKey === '') {
            $msg = function_exists('__') ? __('Не задан API ключ для інвойсів.') : 'Invoice API key missing';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $serviceName = trim((string)($config['service_name'] ?? 'PromoPilot'));
        if ($serviceName === '') {
            $serviceName = 'PromoPilot';
        }
        $serviceDisplayName = $serviceName;
        $userId = (int)($transaction['user_id'] ?? 0);
        $userContext = pp_payment_invoice_get_user_context($userId);
        $userName = trim((string)($options['user_name'] ?? ''));
        if ($userName === '') {
            $userName = trim((string)($userContext['full_name'] ?? ''));
        }
        if ($userName === '') {
            $userName = trim((string)($userContext['username'] ?? ''));
        }
        if ($userName === '') {
            $userName = 'User #' . $userId;
        }
        $userReferenceParts = [];
        if ($userContext['full_name'] !== '') { $userReferenceParts[] = $userContext['full_name']; }
        if ($userContext['username'] !== '') { $userReferenceParts[] = '@' . $userContext['username']; }
        $userReferenceParts[] = 'ID ' . $userId;
        $userReference = implode(' / ', array_filter($userReferenceParts));
        $allowedCurrencies = ['UAH', 'USD', 'EUR'];
        $targetCurrency = strtoupper((string)($options['invoice_currency'] ?? ''));
        if (!in_array($targetCurrency, $allowedCurrencies, true)) {
            $targetCurrency = 'UAH';
        }
        $amountUsd = round((float)($transaction['amount'] ?? 0), 2);
        $issuedBy = sprintf('%s (%s)', $serviceDisplayName, $userName);
        if ($amountUsd <= 0) {
            $msg = function_exists('__') ? __('Сума повинна бути більшою за нуль.') : 'Amount must be positive';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $serviceDescription = sprintf("Призначення платежу: Оплата послуг\nсервісу %s", $serviceDisplayName);
        $noteLines = [
            sprintf('Клієнт: %s (ID %d)', $userName, $userId),
            sprintf('Сума рахунку: %.2f USD', $amountUsd),
            sprintf('Валюта рахунку: %s', $targetCurrency),
        ];
        $notes = implode("\n", $noteLines);
        $callbackUrl = pp_payment_gateway_webhook_url('invoice');
        $payload = [
            'service_name' => $serviceName,
            'service_display_name' => $serviceDisplayName,
            'api_key' => $apiKey,
            'amount' => $amountUsd,
            'currency' => $targetCurrency,
            'issued_by' => $issuedBy,
            'service_description' => $serviceDescription,
            'notes' => $notes,
            'user_name' => $userName,
            'callback_url' => $callbackUrl,
        ];
        $requestForLog = $payload;
        $requestForLog['api_key'] = '***';
        $response = pp_payment_invoice_request($gateway, $payload);
        if (empty($response['ok'])) {
            $error = $response['error'] ?? 'Invoice API error';
            $details = [];
            if (!empty($response['body']) || isset($response['decoded']) || isset($response['status_code'])) {
                $details = [
                    'status_code' => $response['status_code'] ?? null,
                    'body' => $response['body'] ?? null,
                    'decoded' => $response['decoded'] ?? null,
                ];
            }
            return ['ok' => false, 'error' => $error, 'status' => 'failed', 'details' => $details];
        }
        $data = $response['decoded'] ?? [];
        $invoiceNumber = (string)($data['invoice_number'] ?? '');
        $pdfUrl = (string)($data['pdf_url'] ?? '');
        if ($invoiceNumber === '' || $pdfUrl === '') {
            return ['ok' => false, 'error' => 'invoice_invalid_response', 'status' => 'failed'];
        }
        try {
            $downloadToken = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $entropy = hash('sha256', microtime(true) . mt_rand() . $invoiceNumber . $userId);
            $downloadToken = substr($entropy, 0, 32);
        }

        $providerPayload = [
            'request' => $requestForLog,
            'response' => $data,
            'http_status' => $response['status_code'] ?? 200,
            'invoice_currency' => $targetCurrency,
            'raw_response_body' => $response['body'] ?? '',
            'invoice_pdf_url' => $pdfUrl,
            'invoice_download_token' => $downloadToken,
        ];
        $customerPayload = [
            'invoice_id' => $invoiceNumber,
            'payment_url' => null,
            'amount' => isset($data['amount']) ? (float)$data['amount'] : $amountUsd,
            'currency' => strtoupper((string)($data['currency'] ?? $targetCurrency)),
            'notes' => (string)($data['notes'] ?? $notes),
            'message' => function_exists('__')
                ? __('Завантажте рахунок-фактуру, оплатіть у банку та зачекайте автоматичного підтвердження.')
                : 'Download the invoice PDF, pay via bank, and wait for automatic confirmation.',
            'invoice_currency' => $targetCurrency,
            'user_reference' => $userReference,
            'download_filename' => $invoiceNumber !== '' ? ($invoiceNumber . '.pdf') : '',
            'invoice_download_token' => $downloadToken,
        ];
        if (!empty($data['pdf_url'])) {
            $customerPayload['download_label'] = function_exists('__')
                ? __('Завантажити PDF-рахунок')
                : 'Download invoice PDF';
        }
        return [
            'ok' => true,
            'status' => 'awaiting_confirmation',
            'provider_reference' => $invoiceNumber,
            'payment_url' => null,
            'provider_payload' => $providerPayload,
            'customer_payload' => $customerPayload,
        ];
    }
}

if (!function_exists('pp_payment_handle_invoice_webhook')) {
    function pp_payment_handle_invoice_webhook(array $payload, array $headers = [], string $rawBody = ''): array {
        $gateway = pp_payment_gateway_get('invoice');
        if (!$gateway || empty($gateway['is_enabled'])) {
            return ['ok' => false, 'status' => 503, 'error' => 'gateway_disabled'];
        }
        $invoiceNumber = (string)($payload['invoice_number'] ?? $payload['invoiceNumber'] ?? '');
        if ($invoiceNumber === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'invoice_number_missing'];
        }
        $status = strtolower((string)($payload['status'] ?? ''));
        $transaction = pp_payment_transaction_find_by_reference('invoice', $invoiceNumber);
        if (!$transaction) {
            return ['ok' => false, 'status' => 404, 'error' => 'transaction_not_found'];
        }
        if (in_array($status, ['paid', 'success', 'confirmed'], true)) {
            $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
            $result = pp_payment_transaction_mark_confirmed((int)$transaction['id'], null, $payload);
            if (!empty($result['ok'])) {
                $httpStatus = !empty($result['already']) ? 200 : 200;
                return ['ok' => true, 'status' => $httpStatus];
            }
            return ['ok' => false, 'status' => 409, 'error' => $result['error'] ?? 'update_failed'];
        }
        if (in_array($status, ['cancelled', 'canceled', 'failed', 'expired'], true)) {
            pp_payment_transaction_mark_failed((int)$transaction['id'], $status, $payload, $payload['error'] ?? null);
            return ['ok' => true, 'status' => 200];
        }
        return ['ok' => true, 'status' => 202];
    }
}
