<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$currentBalance = get_current_user_balance() ?? 0.0;
$paymentGateways = pp_payment_gateways(false);
$gatewayCodes = array_keys($paymentGateways);
$selectedGatewayCode = strtolower(trim((string)($_POST['gateway'] ?? $_GET['gateway'] ?? ($gatewayCodes[0] ?? ''))));
if ($selectedGatewayCode === '' || !isset($paymentGateways[$selectedGatewayCode])) {
    $selectedGatewayCode = $gatewayCodes[0] ?? '';
}

$messages = ['success' => [], 'error' => []];
$createdPayment = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topup'])) {
    if (!verify_csrf()) {
        $messages['error'][] = __('Не удалось создать платёж (CSRF).');
    } elseif (empty($paymentGateways)) {
        $messages['error'][] = __('Нет доступных платёжных систем.');
    } else {
        $gatewayCode = strtolower(trim((string)($_POST['gateway'] ?? '')));
        $amountRaw = str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
        $amount = round((float)$amountRaw, 2);
        if ($amount <= 0) {
            $messages['error'][] = __('Введите сумму больше нуля.');
        } elseif (!isset($paymentGateways[$gatewayCode])) {
            $messages['error'][] = __('Выберите платёжную систему.');
        } else {
            $result = pp_payment_transaction_create($userId, $gatewayCode, $amount);
            if (!empty($result['ok'])) {
                $createdPayment = $result;
                $selectedGatewayCode = $gatewayCode;
                $messages['success'][] = __('Платёж создан. Следуйте инструкции для завершения.');
            } else {
                $messages['error'][] = __('Не удалось создать платёж.') . ' ' . htmlspecialchars((string)($result['error'] ?? ''));
            }
        }
    }
}

$transactions = pp_payment_transactions_for_user($userId, 50, 0);
$balanceHistory = pp_balance_history_for_user($userId, 25, 0);

$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
include '../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';

function pp_client_tx_status_badge(string $status): string {
    $statusKey = strtolower(trim($status));
    switch ($statusKey) {
        case 'confirmed':
            return 'badge bg-success';
        case 'awaiting_confirmation':
        case 'pending':
            return 'badge bg-warning text-dark';
        case 'failed':
            return 'badge bg-danger';
        case 'cancelled':
        case 'canceled':
        case 'expired':
            return 'badge bg-secondary';
        default:
            return 'badge bg-light text-dark';
    }
}

?>

<div class="main-content fade-in">
    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-4 flex-column flex-md-row gap-3">
                        <div>
                            <h1 class="h4 mb-1"><?php echo __('Финансовый дашборд'); ?></h1>
                            <p class="text-muted mb-0 small"><?php echo __('Пополняйте баланс и отслеживайте статус платежей в одном месте.'); ?></p>
                        </div>
                        <div class="text-end">
                            <div class="text-uppercase small text-muted fw-semibold"><?php echo __('Текущий баланс'); ?></div>
                            <div class="display-6 fw-semibold text-primary"><?php echo htmlspecialchars(format_currency($currentBalance)); ?></div>
                        </div>
                    </div>

                    <?php foreach ($messages['success'] as $msg): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($messages['error'] as $msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($paymentGateways)): ?>
                        <div class="alert alert-warning mb-0"><?php echo __('Платёжные системы пока не подключены. Обратитесь к администратору.'); ?></div>
                    <?php else: ?>
                        <form method="post" class="needs-validation" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="create_topup" value="1">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="topup-amount" class="form-label"><?php echo __('Сумма пополнения'); ?></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="topup-amount" name="amount" min="1" step="0.01" value="<?php echo htmlspecialchars((string)($_POST['amount'] ?? '1000')); ?>" required>
                                        <?php if ($selectedGatewayCode && isset($paymentGateways[$selectedGatewayCode])): ?>
                                            <span class="input-group-text"><?php echo htmlspecialchars($paymentGateways[$selectedGatewayCode]['currency'] ?? ''); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="topup-gateway" class="form-label"><?php echo __('Платёжная система'); ?></label>
                                    <select class="form-select" id="topup-gateway" name="gateway" required onchange="this.form.submit();">
                                        <?php foreach ($paymentGateways as $code => $gateway): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $selectedGatewayCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($gateway['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo __('Выбор изменит валюту и инструкцию.'); ?></div>
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-gradient"><i class="bi bi-lightning-charge me-1"></i><?php echo __('Создать платёж'); ?></button>
                                <a href="<?php echo pp_url('client/balance.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i><?php echo __('Обновить'); ?></a>
                            </div>
                        </form>

                        <?php if ($selectedGatewayCode && isset($paymentGateways[$selectedGatewayCode])): ?>
                            <?php $selectedGateway = $paymentGateways[$selectedGatewayCode]; ?>
                            <div class="mt-4 p-3 border rounded bg-light">
                                <h2 class="h6 d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-info-circle text-primary"></i>
                                    <span><?php echo __('Инструкция по оплате'); ?> — <?php echo htmlspecialchars($selectedGateway['title']); ?></span>
                                </h2>
                                <?php if (!empty($selectedGateway['instructions'])): ?>
                                    <div class="small mb-0"><?php echo nl2br(htmlspecialchars($selectedGateway['instructions'])); ?></div>
                                <?php else: ?>
                                    <div class="text-muted small mb-0"><?php echo __('Инструкция не заполнена администратором. Используйте данные провайдера для оплаты.'); ?></div>
                                <?php endif; ?>
                                <div class="text-muted small mt-3">
                                    <?php echo __('После подтверждения платежа система автоматически зачислит сумму на ваш баланс.'); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($createdPayment && !empty($createdPayment['customer_payload'])): ?>
                            <?php $payload = $createdPayment['customer_payload']; ?>
                            <div class="alert alert-primary mt-4" role="alert">
                                <h2 class="h6 mb-2"><i class="bi bi-credit-card me-2"></i><?php echo __('Следующий шаг'); ?></h2>
                                <?php if (!empty($createdPayment['payment_url'])): ?>
                                    <p class="mb-2"><?php echo __('Перейдите по ссылке ниже, чтобы завершить оплату:'); ?></p>
                                    <p class="mb-3"><a class="btn btn-primary" href="<?php echo htmlspecialchars($createdPayment['payment_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Открыть страницу оплаты'); ?></a></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['qr_content'])): ?>
                                    <p class="mb-2"><?php echo __('Отсканируйте QR-код в приложении Binance Pay:'); ?></p>
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?php echo urlencode($payload['qr_content']); ?>" alt="QR" class="border rounded p-2 bg-white" loading="lazy">
                                <?php endif; ?>
                                <?php if (!empty($payload['invoice_id'])): ?>
                                    <p class="mb-0 small text-muted"><?php echo __('Номер счёта'); ?>: <strong><?php echo htmlspecialchars($payload['invoice_id']); ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['prepay_id'])): ?>
                                    <p class="mb-0 small text-muted"><?php echo __('Идентификатор платежа'); ?>: <strong><?php echo htmlspecialchars($payload['prepay_id']); ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['wallet_address'])): ?>
                                    <hr class="my-3">
                                    <p class="mb-2"><?php echo __('Для завершения перевода отправьте USDT на кошелёк:'); ?></p>
                                    <?php
                                        $walletAddress = (string)$payload['wallet_address'];
                                        $walletNetwork = (string)($payload['wallet_network'] ?? '');
                                        $walletMemo = (string)($payload['wallet_memo'] ?? '');
                                        $walletAddressJs = json_encode($walletAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    ?>
                                    <div class="input-group input-group-sm mb-2">
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($walletAddress); ?>" readonly>
                                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard?.writeText(<?php echo $walletAddressJs; ?>);">
                                            <?php echo __('Скопировать'); ?>
                                        </button>
                                    </div>
                                    <?php if ($walletNetwork !== ''): ?>
                                        <p class="small text-muted mb-1"><?php echo __('Сеть'); ?>: <strong><?php echo htmlspecialchars($walletNetwork); ?></strong></p>
                                    <?php endif; ?>
                                    <?php if ($walletMemo !== ''): ?>
                                        <p class="small text-muted mb-1"><?php echo __('Memo/Tag'); ?>: <strong><?php echo htmlspecialchars($walletMemo); ?></strong></p>
                                    <?php endif; ?>
                                    <p class="small text-muted mb-0"><?php echo __('Сумма к переводу'); ?>: <strong><?php
                                        $walletAmountValue = sprintf('%.2f %s', (float)($payload['amount'] ?? $createdPayment['transaction']['amount'] ?? 0), (string)($createdPayment['transaction']['currency'] ?? 'USDT'));
                                        echo htmlspecialchars($walletAmountValue);
                                    ?></strong></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?php echo __('Поддерживаемые системы'); ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($paymentGateways)): ?>
                        <p class="text-muted small mb-0"><?php echo __('Системы оплаты отсутствуют.'); ?></p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($paymentGateways as $code => $gateway): ?>
                                <li class="d-flex justify-content-between align-items-center border-bottom py-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($gateway['title']); ?></div>
                                        <div class="small text-muted"><?php echo __('Валюта'); ?>: <?php echo htmlspecialchars($gateway['currency'] ?? ''); ?></div>
                                    </div>
                                    <?php if ($selectedGatewayCode === $code): ?>
                                        <span class="badge bg-primary"><?php echo __('Выбрано'); ?></span>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars(pp_url('client/balance.php?gateway=' . urlencode($code))); ?>"><?php echo __('Выбрать'); ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0"><?php echo __('История платежей'); ?></h2>
            <span class="badge bg-light text-dark"><?php echo count($transactions); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th><?php echo __('Дата'); ?></th>
                        <th><?php echo __('Сумма'); ?></th>
                        <th><?php echo __('Система'); ?></th>
                        <th><?php echo __('Статус'); ?></th>
                        <th><?php echo __('Референс'); ?></th>
                        <th><?php echo __('Комментарий'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4"><?php echo __('Платежей пока нет.'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>
                                    <div class="small text-muted"><?php echo htmlspecialchars($tx['created_at']); ?></div>
                                    <?php if (!empty($tx['confirmed_at'])): ?>
                                        <div class="small text-success"><?php echo __('Зачислено'); ?>: <?php echo htmlspecialchars($tx['confirmed_at']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo number_format($tx['amount'], 2, '.', ' '); ?> <?php echo htmlspecialchars(strtoupper($tx['currency'])); ?>
                                </td>
                                <td><?php echo htmlspecialchars(pp_payment_gateway_title($tx['gateway_code'], strtoupper($tx['gateway_code']))); ?></td>
                                <td><span class="<?php echo pp_client_tx_status_badge($tx['status']); ?>"><?php echo htmlspecialchars(pp_payment_transaction_status_label($tx['status'])); ?></span></td>
                                <td class="small text-break">
                                    <?php echo htmlspecialchars($tx['provider_reference'] ?? '—'); ?>
                                </td>
                                <td class="small">
                                    <?php if (!empty($tx['error_message'])): ?>
                                        <span class="text-danger"><?php echo htmlspecialchars($tx['error_message']); ?></span>
                                    <?php elseif (!empty($tx['customer_payload']['payment_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($tx['customer_payload']['payment_url']); ?>" target="_blank" rel="noopener"><?php echo __('Ссылка на оплату'); ?></a>
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
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0"><?php echo __('История изменений баланса'); ?></h2>
            <span class="badge bg-light text-dark"><?php echo count($balanceHistory); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?php echo __('Дата'); ?></th>
                        <th><?php echo __('Изменение'); ?></th>
                        <th><?php echo __('Баланс после изменения'); ?></th>
                        <th><?php echo __('Причина'); ?></th>
                        <th><?php echo __('Комментарий администратора'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($balanceHistory)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4"><?php echo __('Событий истории пока нет.'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($balanceHistory as $event): ?>
                            <?php
                                $delta = (float)$event['delta'];
                                $after = format_currency((float)$event['balance_after']);
                                $changeClass = $delta >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold';
                                $comment = pp_balance_event_comment($event);
                                $adminName = '';
                                if (!empty($event['admin_full_name'])) {
                                    $adminName = $event['admin_full_name'];
                                } elseif (!empty($event['admin_username'])) {
                                    $adminName = $event['admin_username'];
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars((string)$event['created_at']); ?></div>
                                    <?php if ($adminName !== ''): ?>
                                        <div class="small text-muted"><?php echo __('Администратор'); ?>: <?php echo htmlspecialchars($adminName); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo $changeClass; ?>">
                                    <?php echo htmlspecialchars(pp_balance_sign_amount($delta)); ?>
                                </td>
                                <td>
                                    <span class="small fw-semibold text-dark"><?php echo htmlspecialchars($after); ?></span>
                                </td>
                                <td>
                                    <div class="small text-muted mb-1"><?php echo htmlspecialchars(pp_balance_event_reason($event)); ?></div>
                                    <span class="badge bg-light text-dark text-uppercase small"><?php echo htmlspecialchars($event['source']); ?></span>
                                </td>
                                <td>
                                    <?php if ($comment !== null): ?>
                                        <div class="small text-break"><?php echo nl2br(htmlspecialchars($comment)); ?></div>
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
    </div>
</div>

<?php include '../includes/footer.php'; ?>
