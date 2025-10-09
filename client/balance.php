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
$requestedTransactionId = isset($_GET['txn']) ? (int)$_GET['txn'] : 0;
$invoiceCurrencyOptions = ['UAH', 'USD', 'EUR'];
$invoiceCurrencySelection = strtoupper((string)($_POST['invoice_currency'] ?? 'UAH'));
if (!in_array($invoiceCurrencySelection, $invoiceCurrencyOptions, true)) {
    $invoiceCurrencySelection = 'UAH';
}

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
            $options = [];
            if ($gatewayCode === 'invoice') {
                $options['invoice_currency'] = $invoiceCurrencySelection;
            }
            $result = pp_payment_transaction_create($userId, $gatewayCode, $amount, $options);
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

if (isset($paymentGateways['monobank']) && function_exists('pp_payment_monobank_refresh_pending_for_user')) {
    $autoResult = pp_payment_monobank_refresh_pending_for_user($userId, $requestedTransactionId > 0 ? $requestedTransactionId : null, 8);
    if (!empty($autoResult['results']) && is_array($autoResult['results'])) {
        foreach ($autoResult['results'] as $txId => $res) {
            if (!is_array($res)) {
                continue;
            }
            $status = strtolower((string)($res['status'] ?? ''));
            if (!empty($res['status_changed']) && $status === 'confirmed') {
                $messages['success'][] = sprintf(__('Платёж #%d через Monobank подтверждён, средства зачислены.'), (int)$txId);
            } elseif (!empty($res['status_changed']) && in_array($status, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
                $label = pp_payment_transaction_status_label($status);
                $messages['error'][] = sprintf(__('Платёж #%d через Monobank завершён со статусом: %s'), (int)$txId, $label);
            } else {
                $errorCode = strtolower((string)($res['error'] ?? ''));
                $ignoredErrors = ['gateway_disabled', 'token_missing', 'gateway_mismatch', 'forbidden', 'missing_invoice', 'invalid_transaction', 'not_found', 'invoice_not_found', 'monobank_invoice_not_found', 'status_request_failed', 'pending', 'noinvoice', 'http 404'];
                if (empty($res['ok']) && $requestedTransactionId === (int)$txId && !in_array($errorCode, $ignoredErrors, true)) {
                    $messages['error'][] = sprintf(__('Не удалось проверить статус платежа #%d (Monobank). Попробуйте обновить страницу позже.'), (int)$txId);
                }
            }
        }
    }
}

if (isset($paymentGateways['binance']) && function_exists('pp_payment_binance_refresh_pending_for_user')) {
    $autoResult = pp_payment_binance_refresh_pending_for_user($userId, $requestedTransactionId > 0 ? $requestedTransactionId : null, 8);
    if (!empty($autoResult['results']) && is_array($autoResult['results'])) {
        foreach ($autoResult['results'] as $txId => $res) {
            if (!is_array($res)) {
                continue;
            }
            $status = strtolower((string)($res['status'] ?? ''));
            if (!empty($res['status_changed']) && $status === 'confirmed') {
                $messages['success'][] = sprintf(__('Платёж #%d через Binance (Spot) подтверждён, средства зачислены.'), (int)$txId);
            }
        }
    }
}

if (isset($paymentGateways['metamask']) && function_exists('pp_payment_metamask_refresh_pending_for_user')) {
    $autoResult = pp_payment_metamask_refresh_pending_for_user($userId, $requestedTransactionId > 0 ? $requestedTransactionId : null, 8);
    if (!empty($autoResult['results']) && is_array($autoResult['results'])) {
        foreach ($autoResult['results'] as $txId => $res) {
            if (!is_array($res)) { continue; }
            $status = strtolower((string)($res['status'] ?? ''));
            if (!empty($res['status_changed']) && $status === 'confirmed') {
                $messages['success'][] = sprintf(__('Платёж #%d через MetaMask/EVM подтверждён, средства зачислены.'), (int)$txId);
            }
        }
    }
}

if (isset($paymentGateways['crypto_universal']) && function_exists('pp_payment_crypto_universal_refresh_pending_for_user')) {
    $autoResult = pp_payment_crypto_universal_refresh_pending_for_user($userId, $requestedTransactionId > 0 ? $requestedTransactionId : null, 8);
    if (!empty($autoResult['results']) && is_array($autoResult['results'])) {
        foreach ($autoResult['results'] as $txId => $res) {
            if (!is_array($res)) { continue; }
            $status = strtolower((string)($res['status'] ?? ''));
            if (!empty($res['status_changed']) && $status === 'confirmed') {
                $messages['success'][] = sprintf(__('Платёж #%d через Crypto (USDT Multi-Network) подтверждён, средства зачислены.'), (int)$txId);
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
                                    <select class="form-select" id="topup-gateway" name="gateway" required>
                                        <?php foreach ($paymentGateways as $code => $gateway): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $selectedGatewayCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($gateway['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo __('Выбор изменит валюту и инструкцию.'); ?></div>
                                </div>
                                <?php if ($selectedGatewayCode === 'invoice'): ?>
                                    <div class="col-md-6">
                                        <label for="invoice-currency" class="form-label"><?php echo __('Валюта інвойсу'); ?></label>
                                        <select class="form-select" id="invoice-currency" name="invoice_currency" required>
                                            <?php foreach ($invoiceCurrencyOptions as $currencyCode): ?>
                                                <option value="<?php echo htmlspecialchars($currencyCode); ?>" <?php echo $invoiceCurrencySelection === $currencyCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($currencyCode); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text"><?php echo __('Рахунок буде виписаний у вибраній валюті. Сума до оплати перерахується автоматично.'); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-4 d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-gradient"><i class="bi bi-lightning-charge me-1"></i><?php echo __('Создать платёж'); ?></button>
                                <a href="<?php echo pp_url('client/balance.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i><?php echo __('Обновить'); ?></a>
                                <?php if ($selectedGatewayCode && isset($paymentGateways[$selectedGatewayCode])): ?>
                                    <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#paymentInstructionModal">
                                        <i class="bi bi-info-circle me-1"></i><?php echo __('Инструкция'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($selectedGatewayCode && isset($paymentGateways[$selectedGatewayCode])): ?>
                            <?php $selectedGateway = $paymentGateways[$selectedGatewayCode]; ?>
                            <!-- Payment Instruction Modal -->
                            <div class="modal fade modal-glass" id="paymentInstructionModal" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-content--glass">
                                        <div class="modal-ribbon modal-ribbon--info" aria-hidden="true"></div>
                                        <div class="modal-header modal-header--glass">
                                            <h5 class="modal-title"><i class="bi bi-info-circle me-2 text-primary"></i><?php echo __('Инструкция по оплате'); ?> — <?php echo htmlspecialchars($selectedGateway['title']); ?></h5>
                                            <button type="button" class="btn-close btn-close-circle" data-bs-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>"></button>
                                        </div>
                                        <div class="modal-body modal-body--glass">
                                            <?php if (!empty($selectedGateway['instructions'])): ?>
                                                <div class="small mb-0"><?php echo nl2br(htmlspecialchars($selectedGateway['instructions'])); ?></div>
                                            <?php elseif ($selectedGatewayCode === 'monobank'): ?>
                                                <div class="small mb-0"><?php echo __('Оплата происходит на странице Monobank. После успешного перевода и возврата сюда мы проверим счёт и зачислим средства автоматически.'); ?></div>
                                            <?php elseif ($selectedGatewayCode === 'invoice'): ?>
                                                <div class="small mb-0"><?php echo __('Скачайте рахунок-фактуру, оплатіть через банк або онлайн-банк. Після підтвердження платежу сервіс автоматично зачислить кошти на баланс.'); ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small mb-0"><?php echo __('Инструкция не заполнена администратором. Используйте данные провайдера для оплаты.'); ?></div>
                                            <?php endif; ?>
                                            <hr class="my-3">
                                            <?php if ($selectedGatewayCode === 'monobank'): ?>
                                                <div class="text-muted small"><?php echo __('Сохраните вкладку открытой: система регулярно запрашивает статус счёта Monobank и зачисляет оплату сразу после подтверждения.'); ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small"><?php echo __('После подтверждения платежа система автоматически зачислит сумму на ваш баланс.'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer modal-footer--glass">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($createdPayment && !empty($createdPayment['customer_payload'])): ?>
                            <?php
                                $payload = $createdPayment['customer_payload'];
                                $transactionId = (int)($createdPayment['transaction']['id'] ?? 0);
                                $txGatewayCode = strtolower((string)($createdPayment['transaction']['gateway_code'] ?? ''));
                                $isInvoicePayment = $txGatewayCode === 'invoice';
                                $invoiceDownloadToken = $isInvoicePayment ? (string)($payload['invoice_download_token'] ?? '') : '';
                                $invoiceDownloadUrl = ($isInvoicePayment && $transactionId > 0 && $invoiceDownloadToken !== '')
                                    ? pp_url('client/invoice_download.php?txn=' . urlencode((string)$transactionId) . '&token=' . urlencode($invoiceDownloadToken))
                                    : '';
                                $downloadLabel = $invoiceDownloadUrl !== '' ? (string)($payload['download_label'] ?? __('Завантажити PDF-рахунок')) : '';
                                $downloadFileName = $isInvoicePayment ? (string)($payload['download_filename'] ?? '') : '';
                            ?>
                            <div class="nextstep-card" role="region" aria-label="<?php echo __('Следующий шаг'); ?>">
                                <div class="nextstep-card__ribbon" aria-hidden="true"></div>
                                <div class="nextstep-card__body">
                                    <div class="nextstep-card__title"><span class="icon"><i class="bi bi-credit-card"></i></span><span><?php echo __('Следующий шаг'); ?></span></div>
                                    <?php if (!$isInvoicePayment && !empty($createdPayment['payment_url'])): ?>
                                        <p class="mb-2 nextstep-card__meta"><?php echo __('Перейдите по ссылке ниже, чтобы завершить оплату:'); ?></p>
                                        <p class="mb-3"><a class="btn btn-primary" href="<?php echo htmlspecialchars($createdPayment['payment_url']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Открыть страницу оплаты'); ?></a></p>
                                    <?php elseif ($isInvoicePayment && $invoiceDownloadUrl !== ''): ?>
                                        <p class="mb-2 nextstep-card__meta"><?php echo __('Завантажте рахунок-фактуру та оплатіть через ваш банк.'); ?></p>
                                        <p class="mb-3">
                                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($invoiceDownloadUrl); ?>" <?php echo $downloadFileName !== '' ? 'download="' . htmlspecialchars($downloadFileName) . '"' : 'download'; ?> rel="noopener">
                                                <i class="bi bi-file-earmark-arrow-down me-1"></i><?php echo htmlspecialchars($downloadLabel !== '' ? $downloadLabel : __('Скачать інвойс (PDF)')); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if (!empty($payload['message'])): ?>
                                        <p class="mb-3 small text-muted"><?php echo htmlspecialchars($payload['message']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($payload['notes'])): ?>
                                        <p class="mb-3 small text-muted"><?php echo htmlspecialchars($payload['notes']); ?></p>
                                    <?php endif; ?>
                                <?php if (!empty($payload['qr_content'])): ?>
                                    <p class="mb-2"><?php echo __('Отсканируйте QR-код в приложении Binance Pay:'); ?></p>
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?php echo urlencode($payload['qr_content']); ?>" alt="QR" class="border rounded p-2 bg-white" loading="lazy">
                                <?php endif; ?>
                                <?php if (!empty($payload['invoice_id'])): ?>
                                    <p class="mb-0 small text-muted"><?php echo __('Номер счёта'); ?>: <strong><?php echo htmlspecialchars($payload['invoice_id']); ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['invoice_currency'])): ?>
                                    <p class="mb-0 small text-muted"><?php echo __('Валюта рахунку'); ?>: <strong><?php echo htmlspecialchars($payload['invoice_currency']); ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['prepay_id'])): ?>
                                    <p class="mb-0 small text-muted"><?php echo __('Идентификатор платежа'); ?>: <strong><?php echo htmlspecialchars($payload['prepay_id']); ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($payload['commission_note'])): ?>
                                    <p class="mb-0 small text-muted mt-2"><?php echo htmlspecialchars($payload['commission_note']); ?></p>
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
                                    <?php elseif (!empty($tx['customer_payload']['invoice_download_token'])): ?>
                                        <?php
                                            $historyDownloadLabel = (string)($tx['customer_payload']['download_label'] ?? __('Скачать інвойс (PDF)'));
                                            $historyDownloadName = (string)($tx['customer_payload']['download_filename'] ?? '');
                                            $historyToken = (string)$tx['customer_payload']['invoice_download_token'];
                                            $historyLink = $historyToken !== '' ? pp_url('client/invoice_download.php?txn=' . urlencode((string)$tx['id']) . '&token=' . urlencode($historyToken)) : '';
                                        ?>
                                        <?php if ($historyLink !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($historyLink); ?>" <?php echo $historyDownloadName !== '' ? 'download="' . htmlspecialchars($historyDownloadName) . '"' : 'download'; ?> rel="noopener"><?php echo htmlspecialchars($historyDownloadLabel); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    <?php elseif (!empty($tx['customer_payload']['payment_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($tx['customer_payload']['payment_url']); ?>" target="_blank" rel="noopener"><?php echo __('Ссылка на оплату'); ?></a>
                                        <?php if (!empty($tx['customer_payload']['commission_note'])): ?>
                                            <div class="text-muted small mt-1"><?php echo htmlspecialchars($tx['customer_payload']['commission_note']); ?></div>
                                        <?php endif; ?>
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

<?php
$autoInvoiceDownloadUrl = '';
$autoInvoiceDownloadName = '';
if ($createdPayment && !empty($createdPayment['customer_payload'])) {
    $autoPayload = $createdPayment['customer_payload'];
    $autoGateway = strtolower((string)($createdPayment['transaction']['gateway_code'] ?? ''));
    if ($autoGateway === 'invoice') {
        $txnId = (int)($createdPayment['transaction']['id'] ?? 0);
        $token = (string)($autoPayload['invoice_download_token'] ?? '');
        if ($txnId > 0 && $token !== '') {
            $autoInvoiceDownloadUrl = pp_url('client/invoice_download.php?txn=' . urlencode((string)$txnId) . '&token=' . urlencode($token));
            $autoInvoiceDownloadName = (string)($autoPayload['download_filename'] ?? '');
        }
    }
}
?>

<?php include '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure the payment instruction modal sits at the <body> root to avoid z-index/stacking issues with backdrop
    var instModalEl = document.getElementById('paymentInstructionModal');
    if (instModalEl && instModalEl.parentElement !== document.body) {
        document.body.appendChild(instModalEl);
    }

    var gatewaySelect = document.getElementById('topup-gateway');
    if (gatewaySelect && gatewaySelect.form) {
        gatewaySelect.addEventListener('change', function() {
            var form = gatewaySelect.form;
            var createInput = form.querySelector('input[name="create_topup"]');
            if (createInput) {
                createInput.disabled = true;
            }
            form.submit();
        });
    }

<?php if ($autoInvoiceDownloadUrl !== ''): ?>
    (function triggerInvoiceDownload() {
        var link = document.createElement('a');
        link.href = <?php echo json_encode($autoInvoiceDownloadUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        link.rel = 'noopener';
        link.target = '_blank';
        <?php if ($autoInvoiceDownloadName !== ''): ?>
        link.download = <?php echo json_encode($autoInvoiceDownloadName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        <?php else: ?>
        link.download = '';
        <?php endif; ?>
        link.style.display = 'none';
        document.body.appendChild(link);
        requestAnimationFrame(function() {
            link.click();
            setTimeout(function() {
                if (link.parentNode) {
                    link.parentNode.removeChild(link);
                }
            }, 1200);
        });
    })();
<?php endif; ?>
});
</script>
