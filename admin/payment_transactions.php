<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$conn = connect_db();
$gateways = pp_payment_gateways(true);

$gatewayFilter = strtolower(trim((string)($_GET['gateway'] ?? 'all')));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$hasGatewayFilter = ($gatewayFilter !== '' && $gatewayFilter !== 'all');
$hasStatusFilter = ($statusFilter !== '' && $statusFilter !== 'all');

$total = 0;
if ($hasGatewayFilter && $hasStatusFilter) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM payment_transactions pt WHERE pt.gateway_code = ? AND pt.status = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $gatewayFilter, $statusFilter);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $total = (int)$row['cnt'];
        }
        if ($res) { $res->free(); }
        $stmt->close();
    }
} elseif ($hasGatewayFilter) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM payment_transactions pt WHERE pt.gateway_code = ?');
    if ($stmt) {
        $stmt->bind_param('s', $gatewayFilter);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $total = (int)$row['cnt'];
        }
        if ($res) { $res->free(); }
        $stmt->close();
    }
} elseif ($hasStatusFilter) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM payment_transactions pt WHERE pt.status = ?');
    if ($stmt) {
        $stmt->bind_param('s', $statusFilter);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $total = (int)$row['cnt'];
        }
        if ($res) { $res->free(); }
        $stmt->close();
    }
} else {
    $res = $conn->query('SELECT COUNT(*) AS cnt FROM payment_transactions pt');
    if ($res && ($row = $res->fetch_assoc())) {
        $total = (int)$row['cnt'];
    }
    if ($res) { $res->free(); }
}

$transactions = [];
if ($hasGatewayFilter && $hasStatusFilter) {
    $stmt = $conn->prepare('SELECT pt.*, u.username FROM payment_transactions pt LEFT JOIN users u ON u.id = pt.user_id WHERE pt.gateway_code = ? AND pt.status = ? ORDER BY pt.id DESC LIMIT ? OFFSET ?');
    if ($stmt) {
        $stmt->bind_param('ssii', $gatewayFilter, $statusFilter, $perPage, $offset);
    }
} elseif ($hasGatewayFilter) {
    $stmt = $conn->prepare('SELECT pt.*, u.username FROM payment_transactions pt LEFT JOIN users u ON u.id = pt.user_id WHERE pt.gateway_code = ? ORDER BY pt.id DESC LIMIT ? OFFSET ?');
    if ($stmt) {
        $stmt->bind_param('sii', $gatewayFilter, $perPage, $offset);
    }
} elseif ($hasStatusFilter) {
    $stmt = $conn->prepare('SELECT pt.*, u.username FROM payment_transactions pt LEFT JOIN users u ON u.id = pt.user_id WHERE pt.status = ? ORDER BY pt.id DESC LIMIT ? OFFSET ?');
    if ($stmt) {
        $stmt->bind_param('sii', $statusFilter, $perPage, $offset);
    }
} else {
    $stmt = $conn->prepare('SELECT pt.*, u.username FROM payment_transactions pt LEFT JOIN users u ON u.id = pt.user_id ORDER BY pt.id DESC LIMIT ? OFFSET ?');
    if ($stmt) {
        $stmt->bind_param('ii', $perPage, $offset);
    }
}

if (isset($stmt) && $stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['amount'] = (float)$row['amount'];
            $row['provider_payload'] = pp_payment_json_decode($row['provider_payload'] ?? '', []);
            $row['customer_payload'] = pp_payment_json_decode($row['customer_payload'] ?? '', []);
            $transactions[] = $row;
        }
        $res->free();
    }
    $stmt->close();
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

$pp_container = true;
$pp_container_class = 'container-fluid';
include '../includes/header.php';

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
        default:
            return 'bg-light text-dark';
    }
}
?>

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
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-4">
                <label class="form-label" for="filter-gateway"><?php echo __('Платёжная система'); ?></label>
                <select class="form-select" id="filter-gateway" name="gateway">
                    <option value="all" <?php echo ($gatewayFilter === 'all' || $gatewayFilter === '') ? 'selected' : ''; ?>><?php echo __('Все системы'); ?></option>
                    <?php foreach ($gateways as $code => $gw): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $gatewayFilter === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($gw['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="filter-status"><?php echo __('Статус'); ?></label>
                <select class="form-select" id="filter-status" name="status">
                    <option value="all" <?php echo ($statusFilter === 'all' || $statusFilter === '') ? 'selected' : ''; ?>><?php echo __('Все статусы'); ?></option>
                    <?php foreach (['pending', 'awaiting_confirmation', 'confirmed', 'failed', 'cancelled', 'expired'] as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(pp_payment_transaction_status_label($status)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-filter me-1"></i><?php echo __('Применить'); ?></button>
                <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?php echo __('ID'); ?></th>
                    <th><?php echo __('Дата'); ?></th>
                    <th><?php echo __('Пользователь'); ?></th>
                    <th><?php echo __('Система'); ?></th>
                    <th><?php echo __('Сумма'); ?></th>
                    <th><?php echo __('Статус'); ?></th>
                    <th><?php echo __('Референс'); ?></th>
                    <th><?php echo __('Комментарий'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4"><?php echo __('Транзакции не найдены.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><span class="text-muted">#<?php echo (int)$tx['id']; ?></span></td>
                            <td>
                                <div class="small text-muted"><?php echo htmlspecialchars($tx['created_at']); ?></div>
                                <?php if (!empty($tx['confirmed_at'])): ?>
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
                                <strong><?php echo htmlspecialchars(pp_payment_gateway_title($tx['gateway_code'], strtoupper($tx['gateway_code']))); ?></strong>
                            </td>
                            <td>
                                <div><?php echo number_format($tx['amount'], 2, '.', ' '); ?> <?php echo htmlspecialchars(strtoupper($tx['currency'])); ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo pp_admin_tx_status_badge($tx['status']); ?>">
                                    <?php echo htmlspecialchars(pp_payment_transaction_status_label($tx['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="text-break small"><?php echo htmlspecialchars($tx['provider_reference'] ?? '—'); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($tx['error_message'])): ?>
                                    <div class="text-danger small"><?php echo htmlspecialchars($tx['error_message']); ?></div>
                                <?php elseif (!empty($tx['customer_payload']['payment_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($tx['customer_payload']['payment_url']); ?>" target="_blank" rel="noopener" class="small">URL</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
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
                <?php echo sprintf(__('Показано %d–%d из %d'), $offset + 1, min($offset + $perPage, $total), $total); ?>
            </div>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars(pp_url('admin/payment_transactions.php?' . http_build_query(array_filter(['gateway' => $gatewayFilter !== 'all' ? $gatewayFilter : null, 'status' => $statusFilter !== 'all' ? $statusFilter : null, 'page' => max(1, $page - 1)])))); ?>" aria-label="<?php echo __('Предыдущая'); ?>">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(pp_url('admin/payment_transactions.php?' . http_build_query(array_filter(['gateway' => $gatewayFilter !== 'all' ? $gatewayFilter : null, 'status' => $statusFilter !== 'all' ? $statusFilter : null, 'page' => $i])))); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars(pp_url('admin/payment_transactions.php?' . http_build_query(array_filter(['gateway' => $gatewayFilter !== 'all' ? $gatewayFilter : null, 'status' => $statusFilter !== 'all' ? $statusFilter : null, 'page' => min($totalPages, $page + 1)])))); ?>" aria-label="<?php echo __('Следующая'); ?>">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

    <?php if ($conn) { $conn->close(); } ?>
<?php include '../includes/footer.php'; ?>
