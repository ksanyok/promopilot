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
        $raw = str_replace(',', '.', (string)($_POST['promotion_discount'] ?? '0'));
        $discount = max(0, min(100, round((float)$raw, 2)));
        $conn = connect_db();
        $stmt = $conn->prepare('UPDATE users SET promotion_discount = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('di', $discount, $user_id);
            if ($stmt->execute()) {
                $message = __('Скидка обновлена!');
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
$stmt = $conn->prepare('SELECT username, promotion_discount FROM users WHERE id = ? LIMIT 1');
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
                <h4><?php echo __('Изменить скидку'); ?> - <?php echo htmlspecialchars($user['username']); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('Новая скидка (%)'); ?></label>
                        <input type="number" step="0.01" min="0" max="100" name="promotion_discount" class="form-control" value="<?php echo htmlspecialchars(number_format((float)($user['promotion_discount'] ?? 0), 2, '.', '')); ?>" required>
                        <div class="form-text"><?php echo __('Процент скидки применяется при запуске продвижения. 0 — без скидки.'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo __('Обновить скидку'); ?></button>
                    <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-secondary"><?php echo __('Вернуться'); ?></a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
