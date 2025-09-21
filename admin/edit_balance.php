<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$user_id = (int)($_GET['user_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $new_balance = (float)$_POST['balance'];
        $conn = connect_db();
        $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->bind_param("di", $new_balance, $user_id);
        if ($stmt->execute()) {
            $message = __('Баланс обновлен!');
        } else {
            $message = __('Ошибка обновления.');
        }
        $conn->close();
    }
}

$conn = connect_db();
$stmt = $conn->prepare("SELECT username, balance FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$conn->close();

if (!$user) {
    redirect('admin/admin.php');
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h4><?php echo __('Изменить баланс'); ?> - <?php echo htmlspecialchars($user['username']); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label><?php echo __('Новый баланс'); ?>:</label>
                        <input type="number" step="0.01" name="balance" class="form-control" value="<?php echo htmlspecialchars($user['balance']); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo __('Обновить баланс'); ?></button>
                    <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-secondary"><?php echo __('Вернуться'); ?></a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>