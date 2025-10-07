<?php
// Payment gateways and transactions helper layer for PromoPilot

if (!defined('PP_ROOT_PATH')) {
    define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));
}

if (!function_exists('pp_payment_gateway_module_dir')) {
    function pp_payment_gateway_module_dir(): string {
        return __DIR__ . '/payments/gateways';
    }
}

if (!function_exists('pp_payment_gateway_modules')) {
    function pp_payment_gateway_modules(): array {
        static $modules = null;
        if ($modules !== null) {
            return $modules;
        }
        $modules = [];
        $dir = pp_payment_gateway_module_dir();
        if (is_dir($dir)) {
            $files = glob($dir . '/*.php') ?: [];
            foreach ($files as $file) {
                $code = strtolower((string)pathinfo($file, PATHINFO_FILENAME));
                if ($code === '') {
                    continue;
                }
                $modules[$code] = $file;
            }
        }
        return $modules;
    }
}

if (!function_exists('pp_payment_gateway_load_module')) {
    function pp_payment_gateway_load_module(?string $code = null): void {
        static $loaded = [];
        $modules = pp_payment_gateway_modules();
        if ($code === null) {
            foreach ($modules as $moduleCode => $file) {
                if (isset($loaded[$moduleCode])) {
                    continue;
                }
                if (is_readable($file)) {
                    require_once $file;
                    $loaded[$moduleCode] = true;
                }
            }
            return;
        }
        $code = strtolower(trim($code));
        if ($code === '' || !isset($modules[$code]) || isset($loaded[$code])) {
            return;
        }
        $file = $modules[$code];
        if (is_readable($file)) {
            require_once $file;
            $loaded[$code] = true;
        }
    }
}

pp_payment_gateway_load_module();

if (!function_exists('pp_payment_gateway_definitions')) {
    function pp_payment_gateway_definitions(): array {
        static $definitions = null;
        if ($definitions !== null) {
            return $definitions;
        }
        $definitions = [];
        pp_payment_gateway_load_module();
        foreach (pp_payment_gateway_modules() as $code => $_file) {
            $fn = 'pp_payment_gateway_' . $code . '_definition';
            if (function_exists($fn)) {
                $definition = $fn();
                if (is_array($definition)) {
                    $definition['code'] = $definition['code'] ?? $code;
                    $definitions[$code] = $definition;
                }
            }
        }
        return $definitions;
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
        $codeNormalized = strtolower(trim($code));
        if ($codeNormalized === 'manual') {
            return function_exists('__') ? __('Ручное изменение') : 'Ручное изменение';
        }
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
            'manual' => 'Ручное изменение',
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

if (!function_exists('pp_payment_transaction_set_status')) {
    function pp_payment_transaction_set_status(int $transactionId, string $status): array {
        $transactionId = (int)$transactionId;
        $status = strtolower(trim($status));
        $allowed = ['pending', 'awaiting_confirmation'];
        if ($transactionId <= 0 || !in_array($status, $allowed, true)) {
            return ['ok' => false, 'error' => 'status_not_supported'];
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
        $current = strtolower((string)$row['status']);
        if ($current === $status) {
            $conn->commit();
            $conn->close();
            return ['ok' => true, 'already' => true, 'status' => $status];
        }
        if ($current === 'confirmed') {
            $conn->commit();
            $conn->close();
            return ['ok' => false, 'error' => 'already_confirmed'];
        }
        $payloadStruct = pp_payment_json_decode($row['provider_payload'] ?? '', []);
        if (!isset($payloadStruct['events']) || !is_array($payloadStruct['events'])) {
            $payloadStruct['events'] = [];
        }
        $payloadStruct['events'][] = [
            'ts' => time(),
            'status' => $status,
            'trigger' => 'manual_admin_update',
        ];
        $payloadStruct['last_status'] = $status;
        $payloadJson = json_encode($payloadStruct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = null;
        }
        $stmt = $conn->prepare("UPDATE payment_transactions SET status = ?, provider_payload = ?, error_message = NULL, confirmed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            $conn->close();
            return ['ok' => false, 'error' => 'Update failed'];
        }
        $stmt->bind_param('ssi', $status, $payloadJson, $transactionId);
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
        if ($code === '') {
            $msg = function_exists('__') ? __('Платежная система недоступна.') : 'Gateway not supported';
            return ['ok' => false, 'error' => $msg];
        }
        pp_payment_gateway_load_module($code);
        $handler = 'pp_payment_gateway_initiate_' . $code;
        if (function_exists($handler)) {
            return $handler($gateway, $transaction, $options);
        }
        $msg = function_exists('__') ? __('Платежная система недоступна.') : 'Gateway not supported';
        return ['ok' => false, 'error' => $msg];
    }
}

if (!function_exists('pp_payment_handle_webhook')) {
    function pp_payment_handle_webhook(string $gatewayCode, array $payload, array $headers = [], string $rawBody = ''): array {
        $code = strtolower(trim($gatewayCode));
        if ($code === '') {
            return ['ok' => false, 'status' => 404, 'error' => 'unsupported_gateway'];
        }
        pp_payment_gateway_load_module($code);
        $handler = 'pp_payment_handle_' . $code . '_webhook';
        if (function_exists($handler)) {
            return $handler($payload, $headers, $rawBody);
        }
        return ['ok' => false, 'status' => 404, 'error' => 'unsupported_gateway'];
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

?>
