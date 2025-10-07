<?php
require_once __DIR__ . '/../includes/init.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/admin.php' : 'client/client.php');
}

// Google OAuth availability on register page
$googleEnabled = get_setting('google_oauth_enabled', '0') === '1';
$googleClientId = trim((string)get_setting('google_client_id', ''));
$googleAvailable = $googleEnabled && $googleClientId !== '';
$nextParam = isset($_GET['next']) ? (string)$_GET['next'] : '';
if ($nextParam !== '' && strpos($nextParam, '://') !== false) { $nextParam = ''; }
$googleStartUrl = pp_url('public/google_oauth_start.php' . ($nextParam !== '' ? ('?next=' . urlencode($nextParam)) : ''));

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm  = $_POST['confirm'];

        if ($password !== $confirm) {
            $message = __('Пароли не совпадают.');
        } else {
            $conn = connect_db();
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $message = __('Пользователь с таким логином уже существует.');
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'client')");
                $stmt->bind_param("ss", $username, $hashed);
                if ($stmt->execute()) {
                    $newUserId = (int)$stmt->insert_id;
                    // Assign referral if cookie present and generate referral code
                    try { $conn2 = $conn; if (function_exists('pp_referral_assign_user_if_needed')) { pp_referral_assign_user_if_needed($conn2, $newUserId); } if (function_exists('pp_referral_get_or_create_user_code')) { pp_referral_get_or_create_user_code($conn2, $newUserId); } } catch (Throwable $e) { /* ignore */ }
                    $message = __('Регистрация успешна!') . ' <a href="' . pp_url('auth/login.php') . '">' . __('Войти') . '</a>';
                } else {
                    $message = __('Ошибка регистрации.');
                }
            }
            $conn->close();
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4><?php echo __('Регистрация'); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
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
                    <div class="mb-3">
                        <label><?php echo __('Подтвердите пароль'); ?>:</label>
                        <input type="password" name="confirm" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-person-plus me-1"></i><?php echo __('Зарегистрироваться'); ?></button>
                </form>
                <?php if ($googleAvailable): ?>
                    <div class="text-center text-muted my-3">— <?php echo __('или'); ?> —</div>
                    <a class="btn btn-outline-danger w-100" href="<?php echo htmlspecialchars($googleStartUrl); ?>">
                        <i class="bi bi-google me-1"></i><?php echo __('Войти через Google'); ?>
                    </a>
                <?php endif; ?>
                <p class="mt-3 text-center"><?php echo __('Уже есть аккаунт?'); ?> <a href="<?php echo pp_url('auth/login.php'); ?>"><?php echo __('Войти'); ?></a></p>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>