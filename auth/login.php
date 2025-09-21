<?php
require_once __DIR__ . '/../includes/init.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/admin.php' : 'client/client.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $username = $_POST['username'];
        $password = $_POST['password'];

        $conn = connect_db();
        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                pp_session_regenerate();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                redirect($user['role'] === 'admin' ? 'admin/admin.php' : 'client/client.php');
            } else {
                $message = 'Неверный пароль.';
            }
        } else {
            $message = 'Пользователь не найден.';
        }
        $conn->close();
    }
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h4><?php echo __('Вход'); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-danger"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label><?php echo __('Логин'); ?>:</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label><?php echo __('Пароль'); ?>:</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Войти'); ?></button>
                </form>
                <p class="mt-3 text-center"><?php echo __('Нет аккаунта?'); ?> <a href="<?php echo pp_url('auth/register.php'); ?>"><?php echo __('Зарегистрироваться'); ?></a></p>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>