<?php
require_once __DIR__ . '/includes/auth.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    if ($login === '' || $pass === '') {
        $err = 'Введите логин и пароль.';
    } else if (admin_login($login, $pass)) {
        header('Location: /');
        exit;
    } else {
        $err = 'Неверный логин или пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход администратора</title>
    <link rel="stylesheet" href="/styles/main.css">
    <style>
        .login-box { max-width:420px; margin:60px auto; background:#fff; border:1px solid #eee; border-radius:10px; padding:24px; box-shadow:0 8px 24px rgba(0,0,0,0.06); }
        .login-box h1 { margin:0 0 12px; font-size:20px; }
        .field { margin-bottom:12px; }
        .field label { display:block; margin-bottom:6px; font-size:13px; color:#333; }
        .field input { width:100%; padding:10px 12px; border:1px solid #dfe3e8; border-radius:8px; }
        .btn { appearance:none; background:#0f62fe; color:#fff; border:0; padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600; width:100%; }
        .error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 12px; border-radius:8px; margin-bottom:12px; }
    </style>
</head>
<body>
<div class="login-box">
    <h1>Вход администратора</h1>
    <?php if ($err): ?><div class="error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <form method="post">
        <div class="field">
            <label>Логин</label>
            <input type="text" name="login" required>
        </div>
        <div class="field">
            <label>Пароль</label>
            <input type="password" name="password" required>
        </div>
        <button class="btn" type="submit">Войти</button>
    </form>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>