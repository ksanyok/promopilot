<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$user_id = (int)($_GET['user_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $commissionRaw = trim((string)($_POST['referral_commission_percent'] ?? ''));
        $codeRaw = trim((string)($_POST['referral_code'] ?? ''));
        $conn = connect_db();
        // Update commission (allow empty to reset to 0)
        $commission = 0.0;
        if ($commissionRaw !== '') {
            $commission = max(0, min(100, round((float)str_replace(',', '.', $commissionRaw), 2)));
        }
        // Ensure a code — auto-generate if empty
        $refCode = $codeRaw;
        if ($refCode === '') {
            $rand = bin2hex(random_bytes(3));
            $refCode = 'u' . $user_id . '-' . $rand;
        }
        // Best-effort uniqueness
        for ($i = 0; $i < 3; $i++) {
            $stmt = $conn->prepare('UPDATE users SET referral_code = ? WHERE id = ?');
            if (!$stmt) { break; }
            $stmt->bind_param('si', $refCode, $user_id);
            if ($stmt->execute()) { $stmt->close(); break; }
            $stmt->close();
            $refCode .= '-' . substr(bin2hex(random_bytes(1)), 0, 2);
        }
        $stmt = $conn->prepare('UPDATE users SET referral_commission_percent = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('di', $commission, $user_id);
            if ($stmt->execute()) {
                $message = __('Реферальные настройки обновлены!');
            } else {
                $message = __('Ошибка обновления.');
            }
            $stmt->close();
        } else {
            $message = __('Ошибка обновления.');
        }
        $conn->close();
    }
}

$conn = connect_db();
$stmt = $conn->prepare('SELECT username, referral_commission_percent, referral_code FROM users WHERE id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
} else {
    $user = null;
}
$conn->close();

if (!$user) {
    redirect('admin/admin.php');
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4><?php echo __('Реферальная комиссия'); ?> - <?php echo htmlspecialchars($user['username']); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Процент вознаграждения партнёра (%)'); ?></label>
                        <input type="number" step="0.01" min="0" max="100" name="referral_commission_percent" class="form-control" value="<?php echo htmlspecialchars(number_format((float)($user['referral_commission_percent'] ?? 0), 2, '.', '')); ?>">
                        <div class="form-text"><?php echo __('Оставьте пустым для базового значения из настроек программы.'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Реферальный код'); ?></label>
                        <input type="text" name="referral_code" class="form-control" value="<?php echo htmlspecialchars((string)($user['referral_code'] ?? '')); ?>" placeholder="u<?php echo (int)$user_id; ?>-xxxxxx">
                        <div class="form-text"><?php echo __('Можно указать свой. Если оставить пустым — будет сгенерирован.'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo __('Сохранить'); ?></button>
                    <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-secondary"><?php echo __('Вернуться'); ?></a>
                </form>
            </div>
        </div>
    </div>
    </div>
<?php include '../includes/footer.php'; ?>
