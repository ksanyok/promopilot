<?php
session_start();
require_once __DIR__ . '/includes/db.php';
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
        <h1>Вход на сайт</h1>
        <p>Для продолжения работы с сайтом, пожалуйста, выполните вход через ваш Google аккаунт.</p>
        <a href="<?= htmlspecialchars($loginUrl) ?>" class="login-button">Вход через Google</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>
