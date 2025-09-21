<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Process local (admin) login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($login !== '' && $password !== '') {
        if (admin_login($login, $password)) {
            header('Location: /admin.php');
            exit;
        } else {
            $authError = 'Неверный логин или пароль.';
        }
    } else {
        $authError = 'Введите логин и пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход на сайт</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="main-container">
    <div class="content">
        <h1>Вход</h1>
        <p>Выберите способ авторизации: по логину и паролю администратора или через Google.</p>

        <?php if (!empty($authError)): ?>
            <div class="alert error"><?php echo htmlspecialchars($authError); ?></div>
        <?php endif; ?>

        <div class="auth-grid">
            <div class="auth-card">
                <h3>Вход по логину и паролю</h3>
                <form method="post">
                    <input type="text" name="login" placeholder="Логин" required>
                    <input type="password" name="password" placeholder="Пароль" required>
                    <button type="submit" class="btn">Войти</button>
                </form>
            </div>
            <div class="auth-card">
                <h3>Вход через Google</h3>
                <?php if (isset($googleAuthAvailable) && $googleAuthAvailable && !empty($loginUrl)): ?>
                    <p>Используйте ваш Google-аккаунт для входа.</p>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="login-button">Войти через Google</a>
                <?php else: ?>
                    <p>Google вход пока не настроен. Его можно добавить позже, указав GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI в файле .env.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>
