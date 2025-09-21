<?php
require 'db.php';

$selectedProjectId = null;

function appendLog($message) {
    $logFile = 'promotion_log.txt';
    $currentTime = date('Y-m-d H:i:s');
    $logMessage = $currentTime . " - " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['selectProject'])) {
        $selectedProjectId = $_POST['projectId'];
    }

    if (isset($_POST['addProject'])) {
        $name = $_POST['projectName'];
        $sql = "INSERT INTO projects (name) VALUES (?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name]);
        echo "<p>Проект успешно добавлен!</p>";
    } 


if (isset($_POST['promote'])) {
    $pageId = $_POST['pageId'];
    appendLog("Добавление в очередь страницы с ID: $pageId");

    // Запрос к БД для получения данных страницы
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $stmt->execute([$pageId]);
    $page = $stmt->fetch();

    if ($page) {
        $sql = "INSERT INTO publication_queue (page_id, project_id, page_url, anchor, language) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pageId, $page['project_id'], $page['page_url'], $page['anchor'], $page['language']]);
        appendLog("Страница с ID $pageId добавлена в очередь.");
    } else {
        appendLog("Страница с ID $pageId не найдена.");
    }
}

/* 	if (isset($_POST['promote'])) {
		$pageId = $_POST['pageId'];
		appendLog("Начало обработки продвижения страницы с ID: $pageId");

		$stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
		$stmt->execute([$pageId]);
		$page = $stmt->fetch();

		if ($page) {
			$projectId = $page['project_id']; // Получаем ID проекта из таблицы pages
			appendLog("Страница найдена в базе данных: " . print_r($page, true));

			$nodePath = '/home/topbit/.nvm/versions/node/v18.13.0/bin/node';
			$publishScriptPath = __DIR__ . '/../auto-publisher/publish.js';

			$openaiApiKey = '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'; 

			$command = "$nodePath " . escapeshellarg($publishScriptPath) . " "
					   . escapeshellarg($page['page_url']) . " "
					   . escapeshellarg($page['anchor']) . " "
					   . escapeshellarg($page['language']) . " "
					   . escapeshellarg($openaiApiKey);

			appendLog("Выполняется команда: $command");
			$output = shell_exec($command . ' 2>&1');
			appendLog("Результат выполнения команды: " . $output);

			// Разделяем вывод на отдельные JSON строки
			$lines = explode("\n", trim($output));
			foreach ($lines as $line) {
				appendLog("Попытка декодирования JSON: " . $line);
				$result = json_decode($line, true);

				if (json_last_error() === JSON_ERROR_NONE && isset($result['publishedUrl'])) {
					$publishedUrl = $result['publishedUrl'];
					$title = $result['title'] ?? 'Не указано';
					$author = $result['author'] ?? 'Анонимный автор';
					$network = $result['network'] ?? 'Unknown';

					$sql = "INSERT INTO publications (project_id, page_id, published_url, level, anchor, language, title, author_name, network) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
					$stmt = $pdo->prepare($sql);
					$executeResult = $stmt->execute([$projectId, $pageId, $publishedUrl, 1, $page['anchor'], $page['language'], $title, $author, $network]);

					if ($executeResult) {
						appendLog("Статья успешно опубликована и сохранена: $publishedUrl");
					} else {
						$errorInfo = $stmt->errorInfo();
						appendLog("Ошибка при сохранении публикации в базу данных: " . print_r($errorInfo, true));
					}
				} else {
					appendLog("Не удалось опубликовать статью или разобрать результат. Данные для разбора: " . $line);
				}
			}

			// Перенаправляем на ту же страницу с GET-параметром для предотвращения повторной отправки
			header("Location: project.php?id=$projectId&published=true");
			exit;
		} else {
			appendLog("Страница с ID $pageId не найдена.");
		}
	} */

}
?>