<?php
// Crypto (USDT Multi-Network) gateway: TRC20 / ERC20 / BEP20 with auto-confirm via TronScan / Etherscan / BscScan

if (!function_exists('pp_payment_gateway_crypto_universal_definition')) {
    function pp_payment_gateway_crypto_universal_definition(): array {
        return [
            'code' => 'crypto_universal',
            'title' => 'Crypto (USDT Multi-Network)',
            'currency' => 'USDT',
            'sort_order' => 25,
            'config_defaults' => [
                'usdt_trc20_address' => '',
                'usdt_erc20_address' => '',
                'usdt_bep20_address' => '',
                'etherscan_api_key' => '',
                'bscscan_api_key' => '',
                'enable_unique_amount' => 1,
            ],
        ];
    }
}

if (!function_exists('pp_payment_gateway_initiate_crypto_universal')) {
    function pp_payment_gateway_initiate_crypto_universal(array $gateway, array $transaction, array $options = []): array {
        $cfg = $gateway['config'] ?? [];
        $trc = trim((string)($cfg['usdt_trc20_address'] ?? ''));
        $erc = trim((string)($cfg['usdt_erc20_address'] ?? ''));
        $bep = trim((string)($cfg['usdt_bep20_address'] ?? ''));
        if ($trc === '' && $erc === '' && $bep === '') {
            $msg = function_exists('__') ? __('Укажите хотя бы один адрес для приёма USDT (TRC20/ETH/BSC).') : 'Provide at least one USDT address.';
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
        $networks = [];
        if ($trc !== '') $networks[] = ['network' => 'TRC20', 'address' => $trc];
        if ($erc !== '') $networks[] = ['network' => 'ERC20', 'address' => $erc];
        if ($bep !== '') $networks[] = ['network' => 'BEP20', 'address' => $bep];
        $customerPayload = [
            'currency' => 'USDT',
            'amount' => number_format($uniqueAmount, 6, '.', ''),
            'message' => function_exists('__') ? __('Оплатите ТOЧНО указанную сумму USDT на любой из адресов ниже. Поддерживаются сети: TRC20, ERC20, BEP20.') : 'Send the EXACT USDT amount to any address below (TRC20/ERC20/BEP20).',
            'networks' => $networks,
        ];
        return [
            'ok' => true,
            'status' => 'awaiting_confirmation',
            'provider_reference' => null,
            'payment_url' => null,
            'provider_payload' => [
                'expected_amount' => $uniqueAmount,
                'expected_amount_fmt' => number_format($uniqueAmount, 6, '.', ''),
                'addresses' => [
                    'TRC20' => $trc,
                    'ERC20' => $erc,
                    'BEP20' => $bep,
                ],
            ],
            'customer_payload' => $customerPayload,
        ];
    }
}

// --- Helpers ---
if (!function_exists('pp_crypto_http_get')) {
    function pp_crypto_http_get(string $url, array $params): array {
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

if (!function_exists('pp_crypto_str_amount_to_float')) {
    function pp_crypto_str_amount_to_float(string $valueRaw, int $decimals): float {
        $valueRaw = ltrim($valueRaw, '+');
        if ($decimals <= 0) return (float)$valueRaw;
        $len = strlen($valueRaw);
        if (!ctype_digit($valueRaw)) return (float)$valueRaw;
        if ($len <= $decimals) {
            $zeros = str_repeat('0', $decimals - $len);
            $s = '0.' . $zeros . $valueRaw;
            return (float)$s;
        }
        $intPart = substr($valueRaw, 0, $len - $decimals);
        $fracPart = substr($valueRaw, -$decimals);
        return (float)($intPart . '.' . $fracPart);
    }
}

// EVM USDT contracts
if (!function_exists('pp_crypto_usdt_contract')) {
    function pp_crypto_usdt_contract(string $network): string {
        switch (strtoupper($network)) {
            case 'ERC20': return '0xdAC17F958D2ee523a2206206994597C13D831ec7';
            case 'BEP20': return '0x55d398326f99059fF775485246999027B3197955';
        }
        return '';
    }
}

if (!function_exists('pp_crypto_check_evm_deposit')) {
    function pp_crypto_check_evm_deposit(string $network, string $address, float $expected, string $apiKey, int $createdAt): array {
        $base = $network === 'ERC20' ? 'https://api.etherscan.io/api' : 'https://api.bscscan.com/api';
        $contract = pp_crypto_usdt_contract($network);
        if ($contract === '') return ['ok' => false, 'error' => 'network_not_supported'];
        $res = pp_crypto_http_get($base, [
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
        if (empty($res['ok'])) return $res;
        $result = is_array($res['decoded']['result'] ?? null) ? $res['decoded']['result'] : [];
        foreach ($result as $item) {
            if (!is_array($item)) continue;
            $to = strtolower((string)($item['to'] ?? ''));
            if ($to !== strtolower($address)) continue;
            $ts = isset($item['timeStamp']) ? (int)$item['timeStamp'] : 0;
            if ($ts > 0 && $ts < $createdAt) continue;
            $decimals = isset($item['tokenDecimal']) ? (int)$item['tokenDecimal'] : 6;
            $valueRaw = (string)($item['value'] ?? '0');
            $value = pp_crypto_str_amount_to_float($valueRaw, $decimals);
            if (abs($value - $expected) < 0.000001) {
                return ['ok' => true, 'match' => $item];
            }
        }
        return ['ok' => true, 'match' => null];
    }
}

// TronScan TRC20 check
if (!function_exists('pp_crypto_check_trc20_deposit')) {
    function pp_crypto_check_trc20_deposit(string $address, float $expected, int $createdAt): array {
        $url = 'https://apilist.tronscanapi.com/api/token_trc20/transfers';
        $res = pp_crypto_http_get($url, [
            'limit' => 50,
            'start' => 0,
            'sort' => 'desc',
            'count' => true,
            'contract_address' => 'Tether USD', // TronScan sometimes allows contract name; fallback filter by symbol and recipient
            'toAddress' => $address,
        ]);
        if (empty($res['ok'])) return $res;
        $data = $res['decoded'];
        $list = [];
        if (isset($data['token_transfers']) && is_array($data['token_transfers'])) {
            $list = $data['token_transfers'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $list = $data['data'];
        }
        foreach ($list as $it) {
            if (!is_array($it)) continue;
            $to = strtolower((string)($it['to_address'] ?? ($it['toAddress'] ?? '')));
            if ($to !== strtolower($address)) continue;
            $symbol = strtoupper((string)($it['token_symbol'] ?? ($it['tokenName'] ?? '')));
            if ($symbol !== '' && $symbol !== 'USDT') continue;
            $ts = isset($it['block_ts']) ? (int)($it['block_ts']/1000) : (isset($it['timestamp']) ? (int)($it['timestamp']/1000) : 0);
            if ($ts > 0 && $ts < $createdAt) continue;
            $decimals = isset($it['token_decimal']) ? (int)$it['token_decimal'] : (isset($it['decimals']) ? (int)$it['decimals'] : 6);
            $valueRaw = (string)($it['amount_str'] ?? ($it['value'] ?? ($it['quant'] ?? '0')));
            $value = pp_crypto_str_amount_to_float($valueRaw, $decimals);
            if (abs($value - $expected) < 0.000001) {
                return ['ok' => true, 'match' => $it];
            }
        }
        return ['ok' => true, 'match' => null];
    }
}

if (!function_exists('pp_payment_crypto_universal_refresh_transaction')) {
    function pp_payment_crypto_universal_refresh_transaction(int $transactionId, array $options = []): array {
        $txId = (int)$transactionId;
        if ($txId <= 0) return ['ok' => false, 'error' => 'invalid_transaction'];
        $tx = pp_payment_transaction_get($txId);
        if (!$tx) return ['ok' => false, 'error' => 'not_found'];
        if (strtolower((string)$tx['gateway_code']) !== 'crypto_universal') {
            return ['ok' => false, 'error' => 'gateway_mismatch', 'transaction' => $tx];
        }
        if (strtolower((string)$tx['status']) === 'confirmed') {
            return ['ok' => true, 'status' => 'confirmed', 'status_changed' => false, 'already' => true, 'transaction' => $tx];
        }
        $gateway = pp_payment_gateway_get('crypto_universal');
        if (!$gateway || empty($gateway['is_enabled'])) return ['ok' => false, 'error' => 'gateway_disabled', 'transaction' => $tx];
        $cfg = $gateway['config'] ?? [];
        $providerPayload = is_array($tx['provider_payload'] ?? null) ? $tx['provider_payload'] : [];
        $expected = isset($providerPayload['expected_amount']) ? (float)$providerPayload['expected_amount'] : (float)$tx['amount'];
        $addresses = is_array($providerPayload['addresses'] ?? null) ? $providerPayload['addresses'] : [
            'TRC20' => (string)($cfg['usdt_trc20_address'] ?? ''),
            'ERC20' => (string)($cfg['usdt_erc20_address'] ?? ''),
            'BEP20' => (string)($cfg['usdt_bep20_address'] ?? ''),
        ];
        $createdAt = isset($tx['created_at']) ? strtotime((string)$tx['created_at']) : (time() - 86400);
        if ($createdAt === false) $createdAt = time() - 86400;
        // Try TRC20
        if (!empty($addresses['TRC20'])) {
            $tr = pp_crypto_check_trc20_deposit((string)$addresses['TRC20'], $expected, $createdAt);
            if (!empty($tr['ok']) && !empty($tr['match'])) {
                $event = ['source' => 'trc20_scan', 'match' => $tr['match'], 'address' => $addresses['TRC20']];
                $mark = pp_payment_transaction_mark_confirmed($txId, null, $event);
                if (!empty($mark['ok'])) return ['ok' => true, 'status' => 'confirmed', 'status_changed' => empty($mark['already']), 'transaction' => $mark['transaction'] ?? $tx];
                return ['ok' => false, 'error' => $mark['error'] ?? 'confirm_failed', 'transaction' => $tx];
            }
        }
        // Try ERC20
        if (!empty($addresses['ERC20'])) {
            $er = pp_crypto_check_evm_deposit('ERC20', (string)$addresses['ERC20'], $expected, (string)($cfg['etherscan_api_key'] ?? ''), $createdAt);
            if (!empty($er['ok']) && !empty($er['match'])) {
                $event = ['source' => 'erc20_scan', 'match' => $er['match'], 'address' => $addresses['ERC20']];
                $mark = pp_payment_transaction_mark_confirmed($txId, null, $event);
                if (!empty($mark['ok'])) return ['ok' => true, 'status' => 'confirmed', 'status_changed' => empty($mark['already']), 'transaction' => $mark['transaction'] ?? $tx];
                return ['ok' => false, 'error' => $mark['error'] ?? 'confirm_failed', 'transaction' => $tx];
            }
        }
        // Try BEP20
        if (!empty($addresses['BEP20'])) {
            $bs = pp_crypto_check_evm_deposit('BEP20', (string)$addresses['BEP20'], $expected, (string)($cfg['bscscan_api_key'] ?? ''), $createdAt);
            if (!empty($bs['ok']) && !empty($bs['match'])) {
                $event = ['source' => 'bep20_scan', 'match' => $bs['match'], 'address' => $addresses['BEP20']];
                $mark = pp_payment_transaction_mark_confirmed($txId, null, $event);
                if (!empty($mark['ok'])) return ['ok' => true, 'status' => 'confirmed', 'status_changed' => empty($mark['already']), 'transaction' => $mark['transaction'] ?? $tx];
                return ['ok' => false, 'error' => $mark['error'] ?? 'confirm_failed', 'transaction' => $tx];
            }
        }
        return ['ok' => true, 'status' => 'pending', 'status_changed' => false, 'transaction' => $tx];
    }
}

if (!function_exists('pp_payment_crypto_universal_refresh_pending_for_user')) {
    function pp_payment_crypto_universal_refresh_pending_for_user(int $userId, ?int $includeTransactionId = null, int $limit = 5): array {
        $userId = (int)$userId;
        $limit = max(1, min(20, (int)$limit));
        $ids = [];
        if ($userId > 0) {
            try { $conn = connect_db(); } catch (Throwable $e) { $conn = null; }
            if ($conn) {
                $stmt = $conn->prepare("SELECT id FROM payment_transactions WHERE user_id = ? AND gateway_code = 'crypto_universal' AND status IN ('pending','awaiting_confirmation') ORDER BY id DESC LIMIT ?");
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
        foreach ($ids as $id) { $results[$id] = pp_payment_crypto_universal_refresh_transaction($id, ['expected_user_id' => $userId]); }
        return ['ok' => true, 'results' => $results];
    }
}

?>