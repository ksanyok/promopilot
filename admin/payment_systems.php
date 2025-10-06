<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$messages = ['success' => [], 'error' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $messages['error'][] = __('Не удалось сохранить настройки (CSRF).');
    } else {
        $gatewayCode = strtolower(trim((string)($_POST['gateway_code'] ?? '')));
        $title = trim((string)($_POST['title'] ?? ''));
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $config = [];

        switch ($gatewayCode) {
            case 'monobank':
                $instructions = '';
                $markupRaw = str_replace(',', '.', (string)($_POST['monobank_usd_markup'] ?? '5'));
                $markup = is_numeric($markupRaw) ? (float)$markupRaw : 5.0;
                $markup = max(-99.0, min(200.0, $markup));

                $manualRateRaw = str_replace(',', '.', (string)($_POST['monobank_manual_rate'] ?? ''));
                $manualRate = '';
                if ($manualRateRaw !== '' && is_numeric($manualRateRaw)) {
                    $manualRate = number_format((float)$manualRateRaw, 6, '.', '');
                }

                $invoiceLifetime = (int)($_POST['monobank_invoice_lifetime'] ?? 900);
                $invoiceLifetime = max(60, min(86400, $invoiceLifetime));

                $config = [
                    'token' => trim((string)($_POST['monobank_token'] ?? '')),
                    'destination' => trim((string)($_POST['monobank_destination'] ?? '')),
                    'redirect_url' => trim((string)($_POST['monobank_redirect_url'] ?? '')),
                    'invoice_lifetime' => $invoiceLifetime,
                    'environment' => 'production',
                    'usd_markup_percent' => $markup,
                    'usd_manual_rate' => $manualRate,
                ];
                break;

            case 'binance':
                $mode = strtolower((string)($_POST['binance_mode'] ?? 'merchant'));
                if (!in_array($mode, ['merchant', 'wallet'], true)) {
                    $mode = 'merchant';
                }

                $environment = strtolower((string)($_POST['binance_environment'] ?? 'production'));
                $environment = in_array($environment, ['sandbox', 'test'], true) ? 'sandbox' : 'production';

                $terminalType = strtoupper(trim((string)($_POST['binance_terminal_type'] ?? 'WEB')));
                if ($terminalType === '') {
                    $terminalType = 'WEB';
                }

                $walletNetwork = strtoupper(trim((string)($_POST['binance_wallet_network'] ?? 'TRC20')));
                if ($walletNetwork === '') {
                    $walletNetwork = 'TRC20';
                }

                $config = [
                    'api_key' => trim((string)($_POST['binance_api_key'] ?? '')),
                    'api_secret' => trim((string)($_POST['binance_api_secret'] ?? '')),
                    'merchant_id' => trim((string)($_POST['binance_merchant_id'] ?? '')),
                    'return_url' => trim((string)($_POST['binance_return_url'] ?? '')),
                    'environment' => $environment,
                    'terminal_type' => $terminalType,
                    'mode' => $mode,
                    'wallet_address' => trim((string)($_POST['binance_wallet_address'] ?? '')),
                    'wallet_network' => $walletNetwork,
                    'wallet_memo' => trim((string)($_POST['binance_wallet_memo'] ?? '')),
                ];
                break;

            default:
                $messages['error'][] = __('Неизвестная платёжная система.');
                $config = null;
        }

        if (is_array($config)) {
            $saveData = [
                'title' => $title,
                'is_enabled' => $isEnabled,
                'instructions' => $instructions,
                'config' => $config,
            ];
            if (pp_payment_gateway_save($gatewayCode, $saveData)) {
                $messages['success'][] = __('Настройки сохранены.');
            } else {
                $messages['error'][] = __('Не удалось сохранить настройки.');
            }
        }
    }
}

$gateways = pp_payment_gateways(true);
$monobank = $gateways['monobank'] ?? pp_payment_gateway_get('monobank');
$binance = $gateways['binance'] ?? pp_payment_gateway_get('binance');

$monobankConfig = $monobank['config'] ?? [];
$binanceConfig = $binance['config'] ?? [];
$monobankMarkupValue = isset($monobankConfig['usd_markup_percent']) ? (float)$monobankConfig['usd_markup_percent'] : 5.0;
$monobankManualRateValue = (string)($monobankConfig['usd_manual_rate'] ?? '');
$binanceMode = strtolower((string)($binanceConfig['mode'] ?? 'merchant'));
if (!in_array($binanceMode, ['merchant', 'wallet'], true)) {
    $binanceMode = 'merchant';
}

$pp_admin_sidebar_active = 'payment_systems';
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
include '../includes/header.php';
include __DIR__ . '/../includes/admin_sidebar.php';
?>

<div class="main-content fade-in">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h1 class="h3 mb-1"><?php echo __('Платёжные системы'); ?></h1>
            <p class="text-muted mb-0 small"><?php echo __('Управляйте подключениями к платёжным шлюзам и отслеживайте транзакции клиентов.'); ?></p>
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

    <div class="alert alert-info d-flex align-items-start gap-3 mb-4" role="alert">
        <i class="bi bi-lightbulb" aria-hidden="true"></i>
        <div>
            <h2 class="h6 mb-2"><?php echo __('Как работает пополнение баланса'); ?></h2>
            <ul class="mb-0 small ps-3">
                <li><?php echo __('Клиент указывает сумму в USD, система создаёт транзакцию и показывает инструкции по оплате.'); ?></li>
                <li><?php echo __('Для Monobank счёт формируется в гривне: курс USD→UAH подтягивается автоматически и может быть скорректирован на наценку.'); ?></li>
                <li><?php echo __('Для Binance выберите мерчант Binance Pay или простой кошелёк USDT TRC20, если мерчанта нет.'); ?></li>
                <li><?php echo __('Monobank проверяется автоматически после возврата клиента; для других систем при необходимости подтверждайте транзакции вручную или подключайте вебхуки провайдера.'); ?></li>
            </ul>
        </div>
    </div>

    <section class="mb-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($monobank['title'] ?? 'Monobank'); ?></h2>
                    <p class="mb-0 text-muted small"><?php echo __('Принимает платежи в гривне (UAH) через API Monobank.'); ?></p>
                </div>
                <span class="badge bg-<?php echo !empty($monobank['is_enabled']) ? 'success' : 'secondary'; ?>">
                    <?php echo !empty($monobank['is_enabled']) ? __('Включено') : __('Отключено'); ?>
                </span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="gateway_code" value="monobank">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="monobank-title"><?php echo __('Название'); ?></label>
                            <input type="text" class="form-control" id="monobank-title" name="title" value="<?php echo htmlspecialchars($monobank['title'] ?? 'Monobank'); ?>" placeholder="Monobank">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="monobank-enabled" name="is_enabled" <?php echo !empty($monobank['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="monobank-enabled"><?php echo __('Включить приём платежей'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="monobank-token"><?php echo __('X-Token'); ?></label>
                            <input type="text" class="form-control" id="monobank-token" name="monobank_token" value="<?php echo htmlspecialchars($monobankConfig['token'] ?? ''); ?>" placeholder="live_xxxxxxxxx">
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="monobank-lifetime"><?php echo __('Время жизни счёта (сек)'); ?></label>
                            <input type="number" class="form-control" id="monobank-lifetime" name="monobank_invoice_lifetime" value="<?php echo (int)($monobankConfig['invoice_lifetime'] ?? 900); ?>" min="60" max="86400">
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="monobank-markup"><?php echo __('Наценка к курсу USD→UAH (%)'); ?></label>
                            <div class="input-group">
                                <input type="number" step="0.1" class="form-control" id="monobank-markup" name="monobank_usd_markup" value="<?php echo htmlspecialchars(number_format($monobankMarkupValue, 2, '.', '')); ?>">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text"><?php echo __('Добавляется к официальному курсу перед выставлением счёта (по умолчанию +5%).'); ?></div>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="monobank-manual-rate"><?php echo __('Ручной курс USD→UAH (опционально)'); ?></label>
                            <input type="number" step="0.0001" class="form-control" id="monobank-manual-rate" name="monobank_manual_rate" value="<?php echo htmlspecialchars($monobankManualRateValue); ?>" placeholder="41.2500">
                            <div class="form-text"><?php echo __('Используется, если API Monobank недоступен.'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="monobank-destination"><?php echo __('Назначение платежа'); ?></label>
                            <input type="text" class="form-control" id="monobank-destination" name="monobank_destination" value="<?php echo htmlspecialchars($monobankConfig['destination'] ?? ''); ?>" placeholder="PromoPilot пополнение">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="monobank-redirect"><?php echo __('Redirect URL после оплаты'); ?></label>
                            <input type="url" class="form-control" id="monobank-redirect" name="monobank_redirect_url" value="<?php echo htmlspecialchars($monobankConfig['redirect_url'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(pp_url('client/balance.php')); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?php echo __('Webhook URL'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(pp_payment_gateway_webhook_url('monobank')); ?>" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard?.writeText(<?php echo json_encode(pp_payment_gateway_webhook_url('monobank'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <small class="text-muted"><?php echo __('Скопируйте адрес и укажите его в настройках вебхуков Monobank.'); ?></small>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i><?php echo __('Транзакции'); ?></a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="mb-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($binance['title'] ?? 'Binance Pay (USDT TRC20)'); ?></h2>
                    <p class="mb-0 text-muted small"><?php echo __('Принимает криптовалютные платежи USDT TRC20 через Binance Pay.'); ?></p>
                </div>
                <span class="badge bg-<?php echo !empty($binance['is_enabled']) ? 'success' : 'secondary'; ?>">
                    <?php echo !empty($binance['is_enabled']) ? __('Включено') : __('Отключено'); ?>
                </span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="gateway_code" value="binance">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="binance-mode"><?php echo __('Режим подключения'); ?></label>
                            <select class="form-select" id="binance-mode" name="binance_mode" onchange="ppToggleBinanceMode(this.value);">
                                <option value="merchant" <?php echo $binanceMode === 'merchant' ? 'selected' : ''; ?>><?php echo __('Мерчант Binance Pay'); ?></option>
                                <option value="wallet" <?php echo $binanceMode === 'wallet' ? 'selected' : ''; ?>><?php echo __('Простой кошелёк'); ?></option>
                            </select>
                            <div class="form-text"><?php echo __('Выберите «Простой кошелёк», если нет доступа к мерчанту Binance Pay.'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="binance-title"><?php echo __('Название'); ?></label>
                            <input type="text" class="form-control" id="binance-title" name="title" value="<?php echo htmlspecialchars($binance['title'] ?? 'Binance Pay (USDT TRC20)'); ?>" placeholder="Binance Pay">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="binance-enabled" name="is_enabled" <?php echo !empty($binance['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="binance-enabled"><?php echo __('Включить приём платежей'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6 binance-merchant-field">
                            <label class="form-label" for="binance-api-key"><?php echo __('API Key'); ?></label>
                            <input type="text" class="form-control" id="binance-api-key" name="binance_api_key" value="<?php echo htmlspecialchars($binanceConfig['api_key'] ?? ''); ?>" placeholder="pay_xxxxxxxxx">
                        </div>
                        <div class="col-md-6 binance-merchant-field">
                            <label class="form-label" for="binance-api-secret"><?php echo __('API Secret'); ?></label>
                            <input type="password" class="form-control" id="binance-api-secret" name="binance_api_secret" value="<?php echo htmlspecialchars($binanceConfig['api_secret'] ?? ''); ?>" placeholder="••••••">
                        </div>
                        <div class="col-md-6 binance-merchant-field">
                            <label class="form-label" for="binance-merchant"><?php echo __('Merchant ID (опционально)'); ?></label>
                            <input type="text" class="form-control" id="binance-merchant" name="binance_merchant_id" value="<?php echo htmlspecialchars($binanceConfig['merchant_id'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 binance-merchant-field">
                            <label class="form-label" for="binance-return"><?php echo __('Return URL после оплаты'); ?></label>
                            <input type="url" class="form-control" id="binance-return" name="binance_return_url" value="<?php echo htmlspecialchars($binanceConfig['return_url'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(pp_url('client/balance.php')); ?>">
                        </div>
                        <div class="col-md-4 binance-merchant-field">
                            <label class="form-label" for="binance-environment"><?php echo __('Среда'); ?></label>
                            <select class="form-select" id="binance-environment" name="binance_environment">
                                <option value="production" <?php echo ($binanceConfig['environment'] ?? 'production') === 'production' ? 'selected' : ''; ?>><?php echo __('Продакшен'); ?></option>
                                <option value="sandbox" <?php echo ($binanceConfig['environment'] ?? '') === 'sandbox' ? 'selected' : ''; ?>><?php echo __('Песочница'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4 binance-merchant-field">
                            <label class="form-label" for="binance-terminal"><?php echo __('Тип терминала'); ?></label>
                            <input type="text" class="form-control" id="binance-terminal" name="binance_terminal_type" value="<?php echo htmlspecialchars($binanceConfig['terminal_type'] ?? 'WEB'); ?>" placeholder="WEB">
                        </div>
                        <div class="col-md-4 binance-merchant-field">
                            <label class="form-label"><?php echo __('Webhook URL'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(pp_payment_gateway_webhook_url('binance')); ?>" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard?.writeText(<?php echo json_encode(pp_payment_gateway_webhook_url('binance'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <small class="text-muted"><?php echo __('Укажите адрес в настройках уведомлений Binance Pay.'); ?></small>
                        </div>
                        <div class="col-md-6 binance-wallet-field">
                            <label class="form-label" for="binance-wallet-address"><?php echo __('Адрес кошелька USDT'); ?></label>
                            <input type="text" class="form-control" id="binance-wallet-address" name="binance_wallet_address" value="<?php echo htmlspecialchars($binanceConfig['wallet_address'] ?? ''); ?>" placeholder="T...">
                            <div class="form-text"><?php echo __('Укажите TRC20 адрес, на который клиент должен отправить USDT.'); ?></div>
                        </div>
                        <div class="col-md-3 binance-wallet-field">
                            <label class="form-label" for="binance-wallet-network"><?php echo __('Сеть'); ?></label>
                            <input type="text" class="form-control" id="binance-wallet-network" name="binance_wallet_network" value="<?php echo htmlspecialchars($binanceConfig['wallet_network'] ?? 'TRC20'); ?>" placeholder="TRC20">
                        </div>
                        <div class="col-md-3 binance-wallet-field">
                            <label class="form-label" for="binance-wallet-memo"><?php echo __('Memo/Tag (если нужен)'); ?></label>
                            <input type="text" class="form-control" id="binance-wallet-memo" name="binance_wallet_memo" value="<?php echo htmlspecialchars($binanceConfig['wallet_memo'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="binance-instructions"><?php echo __('Инструкция для клиентов'); ?></label>
                            <textarea class="form-control" id="binance-instructions" name="instructions" rows="3" placeholder="<?php echo __('Опишите как оплатить через Binance Pay.'); ?>"><?php echo htmlspecialchars($binance['instructions'] ?? ''); ?></textarea>
                            <div class="form-text"><i class="bi bi-info-circle text-muted"></i> <?php echo __('Добавьте шаги оплаты или перевода USDT на указанный адрес.'); ?></div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i><?php echo __('Транзакции'); ?></a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<script>
    function ppToggleBinanceMode(mode) {
        var merchantFields = document.querySelectorAll('.binance-merchant-field');
        var walletFields = document.querySelectorAll('.binance-wallet-field');
        merchantFields.forEach(function (el) {
            el.style.display = (mode === 'wallet') ? 'none' : '';
        });
        walletFields.forEach(function (el) {
            el.style.display = (mode === 'wallet') ? '' : 'none';
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        ppToggleBinanceMode('<?php echo $binanceMode; ?>');
    });
</script>

<?php include '../includes/footer.php'; ?>
