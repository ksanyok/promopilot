<?php
// Binance Pay payment gateway integration for PromoPilot

if (!function_exists('pp_payment_gateway_binance_definition')) {
    function pp_payment_gateway_binance_definition(): array {
        return [
            'code' => 'binance',
            'title' => 'Binance Pay (USDT TRC20)',
            'currency' => 'USDT',
            'sort_order' => 20,
            'config_defaults' => [
                'api_key' => '',
                'api_secret' => '',
                'merchant_id' => '',
                'return_url' => '',
                'environment' => 'production',
                'webhook_key' => '',
                'terminal_type' => 'WEB',
                'mode' => 'merchant',
                'wallet_address' => '',
                'wallet_network' => 'TRC20',
                'wallet_memo' => '',
            ],
        ];
    }
}

if (!function_exists('pp_payment_gateway_initiate_binance')) {
    function pp_payment_gateway_initiate_binance(array $gateway, array $transaction, array $options = []): array {
        $config = $gateway['config'] ?? [];
        $mode = strtolower((string)($config['mode'] ?? 'merchant'));
        $mode = in_array($mode, ['wallet', 'merchant'], true) ? $mode : 'merchant';
        if ($mode === 'wallet') {
            $address = trim((string)($config['wallet_address'] ?? ''));
            if ($address === '') {
                $msg = function_exists('__') ? __('Укажите адрес кошелька для приёма USDT.') : 'USDT wallet address required';
                return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
            }
            $network = strtoupper(trim((string)($config['wallet_network'] ?? 'TRC20')));
            if ($network === '') {
                $network = 'TRC20';
            }
            $memo = trim((string)($config['wallet_memo'] ?? ''));
            $amountFormatted = number_format((float)$transaction['amount'], 2, '.', '');
            $customerPayload = [
                'wallet_address' => $address,
                'wallet_network' => $network,
                'wallet_memo' => $memo,
                'amount' => $amountFormatted,
                'currency' => 'USDT',
                'message' => function_exists('__') ? __('Переведите указанную сумму USDT на кошелёк ниже. После поступления средств мы подтвердим транзакцию.') : 'Send the specified USDT amount to the wallet below. The team will confirm once received.',
                'manual_confirmation_required' => true,
            ];
            return [
                'ok' => true,
                'status' => 'awaiting_confirmation',
                'provider_reference' => null,
                'payment_url' => null,
                'provider_payload' => [
                    'mode' => 'wallet',
                    'wallet_address' => $address,
                    'wallet_network' => $network,
                    'wallet_memo' => $memo,
                ],
                'customer_payload' => $customerPayload,
            ];
        }
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $apiSecret = trim((string)($config['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            $msg = function_exists('__') ? __('Укажите API ключи Binance Pay.') : 'Binance API keys required';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $merchantTradeNo = 'PPB-' . (int)$transaction['id'];
        $orderAmount = number_format((float)$transaction['amount'], 2, '.', '');
        $returnUrl = trim((string)($config['return_url'] ?? ''));
        if ($returnUrl === '') {
            $returnUrl = pp_url('client/balance.php?txn=' . (int)$transaction['id']);
        }
        $payload = [
            'merchantTradeNo' => $merchantTradeNo,
            'orderAmount' => $orderAmount,
            'currency' => 'USDT',
            'goods' => [
                'goodsType' => '01',
                'goodsCategory' => 'D000',
                'referenceGoodsId' => 'balance_topup',
                'goodsName' => 'PromoPilot Balance Top-up',
                'goodsDetail' => 'Account recharge #' . (int)$transaction['id'],
            ],
            'returnUrl' => $returnUrl,
            'webhookUrl' => pp_payment_gateway_webhook_url('binance'),
            'supportPayCurrency' => 'USDT',
            'supportPayNetworks' => ['TRX'],
            'expireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
        ];
        if (!empty($config['merchant_id'])) {
            $payload['merchantId'] = trim((string)$config['merchant_id']);
        }
        if (!empty($config['terminal_type'])) {
            $payload['terminalType'] = strtoupper((string)$config['terminal_type']);
        }
        $response = pp_payment_binance_request($config, 'openapi/order', $payload);
        if (empty($response['ok'])) {
            return ['ok' => false, 'error' => $response['error'] ?? 'Binance error', 'status' => 'failed'];
        }
        $data = $response['decoded']['data'] ?? [];
        $prepayId = (string)($data['prepayId'] ?? '');
        $checkoutUrl = $data['checkoutUrl'] ?? ($data['universalUrl'] ?? null);
        $qrContent = $data['qrContent'] ?? ($data['qrCode'] ?? null);
        if ($prepayId === '') {
            return ['ok' => false, 'error' => 'Invalid Binance response', 'status' => 'failed'];
        }
        $customerPayload = [
            'payment_url' => $checkoutUrl,
            'qr_content' => $qrContent,
            'prepay_id' => $prepayId,
            'merchant_trade_no' => $merchantTradeNo,
            'message' => function_exists('__') ? __('Оплатите счёт в Binance Pay (USDT TRC20).') : 'Complete the payment via Binance Pay (USDT TRC20).',
        ];
        return [
            'ok' => true,
            'status' => 'awaiting_confirmation',
            'provider_reference' => $prepayId,
            'payment_url' => $checkoutUrl,
            'payment_qr' => $qrContent,
            'provider_payload' => $response['decoded'],
            'customer_payload' => $customerPayload,
        ];
    }
}

if (!function_exists('pp_payment_binance_request')) {
    function pp_payment_binance_request(array $config, string $path, array $payload): array {
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $apiSecret = trim((string)($config['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            return ['ok' => false, 'error' => 'missing_keys'];
        }
        $base = 'https://bpay.binanceapi.com/binancepay';
        $environment = strtolower((string)($config['environment'] ?? 'production'));
        if ($environment === 'sandbox' || $environment === 'test') {
            $base = 'https://bpay.binanceapi.com/binancepay-test';
        }
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'error' => 'json_encode_failed'];
        }
        $timestamp = (string)round(microtime(true) * 1000);
        $nonceSource = null;
        if (function_exists('random_bytes')) {
            try {
                $nonceSource = random_bytes(16);
            } catch (Throwable $e) {
                $nonceSource = null;
            }
        }
        if ($nonceSource === null && function_exists('openssl_random_pseudo_bytes')) {
            $nonceSource = openssl_random_pseudo_bytes(16);
        }
        if ($nonceSource === false || $nonceSource === null) {
            $nonceSource = md5(uniqid((string)mt_rand(), true), true);
        }
        $nonce = bin2hex($nonceSource);
        $signaturePayload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = strtoupper(hash_hmac('sha512', $signaturePayload, $apiSecret));
        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $err = $response === false ? curl_error($ch) : null;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'error' => $err ?? 'curl_failed'];
        }
        $decoded = json_decode($response, true);
        $status = strtoupper((string)($decoded['status'] ?? ''));
        $success = ($code >= 200 && $code < 300) && $status === 'SUCCESS' && ($decoded['code'] ?? '000000') === '000000';
        if (!$success) {
            $error = $decoded['errorMessage'] ?? $decoded['message'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'error' => $error, 'status_code' => $code, 'body' => $response, 'decoded' => is_array($decoded) ? $decoded : []];
        }
        return ['ok' => true, 'decoded' => is_array($decoded) ? $decoded : [], 'status_code' => $code, 'body' => $response];
    }
}

if (!function_exists('pp_payment_handle_binance_webhook')) {
    function pp_payment_handle_binance_webhook(array $payload, array $headers, string $rawBody = ''): array {
        $gateway = pp_payment_gateway_get('binance');
        if (!$gateway || empty($gateway['is_enabled'])) {
            return ['ok' => false, 'status' => 503, 'error' => 'gateway_disabled'];
        }
        $config = $gateway['config'] ?? [];
        $apiSecret = trim((string)($config['api_secret'] ?? ''));
        if ($apiSecret === '') {
            return ['ok' => false, 'status' => 503, 'error' => 'secret_missing'];
        }
        $headersLower = [];
        foreach ($headers as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        $timestamp = $headersLower['binancepay-timestamp'] ?? '';
        $nonce = $headersLower['binancepay-nonce'] ?? '';
        $signature = $headersLower['binancepay-signature'] ?? '';
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'signature_missing'];
        }
        $expected = strtoupper(hash_hmac('sha512', $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n", $apiSecret));
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'status' => 401, 'error' => 'signature_invalid'];
        }
        $data = $payload['data'] ?? [];
        $merchantTradeNo = (string)($data['merchantTradeNo'] ?? '');
        $prepayId = (string)($data['prepayId'] ?? '');
        $transaction = null;
        if ($prepayId !== '') {
            $transaction = pp_payment_transaction_find_by_reference('binance', $prepayId);
        }
        if (!$transaction && $merchantTradeNo !== '' && preg_match('~PPB-(\d+)~', $merchantTradeNo, $m)) {
            $transaction = pp_payment_transaction_get((int)$m[1]);
        }
        if (!$transaction) {
            return ['ok' => false, 'status' => 404, 'error' => 'transaction_not_found'];
        }
        $bizStatus = strtoupper((string)($payload['bizStatus'] ?? ($data['status'] ?? '')));
        $successStatuses = ['PAY_SUCCESS', 'SUCCESS', 'PAID'];
        $failStatuses = ['PAY_FAIL', 'PAY_CLOSED', 'EXPIRED', 'FAIL'];
        if (in_array($bizStatus, $successStatuses, true)) {
            $amount = null;
            if (isset($data['orderAmount'])) {
                $amount = (float)$data['orderAmount'];
            } elseif (isset($data['totalFee']['amount'])) {
                $amount = (float)$data['totalFee']['amount'];
            }
            $result = pp_payment_transaction_mark_confirmed((int)$transaction['id'], $amount, $payload);
            if (!empty($result['ok'])) {
                return ['ok' => true, 'status' => 200];
            }
            return ['ok' => false, 'status' => 409, 'error' => $result['error'] ?? 'update_failed'];
        }
        if (in_array($bizStatus, $failStatuses, true)) {
            pp_payment_transaction_mark_failed((int)$transaction['id'], strtolower($bizStatus), $payload, $payload['message'] ?? null);
            return ['ok' => true, 'status' => 200];
        }
        return ['ok' => true, 'status' => 202];
    }
}
