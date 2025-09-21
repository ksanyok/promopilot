<?php
require_once __DIR__ . '/includes/auth.php';

// Handle admin logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    admin_logout();
    header('Location: /admin-login.php');
    exit;
}

admin_require_auth();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель — PromoPilot</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<div class="main-container">
    <div class="content">
        <div class="project-info" style="text-align:left;">
            <h1 style="margin-bottom:8px;">Админ-панель</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($_SESSION['admin_login'] ?? 'admin'); ?>.</p>
        </div>
        <div class="auth-grid" style="margin-top:16px;">
            <div class="auth-card">
                <h3>Проекты</h3>
                <p>Просмотр и управление проектами.</p>
                <a class="btn" href="/projects.php">Открыть проекты</a>
                <div style="height:8px"></div>
                <a class="btn" href="/add-project.php">Добавить проект</a>
            </div>
            <div class="auth-card">
                <h3>Очередь публикаций</h3>
                <p>Контроль очереди на публикации.</p>
                <a class="btn" href="/process_queue.php">Открыть очередь</a>
            </div>
            <div class="auth-card">
                <h3>Навигация</h3>
                <p>Быстрые ссылки и выход.</p>
                <a class="btn" href="/">На главную</a>
                <div style="height:8px"></div>
                <a class="btn" href="/admin.php?action=logout">Выйти из админки</a>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>