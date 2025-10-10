<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

if (!function_exists('pp_admin_bind_params')) {
    function pp_admin_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
        if ($types === '') {
            if (!empty($params)) {
                $message = sprintf('bind_param mismatch in pp_admin_bind_params: empty types string with %d params', count($params));
                error_log($message);
                throw new InvalidArgumentException($message);
            }
            return;
        }
        pp_stmt_bind_safe_array($stmt, $types, $params);
    }
}

$messages = ['success' => [], 'error' => []];

$gateways = pp_payment_gateways(true);

$allowedStatuses = ['all', 'pending', 'awaiting_confirmation', 'confirmed', 'failed', 'cancelled', 'canceled', 'expired', 'manual'];
$allowedTypes = ['all', 'payments', 'manual'];

$gatewayFilterRaw = strtolower(trim((string)($_GET['gateway'] ?? 'all')));
$statusFilterRaw = strtolower(trim((string)($_GET['status'] ?? 'all')));
$typeFilterRaw = strtolower(trim((string)($_GET['type'] ?? 'all')));

$gatewayCodes = array_map('strtolower', array_keys($gateways));
if ($gatewayFilterRaw !== '' && $gatewayFilterRaw !== 'all' && $gatewayFilterRaw !== 'manual' && !in_array($gatewayFilterRaw, $gatewayCodes, true)) {
    $gatewayFilterRaw = 'all';
}
if (!in_array($statusFilterRaw, $allowedStatuses, true)) {
    $statusFilterRaw = 'all';
}
if (!in_array($typeFilterRaw, $allowedTypes, true)) {
    $typeFilterRaw = 'all';
}

$userQuery = trim((string)($_GET['user'] ?? ''));
$referenceQuery = trim((string)($_GET['reference'] ?? ''));

$dateFromRaw = trim((string)($_GET['date_from'] ?? ''));
$dateToRaw = trim((string)($_GET['date_to'] ?? ''));
$dateFromSql = null;
$dateToSql = null;
$dateFromValue = '';
$dateToValue = '';
if ($dateFromRaw !== '') {
    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFromRaw);
    if ($fromDate) {
        $dateFromSql = $fromDate->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $dateFromValue = $fromDate->format('Y-m-d');
    }
}
if ($dateToRaw !== '') {
    $toDate = DateTime::createFromFormat('Y-m-d', $dateToRaw);
    if ($toDate) {
        $dateToSql = $toDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        $dateToValue = $toDate->format('Y-m-d');
    }
}

$amountMinRaw = str_replace(',', '.', trim((string)($_GET['amount_min'] ?? '')));
$amountMaxRaw = str_replace(',', '.', trim((string)($_GET['amount_max'] ?? '')));
$amountMin = is_numeric($amountMinRaw) ? round((float)$amountMinRaw, 2) : null;
$amountMax = is_numeric($amountMaxRaw) ? round((float)$amountMaxRaw, 2) : null;

$gatewayFilter = $gatewayFilterRaw === '' ? 'all' : $gatewayFilterRaw;
$statusFilter = $statusFilterRaw === '' ? 'all' : $statusFilterRaw;
$typeFilter = $typeFilterRaw === '' ? 'all' : $typeFilterRaw;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action'])) {
        if (!verify_csrf()) {
            $messages['error'][] = __('Не удалось выполнить действие (CSRF).');
        } else {
            $bulkAction = (string)($_POST['bulk_action'] ?? '');
            $idList = $_POST['transaction_ids'] ?? [];
            if (!is_array($idList)) {
                $idList = [];
            }
            $idList = array_unique(array_map('intval', $idList));
            $idList = array_values(array_filter($idList, static fn($id) => $id > 0));
            if (empty($idList)) {
                $messages['error'][] = __('Выберите хотя бы один платёж.');
            } else {
                $supportedActions = ['approve', 'reject'];
                if (!in_array($bulkAction, $supportedActions, true)) {
                    $messages['error'][] = __('Неизвестное действие.');
                } else {
                    $processed = 0;
                    $already = 0;
                    $failed = 0;
                    foreach ($idList as $txId) {
                        if ($bulkAction === 'approve') {
                            $result = pp_payment_transaction_mark_confirmed($txId);
                            if (!empty($result['ok'])) {
                                if (!empty($result['already'])) {
                                    $already++;
                                } else {
                                    $processed++;
                                }
                            } else {
                                $failed++;
                            }
                        } elseif ($bulkAction === 'reject') {
                            $result = pp_payment_transaction_mark_failed($txId, 'cancelled', [], __('Отклонено администратором'));
                            if (!empty($result['ok'])) {
                                if (!empty($result['already_confirmed'])) {
                                    $already++;
                                } else {
                                    $processed++;
                                }
                            } else {
                                $failed++;
                            }
                        }
                    }
                    if ($processed > 0) {
                        $messages['success'][] = sprintf(__('Успешно обработано: %d'), $processed);
                    }
                    if ($already > 0) {
                        $messages['success'][] = sprintf(__('Уже были подтверждены: %d'), $already);
                    }
                    if ($failed > 0) {
                        $messages['error'][] = sprintf(__('Не удалось обработать: %d'), $failed);
                    }
                }
            }
        }
    } elseif (isset($_POST['status_modal_action'])) {
        if (!verify_csrf()) {
            $messages['error'][] = __('Не удалось обновить статус (CSRF).');
        } else {
            $txId = (int)($_POST['transaction_id'] ?? 0);
            $newStatus = strtolower(trim((string)($_POST['new_status'] ?? '')));
            if ($newStatus === 'canceled') {
                $newStatus = 'cancelled';
            }
            $allowedStatus = ['pending', 'awaiting_confirmation', 'confirmed', 'failed', 'cancelled', 'expired'];
            if ($txId <= 0) {
                $messages['error'][] = __('Не удалось определить платёж.');
            } elseif (!in_array($newStatus, $allowedStatus, true)) {
                $messages['error'][] = __('Выбран некорректный статус.');
            } else {
                $transaction = pp_payment_transaction_get($txId);
                if (!$transaction) {
                    $messages['error'][] = __('Платёж не найден.');
                } else {
                    $result = null;
                    if ($newStatus === 'confirmed') {
                        $result = pp_payment_transaction_mark_confirmed($txId);
                    } elseif (in_array($newStatus, ['failed', 'cancelled', 'expired'], true)) {
                        $result = pp_payment_transaction_mark_failed($txId, $newStatus, [], __('Статус обновлён вручную.'));
                    } else {
                        $result = pp_payment_transaction_set_status($txId, $newStatus);
                    }
                    if (!empty($result['ok'])) {
                        if (!empty($result['already'])) {
                            $messages['success'][] = __('Статус уже был установлен ранее.');
                        } elseif (!empty($result['already_confirmed'])) {
                            $messages['error'][] = __('Платёж уже подтверждён, статус оставить без изменений.');
                        } else {
                            $statusLabel = pp_payment_transaction_status_label($newStatus);
                            $messages['success'][] = sprintf(
                                __('Статус обновлён на «%s».'),
                                $statusLabel
                            );
                        }
                    } else {
                        $errorMsg = trim((string)($result['error'] ?? ''));
                        if ($errorMsg === 'already_confirmed') {
                            $messages['error'][] = __('Платёж уже подтверждён, статус оставить без изменений.');
                        } else {
                            $messages['error'][] = __('Не удалось обновить статус.');
                            if ($errorMsg !== '') {
                                $messages['error'][] = $errorMsg;
                            }
                        }
                    }
                }
            }
        }
    }
}

$hasGatewayFilter = ($gatewayFilter !== '' && $gatewayFilter !== 'all');
$hasStatusFilter = ($statusFilter !== '' && $statusFilter !== 'all');
$hasTypeFilter = ($typeFilter !== '' && $typeFilter !== 'all');
$hasUserFilter = ($userQuery !== '');
$hasReferenceFilter = ($referenceQuery !== '');

$includePayments = true;
$includeManual = true;

if ($typeFilter === 'manual') {
    $includePayments = false;
}
if ($typeFilter === 'payments') {
    $includeManual = false;
}
if ($gatewayFilter === 'manual') {
    $includePayments = false;
}
if ($hasGatewayFilter && $gatewayFilter !== 'manual') {
    $includeManual = false;
}
if ($statusFilter === 'manual') {
    $includePayments = false;
} elseif ($hasStatusFilter && $statusFilter !== 'manual') {
    $includeManual = false;
}

$defaultCurrency = strtoupper((string)get_currency_code());
if ($defaultCurrency === '') {
    $defaultCurrency = 'USD';
}

$transactions = [];
$total = 0;
$totalPages = 1;

try {
    $conn = connect_db();
} catch (Throwable $e) {
    $conn = null;
}

if (!$conn) {
    $messages['error'][] = __('Не удалось подключиться к базе данных.');
} else {
    $unionParts = [];

    if ($includePayments) {
        $paymentSql = <<<'SQL'
SELECT
    'payment' AS record_type,
    pt.id AS primary_id,
    pt.created_at,
    pt.confirmed_at,
    pt.user_id,
    u.username,
    pt.gateway_code,
    pt.amount,
    pt.currency,
    pt.status,
    pt.provider_reference,
    pt.error_message,
    pt.provider_payload,
    pt.customer_payload,
    NULL AS manual_admin_id,
    NULL AS manual_admin_username,
    NULL AS manual_admin_full_name,
    NULL AS manual_comment,
    NULL AS manual_meta_json,
    NULL AS manual_balance_after
FROM payment_transactions AS pt
LEFT JOIN users AS u ON u.id = pt.user_id
SQL;

        $paymentConditions = [];
        $paymentTypes = '';
        $paymentParams = [];

        if ($hasGatewayFilter && $gatewayFilter !== 'manual') {
            $paymentConditions[] = 'pt.gateway_code = ?';
            $paymentTypes .= 's';
            $paymentParams[] = $gatewayFilter;
        }
        if ($hasStatusFilter && $statusFilter !== 'manual') {
            $paymentConditions[] = 'pt.status = ?';
            $paymentTypes .= 's';
            $paymentParams[] = $statusFilter;
        }
        if ($dateFromSql !== null) {
            $paymentConditions[] = 'pt.created_at >= ?';
            $paymentTypes .= 's';
            $paymentParams[] = $dateFromSql;
        }
        if ($dateToSql !== null) {
            $paymentConditions[] = 'pt.created_at <= ?';
            $paymentTypes .= 's';
            $paymentParams[] = $dateToSql;
        }
        if ($amountMin !== null) {
            $paymentConditions[] = 'pt.amount >= ?';
            $paymentTypes .= 'd';
            $paymentParams[] = $amountMin;
        }
        if ($amountMax !== null) {
            $paymentConditions[] = 'pt.amount <= ?';
            $paymentTypes .= 'd';
            $paymentParams[] = $amountMax;
        }
        if ($hasUserFilter) {
            $like = '%' . $userQuery . '%';
            $userCondition = '(u.username LIKE ? OR u.email LIKE ?';
            $paymentTypes .= 'ss';
            $paymentParams[] = $like;
            $paymentParams[] = $like;
            if (ctype_digit($userQuery)) {
                $userCondition .= ' OR pt.user_id = ?';
                $paymentTypes .= 'i';
                $paymentParams[] = (int)$userQuery;
            }
            $userCondition .= ')';
            $paymentConditions[] = $userCondition;
        }
        if ($hasReferenceFilter) {
            $referenceLike = '%' . $referenceQuery . '%';
            $refCondition = 'pt.provider_reference LIKE ?';
            $paymentTypes .= 's';
            $paymentParams[] = $referenceLike;
            if (ctype_digit($referenceQuery)) {
                $refCondition = '(' . $refCondition . ' OR pt.id = ?)';
                $paymentTypes .= 'i';
                $paymentParams[] = (int)$referenceQuery;
            }
            $paymentConditions[] = $refCondition;
        }

        if (!empty($paymentConditions)) {
            $paymentSql .= ' WHERE ' . implode(' AND ', $paymentConditions);
        }

        $unionParts[] = ['sql' => $paymentSql, 'types' => $paymentTypes, 'params' => $paymentParams];
    }

    if ($includeManual) {
        $currencyFallbackSql = "'" . $conn->real_escape_string($defaultCurrency) . "'";
        $manualSql = <<<'SQL'
SELECT
    'manual' AS record_type,
    bh.id AS primary_id,
    bh.created_at,
    NULL AS confirmed_at,
    bh.user_id,
    u.username,
    'manual' AS gateway_code,
    bh.delta AS amount,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(bh.meta_json, '$.currency')), %s) AS currency,
    'manual' AS status,
    NULL AS provider_reference,
    NULL AS error_message,
    NULL AS provider_payload,
    NULL AS customer_payload,
    bh.created_by_admin_id AS manual_admin_id,
    adm.username AS manual_admin_username,
    adm.full_name AS manual_admin_full_name,
    JSON_UNQUOTE(JSON_EXTRACT(bh.meta_json, '$.comment')) AS manual_comment,
    bh.meta_json AS manual_meta_json,
    bh.balance_after AS manual_balance_after
FROM balance_history AS bh
LEFT JOIN users AS u ON u.id = bh.user_id
LEFT JOIN users AS adm ON adm.id = bh.created_by_admin_id
SQL;
    $manualSql = sprintf($manualSql, $currencyFallbackSql);

        $manualConditions = ["bh.source = 'manual'"];
        $manualTypes = '';
        $manualParams = [];

        if ($dateFromSql !== null) {
            $manualConditions[] = 'bh.created_at >= ?';
            $manualTypes .= 's';
            $manualParams[] = $dateFromSql;
        }
        if ($dateToSql !== null) {
            $manualConditions[] = 'bh.created_at <= ?';
            $manualTypes .= 's';
            $manualParams[] = $dateToSql;
        }
        if ($amountMin !== null) {
            $manualConditions[] = 'bh.delta >= ?';
            $manualTypes .= 'd';
            $manualParams[] = $amountMin;
        }
        if ($amountMax !== null) {
            $manualConditions[] = 'bh.delta <= ?';
            $manualTypes .= 'd';
            $manualParams[] = $amountMax;
        }
        if ($hasUserFilter) {
            $like = '%' . $userQuery . '%';
            $userCondition = '(u.username LIKE ? OR u.email LIKE ?';
            $manualTypes .= 'ss';
            $manualParams[] = $like;
            $manualParams[] = $like;
            if (ctype_digit($userQuery)) {
                $userCondition .= ' OR bh.user_id = ?';
                $manualTypes .= 'i';
                $manualParams[] = (int)$userQuery;
            }
            $userCondition .= ')';
            $manualConditions[] = $userCondition;
        }
        if ($hasReferenceFilter) {
            $referenceLike = '%' . $referenceQuery . '%';
            $refParts = ["JSON_UNQUOTE(JSON_EXTRACT(bh.meta_json, '$.comment')) LIKE ?"];
            $manualTypes .= 's';
            $manualParams[] = $referenceLike;
            if (ctype_digit($referenceQuery)) {
                $refParts[] = 'bh.id = ?';
                $manualTypes .= 'i';
                $manualParams[] = (int)$referenceQuery;
            }
            $manualConditions[] = '(' . implode(' OR ', $refParts) . ')';
        }

        if (!empty($manualConditions)) {
            $manualSql .= ' WHERE ' . implode(' AND ', $manualConditions);
        }

        $unionParts[] = ['sql' => $manualSql, 'types' => $manualTypes, 'params' => $manualParams];
    }

    if (!empty($unionParts)) {
        $unionSqlParts = array_column($unionParts, 'sql');
        $combinedSql = implode(' UNION ALL ', $unionSqlParts);
        $combinedTypes = '';
        $combinedParams = [];
        foreach ($unionParts as $part) {
            $combinedTypes .= $part['types'];
            foreach ($part['params'] as $param) {
                $combinedParams[] = $param;
            }
        }

        // Count total
        $countSql = 'SELECT COUNT(*) AS cnt FROM (' . $combinedSql . ') AS combined';
        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            pp_admin_bind_params($stmt, $combinedTypes, $combinedParams);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $total = (int)$row['cnt'];
            }
            if ($res) {
                $res->free();
            }
            $stmt->close();
        }

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $dataSql = 'SELECT * FROM (' . $combinedSql . ') AS combined ORDER BY created_at DESC, primary_id DESC LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $dataTypes = $combinedTypes . 'ii';
            $dataParams = $combinedParams;
            $dataParams[] = $perPage;
            $dataParams[] = $offset;
            pp_admin_bind_params($stmt, $dataTypes, $dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['amount'] = (float)$row['amount'];
                    $row['record_type'] = (string)$row['record_type'];
                    $row['gateway_code'] = strtolower((string)$row['gateway_code']);
                    $row['provider_payload'] = pp_payment_json_decode($row['provider_payload'] ?? '', []);
                    $row['customer_payload'] = pp_payment_json_decode($row['customer_payload'] ?? '', []);
                    if (isset($row['manual_meta_json']) && $row['manual_meta_json'] !== null && $row['manual_meta_json'] !== '') {
                        $meta = json_decode((string)$row['manual_meta_json'], true);
                        if (!is_array($meta)) {
                            $meta = [];
                        }
                    } else {
                        $meta = [];
                    }
                    $row['manual_meta'] = $meta;
                    $transactions[] = $row;
                }
                $res->free();
            }
            $stmt->close();
        }
    }

    $conn->close();
}

$pp_admin_sidebar_active = 'payment_transactions';
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
include '../includes/header.php';
include __DIR__ . '/../includes/admin_sidebar.php';

function pp_admin_tx_status_badge(string $status): string {
    $statusKey = strtolower(trim($status));
    switch ($statusKey) {
        case 'confirmed':
            return 'bg-success';
        case 'awaiting_confirmation':
        case 'pending':
            return 'bg-warning text-dark';
        case 'failed':
            return 'bg-danger';
        case 'cancelled':
        case 'canceled':
        case 'expired':
            return 'bg-secondary';
            case 'manual':
                return 'bg-info text-dark';
        default:
            return 'bg-light text-dark';
    }
}
?>

<div class="main-content fade-in">
<div class="card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h1 class="h3 mb-1"><?php echo __('Транзакции'); ?></h1>
            <p class="text-muted mb-0 small"><?php echo __('История пополнений клиентских балансов по платёжным системам.'); ?></p>
        </div>
        <a href="<?php echo pp_url('admin/payment_systems.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-gear me-1"></i><?php echo __('Настройки платёжных систем'); ?></a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if (!empty($messages['success'])): ?>
            <?php foreach ($messages['success'] as $msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($messages['error'])): ?>
            <?php foreach ($messages['error'] as $msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form class="row g-3 align-items-end" method="get">
            <div class="col-lg-3">
                <label class="form-label" for="filter-gateway"><?php echo __('Платёжная система'); ?></label>
                <select class="form-select" id="filter-gateway" name="gateway">
                    <option value="all" <?php echo ($gatewayFilter === 'all' || $gatewayFilter === '') ? 'selected' : ''; ?>><?php echo __('Все системы'); ?></option>
                    <?php foreach ($gateways as $code => $gw): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($code)); ?>" <?php echo $gatewayFilter === strtolower($code) ? 'selected' : ''; ?>><?php echo htmlspecialchars($gw['title']); ?></option>
                    <?php endforeach; ?>
                    <option value="manual" <?php echo $gatewayFilter === 'manual' ? 'selected' : ''; ?>><?php echo __('Ручные изменения'); ?></option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="filter-status"><?php echo __('Статус'); ?></label>
                <select class="form-select" id="filter-status" name="status">
                    <option value="all" <?php echo ($statusFilter === 'all' || $statusFilter === '') ? 'selected' : ''; ?>><?php echo __('Все статусы'); ?></option>
                    <?php foreach (['pending', 'awaiting_confirmation', 'confirmed', 'failed', 'cancelled', 'expired', 'manual'] as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(pp_payment_transaction_status_label($status)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="filter-type"><?php echo __('Тип записи'); ?></label>
                <select class="form-select" id="filter-type" name="type">
                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>><?php echo __('Все записи'); ?></option>
                    <option value="payments" <?php echo $typeFilter === 'payments' ? 'selected' : ''; ?>><?php echo __('Платежи'); ?></option>
                    <option value="manual" <?php echo $typeFilter === 'manual' ? 'selected' : ''; ?>><?php echo __('Ручные изменения'); ?></option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="filter-user"><?php echo __('Пользователь'); ?></label>
                <input type="text" class="form-control" id="filter-user" name="user" value="<?php echo htmlspecialchars($userQuery); ?>" placeholder="username / email / ID">
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="filter-reference"><?php echo __('Референс или ID'); ?></label>
                <input type="text" class="form-control" id="filter-reference" name="reference" value="<?php echo htmlspecialchars($referenceQuery); ?>" placeholder="ABC123 или 42">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filter-date-from"><?php echo __('Дата с'); ?></label>
                <input type="date" class="form-control" id="filter-date-from" name="date_from" value="<?php echo htmlspecialchars($dateFromValue); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filter-date-to"><?php echo __('Дата по'); ?></label>
                <input type="date" class="form-control" id="filter-date-to" name="date_to" value="<?php echo htmlspecialchars($dateToValue); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filter-amount-min"><?php echo __('Сумма от'); ?></label>
                <input type="number" step="0.01" class="form-control" id="filter-amount-min" name="amount_min" value="<?php echo $amountMin !== null ? htmlspecialchars(number_format($amountMin, 2, '.', '')) : ''; ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filter-amount-max"><?php echo __('Сумма до'); ?></label>
                <input type="number" step="0.01" class="form-control" id="filter-amount-max" name="amount_max" value="<?php echo $amountMax !== null ? htmlspecialchars(number_format($amountMax, 2, '.', '')) : ''; ?>">
            </div>
            <div class="col-lg-4 col-md-8 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-filter me-1"></i><?php echo __('Применить'); ?></button>
                <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<?php
$filterQuery = [
    'gateway' => $hasGatewayFilter ? $gatewayFilter : null,
    'status' => $hasStatusFilter ? $statusFilter : null,
    'type' => $hasTypeFilter ? $typeFilter : null,
    'user' => $hasUserFilter ? $userQuery : null,
    'reference' => $hasReferenceFilter ? $referenceQuery : null,
    'date_from' => $dateFromValue !== '' ? $dateFromValue : null,
    'date_to' => $dateToValue !== '' ? $dateToValue : null,
    'amount_min' => $amountMin !== null ? number_format($amountMin, 2, '.', '') : null,
    'amount_max' => $amountMax !== null ? number_format($amountMax, 2, '.', '') : null,
];
$filterQueryClean = array_filter($filterQuery, static fn($value) => $value !== null && $value !== '');
$currentUrlBase = 'admin/payment_transactions.php';
$formAction = pp_url($currentUrlBase . (!empty($filterQueryClean) ? '?' . http_build_query($filterQueryClean) : ''));
?>

<div class="card">
    <form method="post" action="<?php echo htmlspecialchars($formAction); ?>">
        <?php echo csrf_field(); ?>
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1"><?php echo __('Управление платежами'); ?></h2>
                <p class="text-muted mb-0 small"><?php echo __('Выделите нужные платежи для массового подтверждения или отклонения.'); ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success" name="bulk_action" value="approve" data-bulk-action disabled>
                    <i class="bi bi-check2-circle me-1"></i><?php echo __('Подтвердить выделенные'); ?>
                </button>
                <button type="submit" class="btn btn-outline-danger" name="bulk_action" value="reject" data-bulk-action disabled>
                    <i class="bi bi-x-octagon me-1"></i><?php echo __('Отклонить выделенные'); ?>
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-transactions">
                <thead>
                    <tr>
                        <th class="text-center" style="width:36px;">
                            <input type="checkbox" class="form-check-input" data-bulk-check-all>
                        </th>
                        <th><?php echo __('ID'); ?></th>
                        <th><?php echo __('Дата'); ?></th>
                        <th><?php echo __('Пользователь'); ?></th>
                        <th><?php echo __('Источник'); ?></th>
                        <th><?php echo __('Сумма'); ?></th>
                        <th><?php echo __('Статус'); ?></th>
                        <th><?php echo __('Детали'); ?></th>
                        <th><?php echo __('Комментарий'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4"><?php echo __('Записи не найдены.'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                                $isManual = ($tx['record_type'] === 'manual');
                                $selectable = !$isManual && in_array($tx['status'], ['pending', 'awaiting_confirmation'], true);
                                $rowClass = $isManual ? 'manual-row' : '';
                                $amountClass = $isManual ? ($tx['amount'] >= 0 ? 'text-success' : 'text-danger') : '';
                                $idLabel = $isManual ? 'M' . (int)$tx['primary_id'] : (int)$tx['primary_id'];
                                $manualComment = $isManual ? trim((string)($tx['manual_comment'] ?? '')) : '';
                                $manualAdmin = $isManual ? trim((string)($tx['manual_admin_username'] ?? $tx['manual_admin_full_name'] ?? '')) : '';
                                $manualBalanceAfter = $isManual && isset($tx['manual_balance_after']) ? (float)$tx['manual_balance_after'] : null;
                                $gatewayTitle = pp_payment_gateway_title($tx['gateway_code'], strtoupper($tx['gateway_code']));
                                $isInvoiceGateway = (!$isManual && $tx['gateway_code'] === 'invoice');
                                $invoiceDownloadToken = $isInvoiceGateway ? (string)($tx['customer_payload']['invoice_download_token'] ?? '') : '';
                                $invoiceDownloadUrl = $invoiceDownloadToken !== ''
                                    ? pp_url('client/invoice_download.php?txn=' . urlencode((string)$tx['primary_id']) . '&token=' . urlencode($invoiceDownloadToken))
                                    : '';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="text-center">
                                    <?php if ($selectable): ?>
                                        <input type="checkbox" class="form-check-input js-bulk-checkbox" name="transaction_ids[]" value="<?php echo (int)$tx['primary_id']; ?>">
                                    <?php elseif (!$isManual): ?>
                                        <input type="checkbox" class="form-check-input" disabled>
                                    <?php endif; ?>
                                </td>
                                <td><span class="text-muted">#<?php echo htmlspecialchars((string)$idLabel); ?></span></td>
                                <td>
                                    <div class="small text-muted"><?php echo htmlspecialchars($tx['created_at']); ?></div>
                                    <?php if (!$isManual && !empty($tx['confirmed_at'])): ?>
                                        <div class="small text-success"><?php echo __('Подтверждена'); ?>: <?php echo htmlspecialchars($tx['confirmed_at']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($tx['username'])): ?>
                                        <strong><?php echo htmlspecialchars($tx['username']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">ID <?php echo (int)$tx['user_id']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <span class="badge bg-info text-dark"><?php echo htmlspecialchars($gatewayTitle); ?></span>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($gatewayTitle); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo $amountClass; ?>">
                                    <?php if ($isManual): ?>
                                        <?php echo htmlspecialchars(pp_balance_sign_amount($tx['amount'])); ?>
                                    <?php else: ?>
                                        <?php echo number_format($tx['amount'], 2, '.', ' '); ?>
                                    <?php endif; ?>
                                    <span class="text-muted ms-1"><?php echo htmlspecialchars(strtoupper($tx['currency'])); ?></span>
                                    <?php if ($manualBalanceAfter !== null): ?>
                                        <div class="small text-muted"><?php echo __('Баланс после'); ?>: <?php echo htmlspecialchars(format_currency($manualBalanceAfter)); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <span class="badge <?php echo pp_admin_tx_status_badge($tx['status']); ?>">
                                            <?php echo htmlspecialchars(pp_payment_transaction_status_label($tx['status'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <button type="button"
                                            class="badge <?php echo pp_admin_tx_status_badge($tx['status']); ?> status-badge js-status-modal"
                                            data-id="<?php echo (int)$tx['primary_id']; ?>"
                                            data-display-id="<?php echo htmlspecialchars('#' . (int)$tx['primary_id']); ?>"
                                            data-status="<?php echo htmlspecialchars(strtolower($tx['status'])); ?>"
                                            data-status-label="<?php echo htmlspecialchars(pp_payment_transaction_status_label($tx['status'])); ?>"
                                            data-username="<?php echo htmlspecialchars((string)($tx['username'] ?? sprintf(__('ID %d'), (int)$tx['user_id']))); ?>"
                                            data-amount="<?php echo htmlspecialchars(number_format($tx['amount'], 2, '.', ' ')); ?>"
                                            data-currency="<?php echo htmlspecialchars(strtoupper($tx['currency'])); ?>"
                                            data-gateway="<?php echo htmlspecialchars($gatewayTitle); ?>">
                                            <?php echo htmlspecialchars(pp_payment_transaction_status_label($tx['status'])); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <?php if ($manualAdmin !== ''): ?>
                                            <div class="small text-muted"><?php echo __('Администратор'); ?>: <?php echo htmlspecialchars($manualAdmin); ?></div>
                                        <?php endif; ?>
                                        <div class="small text-muted">ID <?php echo (int)$tx['primary_id']; ?></div>
                                    <?php else: ?>
                                        <div class="text-break small"><?php echo htmlspecialchars($tx['provider_reference'] ?? '—'); ?></div>
                                        <?php if (!empty($tx['customer_payload']['payment_url']) && !$isInvoiceGateway): ?>
                                            <a href="<?php echo htmlspecialchars($tx['customer_payload']['payment_url']); ?>" target="_blank" rel="noopener" class="small d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-box-arrow-up-right"></i><?php echo __('Ссылка на оплату'); ?>
                                            </a>
                                        <?php elseif ($invoiceDownloadUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($invoiceDownloadUrl); ?>" target="_blank" rel="noopener" class="small d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-file-earmark-arrow-down"></i><?php echo __('Скачать счёт'); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <?php if ($manualComment !== ''): ?>
                                            <div class="small"><?php echo nl2br(htmlspecialchars($manualComment)); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($tx['error_message'])): ?>
                                            <div class="text-danger small"><?php echo htmlspecialchars($tx['error_message']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-column flex-md-row gap-2">
                <div class="text-muted small">
                    <?php echo sprintf(__('Показано %d–%d из %d'), $total === 0 ? 0 : $offset + 1, min($offset + $perPage, $total), $total); ?>
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <?php
                        $paginationBase = $filterQueryClean;
                        ?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <?php $prevQuery = $paginationBase; $prevQuery['page'] = max(1, $page - 1); ?>
                            <a class="page-link" href="<?php echo htmlspecialchars(pp_url($currentUrlBase . '?' . http_build_query($prevQuery))); ?>" aria-label="<?php echo __('Предыдущая'); ?>">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php $pageQuery = $paginationBase; if ($i > 1) { $pageQuery['page'] = $i; } else { unset($pageQuery['page']); } ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(pp_url($currentUrlBase . (!empty($pageQuery) ? '?' . http_build_query($pageQuery) : ''))); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <?php $nextQuery = $paginationBase; $nextQuery['page'] = min($totalPages, $page + 1); ?>
                            <a class="page-link" href="<?php echo htmlspecialchars(pp_url($currentUrlBase . '?' . http_build_query($nextQuery))); ?>" aria-label="<?php echo __('Следующая'); ?>">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </form>
</div>

</div>

<?php $statusModalOptions = ['pending', 'awaiting_confirmation', 'confirmed', 'failed', 'cancelled', 'expired']; ?>
<div class="modal fade" id="tx-status-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="status_modal_action" value="1">
                <input type="hidden" name="transaction_id" value="">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-semibold"><?php echo __('Изменить статус платежа'); ?></h5>
                        <p class="text-muted small mb-0" data-modal-status-context></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="rounded bg-dark-subtle bg-opacity-50 p-3 mb-3 text-muted small d-flex flex-column gap-1">
                        <div>
                            <span class="fw-semibold" data-modal-transaction-id>—</span>
                            <span class="ms-2" data-modal-transaction-user>—</span>
                        </div>
                        <div data-modal-transaction-amount></div>
                        <div><?php echo __('Текущий статус'); ?>:
                            <span class="badge bg-secondary" data-modal-status-label>—</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="tx-status-select"><?php echo __('Новый статус'); ?></label>
                        <select class="form-select" id="tx-status-select" name="new_status" required>
                            <?php foreach ($statusModalOptions as $status): ?>
                                <option value="<?php echo $status; ?>"><?php echo htmlspecialchars(pp_payment_transaction_status_label($status)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-info small mb-0" role="alert">
                        <?php echo __('При выборе статуса «Зачислено» средства будут автоматически добавлены на баланс клиента.'); ?>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Отмена'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('Сохранить'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.querySelector('[data-bulk-check-all]');
    const checkboxes = Array.from(document.querySelectorAll('.js-bulk-checkbox'));
    const actionButtons = Array.from(document.querySelectorAll('[data-bulk-action]'));

    function updateButtons() {
        const hasChecked = checkboxes.some(cb => cb.checked);
        actionButtons.forEach(btn => {
            btn.disabled = !hasChecked;
        });
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            checkboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = checkAll.checked;
                }
            });
            updateButtons();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateButtons);
    });

    updateButtons();

    const statusButtons = Array.from(document.querySelectorAll('.js-status-modal'));
    const modalElement = document.getElementById('tx-status-modal');
    if (modalElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        const txIdInput = modalElement.querySelector('input[name="transaction_id"]');
        const statusSelect = modalElement.querySelector('#tx-status-select');
        const statusLabel = modalElement.querySelector('[data-modal-status-label]');
        const txIdLabel = modalElement.querySelector('[data-modal-transaction-id]');
        const txUserLabel = modalElement.querySelector('[data-modal-transaction-user]');
        const txAmountLabel = modalElement.querySelector('[data-modal-transaction-amount]');
        const statusContext = modalElement.querySelector('[data-modal-status-context]');

        statusButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.getAttribute('data-id');
                const displayId = this.getAttribute('data-display-id') || ('#' + id);
                let status = this.getAttribute('data-status') || '';
                const statusHuman = this.getAttribute('data-status-label') || '';
                const username = this.getAttribute('data-username') || '';
                const amount = this.getAttribute('data-amount') || '';
                const currency = this.getAttribute('data-currency') || '';
                const gateway = this.getAttribute('data-gateway') || '';
                txIdInput.value = id;
                txIdLabel.textContent = displayId;
                txUserLabel.textContent = username;
                if (amount !== '') {
                    txAmountLabel.textContent = '<?php echo addslashes(__('Сумма')); ?>: ' + amount + ' ' + currency;
                    txAmountLabel.classList.remove('d-none');
                } else {
                    txAmountLabel.textContent = '';
                    txAmountLabel.classList.add('d-none');
                }
                statusLabel.textContent = statusHuman;
                if (gateway !== '') {
                    statusContext.textContent = '<?php echo addslashes(__('Источник')); ?>: ' + gateway;
                } else {
                    statusContext.textContent = '<?php echo addslashes(__('Выберите новый статус для платежа.')); ?>';
                }
                if (status === 'canceled') {
                    status = 'cancelled';
                }
                const selectableStatuses = Array.from(statusSelect.options).map(option => option.value);
                if (selectableStatuses.includes(status)) {
                    statusSelect.value = status;
                } else {
                    statusSelect.selectedIndex = 0;
                }
                modal.show();
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
