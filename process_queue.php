<?php
require_once __DIR__ . '/includes/db.php'; // Подключаем базу данных из папки includes

function appendLog($message) {
    $logFile = __DIR__ . '/promotion_log.txt'; // Лог файл в корневой директории
    $currentTime = date('Y-m-d H:i:s');
    $logMessage = $currentTime . " - " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function processQueue() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM publication_queue WHERE status = 'pending'");
    $stmt->execute();
    while ($item = $stmt->fetch()) {
        // Обновляем статус в 'processing'
        $updateStmt = $pdo->prepare("UPDATE publication_queue SET status = 'processing' WHERE id = ?");
        $updateStmt->execute([$item['id']]);

        appendLog("Начало обработки публикации страницы с ID: " . $item['id']);
        $nodePath = '/home/topbit/.nvm/versions/node/v18.13.0/bin/node';
        $publishScriptPath = __DIR__ . '/auto-publisher/publish.js';

        $openaiApiKey = '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'; // Используйте безопасное хранение ключей

        $command = "$nodePath " . escapeshellarg($publishScriptPath) . " "
                   . escapeshellarg($item['page_url']) . " "
                   . escapeshellarg($item['anchor']) . " "
                   . escapeshellarg($item['language']) . " "
                   . escapeshellarg($openaiApiKey);

        appendLog("Выполняется команда: $command");
        $output = shell_exec($command . ' 2>&1');
        appendLog("Результат выполнения команды: " . $output);

        $processed = false;
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $result = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($result['publishedUrl'])) {
                $publishedUrl = $result['publishedUrl'];
                $title = $result['title'] ?? 'Не указано';
                $author = $result['author'] ?? 'Анонимный автор';
                $network = $result['network'] ?? 'Unknown';

                $sql = "INSERT INTO publications (project_id, page_id, published_url, level, anchor, language, title, author_name, network) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmtPub = $pdo->prepare($sql);
                $executeResult = $stmtPub->execute([$item['project_id'], $item['page_id'], $publishedUrl, 1, $item['anchor'], $item['language'], $title, $author, $network]);

                if ($executeResult) {
                    appendLog("Статья успешно опубликована и сохранена: $publishedUrl");
                    $processed = true;
                } else {
                    $errorInfo = $stmtPub->errorInfo();
                    appendLog("Ошибка при сохранении публикации в базу данных: " . print_r($errorInfo, true));
                }
            } else {
                appendLog("Не удалось опубликовать статью или разобрать результат. Данные для разбора: " . $line);
            }
        }

        if ($processed) {
            // Удаляем запись из очереди после обработки
            $deleteStmt = $pdo->prepare("DELETE FROM publication_queue WHERE id = ?");
            $deleteStmt->execute([$item['id']]);
            appendLog("Запись с ID " . $item['id'] . " удалена из очереди после успешной обработки.");
        } else {
            // Обновляем статус на 'failed', если публикация не удалась
            $updateFailedStmt = $pdo->prepare("UPDATE publication_queue SET status = 'failed' WHERE id = ?");
            $updateFailedStmt->execute([$item['id']]);
        }
    }
}

processQueue();
?>
