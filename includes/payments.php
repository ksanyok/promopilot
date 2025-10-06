<?php
// Payment gateways and transactions helper layer for PromoPilot

if (!defined('PP_ROOT_PATH')) {
    define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));
}

if (!function_exists('pp_payment_gateway_definitions')) {
    function pp_payment_gateway_definitions(): array {
        return [
            'monobank' => [
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
            ],
            'binance' => [
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
            ],
        ];
    }
}

if (!function_exists('pp_payment_json_decode')) {
    function pp_payment_json_decode(?string $json, $default = []) {
        if ($json === null || $json === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $default;
        }
        return $decoded;
    }
}

if (!function_exists('pp_payment_apply_gateway_defaults')) {
    function pp_payment_apply_gateway_defaults(string $code, array $gateway): array {
        $definitions = pp_payment_gateway_definitions();
        $def = $definitions[$code] ?? null;
        if ($def) {
            if (empty($gateway['title'])) {
                $gateway['title'] = $def['title'];
            }
            $gateway['currency'] = $gateway['currency'] ?? ($def['currency'] ?? 'UAH');
            $gateway['sort_order'] = isset($gateway['sort_order']) ? (int)$gateway['sort_order'] : (int)($def['sort_order'] ?? 0);
            $configDefaults = $def['config_defaults'] ?? [];
            $config = is_array($gateway['config'] ?? null) ? $gateway['config'] : [];
            $gateway['config'] = array_merge($configDefaults, $config);
        } else {
            $gateway['currency'] = $gateway['currency'] ?? 'UAH';
            $gateway['sort_order'] = isset($gateway['sort_order']) ? (int)$gateway['sort_order'] : 0;
            $gateway['config'] = is_array($gateway['config'] ?? null) ? $gateway['config'] : [];
        }
        $gateway['code'] = $code;
        $gateway['is_enabled'] = isset($gateway['is_enabled']) ? (int)$gateway['is_enabled'] : 0;
        return $gateway;
    }
}

if (!function_exists('pp_payment_gateway_webhook_url')) {
    function pp_payment_gateway_webhook_url(string $code): string {
        if (!function_exists('pp_url')) {
            return '';
        }
        $slug = strtolower(trim($code));
        return pp_url('public/payment_webhook.php?gateway=' . rawurlencode($slug));
    }
}

if (!function_exists('pp_payment_gateways')) {
    function pp_payment_gateways(bool $includeDisabled = false): array {
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return [];
        }
        if (!$conn) {
            return [];
        }
        $sql = 'SELECT id, code, title, is_enabled, config, instructions, sort_order, created_at, updated_at FROM payment_gateways';
        if (!$includeDisabled) {
            $sql .= ' WHERE is_enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, title ASC';
        $items = [];
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $code = strtolower((string)$row['code']);
                $gateway = [
                    'id' => (int)$row['id'],
                    'code' => $code,
                    'title' => (string)($row['title'] ?? ''),
                    'is_enabled' => (int)($row['is_enabled'] ?? 0),
                    'config' => pp_payment_json_decode($row['config'] ?? '', []),
                    'instructions' => (string)($row['instructions'] ?? ''),
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
                $items[$code] = pp_payment_apply_gateway_defaults($code, $gateway);
            }
            $res->free();
        }
        $conn->close();
        uasort($items, static function (array $a, array $b): int {
            $sa = $a['sort_order'] ?? 0;
            $sb = $b['sort_order'] ?? 0;
            if ($sa === $sb) {
                return strcmp((string)$a['title'], (string)$b['title']);
            }
            return $sa <=> $sb;
        });
        if (!$includeDisabled) {
            // remove disabled gateways just in case
            $items = array_filter($items, static fn($g) => !empty($g['is_enabled']));
        }
        return $items;
    }
}

if (!function_exists('pp_payment_gateway_get')) {
    function pp_payment_gateway_get(string $code, bool $includeDisabled = true): ?array {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        $gateways = pp_payment_gateways(true);
        if (!isset($gateways[$code])) {
            return null;
        }
        $gateway = $gateways[$code];
        if (!$includeDisabled && empty($gateway['is_enabled'])) {
            return null;
        }
        return $gateway;
    }
}

if (!function_exists('pp_payment_gateway_title')) {
    function pp_payment_gateway_title(string $code, ?string $fallback = null): string {
        $gateway = pp_payment_gateway_get($code);
        if ($gateway) {
            return (string)$gateway['title'];
        }
        $defs = pp_payment_gateway_definitions();
        if (isset($defs[$code]['title'])) {
            return (string)$defs[$code]['title'];
        }
        return $fallback ?? $code;
    }
}

if (!function_exists('pp_payment_gateway_currency')) {
    function pp_payment_gateway_currency(string $code): string {
        $gateway = pp_payment_gateway_get($code);
        $currency = $gateway['currency'] ?? '';
        $currency = strtoupper((string)$currency);
        if ($currency === '') {
            $currency = 'UAH';
        }
        return $currency;
    }
}

if (!function_exists('pp_payment_gateway_save')) {
    function pp_payment_gateway_save(string $code, array $data): bool {
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }
        $definitions = pp_payment_gateway_definitions();
        $def = $definitions[$code] ?? [];
        $title = trim((string)($data['title'] ?? ($def['title'] ?? $code)));
        if ($title === '') {
            $title = ucfirst($code);
        }
        $isEnabled = !empty($data['is_enabled']) ? 1 : 0;
        $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : (int)($def['sort_order'] ?? 0);
        $instructions = trim((string)($data['instructions'] ?? ''));
        $config = $data['config'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }
        $defaults = $def['config_defaults'] ?? [];
        $config = array_merge($defaults, $config);
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($configJson === false) {
            $configJson = '{}';
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return false;
        }
        if (!$conn) {
            return false;
        }
        $stmt = $conn->prepare("INSERT INTO payment_gateways (code, title, is_enabled, config, instructions, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE title = VALUES(title), is_enabled = VALUES(is_enabled), config = VALUES(config), instructions = VALUES(instructions), sort_order = VALUES(sort_order), updated_at = CURRENT_TIMESTAMP");
        if (!$stmt) {
            $conn->close();
            return false;
        }
        $stmt->bind_param('ssissi', $code, $title, $isEnabled, $configJson, $instructions, $sortOrder);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        return (bool)$ok;
    }
}

if (!function_exists('pp_payment_transactions_for_user')) {
    function pp_payment_transactions_for_user(int $userId, int $limit = 25, int $offset = 0): array {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return [];
        }
        if (!$conn) {
            return [];
        }
        $stmt = $conn->prepare("SELECT id, user_id, gateway_code, amount, currency, status, provider_reference, provider_payload, customer_payload, error_message, confirmed_at, created_at, updated_at FROM payment_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?");
        if (!$stmt) {
            $conn->close();
            return [];
        }
        $stmt->bind_param('iii', $userId, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['amount'] = (float)$row['amount'];
                $row['provider_payload'] = pp_payment_json_decode($row['provider_payload'] ?? '', []);
                $row['customer_payload'] = pp_payment_json_decode($row['customer_payload'] ?? '', []);
                $row['gateway_code'] = strtolower((string)$row['gateway_code']);
                $row['status'] = (string)$row['status'];
                $rows[] = $row;
            }
            $res->free();
        }
        $stmt->close();
        $conn->close();
        return $rows;
    }
}

if (!function_exists('pp_payment_transaction_get')) {
    function pp_payment_transaction_get(int $transactionId): ?array {
        $transactionId = (int)$transactionId;
        if ($transactionId <= 0) {
            return null;
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return null;
        }
        if (!$conn) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, user_id, gateway_code, amount, currency, status, provider_reference, provider_payload, customer_payload, error_message, confirmed_at, created_at, updated_at FROM payment_transactions WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $conn->close();
            return null;
        }
    $balanceEvent = null;
    $stmt->bind_param('i', $transactionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        $conn->close();
        if (!$row) {
            return null;
        }
        $row['amount'] = (float)$row['amount'];
        $row['provider_payload'] = pp_payment_json_decode($row['provider_payload'] ?? '', []);
        $row['customer_payload'] = pp_payment_json_decode($row['customer_payload'] ?? '', []);
        $row['gateway_code'] = strtolower((string)$row['gateway_code']);
        return $row;
    }
}

if (!function_exists('pp_payment_transaction_find_by_reference')) {
    function pp_payment_transaction_find_by_reference(string $gatewayCode, string $reference): ?array {
        $gatewayCode = strtolower(trim($gatewayCode));
        $reference = trim($reference);
        if ($gatewayCode === '' || $reference === '') {
            return null;
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return null;
        }
        if (!$conn) {
            return null;
        }
        $stmt = $conn->prepare("SELECT id, user_id, gateway_code, amount, currency, status, provider_reference, provider_payload, customer_payload, error_message, confirmed_at, created_at, updated_at FROM payment_transactions WHERE gateway_code = ? AND provider_reference = ? LIMIT 1");
        if (!$stmt) {
            $conn->close();
            return null;
        }
        $stmt->bind_param('ss', $gatewayCode, $reference);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        $conn->close();
        if (!$row) {
            return null;
        }
        $row['amount'] = (float)$row['amount'];
        $row['provider_payload'] = pp_payment_json_decode($row['provider_payload'] ?? '', []);
        $row['customer_payload'] = pp_payment_json_decode($row['customer_payload'] ?? '', []);
        $row['gateway_code'] = strtolower((string)$row['gateway_code']);
        return $row;
    }
}

if (!function_exists('pp_payment_transaction_status_label')) {
    function pp_payment_transaction_status_label(string $status): string {
        $statusKey = strtolower(trim($status));
        $map = [
            'pending' => 'Ожидает оплаты',
            'awaiting_confirmation' => 'Ожидает подтверждения',
            'confirmed' => 'Зачислено',
            'failed' => 'Ошибка',
            'cancelled' => 'Отменено',
            'canceled' => 'Отменено',
            'expired' => 'Просрочено',
        ];
        $label = $map[$statusKey] ?? ucfirst($statusKey);
        if (function_exists('__')) {
            return __($label);
        }
        return $label;
    }
}

if (!function_exists('pp_payment_transaction_mark_confirmed')) {
    function pp_payment_transaction_mark_confirmed(int $transactionId, ?float $amountOverride = null, array $providerPayload = []): array {
        $transactionId = (int)$transactionId;
        if ($transactionId <= 0) {
            return ['ok' => false, 'error' => 'Invalid transaction'];
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB connection failed'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB connection failed'];
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT id, user_id, amount, currency, status, provider_payload FROM payment_transactions WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Statement failed'];
        }
        $stmt->bind_param('i', $transactionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        if (!$row) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Transaction not found'];
        }
        if ((string)$row['status'] === 'confirmed') {
            $conn->commit();
            $conn->close();
            return ['ok' => true, 'already' => true, 'transaction' => $row];
        }
        $creditAmount = $amountOverride !== null ? round((float)$amountOverride, 2) : (float)$row['amount'];
        if ($creditAmount <= 0) {
            $creditAmount = (float)$row['amount'];
        }
        $payloadStruct = pp_payment_json_decode($row['provider_payload'] ?? '', []);
        if (!isset($payloadStruct['events']) || !is_array($payloadStruct['events'])) {
            $payloadStruct['events'] = [];
        }
        if (!empty($providerPayload)) {
            $payloadStruct['events'][] = [
                'ts' => time(),
                'status' => 'confirmed',
                'data' => $providerPayload,
            ];
        }
        $payloadStruct['last_status'] = 'confirmed';
        $payloadStruct['confirmed_at'] = date('c');
        $payloadJson = json_encode($payloadStruct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = null;
        }
        $status = 'confirmed';
        $stmt = $conn->prepare("UPDATE payment_transactions SET status = ?, provider_payload = ?, error_message = NULL, confirmed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Update failed'];
        }
        $stmt->bind_param('ssi', $status, $payloadJson, $transactionId);
        $stmt->execute();
        $stmt->close();
        $userStmt = $conn->prepare("SELECT id, balance FROM users WHERE id = ? FOR UPDATE");
        if (!$userStmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Balance lock failed'];
        }
        $userStmt->bind_param('i', $row['user_id']);
        if (!$userStmt->execute()) {
            $userStmt->close();
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Balance read failed'];
        }
        $userRes = $userStmt->get_result();
        $userRow = $userRes ? $userRes->fetch_assoc() : null;
        if ($userRes) {
            $userRes->free();
        }
        $userStmt->close();
        if (!$userRow) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'User missing'];
        }
        $balanceBefore = (float)$userRow['balance'];
        $balanceAfter = round($balanceBefore + $creditAmount, 2);
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Balance update failed'];
        }
        $userId = (int)$row['user_id'];
        $stmt->bind_param('di', $creditAmount, $userId);
        $stmt->execute();
        $stmt->close();
        $balanceEvent = pp_balance_record_event($conn, [
            'user_id' => $userId,
            'delta' => $creditAmount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'source' => 'payment',
            'meta' => [
                'transaction_id' => $transactionId,
                'gateway_code' => (string)$row['gateway_code'],
                'currency' => (string)$row['currency'],
                'amount_original' => (float)$row['amount'],
                'provider_reference' => (string)($row['provider_reference'] ?? ''),
            ],
        ]);
        $conn->commit();
        $conn->close();
        $row['status'] = $status;
        $row['provider_payload'] = $payloadStruct;
        $row['confirmed_amount'] = $creditAmount;
        if (!empty($balanceEvent)) {
            pp_balance_send_event_notification($balanceEvent);
        }
        return ['ok' => true, 'already' => false, 'transaction' => $row];
    }
}

if (!function_exists('pp_payment_transaction_mark_failed')) {
    function pp_payment_transaction_mark_failed(int $transactionId, string $status, array $providerPayload = [], ?string $errorMessage = null): array {
        $transactionId = (int)$transactionId;
        $status = strtolower(trim($status));
        $allowed = ['failed', 'cancelled', 'canceled', 'expired'];
        if (!in_array($status, $allowed, true)) {
            $status = 'failed';
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB connection failed'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'DB connection failed'];
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT id, status, provider_payload FROM payment_transactions WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Statement failed'];
        }
        $stmt->bind_param('i', $transactionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
        if (!$row) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Transaction not found'];
        }
        if ((string)$row['status'] === 'confirmed') {
            $conn->commit();
            $conn->close();
            return ['ok' => true, 'already_confirmed' => true];
        }
        $payloadStruct = pp_payment_json_decode($row['provider_payload'] ?? '', []);
        if (!isset($payloadStruct['events']) || !is_array($payloadStruct['events'])) {
            $payloadStruct['events'] = [];
        }
        if (!empty($providerPayload)) {
            $payloadStruct['events'][] = [
                'ts' => time(),
                'status' => $status,
                'data' => $providerPayload,
            ];
        }
        $payloadStruct['last_status'] = $status;
        if ($errorMessage !== null) {
            $payloadStruct['error_message'] = $errorMessage;
        }
        $payloadJson = json_encode($payloadStruct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = null;
        }
        $stmt = $conn->prepare("UPDATE payment_transactions SET status = ?, provider_payload = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Update failed'];
        }
        $err = $errorMessage !== null ? trim($errorMessage) : null;
        $stmt->bind_param('sssi', $status, $payloadJson, $err, $transactionId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $conn->close();
        return ['ok' => true, 'status' => $status];
    }
}

if (!function_exists('pp_payment_transaction_create')) {
    function pp_payment_transaction_create(int $userId, string $gatewayCode, float $amount, array $options = []): array {
        $userId = (int)$userId;
        $gatewayCode = strtolower(trim($gatewayCode));
        $amount = round((float)$amount, 2);
        if ($userId <= 0 || $gatewayCode === '') {
            return ['ok' => false, 'error' => 'invalid_request'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'amount_too_small'];
        }
        $gateway = pp_payment_gateway_get($gatewayCode, false);
        if (!$gateway || empty($gateway['is_enabled'])) {
            return ['ok' => false, 'error' => 'gateway_disabled'];
        }
        $currency = strtoupper((string)($options['currency'] ?? ($gateway['currency'] ?? pp_payment_gateway_currency($gatewayCode))));
        if ($currency === '') {
            $currency = $gatewayCode === 'binance' ? 'USDT' : 'UAH';
        }
        try {
            $conn = connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_unavailable'];
        }
        if (!$conn) {
            return ['ok' => false, 'error' => 'db_unavailable'];
        }
        $status = 'pending';
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO payment_transactions (user_id, gateway_code, amount, currency, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'db_write_failed'];
        }
        $stmt->bind_param('isdss', $userId, $gatewayCode, $amount, $currency, $status);
        $stmt->execute();
        $transactionId = (int)$stmt->insert_id;
        $stmt->close();
        if ($transactionId <= 0) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'db_write_failed'];
        }
        $transaction = [
            'id' => $transactionId,
            'user_id' => $userId,
            'gateway_code' => $gatewayCode,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
        ];
        $initResult = pp_payment_gateway_initiate_transaction($gateway, $transaction, $options);
        if (empty($initResult['ok'])) {
            $failStatus = $initResult['status'] ?? 'failed';
            $errorMessage = (string)($initResult['error'] ?? 'Gateway error');
            $stmt = $conn->prepare("UPDATE payment_transactions SET status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $failStatus, $errorMessage, $transactionId);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            $conn->close();
            return ['ok' => false, 'error' => $initResult['error'] ?? 'gateway_failed'];
        }
        $newStatus = $initResult['status'] ?? 'awaiting_confirmation';
        $providerReference = $initResult['provider_reference'] ?? null;
        $providerPayloadStruct = [
            'initial' => $initResult['provider_payload'] ?? $initResult,
            'events' => [
                [
                    'ts' => time(),
                    'status' => $newStatus,
                    'data' => $initResult['provider_payload'] ?? $initResult,
                ],
            ],
            'last_status' => $newStatus,
        ];
        $customerPayload = $initResult['customer_payload'] ?? [];
        if (!is_array($customerPayload)) {
            $customerPayload = [];
        }
        $customerPayload['payment_url'] = $customerPayload['payment_url'] ?? ($initResult['payment_url'] ?? null);
        $customerPayload['qr_content'] = $customerPayload['qr_content'] ?? ($initResult['payment_qr'] ?? null);
        $providerPayloadJson = json_encode($providerPayloadStruct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($providerPayloadJson === false) {
            $providerPayloadJson = '{}';
        }
        $customerPayloadJson = json_encode($customerPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($customerPayloadJson === false) {
            $customerPayloadJson = '{}';
        }
        $stmt = $conn->prepare("UPDATE payment_transactions SET status = ?, provider_reference = ?, provider_payload = ?, customer_payload = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ssssi', $newStatus, $providerReference, $providerPayloadJson, $customerPayloadJson, $transactionId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        $conn->close();
        $transaction = pp_payment_transaction_get($transactionId);
        return [
            'ok' => true,
            'transaction_id' => $transactionId,
            'status' => $newStatus,
            'provider_reference' => $providerReference,
            'payment_url' => $initResult['payment_url'] ?? ($customerPayload['payment_url'] ?? null),
            'payment_qr' => $initResult['payment_qr'] ?? ($customerPayload['qr_content'] ?? null),
            'customer_payload' => $customerPayload,
            'transaction' => $transaction,
        ];
    }
}

if (!function_exists('pp_payment_gateway_initiate_transaction')) {
    function pp_payment_gateway_initiate_transaction(array $gateway, array $transaction, array $options = []): array {
        $code = strtolower((string)($gateway['code'] ?? ''));
        switch ($code) {
            case 'monobank':
                return pp_payment_gateway_initiate_monobank($gateway, $transaction, $options);
            case 'binance':
                return pp_payment_gateway_initiate_binance($gateway, $transaction, $options);
            default:
                $msg = function_exists('__') ? __('Платежная система недоступна.') : 'Gateway not supported';
                return ['ok' => false, 'error' => $msg];
        }
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

if (!function_exists('pp_payment_handle_webhook')) {
    function pp_payment_handle_webhook(string $gatewayCode, array $payload, array $headers = [], string $rawBody = ''): array {
        $code = strtolower(trim($gatewayCode));
        switch ($code) {
            case 'monobank':
                return pp_payment_handle_monobank_webhook($payload, $headers, $rawBody);
            case 'binance':
                return pp_payment_handle_binance_webhook($payload, $headers, $rawBody);
            default:
                return ['ok' => false, 'status' => 404, 'error' => 'unsupported_gateway'];
        }
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

?>
