<?php
session_start();
require_once __DIR__ . '/includes/db.php'; // Подключаем файл с настройками базы данных

// Проверка авторизации пользователя
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // Если пользователь не авторизован, перенаправляем его на страницу входа
    header('Location: login.php');
    exit;
}

// Получаем проекты текущего пользователя
$stmt = $pdo->prepare("SELECT * FROM projects WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$projects = $stmt->fetchAll();

// Подключаем шапку и сайдбар
//include 'includes/header.php';
//include 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мои проекты</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<!-- Тут подключаем шапку -->
<?php include 'includes/header.php'; ?>

<div class="main-container">

    <!-- Тут подключаем сайдбар -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="content">
        <h1>Мои проекты</h1>
        <?php if (empty($projects)): ?>
            <p>У вас пока нет проектов.</p>
        <?php else: ?>
            <div class="projects-list">
                <?php foreach ($projects as $project): ?>
                    <div class="project-item">
                        <h2><?= htmlspecialchars($project['name']) ?></h2>
                        <p>Домен: <?= htmlspecialchars($project['domain']) ?></p>
                        <!-- Здесь могут быть другие данные проекта -->
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
