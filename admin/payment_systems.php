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
                if (!in_array($mode, ['merchant', 'wallet', 'spot'], true)) {
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

            case 'metamask':
                $net = strtoupper((string)($_POST['metamask_network'] ?? 'BSC'));
                if (!in_array($net, ['ETH', 'BSC'], true)) { $net = 'BSC'; }
                $unique = isset($_POST['metamask_enable_unique_amount']) ? (int)$_POST['metamask_enable_unique_amount'] : 1;
                $config = [
                    'network' => $net,
                    'recipient_address' => trim((string)($_POST['metamask_address'] ?? '')),
                    'api_key' => trim((string)($_POST['metamask_api_key'] ?? '')),
                    'enable_unique_amount' => $unique ? 1 : 0,
                ];
                break;

            case 'crypto_universal':
                $uniqueCU = isset($_POST['cryptou_enable_unique_amount']) ? (int)$_POST['cryptou_enable_unique_amount'] : 1;
                $config = [
                    'usdt_trc20_address' => trim((string)($_POST['cryptou_usdt_trc20'] ?? '')),
                    'usdt_erc20_address' => trim((string)($_POST['cryptou_usdt_erc20'] ?? '')),
                    'usdt_bep20_address' => trim((string)($_POST['cryptou_usdt_bep20'] ?? '')),
                    'etherscan_api_key' => trim((string)($_POST['cryptou_etherscan_api_key'] ?? '')),
                    'bscscan_api_key' => trim((string)($_POST['cryptou_bscscan_api_key'] ?? '')),
                    'enable_unique_amount' => $uniqueCU ? 1 : 0,
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
$metamask = $gateways['metamask'] ?? pp_payment_gateway_get('metamask');
$cryptoU = $gateways['crypto_universal'] ?? pp_payment_gateway_get('crypto_universal');

$monobankConfig = $monobank['config'] ?? [];
$binanceConfig = $binance['config'] ?? [];
$metamaskConfig = $metamask['config'] ?? [];
$cryptoUConfig = $cryptoU['config'] ?? [];
$monobankMarkupValue = isset($monobankConfig['usd_markup_percent']) ? (float)$monobankConfig['usd_markup_percent'] : 5.0;
$monobankManualRateValue = (string)($monobankConfig['usd_manual_rate'] ?? '');
$binanceMode = strtolower((string)($binanceConfig['mode'] ?? 'merchant'));
if (!in_array($binanceMode, ['merchant', 'wallet', 'spot'], true)) {
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
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($cryptoU['title'] ?? 'Crypto (USDT Multi-Network)'); ?></h2>
                    <p class="mb-0 text-muted small"><?php echo __('Единый приём USDT: TRC20, ERC20, BEP20. Автоподтверждение по блокчейн-сканерам.'); ?></p>
                </div>
                <span class="badge bg-<?php echo !empty($cryptoU['is_enabled']) ? 'success' : 'secondary'; ?>">
                    <?php echo !empty($cryptoU['is_enabled']) ? __('Включено') : __('Отключено'); ?>
                </span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="gateway_code" value="crypto_universal">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cryptou-title"><?php echo __('Название'); ?></label>
                            <input type="text" class="form-control" id="cryptou-title" name="title" value="<?php echo htmlspecialchars($cryptoU['title'] ?? 'Crypto (USDT Multi-Network)'); ?>" placeholder="Crypto (USDT)">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="cryptou-enabled" name="is_enabled" <?php echo !empty($cryptoU['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cryptou-enabled"><?php echo __('Включить приём USDT'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cryptou-trc"><?php echo __('Адрес USDT TRC20'); ?></label>
                            <input type="text" class="form-control" id="cryptou-trc" name="cryptou_usdt_trc20" value="<?php echo htmlspecialchars($cryptoUConfig['usdt_trc20_address'] ?? ''); ?>" placeholder="T...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cryptou-erc"><?php echo __('Адрес USDT ERC20'); ?></label>
                            <input type="text" class="form-control" id="cryptou-erc" name="cryptou_usdt_erc20" value="<?php echo htmlspecialchars($cryptoUConfig['usdt_erc20_address'] ?? ''); ?>" placeholder="0x...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cryptou-bep"><?php echo __('Адрес USDT BEP20'); ?></label>
                            <input type="text" class="form-control" id="cryptou-bep" name="cryptou_usdt_bep20" value="<?php echo htmlspecialchars($cryptoUConfig['usdt_bep20_address'] ?? ''); ?>" placeholder="0x...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cryptou-ethscan"><?php echo __('API Key Etherscan'); ?></label>
                            <input type="text" class="form-control" id="cryptou-ethscan" name="cryptou_etherscan_api_key" value="<?php echo htmlspecialchars($cryptoUConfig['etherscan_api_key'] ?? ''); ?>" placeholder="ethscan_xxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cryptou-bscscan"><?php echo __('API Key BscScan'); ?></label>
                            <input type="text" class="form-control" id="cryptou-bscscan" name="cryptou_bscscan_api_key" value="<?php echo htmlspecialchars($cryptoUConfig['bscscan_api_key'] ?? ''); ?>" placeholder="bscscan_xxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cryptou-unique"><?php echo __('Уникальная сумма'); ?></label>
                            <select class="form-select" id="cryptou-unique" name="cryptou_enable_unique_amount">
                                <option value="1" <?php echo !empty($cryptoUConfig['enable_unique_amount']) ? 'selected' : ''; ?>><?php echo __('Включено'); ?></option>
                                <option value="0" <?php echo empty($cryptoUConfig['enable_unique_amount']) ? 'selected' : ''; ?>><?php echo __('Отключено'); ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="cryptou-instructions"><?php echo __('Инструкция для клиентов'); ?></label>
                            <textarea class="form-control" id="cryptou-instructions" name="instructions" rows="3" placeholder="<?php echo __('Укажите, что можно платить в любой из сетей USDT — TRC20, ERC20, BEP20.'); ?>"><?php echo htmlspecialchars($cryptoU['instructions'] ?? ''); ?></textarea>
                            <div class="form-text"><i class="bi bi-info-circle text-muted"></i> <?php echo __('Клиент увидит список адресов и точную сумму USDT. После поступления цепочка найдётся сканером; зачисление 1 USDT = 1 USD.'); ?></div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i><?php echo __('Транзакции'); ?></a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
                    </div>
                </form>
                <div class="alert alert-secondary small mt-3" role="alert">
                    <strong><?php echo __('Пошаговая настройка (USDT Multi-Network)'); ?>:</strong>
                    <ol class="mb-0 ps-3">
                        <li><?php echo __('Укажите адреса приёма USDT для нужных сетей (минимум один).'); ?></li>
                        <li><?php echo __('Добавьте API ключи Etherscan/BscScan (только чтение) для авто-подтверждения ERC20/BEP20. Для TRC20 используется TronScan без ключа.'); ?></li>
                        <li><?php echo __('Рекомендуется включить «Уникальную сумму» для надёжного сопоставления депозита.'); ?></li>
                        <li><?php echo __('Сохраните настройки и протестируйте небольшой перевод.'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
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
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($metamask['title'] ?? 'MetaMask / EVM (USDT)'); ?></h2>
                    <p class="mb-0 text-muted small"><?php echo __('Прямой приём USDT на EVM-сети (ETH/BSC) с авто-подтверждением через Etherscan/BscScan.'); ?></p>
                </div>
                <span class="badge bg-<?php echo !empty($metamask['is_enabled']) ? 'success' : 'secondary'; ?>">
                    <?php echo !empty($metamask['is_enabled']) ? __('Включено') : __('Отключено'); ?>
                </span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="gateway_code" value="metamask">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="metamask-title"><?php echo __('Название'); ?></label>
                            <input type="text" class="form-control" id="metamask-title" name="title" value="<?php echo htmlspecialchars($metamask['title'] ?? 'MetaMask / EVM (USDT)'); ?>" placeholder="MetaMask / EVM (USDT)">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="metamask-enabled" name="is_enabled" <?php echo !empty($metamask['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="metamask-enabled"><?php echo __('Включить приём USDT'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="metamask-network"><?php echo __('Сеть'); ?></label>
                            <select class="form-select" id="metamask-network" name="metamask_network">
                                <option value="BSC" <?php echo ($metamaskConfig['network'] ?? 'BSC') === 'BSC' ? 'selected' : ''; ?>>BSC (TRON-пара USDT BEP20)</option>
                                <option value="ETH" <?php echo ($metamaskConfig['network'] ?? '') === 'ETH' ? 'selected' : ''; ?>>Ethereum (USDT ERC20)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="metamask-address"><?php echo __('Адрес получателя (EVM)'); ?></label>
                            <input type="text" class="form-control" id="metamask-address" name="metamask_address" value="<?php echo htmlspecialchars($metamaskConfig['recipient_address'] ?? ''); ?>" placeholder="0x...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="metamask-api-key"><?php echo __('API Key (Etherscan/BscScan)'); ?></label>
                            <input type="text" class="form-control" id="metamask-api-key" name="metamask_api_key" value="<?php echo htmlspecialchars($metamaskConfig['api_key'] ?? ''); ?>" placeholder="scan_xxxxxxxxx">
                            <div class="form-text"><?php echo __('Нужен для авто-подтверждения: только чтение.'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="metamask-unique"><?php echo __('Уникальная сумма'); ?></label>
                            <select class="form-select" id="metamask-unique" name="metamask_enable_unique_amount">
                                <option value="1" <?php echo !empty($metamaskConfig['enable_unique_amount']) ? 'selected' : ''; ?>><?php echo __('Включено'); ?></option>
                                <option value="0" <?php echo empty($metamaskConfig['enable_unique_amount']) ? 'selected' : ''; ?>><?php echo __('Отключено'); ?></option>
                            </select>
                            <div class="form-text"><?php echo __('Добавляет небольшие копейки к сумме для точного сопоставления депозита.'); ?></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="metamask-instructions"><?php echo __('Инструкция для клиентов'); ?></label>
                            <textarea class="form-control" id="metamask-instructions" name="instructions" rows="3" placeholder="<?php echo __('Опишите как перевести USDT через MetaMask на указанный адрес.'); ?>"><?php echo htmlspecialchars($metamask['instructions'] ?? ''); ?></textarea>
                            <div class="form-text"><i class="bi bi-info-circle text-muted"></i> <?php echo __('Клиент увидит адрес, сеть и точную сумму USDT. После поступления на ваш адрес система зачислит 1 USDT = 1 USD.'); ?></div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="<?php echo pp_url('admin/payment_transactions.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i><?php echo __('Транзакции'); ?></a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
                    </div>
                </form>
                <div class="alert alert-secondary small mt-3" role="alert">
                    <strong><?php echo __('Пошаговая настройка (EVM/MetaMask)'); ?>:</strong>
                    <ol class="mb-0 ps-3">
                        <li><?php echo __('Укажите сеть (BSC/Ethereum) и EVM-адрес получателя, который контролируете.'); ?></li>
                        <li><?php echo __('Создайте API ключ на BscScan/Etherscan (только чтение) и укажите его.'); ?></li>
                        <li><?php echo __('Рекомендуется включить «Уникальную сумму», чтобы надёжно сопоставлять депозиты.'); ?></li>
                        <li><?php echo __('Сохраните настройки и протестируйте небольшой перевод.'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($binance['title'] ?? 'Binance (USDT TRC20)'); ?></h2>
                    <p class="mb-0 text-muted small"><?php echo __('Принимает USDT TRC20 через Binance Pay или прямой спотовый депозит на биржевой счёт.'); ?></p>
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
                                <option value="spot" <?php echo $binanceMode === 'spot' ? 'selected' : ''; ?>><?php echo __('Спотовый депозит (USDT TRC20)'); ?></option>
                                <option value="wallet" <?php echo $binanceMode === 'wallet' ? 'selected' : ''; ?>><?php echo __('Простой кошелёк'); ?></option>
                            </select>
                            <div class="form-text"><?php echo __('Выберите «Спотовый депозит», если хотите принимать USDT напрямую на биржевой счёт (1 USDT = 1 USD, авто-зачисление).'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="binance-title"><?php echo __('Название'); ?></label>
                            <input type="text" class="form-control" id="binance-title" name="title" value="<?php echo htmlspecialchars($binance['title'] ?? 'Binance (USDT TRC20)'); ?>" placeholder="Binance">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="binance-enabled" name="is_enabled" <?php echo !empty($binance['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="binance-enabled"><?php echo __('Включить приём платежей'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6 binance-api-field">
                            <label class="form-label" for="binance-api-key"><?php echo __('API Key'); ?></label>
                            <input type="text" class="form-control" id="binance-api-key" name="binance_api_key" value="<?php echo htmlspecialchars($binanceConfig['api_key'] ?? ''); ?>" placeholder="pay_xxxxxxxxx">
                        </div>
                        <div class="col-md-6 binance-api-field">
                            <label class="form-label" for="binance-api-secret"><?php echo __('API Secret'); ?></label>
                            <input type="password" class="form-control" id="binance-api-secret" name="binance_api_secret" value="<?php echo htmlspecialchars($binanceConfig['api_secret'] ?? ''); ?>" placeholder="••••••">
                        </div>
                        <div class="col-md-6 binance-pay-field">
                            <label class="form-label" for="binance-merchant"><?php echo __('Merchant ID (опционально)'); ?></label>
                            <input type="text" class="form-control" id="binance-merchant" name="binance_merchant_id" value="<?php echo htmlspecialchars($binanceConfig['merchant_id'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 binance-pay-field">
                            <label class="form-label" for="binance-return"><?php echo __('Return URL после оплаты'); ?></label>
                            <input type="url" class="form-control" id="binance-return" name="binance_return_url" value="<?php echo htmlspecialchars($binanceConfig['return_url'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(pp_url('client/balance.php')); ?>">
                        </div>
                        <div class="col-md-4 binance-pay-field">
                            <label class="form-label" for="binance-environment"><?php echo __('Среда'); ?></label>
                            <select class="form-select" id="binance-environment" name="binance_environment">
                                <option value="production" <?php echo ($binanceConfig['environment'] ?? 'production') === 'production' ? 'selected' : ''; ?>><?php echo __('Продакшен'); ?></option>
                                <option value="sandbox" <?php echo ($binanceConfig['environment'] ?? '') === 'sandbox' ? 'selected' : ''; ?>><?php echo __('Песочница'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4 binance-pay-field">
                            <label class="form-label" for="binance-terminal"><?php echo __('Тип терминала'); ?></label>
                            <input type="text" class="form-control" id="binance-terminal" name="binance_terminal_type" value="<?php echo htmlspecialchars($binanceConfig['terminal_type'] ?? 'WEB'); ?>" placeholder="WEB">
                        </div>
                        <div class="col-md-4 binance-pay-field">
                            <label class="form-label"><?php echo __('Webhook URL'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(pp_payment_gateway_webhook_url('binance')); ?>" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard?.writeText(<?php echo json_encode(pp_payment_gateway_webhook_url('binance'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <small class="text-muted"><?php echo __('Укажите адрес в настройках уведомлений Binance Pay.'); ?></small>
                        </div>
                        <!-- Merchant mode detailed help -->
                        <div class="col-12 binance-merchant-help">
                            <div class="alert alert-secondary small" role="alert">
                                <strong><?php echo __('Мерчант Binance Pay — пошаговая настройка'); ?>:</strong>
                                <ol class="mb-2 ps-3">
                                    <li><?php echo __('Убедитесь, что у вас одобрен мерчант-аккаунт Binance Pay.'); ?></li>
                                    <li><?php echo __('Перейдите в кабинет Binance Pay → API/Интеграции и создайте ключи.'); ?></li>
                                    <li><?php echo __('Скопируйте API Key и Secret в поля выше. При необходимости укажите Merchant ID.'); ?></li>
                                    <li><?php echo __('Включите подходящую среду: «Продакшен» или «Песочница» для тестов.'); ?></li>
                                    <li><?php echo __('Скопируйте Webhook URL (выше) и укажите его в настройках уведомлений Binance Pay. Выберите события об успешной оплате.'); ?></li>
                                    <li><?php echo __('Укажите Return URL — куда возвращать пользователя после оплаты (например, страницу баланса).'); ?></li>
                                    <li><?php echo __('Сохраните настройки и создайте тестовый платёж на небольшую сумму.'); ?></li>
                                </ol>
                                <div class="text-muted"><?php echo __('Поддерживаемая валюта — USDT. Предпочтительная сеть для клиента — TRC20.'); ?></div>
                            </div>
                        </div>
                        <!-- Spot mode detailed help -->
                        <div class="col-12 binance-spot-help">
                            <div class="alert alert-secondary small" role="alert">
                                <strong><?php echo __('Спотовый депозит (USDT TRC20) — пошаговая настройка'); ?>:</strong>
                                <ol class="mb-2 ps-3">
                                    <li><?php echo __('Создайте биржевые API ключи: Профиль Binance → API Management → Create API.'); ?></li>
                                    <li><?php echo __('Оставьте только разрешение «Enable Reading» (только чтение). Торговля и вывод не нужны.'); ?></li>
                                    <li><?php echo __('Добавьте белый список IP сервера (рекомендуется), если включена проверка IP-адресов.'); ?></li>
                                    <li><?php echo __('Включите режим «Спотовый депозит» здесь, укажите API Key/Secret в поля выше.'); ?></li>
                                    <li><?php echo __('Клиенту будет показан TRC20 адрес и «уникальная» сумма USDT для точной идентификации депозита.'); ?></li>
                                    <li><?php echo __('Вебхук не требуется: система периодически опрашивает историю депозита и зачисляет 1 USDT = 1 USD при совпадении суммы.'); ?></li>
                                </ol>
                                <div class="text-muted"><?php echo __('Сеть: TRC20 (в API Binance это сеть TRX). Адрес подтягивается автоматически из Binance.'); ?></div>
                            </div>
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
        var payFields = document.querySelectorAll('.binance-pay-field');
        var merchantFields = document.querySelectorAll('.binance-merchant-field'); // legacy, keep hidden
        var walletFields = document.querySelectorAll('.binance-wallet-field');
        var apiFields = document.querySelectorAll('.binance-api-field');
        var helpMerchant = document.querySelectorAll('.binance-merchant-help');
        var helpSpot = document.querySelectorAll('.binance-spot-help');
        // Hide legacy group if exists
        merchantFields.forEach(function (el) { el.style.display = 'none'; });
        // Toggle groups
        payFields.forEach(function (el) { el.style.display = (mode === 'merchant') ? '' : 'none'; });
        apiFields.forEach(function (el) { el.style.display = (mode === 'merchant' || mode === 'spot') ? '' : 'none'; });
        walletFields.forEach(function (el) {
            el.style.display = (mode === 'wallet') ? '' : 'none';
        });
        helpMerchant.forEach(function (el) { el.style.display = (mode === 'merchant') ? '' : 'none'; });
        helpSpot.forEach(function (el) { el.style.display = (mode === 'spot') ? '' : 'none'; });
    }
    document.addEventListener('DOMContentLoaded', function () {
        ppToggleBinanceMode('<?php echo $binanceMode; ?>');
    });
</script>

<?php include '../includes/footer.php'; ?>
