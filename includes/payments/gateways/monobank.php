<?php
// Monobank payment gateway integration for PromoPilot

if (!function_exists('pp_payment_gateway_monobank_definition')) {
    function pp_payment_gateway_monobank_definition(): array {
        return [
            'code' => 'monobank',
            'title' => 'Monobank',
            'currency' => 'USD',
            'sort_order' => 10,
            'config_defaults' => [
                'token' => '',
                'destination' => 'Пополнение баланса PromoPilot',
                'redirect_url' => '',
                'environment' => 'production',
                'invoice_lifetime' => 900,
                'usd_markup_percent' => 5.0,
                'usd_manual_rate' => '',
            ],
        ];
    }
}

if (!function_exists('pp_payment_gateway_initiate_monobank')) {
    function pp_payment_gateway_initiate_monobank(array $gateway, array $transaction, array $options = []): array {
        $config = $gateway['config'] ?? [];
        $token = trim((string)($config['token'] ?? ''));
        if ($token === '') {
            $msg = function_exists('__') ? __('Не задан API токен Monobank.') : 'Monobank token missing';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $usdAmount = round((float)$transaction['amount'], 2);
        if ($usdAmount <= 0) {
            $msg = function_exists('__') ? __('Сумма должна быть больше нуля.') : 'Amount must be positive';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $rateInfo = pp_payment_monobank_get_usd_rate($config);
        if (empty($rateInfo['ok'])) {
            $error = $rateInfo['error'] ?? 'rate_unavailable';
            $msg = function_exists('__') ? __('Не удалось получить курс Monobank.') : 'Failed to fetch Monobank rate';
            return ['ok' => false, 'error' => $msg . ' (' . $error . ')', 'status' => 'failed'];
        }
        $exchangeRate = (float)$rateInfo['rate'];
        $uahAmount = $usdAmount * $exchangeRate;
        $amountMinor = (int)round($uahAmount * 100);
        if ($amountMinor <= 0) {
            $msg = function_exists('__') ? __('Сумма должна быть больше нуля.') : 'Amount must be positive';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $orderId = 'PPM-' . (int)$transaction['id'];
        $destination = trim((string)($config['destination'] ?? 'PromoPilot balance top-up'));
        $redirectUrl = trim((string)($config['redirect_url'] ?? ''));
        if ($redirectUrl === '') {
            $redirectUrl = pp_url('client/balance.php?txn=' . (int)$transaction['id']);
        }
        $webhookUrl = pp_payment_gateway_webhook_url('monobank');
        $payload = [
            'amount' => $amountMinor,
            'ccy' => 980,
            'merchantPaymInfo' => [
                'reference' => $orderId,
                'destination' => $destination,
                'comment' => 'PromoPilot balance top-up #' . (int)$transaction['id'],
            ],
            'redirectUrl' => $redirectUrl,
            'webHookUrl' => $webhookUrl,
            'orderId' => $orderId,
        ];
        $lifetime = (int)($config['invoice_lifetime'] ?? 900);
        if ($lifetime > 0) {
            $payload['validity'] = max(60, min(86400, $lifetime));
        }
        $response = pp_payment_monobank_request('invoice/create', $payload, $token, $config);
        if (empty($response['ok'])) {
            return ['ok' => false, 'error' => $response['error'] ?? 'Monobank error', 'status' => 'failed'];
        }
        $data = $response['decoded'] ?? [];
        $invoiceId = (string)($data['invoiceId'] ?? '');
        $pageUrl = (string)($data['pageUrl'] ?? ($data['invoiceUrl'] ?? ''));
        if ($invoiceId === '' || $pageUrl === '') {
            return ['ok' => false, 'error' => 'Invalid Monobank response', 'status' => 'failed'];
        }
        $baseRate = (float)($rateInfo['base_rate'] ?? $exchangeRate);
        if ($baseRate <= 0) {
            $baseRate = $exchangeRate;
        }
        $commissionPercent = isset($rateInfo['markup_percent']) ? (float)$rateInfo['markup_percent'] : 0.0;
        $expectedBaseUah = round($usdAmount * $baseRate, 2);
        $commissionUah = round($uahAmount - $expectedBaseUah, 2);
        $commissionUah = $commissionUah < 0 ? 0.0 : $commissionUah;
        $data['_pp_exchange'] = [
            'usd_amount' => $usdAmount,
            'uah_amount' => round($uahAmount, 2),
            'rate' => $exchangeRate,
            'base_rate' => $baseRate,
            'markup_percent' => $commissionPercent,
            'rate_source' => $rateInfo['source'] ?? 'auto',
        ];
        $customerPayload = [
            'payment_url' => $pageUrl,
            'invoice_id' => $invoiceId,
            'order_id' => $orderId,
            'message' => function_exists('__') ? __('Перейдите по ссылке для оплаты счёта Monobank.') : 'Follow the link to pay via Monobank.',
            'amount_usd' => number_format($usdAmount, 2, '.', ''),
            'amount_uah' => number_format($uahAmount, 2, '.', ''),
            'exchange_rate' => number_format($exchangeRate, 6, '.', ''),
            'exchange_source' => $rateInfo['source'] ?? 'auto',
        ];
        $customerPayload['commission_amount_uah'] = number_format($commissionUah, 2, '.', '');
        $customerPayload['commission_percent'] = number_format(max(0.0, $commissionPercent), 2, '.', '');
        $customerPayload['commission_note'] = sprintf(
            __('Поповнення: %1$s USD (~%2$s UAH). Комісія за поповнення: %3$s UAH (%4$s%%).'),
            number_format($usdAmount, 2, '.', ''),
            number_format($uahAmount, 2, '.', ''),
            number_format($commissionUah, 2, '.', ''),
            number_format(max(0.0, $commissionPercent), 2, '.', '')
        );
        if (!empty($data['validity'])) {
            $customerPayload['valid_until'] = (int)$data['validity'];
        }
        return [
            'ok' => true,
            'status' => 'awaiting_confirmation',
            'provider_reference' => $invoiceId,
            'payment_url' => $pageUrl,
            'provider_payload' => $data,
            'customer_payload' => $customerPayload,
        ];
    }
}

if (!function_exists('pp_payment_monobank_request')) {
    function pp_payment_monobank_request(string $path, array $payload, string $token, array $config = []): array {
        $base = trim((string)($config['base_url'] ?? ''));
        if ($base === '') {
            $base = 'https://api.monobank.ua/api/merchant';
        }
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
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
                'X-Token: ' . $token,
            ],
            CURLOPT_TIMEOUT => 15,
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
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $errorText = $decoded['errText'] ?? $decoded['message'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'error' => $errorText, 'status_code' => $code, 'body' => $body];
        }
        return ['ok' => true, 'decoded' => is_array($decoded) ? $decoded : [], 'status_code' => $code, 'body' => $body];
    }
}

if (!function_exists('pp_payment_monobank_fetch_public_rate')) {
    function pp_payment_monobank_fetch_public_rate(): array {
        static $cache = null;
        static $cacheTs = 0;
        if ($cache !== null && (time() - $cacheTs) < 120) {
            return $cache;
        }
        $url = 'https://api.monobank.ua/bank/currency';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
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
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid_response'];
        }
        $rate = null;
        $raw = null;
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $codeA = (int)($entry['currencyCodeA'] ?? 0);
            $codeB = (int)($entry['currencyCodeB'] ?? 0);
            if ($codeA === 840 && $codeB === 980) {
                $candidate = null;
                if (isset($entry['rateCross'])) {
                    $candidate = (float)$entry['rateCross'];
                }
                if (!$candidate && isset($entry['rateSell'])) {
                    $candidate = (float)$entry['rateSell'];
                }
                if (!$candidate && isset($entry['rateBuy'])) {
                    $candidate = (float)$entry['rateBuy'];
                }
                if ($candidate && $candidate > 0) {
                    $rate = $candidate;
                    $raw = $entry;
                    break;
                }
            }
        }
        if (!$rate) {
            return ['ok' => false, 'error' => 'rate_not_found'];
        }
        $cache = ['ok' => true, 'rate' => $rate, 'source' => 'api', 'raw' => $raw];
        $cacheTs = time();
        return $cache;
    }
}

if (!function_exists('pp_payment_monobank_get_usd_rate')) {
    function pp_payment_monobank_get_usd_rate(array $config): array {
        $markupPercent = isset($config['usd_markup_percent']) ? (float)$config['usd_markup_percent'] : 0.0;
        $markupPercent = max(-99.0, min(500.0, $markupPercent));
        $manualRateRaw = trim((string)($config['usd_manual_rate'] ?? ''));
        if ($manualRateRaw !== '' && is_numeric($manualRateRaw)) {
            $baseRate = max(0.0001, (float)$manualRateRaw);
            $rate = $baseRate * (1 + ($markupPercent / 100));
            return [
                'ok' => true,
                'rate' => round($rate, 6),
                'base_rate' => round($baseRate, 6),
                'markup_percent' => $markupPercent,
                'source' => 'manual',
            ];
        }
        $fetched = pp_payment_monobank_fetch_public_rate();
        if (empty($fetched['ok'])) {
            return $fetched;
        }
        $baseRate = (float)$fetched['rate'];
        $rate = $baseRate * (1 + ($markupPercent / 100));
        return [
            'ok' => true,
            'rate' => round($rate, 6),
            'base_rate' => round($baseRate, 6),
            'markup_percent' => $markupPercent,
            'source' => $fetched['source'] ?? 'api',
        ];
    }
}

if (!function_exists('pp_payment_monobank_extract_invoice_id')) {
    function pp_payment_monobank_extract_invoice_id(array $transaction): ?string {
        $reference = trim((string)($transaction['provider_reference'] ?? ''));
        if ($reference !== '') {
            return $reference;
        }
        $customerPayload = $transaction['customer_payload'] ?? [];
        if (is_array($customerPayload)) {
            if (!empty($customerPayload['invoice_id'])) {
                $candidate = trim((string)$customerPayload['invoice_id']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            if (!empty($customerPayload['invoiceId'])) {
                $candidate = trim((string)$customerPayload['invoiceId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        $providerPayload = $transaction['provider_payload'] ?? [];
        if (is_array($providerPayload)) {
            if (!empty($providerPayload['invoiceId'])) {
                $candidate = trim((string)$providerPayload['invoiceId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            if (!empty($providerPayload['initial']['invoiceId'])) {
                $candidate = trim((string)$providerPayload['initial']['invoiceId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        return null;
    }
}

if (!function_exists('pp_payment_monobank_extract_order_id')) {
    function pp_payment_monobank_extract_order_id(array $transaction): ?string {
        $customerPayload = $transaction['customer_payload'] ?? [];
        if (is_array($customerPayload)) {
            if (!empty($customerPayload['order_id'])) {
                $candidate = trim((string)$customerPayload['order_id']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            if (!empty($customerPayload['orderId'])) {
                $candidate = trim((string)$customerPayload['orderId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        $providerPayload = $transaction['provider_payload'] ?? [];
        if (is_array($providerPayload)) {
            if (!empty($providerPayload['orderId'])) {
                $candidate = trim((string)$providerPayload['orderId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            if (!empty($providerPayload['initial']['orderId'])) {
                $candidate = trim((string)$providerPayload['initial']['orderId']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        if (!empty($transaction['id'])) {
            return 'PPM-' . (int)$transaction['id'];
        }
        return null;
    }
}

if (!function_exists('pp_payment_monobank_refresh_transaction')) {
    function pp_payment_monobank_refresh_transaction(int $transactionId, array $options = []): array {
        $transactionId = (int)$transactionId;
        if ($transactionId <= 0) {
            return ['ok' => false, 'error' => 'invalid_transaction'];
        }
        $transaction = pp_payment_transaction_get($transactionId);
        if (!$transaction) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $expectedUserId = isset($options['expected_user_id']) ? (int)$options['expected_user_id'] : null;
        if ($expectedUserId !== null && (int)$transaction['user_id'] !== $expectedUserId) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $gatewayCode = strtolower((string)($transaction['gateway_code'] ?? ''));
        if ($gatewayCode !== 'monobank') {
            return ['ok' => false, 'error' => 'gateway_mismatch', 'transaction' => $transaction];
        }
        $currentStatus = strtolower((string)($transaction['status'] ?? ''));
        if ($currentStatus === 'confirmed') {
            return ['ok' => true, 'status' => 'confirmed', 'status_changed' => false, 'already' => true, 'transaction' => $transaction];
        }
        $invoiceId = pp_payment_monobank_extract_invoice_id($transaction);
        if ($invoiceId === null || $invoiceId === '') {
            return ['ok' => false, 'error' => 'missing_invoice', 'transaction' => $transaction];
        }
        $orderId = pp_payment_monobank_extract_order_id($transaction);
        $gateway = pp_payment_gateway_get('monobank');
        if (!$gateway || empty($gateway['is_enabled'])) {
            return ['ok' => false, 'error' => 'gateway_disabled', 'transaction' => $transaction];
        }
        $config = $gateway['config'] ?? [];
        $token = trim((string)($config['token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'error' => 'token_missing', 'transaction' => $transaction];
        }
        $statusPayload = ['invoiceId' => $invoiceId];
        if ($orderId !== null && $orderId !== '') {
            $statusPayload['orderId'] = $orderId;
        }
        if (!empty($config['merchant_id'])) {
            $statusPayload['merchantId'] = trim((string)$config['merchant_id']);
        }
        $response = pp_payment_monobank_request('invoice/status', $statusPayload, $token, $config);
        $softErrors = ['not_found', 'invoice_not_found', 'invoice not found', 'monobank_invoice_not_found', 'noinvoice', 'http 404'];
        $statusCode = isset($response['status_code']) ? (int)$response['status_code'] : 0;
        if (empty($response['ok'])) {
            $decoded = is_array($response['decoded'] ?? null) ? $response['decoded'] : [];
            $error = (string)($response['error'] ?? 'status_request_failed');
            $errCodeRaw = '';
            if (isset($decoded['errCode'])) {
                $errCodeRaw = strtolower((string)$decoded['errCode']);
            } elseif (isset($decoded['errorCode'])) {
                $errCodeRaw = strtolower((string)$decoded['errorCode']);
            }
            if ($errCodeRaw !== '') {
                $error = $errCodeRaw;
            }
            if ($statusCode === 404 || in_array($error, $softErrors, true)) {
                return [
                    'ok' => true,
                    'status' => 'pending',
                    'status_changed' => false,
                    'transaction' => $transaction,
                    'error' => $error,
                    'payload' => $decoded,
                    'status_code' => $statusCode,
                ];
            }
            return ['ok' => false, 'error' => $error, 'transaction' => $transaction, 'payload' => $decoded, 'status_code' => $statusCode];
        }
        $data = is_array($response['decoded'] ?? null) ? $response['decoded'] : [];
        $status = strtolower((string)($data['status'] ?? ''));
        $eventPayload = [
            'source' => 'monobank_status_poll',
            'invoiceId' => $invoiceId,
            'orderId' => $orderId,
            'status' => $status,
            'payload' => $data,
            'checked_at' => date('c'),
        ];
        if (!empty($data['errCode']) && empty($eventPayload['payload']['errCode_lower'])) {
            $eventPayload['payload']['errCode_lower'] = strtolower((string)$data['errCode']);
        }
        $successStatuses = ['success', 'paid', 'confirmed', 'done', 'completed', 'complete'];
        $failStatuses = ['expired', 'cancelled', 'canceled', 'failure', 'failed', 'reversed', 'revoked', 'declined', 'error'];
        if (in_array($status, $successStatuses, true)) {
            $amountMinor = null;
            if (isset($data['amount'])) {
                $amountMinor = (int)$data['amount'];
            } elseif (isset($data['finalAmount'])) {
                $amountMinor = (int)$data['finalAmount'];
            }
            $amount = $amountMinor !== null ? $amountMinor / 100 : null;
            $mark = pp_payment_transaction_mark_confirmed($transactionId, $amount, $eventPayload);
            if (empty($mark['ok'])) {
                return ['ok' => false, 'error' => $mark['error'] ?? 'confirm_failed', 'status' => 'confirmed', 'transaction' => $transaction];
            }
            $updatedTransaction = $mark['transaction'] ?? $transaction;
            return [
                'ok' => true,
                'status' => 'confirmed',
                'status_changed' => empty($mark['already']),
                'already' => !empty($mark['already']),
                'transaction' => $updatedTransaction,
                'payload' => $data,
            ];
        }
        if (in_array($status, $failStatuses, true)) {
            pp_payment_transaction_mark_failed($transactionId, $status, $eventPayload, $data['errText'] ?? null);
            $updatedTransaction = pp_payment_transaction_get($transactionId) ?? $transaction;
            $finalStatus = strtolower((string)($updatedTransaction['status'] ?? $status ?? 'failed'));
            return [
                'ok' => true,
                'status' => $finalStatus,
                'status_changed' => true,
                'transaction' => $updatedTransaction,
                'payload' => $data,
            ];
        }
        return [
            'ok' => true,
            'status' => $status !== '' ? $status : 'unknown',
            'status_changed' => false,
            'transaction' => $transaction,
            'payload' => $data,
        ];
    }
}

if (!function_exists('pp_payment_monobank_refresh_pending_for_user')) {
    function pp_payment_monobank_refresh_pending_for_user(int $userId, ?int $includeTransactionId = null, int $limit = 5): array {
        $userId = (int)$userId;
        $limit = max(1, min(20, (int)$limit));
        $ids = [];
        if ($userId > 0) {
            try {
                $conn = connect_db();
            } catch (Throwable $e) {
                $conn = null;
            }
            if ($conn) {
                $gatewayCode = 'monobank';
                $stmt = $conn->prepare("SELECT id FROM payment_transactions WHERE user_id = ? AND gateway_code = ? AND status IN ('pending','awaiting_confirmation') ORDER BY id DESC LIMIT ?");
                if ($stmt) {
                    $stmt->bind_param('isi', $userId, $gatewayCode, $limit);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        if ($res) {
                            while ($row = $res->fetch_assoc()) {
                                $ids[] = (int)$row['id'];
                            }
                            $res->free();
                        }
                    }
                    $stmt->close();
                }
                $conn->close();
            }
        }
        if ($includeTransactionId !== null && $includeTransactionId > 0) {
            $ids[] = (int)$includeTransactionId;
        }
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = pp_payment_monobank_refresh_transaction($id, ['expected_user_id' => $userId]);
        }
        return ['ok' => true, 'results' => $results];
    }
}

if (!function_exists('pp_payment_handle_monobank_webhook')) {
    function pp_payment_handle_monobank_webhook(array $payload, array $headers, string $rawBody = ''): array {
        $gateway = pp_payment_gateway_get('monobank');
        if (!$gateway || empty($gateway['is_enabled'])) {
            return ['ok' => false, 'status' => 503, 'error' => 'gateway_disabled'];
        }
        $token = trim((string)($gateway['config']['token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'status' => 503, 'error' => 'token_missing'];
        }
        $headersLower = [];
        foreach ($headers as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        $signature = $headersLower['x-signature'] ?? ($headersLower['x-sign'] ?? '');
        if ($signature === '' && isset($payload['signature'])) {
            $signature = (string)$payload['signature'];
        }
        if ($signature === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'signature_missing'];
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $token, true));
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'status' => 401, 'error' => 'signature_invalid'];
        }
        $invoiceId = (string)($payload['invoiceId'] ?? '');
        $orderId = (string)($payload['orderId'] ?? '');
        $transaction = null;
        if ($invoiceId !== '') {
            $transaction = pp_payment_transaction_find_by_reference('monobank', $invoiceId);
        }
        if (!$transaction && $orderId !== '' && preg_match('~PPM-(\d+)~', $orderId, $m)) {
            $transaction = pp_payment_transaction_get((int)$m[1]);
        }
        if (!$transaction) {
            return ['ok' => false, 'status' => 404, 'error' => 'transaction_not_found'];
        }
        $status = strtolower((string)($payload['status'] ?? ''));
        if (in_array($status, ['success', 'paid', 'confirmed'], true)) {
            $amountMinor = isset($payload['amount']) ? (int)$payload['amount'] : null;
            $amount = $amountMinor !== null ? $amountMinor / 100 : null;
            $result = pp_payment_transaction_mark_confirmed((int)$transaction['id'], $amount, $payload);
            if (!empty($result['ok'])) {
                $httpStatus = !empty($result['already']) ? 200 : 200;
                return ['ok' => true, 'status' => $httpStatus];
            }
            return ['ok' => false, 'status' => 409, 'error' => $result['error'] ?? 'update_failed'];
        }
        if (in_array($status, ['expired', 'cancelled', 'canceled', 'failure', 'failed'], true)) {
            pp_payment_transaction_mark_failed((int)$transaction['id'], $status, $payload, $payload['errText'] ?? null);
            return ['ok' => true, 'status' => 200];
        }
        return ['ok' => true, 'status' => 202];
    }
}
