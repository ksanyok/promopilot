<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$conn = connect_db();
$pp_admin_sidebar_active = 'referral';
include '../includes/header.php';
include __DIR__ . '/../includes/admin_sidebar.php';

$success = '';
$error = '';
if (!empty($_GET['m'])) {
    $msg = (string)$_GET['m'];
    $type = (string)($_GET['t'] ?? 'success');
    if ($type === 'error') { $error = $msg; } else { $success = $msg; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = __('Ошибка сохранения (CSRF).');
    } else {
        $enabled = isset($_POST['referral_enabled']);
        $defaultPercentRaw = str_replace(',', '.', (string)($_POST['referral_default_percent'] ?? '5'));
        $defaultPercent = max(0, min(100, round((float)$defaultPercentRaw, 2)));
        $cookieDays = max(1, min(365, (int)($_POST['referral_cookie_days'] ?? 30)));
        set_settings([
            'referral_enabled' => $enabled ? '1' : '0',
            'referral_default_percent' => number_format($defaultPercent, 2, '.', ''),
            'referral_cookie_days' => (string)$cookieDays,
        ]);
        $success = __('Настройки сохранены.');
    }
}

$refEnabled = get_setting('referral_enabled', '0') === '1';
$refPercent = (string)get_setting('referral_default_percent', '5.00');
$refCookieDays = (int)get_setting('referral_cookie_days', '30');

?>

<div class="main-content fade-in">
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h4 mb-1"><?php echo __('Реферальная программа'); ?></h1>
                <p class="text-muted mb-0 small"><?php echo __('Установите общий процент вознаграждения и повышенную комиссию для отдельных партнёров.'); ?></p>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="mb-5">
        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0"><?php echo __('Общие настройки'); ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="row g-3">
                        <div class="col-md-4 d-flex align-items-center">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ref-enabled" name="referral_enabled" <?php echo $refEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ref-enabled"><?php echo __('Включить реферальную программу'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ref-percent"><?php echo __('Базовый процент вознаграждения (%)'); ?></label>
                            <input type="number" min="0" max="100" step="0.01" class="form-control" id="ref-percent" name="referral_default_percent" value="<?php echo htmlspecialchars($refPercent); ?>">
                            <div class="form-text"><?php echo __('Применяется по умолчанию ко всем партнёрам, если у пользователя не задана персональная ставка.'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ref-cookie-days"><?php echo __('Срок действия реферальной метки (дней)'); ?></label>
                            <input type="number" min="1" max="365" class="form-control" id="ref-cookie-days" name="referral_cookie_days" value="<?php echo (int)$refCookieDays; ?>">
                            <div class="form-text"><?php echo __('Сколько дней хранить идентификатор партнёра в cookie.'); ?></div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="mb-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><?php echo __('Повышенная комиссия для партнёров'); ?></h2>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo pp_url('admin/referral_set_user.php'); ?>" class="row g-3">
                    <?php echo csrf_field(); ?>
                    <div class="col-md-3">
                        <label class="form-label" for="user-id"><?php echo __('ID пользователя'); ?></label>
                        <input type="number" min="1" class="form-control" id="user-id" name="user_id" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="user-commission"><?php echo __('Комиссия партнёра (%)'); ?></label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control" id="user-commission" name="referral_commission_percent" placeholder="например, 10.00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="user-refcode"><?php echo __('Реферальный код (опционально)'); ?></label>
                        <input type="text" class="form-control" id="user-refcode" name="referral_code" placeholder="partner123">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-check2-circle me-1"></i><?php echo __('Сохранить для пользователя'); ?></button>
                    </div>
                    <div class="col-12">
                        <div class="form-text"><?php echo __('Оставьте поле комиссии пустым, чтобы сбросить к базовому значению. Код — опционально; если оставить пустым, будет сгенерирован автоматически.'); ?></div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
