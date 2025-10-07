<?php
// MetaMask / EVM Wallet (USDT) gateway: accepts USDT via EVM networks and auto-confirms using Etherscan/BscScan

if (!function_exists('pp_payment_gateway_metamask_definition')) {
    function pp_payment_gateway_metamask_definition(): array {
        return [
            'code' => 'metamask',
            'title' => 'MetaMask / EVM (USDT)',
            'currency' => 'USDT',
            'sort_order' => 30,
            'config_defaults' => [
                'network' => 'BSC', // ETH or BSC
                'recipient_address' => '',
                'api_key' => '', // Etherscan or BscScan API key for auto-confirm
                'usdt_contract_eth' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                'usdt_contract_bsc' => '0x55d398326f99059fF775485246999027B3197955',
                'enable_unique_amount' => 1,
            ],
        ];
    }
}

if (!function_exists('pp_payment_gateway_initiate_metamask')) {
    function pp_payment_gateway_initiate_metamask(array $gateway, array $transaction, array $options = []): array {
        $cfg = $gateway['config'] ?? [];
        $network = strtoupper(trim((string)($cfg['network'] ?? 'BSC')));
        if (!in_array($network, ['ETH', 'BSC'], true)) {
            $network = 'BSC';
        }
        $address = trim((string)($cfg['recipient_address'] ?? ''));
        if ($address === '') {
            $msg = function_exists('__') ? __('Укажите адрес кошелька для приёма USDT.') : 'Recipient wallet address required';
            return ['ok' => false, 'error' => $msg, 'status' => 'failed'];
        }
        $amountBase = round((float)$transaction['amount'], 2);
        if ($amountBase <= 0) {
            return ['ok' => false, 'error' => 'invalid_amount', 'status' => 'failed'];
        }
        $uniqueAmount = $amountBase;
        if (!empty($cfg['enable_unique_amount'])) {
            $delta = ((int)$transaction['id'] % 97) + 1; // 1..97
            $delta = $delta / 1000000.0; // up to 0.000097
            $uniqueAmount = round($amountBase + $delta, 6);
        }
        $customerPayload = [
            'wallet_address' => $address,
            'network' => $network,
            'currency' => 'USDT',
            'amount' => number_format($uniqueAmount, 6, '.', ''),
            'message' => function_exists('__') ? __('Оплатите ТOЧНО указанную сумму USDT на адрес ниже через MetaMask. Сеть: EVM (ETH/BSC).') : 'Send the EXACT USDT amount to the address below via MetaMask. Network: ETH/BSC.',
            'auto_confirmation' => !empty($cfg['api_key']),
        ];
        return [
            'ok' => true,
            'status' => 'awaiting_confirmation',
            'provider_reference' => null,
            'payment_url' => null,
            'provider_payload' => [
                'network' => $network,
                'recipient_address' => $address,
                'expected_amount' => $uniqueAmount,
                'expected_amount_fmt' => number_format($uniqueAmount, 6, '.', ''),
                'enable_unique_amount' => !empty($cfg['enable_unique_amount']),
            ],
            'customer_payload' => $customerPayload,
        ];
    }
}

// --- Etherscan / BscScan helpers ---
if (!function_exists('pp_payment_metamask_scan_base')) {
    function pp_payment_metamask_scan_base(string $network): string {
        return ($network === 'ETH') ? 'https://api.etherscan.io/api' : 'https://api.bscscan.com/api';
    }
}

if (!function_exists('pp_payment_metamask_get_usdt_contract')) {
    function pp_payment_metamask_get_usdt_contract(array $cfg, string $network): string {
        if ($network === 'ETH') {
            return (string)($cfg['usdt_contract_eth'] ?? '0xdAC17F958D2ee523a2206206994597C13D831ec7');
        }
        return (string)($cfg['usdt_contract_bsc'] ?? '0x55d398326f99059fF775485246999027B3197955');
    }
}

if (!function_exists('pp_payment_metamask_scan_request')) {
    function pp_payment_metamask_scan_request(string $url, array $params): array {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $full = $url . (strpos($url, '?') === false ? '?' : '&') . $query;
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
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
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'http_' . $code, 'body' => $body, 'decoded' => $decoded];
        }
        return ['ok' => true, 'decoded' => is_array($decoded) ? $decoded : []];
    }
}

if (!function_exists('pp_payment_metamask_refresh_transaction')) {
    function pp_payment_metamask_refresh_transaction(int $transactionId, array $options = []): array {
        $txId = (int)$transactionId;
        if ($txId <= 0) return ['ok' => false, 'error' => 'invalid_transaction'];
        $tx = pp_payment_transaction_get($txId);
        if (!$tx) return ['ok' => false, 'error' => 'not_found'];
        if (strtolower((string)$tx['gateway_code']) !== 'metamask') {
            return ['ok' => false, 'error' => 'gateway_mismatch', 'transaction' => $tx];
        }
        if (strtolower((string)$tx['status']) === 'confirmed') {
            return ['ok' => true, 'status' => 'confirmed', 'status_changed' => false, 'already' => true, 'transaction' => $tx];
        }
        $gateway = pp_payment_gateway_get('metamask');
        if (!$gateway || empty($gateway['is_enabled'])) return ['ok' => false, 'error' => 'gateway_disabled', 'transaction' => $tx];
        $cfg = $gateway['config'] ?? [];
        $apiKey = trim((string)($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            // No auto-confirm without API key
            return ['ok' => true, 'status' => (string)$tx['status'] ?: 'pending', 'status_changed' => false, 'transaction' => $tx, 'auto' => false];
        }
        $providerPayload = is_array($tx['provider_payload'] ?? null) ? $tx['provider_payload'] : [];
        $network = strtoupper((string)($providerPayload['network'] ?? ($cfg['network'] ?? 'BSC')));
        if (!in_array($network, ['ETH', 'BSC'], true)) $network = 'BSC';
        $address = (string)($providerPayload['recipient_address'] ?? ($cfg['recipient_address'] ?? ''));
        $expected = isset($providerPayload['expected_amount']) ? (float)$providerPayload['expected_amount'] : (float)$tx['amount'];
        if ($address === '') return ['ok' => false, 'error' => 'address_missing', 'transaction' => $tx];
        $contract = pp_payment_metamask_get_usdt_contract($cfg, $network);
        $baseUrl = pp_payment_metamask_scan_base($network);
        $createdAt = isset($tx['created_at']) ? strtotime((string)$tx['created_at']) : (time() - 86400);
        if ($createdAt === false) $createdAt = time() - 86400;
        // Fetch recent token txs
        $res = pp_payment_metamask_scan_request($baseUrl, [
            'module' => 'account',
            'action' => 'tokentx',
            'contractaddress' => $contract,
            'address' => $address,
            'page' => 1,
            'offset' => 50,
            'startblock' => 0,
            'endblock' => 99999999,
            'sort' => 'desc',
            'apikey' => $apiKey,
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'scan_failed', 'transaction' => $tx];
        }
        $data = $res['decoded'];
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $match = null;
        foreach ($result as $item) {
            if (!is_array($item)) continue;
            $to = strtolower((string)($item['to'] ?? ''));
            if ($to !== strtolower($address)) continue;
            $ts = isset($item['timeStamp']) ? (int)$item['timeStamp'] : 0;
            if ($ts > 0 && $ts < $createdAt) continue;
            $decimals = isset($item['tokenDecimal']) ? (int)$item['tokenDecimal'] : 6;
            $valueRaw = (string)($item['value'] ?? '0');
            // Convert big integer string to float based on decimals
            $valueFloat = 0.0;
            if (ctype_digit($valueRaw)) {
                if ($decimals <= 0) { $valueFloat = (float)$valueRaw; }
                else {
                    $valueFloat = (float)bcdiv($valueRaw, bcpow('10', (string)$decimals, 18), 18);
                }
            } else {
                $valueFloat = (float)$valueRaw;
            }
            // Compare within tiny epsilon
            if (abs($valueFloat - $expected) < 0.000001) {
                $match = $item; break;
            }
        }
        if ($match) {
            $event = [
                'source' => 'evm_scan_poll',
                'network' => $network,
                'contract' => $contract,
                'recipient' => $address,
                'expected' => $expected,
                'matched' => $match,
            ];
            $mark = pp_payment_transaction_mark_confirmed($txId, null, $event);
            if (!empty($mark['ok'])) {
                return [
                    'ok' => true,
                    'status' => 'confirmed',
                    'status_changed' => empty($mark['already']),
                    'already' => !empty($mark['already']),
                    'transaction' => $mark['transaction'] ?? $tx,
                ];
            }
            return ['ok' => false, 'error' => $mark['error'] ?? 'confirm_failed', 'transaction' => $tx];
        }
        return ['ok' => true, 'status' => 'pending', 'status_changed' => false, 'transaction' => $tx];
    }
}

if (!function_exists('pp_payment_metamask_refresh_pending_for_user')) {
    function pp_payment_metamask_refresh_pending_for_user(int $userId, ?int $includeTransactionId = null, int $limit = 5): array {
        $userId = (int)$userId;
        $limit = max(1, min(20, (int)$limit));
        $ids = [];
        if ($userId > 0) {
            try { $conn = connect_db(); } catch (Throwable $e) { $conn = null; }
            if ($conn) {
                $stmt = $conn->prepare("SELECT id FROM payment_transactions WHERE user_id = ? AND gateway_code = 'metamask' AND status IN ('pending','awaiting_confirmation') ORDER BY id DESC LIMIT ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $limit);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        if ($res) { while ($row = $res->fetch_assoc()) { $ids[] = (int)$row['id']; } $res->free(); }
                    }
                    $stmt->close();
                }
                $conn->close();
            }
        }
        if ($includeTransactionId !== null && $includeTransactionId > 0) { $ids[] = (int)$includeTransactionId; }
        $ids = array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
        $results = [];
        foreach ($ids as $id) { $results[$id] = pp_payment_metamask_refresh_transaction($id, ['expected_user_id' => $userId]); }
        return ['ok' => true, 'results' => $results];
    }
}

?>