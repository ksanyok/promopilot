<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$projectIdFromUrl = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

// Загружаем проект из базы данных
$project = null; // Инициализация переменной $project
if ($projectIdFromUrl !== null) {
    $stmt = $pdo->prepare("SELECT id, name, domain, created_at FROM projects WHERE id = :id AND user_id = :user_id");
    if (!$stmt->execute(['id' => $projectIdFromUrl, 'user_id' => $_SESSION['user_id']])) {
        var_dump($stmt->errorInfo()); // Вывести информацию об ошибке, если запрос не был успешен
    } else {
        $project = $stmt->fetch();
        if (!$project) {
            echo "Проект не найден или доступ к нему запрещён.";
            exit;
        }
    }
} else {
    echo "Неверный формат ID проекта.";
    exit;
}

// Проверяем, была ли отправлена форма добавления страницы
if (isset($_POST['addPage'])) {
    // Обработка данных формы и добавление страницы в базу данных
    $pageUrl = $_POST['pageUrl'] ?? '';
    $anchor = $_POST['anchor'] ?? '';
    $language = $_POST['language'] ?? '';
    $wishes = $_POST['wishes'] ?? '';

    // Проверка наличия данных
    if (!empty($pageUrl) && !empty($anchor) && !empty($language)) {
        appendLog("Попытка добавить страницу с URL: $pageUrl"); // Логируем попытку добавления страницы
        // Добавляем страницу в базу данных
        $stmt = $pdo->prepare("INSERT INTO pages (project_id, page_url, anchor, language, wishes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$projectIdFromUrl, $pageUrl, $anchor, $language, $wishes]);
        appendLog("Страница с URL: $pageUrl успешно добавлена"); // Логируем успешное добавление страницы

        // Перенаправляем пользователя, чтобы предотвратить повторную отправку формы
        header("Location: project.php?id=$projectIdFromUrl&added=true");
        exit;
    } else {
        appendLog("Форма добавления страницы была отправлена с неполными данными."); // Логируем попытку отправки неполных данных
        echo "Пожалуйста, заполните все поля формы.";
    }
}


// Проверяем, была ли отправлена форма удаления страницы
if (isset($_POST['deletePage'])) {
    $pageIdToDelete = $_POST['pageIdToDelete'];
    $stmt = $pdo->prepare("DELETE FROM pages WHERE id = ? AND project_id = ?");
    $stmt->execute([$pageIdToDelete, $projectIdFromUrl]);
    // Перенаправляем пользователя, чтобы предотвратить повторную отправку формы
    header("Location: project.php?id=$projectIdFromUrl&deleted=true");
    exit;
}

// Получаем страницы для выбранного проекта
$stmt = $pdo->prepare("SELECT id, page_url, anchor, language FROM pages WHERE project_id = :project_id");
$stmt->execute(['project_id' => $projectIdFromUrl]);
$pages = $stmt->fetchAll();

// Проверяем, была ли выбрана страница проекта для отображения списка страниц
$selectedProjectId = $_POST['projectId'] ?? null;

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <!-- Здесь используем htmlspecialchars безопасно, так как $project['name'] контролируется нами -->
    <title>Проект: <?= htmlspecialchars($project['name']) ?></title>
    <link rel="stylesheet" href="/styles/main.css">
</head>
<body>

<!-- Тут подключаем шапку -->
 <?php include 'includes/header.php'; ?>

<div class="main-container">

    <!-- Тут подключаем сайдбар -->
    <?php include 'includes/sidebar.php'; ?> 

	<div class="content">
	
		<?php if ($project): ?>
			<div class="project-info">
				<h1><?= htmlspecialchars($project['name']); ?></h1>
				<p>Домен: <?= htmlspecialchars($project['domain']); ?></p>
				<p>Дата создания: <?= htmlspecialchars($project['created_at']); ?></p>
			</div>
			
			<h2>Добавить страницу для продвижения</h2>
			<form action="" method="post">
				<!-- Используем новую переменную $projectIdFromUrl -->
				<input type="hidden" name="projectId" value="<?= $projectIdFromUrl ?>">
				<input type="text" name="pageUrl" placeholder="URL страницы" required>
				<input type="text" name="anchor" placeholder="Анкор" required>
				<input type="text" name="language" placeholder="Язык" required>
				<textarea name="wishes" placeholder="Пожелания по написанию"></textarea>
				<button type="submit" name="addPage">Добавить страницу</button>
			</form>

			<!-- Используем htmlspecialchars для предотвращения XSS -->
			<h3>Список страниц для продвижения проекта "<?= htmlspecialchars($project['name']); ?>"</h3>
			<table>
				<tr>
					<th>URL страницы</th>
					<th>Анкор</th>
					<th>Язык</th>
					<th>Статус</th>
					<th>Уровень 1</th>
					<th>Уровень 2</th>
					<th>Уровень 3</th>
					<th>Продвинуть</th>
					<th>Удалить</th>
				</tr>
				<?php
// Перед началом вывода таблицы, проверим, какие страницы находятся в очереди
$stmtQueue = $pdo->prepare("SELECT page_id FROM publication_queue WHERE project_id = ?");
$stmtQueue->execute([$projectIdFromUrl]);
$pagesInQueue = $stmtQueue->fetchAll(PDO::FETCH_COLUMN, 0);  // Получаем массив ID страниц, которые в очереди

// Вывод списка страниц
$sql = "SELECT p.id, p.page_url, p.anchor, p.language, 
        (SELECT COUNT(*) FROM publications WHERE page_id = p.id AND level = 1) AS level1,
        (SELECT COUNT(*) FROM publications WHERE page_id = p.id AND level = 2) AS level2,
        (SELECT COUNT(*) FROM publications WHERE page_id = p.id AND level = 3) AS level3
        FROM pages p WHERE project_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$projectIdFromUrl]);
while ($page = $stmt->fetch()) {
    $totalProgress = round(($page['level1'] + $page['level2'] + $page['level3']) / 3 * 100);  // Вычисляем общий прогресс
    $isInQueue = in_array($page['id'], $pagesInQueue);  // Проверяем, находится ли страница в очереди

    echo "<tr>";
    echo "<td>" . htmlspecialchars($page['page_url']) . "</td>";
    echo "<td>" . htmlspecialchars($page['anchor']) . "</td>";
    echo "<td>" . htmlspecialchars($page['language']) . "</td>";

    echo '<td><div class="progress-circle" data-progress="' . $totalProgress . '"></div></td>';

    echo "<td>" . $page['level1'] . "</td>";
    echo "<td>" . $page['level2'] . "</td>";
    echo "<td>" . $page['level3'] . "</td>";
    echo "<td>
            <form method='post'>
                <input type='hidden' name='pageId' value='" . $page['id'] . "'/>";
    // Блокируем кнопку если страница уже в очереди или прогресс 100%
	// Блокируем кнопку если страница уже в очереди или прогресс 100%
	if ($isInQueue || $totalProgress >= 100) {
		echo "<button type='submit' name='promote' class='disabled' disabled>Продвинуть</button>";
	} else {
		echo "<button type='submit' name='promote'>Продвинуть</button>";
	}

    echo "  </form>
          </td>";
    echo "<td>
            <form method='post'>
                <input type='hidden' name='pageIdToDelete' value='" . $page['id'] . "'/>
                <button type='submit' name='deletePage' class='delete-button'>&times;</button>
            </form>
          </td>";
    echo "</tr>";
}


/* 				while ($page = $stmt->fetch()) {
					echo "<tr>";
					echo "<td>" . htmlspecialchars($page['page_url']) . "</td>";
					echo "<td>" . htmlspecialchars($page['anchor']) . "</td>";
					echo "<td>" . htmlspecialchars($page['language']) . "</td>";
					
					echo '<td>
							  <div class="progress-circle" data-progress="' . round($page['level1'] / 3 * 100) . '"></div>
						  </td>';

					echo "<td>" . $page['level1'] . "</td>";
					echo "<td>" . $page['level2'] . "</td>";
					echo "<td>" . $page['level3'] . "</td>";
					echo "<td>
							<form method='post'>
								<input type='hidden' name='pageId' value='" . $page['id'] . "'/>
								<button type='submit' name='promote'>Продвинуть</button>
							</form>
						  </td>";
					echo "<td>
							<form method='post'>
								<input type='hidden' name='pageIdToDelete' value='" . $page['id'] . "'/>
								<button type='submit' name='deletePage' class='delete-button'>&times;</button>
							</form>
						  </td>";
					echo "</tr>";
				} */
				?>
			</table>



		<?php else: ?>
			<p>Проект не найден или доступ к нему запрещён.</p>
		<?php endif; ?>
	</div>

</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>