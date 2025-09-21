<?php
session_start();
require_once __DIR__ . '/includes/db.php'; // Подключаем файл с настройками базы данных

// Проверка авторизации пользователя
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // Если пользователь не авторизован, перенаправляем его на страницу входа
    header('Location: login.php');
    exit;
}

// Обработка отправки формы
if (isset($_POST['addProject'])) {
    $projectName = trim($_POST['projectName']);
    $domain = trim($_POST['domain']);

    // Валидация входных данных
    if (!empty($projectName) && !empty($domain)) {
        // Добавление нового проекта в базу данных
        $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, domain, created_at) VALUES (:user_id, :name, :domain, NOW())");
        $stmt->execute([
            'user_id' => $_SESSION['user_id'],
            'name' => $projectName,
            'domain' => $domain
        ]);
        // Перенаправляем пользователя на страницу со списком проектов
        header('Location: projects.php');
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавить новый проект</title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<!-- Тут подключаем шапку -->
<?php include 'includes/header.php'; ?>

<div class="main-container">

    <!-- Тут подключаем сайдбар -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Основное содержимое страницы -->
    <div class="content">
        <h1>Добавить новый проект</h1>
        <form action="" method="post">
            <input type="text" name="projectName" placeholder="Название проекта" required>
            <input type="text" name="domain" placeholder="Адрес домена" required>
            <button type="submit" name="addProject">Добавить проект</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>