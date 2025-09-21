<?php
session_start();
require_once __DIR__ . '/includes/db.php'; // Подключаем файл с настройками базы данных

// Проверка авторизации пользователя
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // Если пользователь не авторизован, перенаправляем его на страницу входа
    header('Location: login.php');
    exit;
}

// Проверка наличия параметра id в URL
if (!isset($_GET['id'])) {
    // Если параметр id отсутствует, перенаправляем пользователя на другую страницу или выводим сообщение об ошибке
    header('Location: error.php');
    exit;
}

$projectId = $_GET['id'];

// Получаем проекты текущего пользователя
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND user_id = :user_id");
$stmt->execute(['id' => $projectId, 'user_id' => $_SESSION['user_id']]);
$project = $stmt->fetch();

// Если проект не найден или не принадлежит текущему пользователю, перенаправляем пользователя на другую страницу или выводим сообщение об ошибке
if (!$project) {
    header('Location: error.php');
    exit;
}

// Получаем публикации для выбранного проекта
$stmt = $pdo->prepare("SELECT * FROM publications WHERE project_id = :project_id");
$stmt->execute(['project_id' => $projectId]);
$publications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>История проекта <?= htmlspecialchars($project['name']) ?></title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<!-- Тут подключаем шапку -->
<?php include 'includes/header.php'; ?>

<div class="main-container">

    <!-- Тут подключаем сайдбар -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="content">
        <h1>История проекта <?= htmlspecialchars($project['name']) ?></h1>
        <?php if (empty($publications)): ?>
            <p>Для этого проекта пока нет публикаций.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Заголовок</th>
                        <th>URL</th>
                        <th>Дата публикации</th>
                        <th>Автор</th>
                        <th>Уровень</th>
                        <th>Сеть</th> <!-- Новый заголовок колонки для сети -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publications as $publication): ?>
                        <tr>
                            <td><?= $publication['id'] ?></td>
                            <td><?= htmlspecialchars($publication['title']) ?></td>
                            <td><a href="<?= htmlspecialchars($publication['published_url']) ?>" target="_blank"><?= htmlspecialchars($publication['published_url']) ?></a></td>
                            <td><?= $publication['created_at'] ?></td>
                            <td><?= htmlspecialchars($publication['author_name']) ?></td>
                            <td><?= $publication['level'] ?></td>
                            <td><?= htmlspecialchars($publication['network']) ?></td> <!-- Отображаем сеть -->
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>

