<?php
require_once __DIR__ . '/includes/db.php';

function appendLog($message) {
    $logFile = __DIR__ . '/promotion_log.txt';
    $currentTime = date('Y-m-d H:i:s');
    $logMessage = $currentTime . " - " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function loadEnv($path) {
    $env = [];
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"') {
                $v = stripcslashes(substr($v, 1, -1));
            }
            $env[$k] = $v;
        }
    }
    return $env;
}

function detectNodeBinary($env) {
    // Приоритет: NODE_PATH из .env -> command -v node -> распространенные пути
    if (!empty($env['NODE_PATH']) && is_executable($env['NODE_PATH'])) {
        return $env['NODE_PATH'];
    }
    if (function_exists('shell_exec')) {
        $bin = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($bin && is_executable($bin)) return $bin;
    }
    $candidates = ['/usr/bin/node', '/usr/local/bin/node', '/bin/node'];
    foreach ($candidates as $c) {
        if (is_executable($c)) return $c;
    }
    return 'node'; // Надеемся, что в PATH
}

function processQueue() {
    global $pdo;
    $env = loadEnv(__DIR__ . '/.env');
    $openaiApiKey = $env['OPENAI_API_KEY'] ?? '';
    if ($openaiApiKey === '') {
        appendLog('OPENAI_API_KEY не указан в .env');
        return;
    }

    $nodePath = detectNodeBinary($env);
    $publishScriptPath = __DIR__ . '/auto-publisher/publish.js';

    $stmt = $pdo->prepare("SELECT * FROM publication_queue WHERE status = 'pending'");
    $stmt->execute();
    while ($item = $stmt->fetch()) {
        // Обновляем статус в 'processing'
        $updateStmt = $pdo->prepare("UPDATE publication_queue SET status = 'processing' WHERE id = ?");
        $updateStmt->execute([$item['id']]);

        appendLog("Начало обработки публикации страницы с ID: " . $item['id']);

        $command = escapeshellcmd($nodePath) . ' ' . escapeshellarg($publishScriptPath) . ' '
                   . escapeshellarg($item['page_url']) . ' '
                   . escapeshellarg($item['anchor']) . ' '
                   . escapeshellarg($item['language']) . ' '
                   . escapeshellarg($openaiApiKey);

        appendLog("Выполняется команда: $command");
        $output = function_exists('shell_exec') ? shell_exec($command . ' 2>&1') : '';
        appendLog("Результат выполнения команды: " . $output);

        $processed = false;
        $lines = explode("\n", trim((string)$output));
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
                if (trim($line) !== '') {
                    appendLog("Не удалось опубликовать статью или разобрать результат. Данные: " . $line);
                }
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
