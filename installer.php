<?php
// Автономный установщик PromoPilot
// Загружайте только этот файл в корень будущего сайта и открывайте его в браузере

// Мини-локализация (заглушка)
function __(string $s): string { return $s; }

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = __DIR__;
$errors = [];
$successOutput = '';
$log = [];

function logmsg(string $m) {
    global $log;
    $log[] = $m;
}

function has_bin(string $bin): bool {
    $path = trim((string)@shell_exec("which " . escapeshellarg($bin)));
    return $path !== '';
}

function download_file(string $url, string $dest): bool {
    // curl, если есть
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'PromoPilot Installer',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) {
            @unlink($dest);
            logmsg("curl download error: HTTP $code $err");
            return false;
        }
        return true;
    }
    // fallback file_get_contents
    $data = @file_get_contents($url);
    if ($data === false) return false;
    return @file_put_contents($dest, $data) !== false;
}

function rcopy(string $src, string $dst, array $excludeBaseNames = []): bool {
    if (is_file($src)) {
        $base = basename($src);
        if (in_array($base, $excludeBaseNames, true)) return true; // пропустить
        if (!is_dir(dirname($dst))) {
            if (!@mkdir(dirname($dst), 0755, true) && !is_dir(dirname($dst))) return false;
        }
        return copy($src, $dst);
    }
    if (is_dir($src)) {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) return false;
        $dir = opendir($src);
        if (!$dir) return false;
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            if (in_array($file, $excludeBaseNames, true)) continue;
            $ok = rcopy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file, $excludeBaseNames);
            if (!$ok) { closedir($dir); return false; }
        }
        closedir($dir);
        return true;
    }
    return false;
}

function download_repo_into(string $destDir): bool {
    // 1) Пытаемся скачать ZIP с GitHub
    $zipUrl = 'https://codeload.github.com/ksanyok/promopilot/zip/refs/heads/main';
    $zipPath = sys_get_temp_dir() . '/promopilot_' . uniqid() . '.zip';
    $extractRoot = sys_get_temp_dir() . '/pp_extract_' . uniqid();

    logmsg('Скачиваю репозиторий ZIP: ' . $zipUrl);
    if (download_file($zipUrl, $zipPath)) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                @mkdir($extractRoot, 0755, true);
                if (!$zip->extractTo($extractRoot)) {
                    logmsg('Не удалось распаковать ZIP в: ' . $extractRoot);
                } else {
                    $zip->close();
                    // Найти корневую директорию в архиве (обычно promopilot-main)
                    $rootEntries = scandir($extractRoot);
                    $srcDir = '';
                    foreach ($rootEntries as $e) {
                        if ($e === '.' || $e === '..') continue;
                        if (is_dir($extractRoot . '/' . $e)) { $srcDir = $extractRoot . '/' . $e; break; }
                    }
                    if ($srcDir) {
                        logmsg('Копирую файлы в директорию сайта...');
                        // Не перезаписывать текущий установщик
                        $ok = rcopy($srcDir, $destDir, ['installer.php']);
                        if ($ok) {
                            @unlink($zipPath);
                            return true;
                        }
                        logmsg('Ошибка копирования файлов из распакованного архива.');
                    } else {
                        logmsg('Не удалось найти корневую папку в архиве.');
                    }
                }
            } else {
                logmsg('Не удалось открыть ZIP архив.');
            }
        } else {
            logmsg('Расширение ZipArchive недоступно.');
        }
    } else {
        logmsg('Не удалось скачать ZIP архив.');
    }

    // 2) Fallback: git clone во временную папку, затем скопировать
    if (has_bin('git')) {
        $tmpClone = sys_get_temp_dir() . '/pp_git_' . uniqid();
        @mkdir($tmpClone, 0755, true);
        $cmd = 'git clone --depth=1 https://github.com/ksanyok/promopilot.git ' . escapeshellarg($tmpClone) . ' 2>&1';
        logmsg('Пробую git clone...');
        exec($cmd, $out, $code);
        logmsg("git вывод:\n" . implode("\n", (array)$out));
        if ($code === 0 && is_dir($tmpClone)) {
            if (rcopy($tmpClone, $destDir, ['.git', 'installer.php'])) return true;
            logmsg('Ошибка копирования после git clone.');
        } else {
            logmsg('git clone завершился с кодом ' . $code);
        }
    } else {
        logmsg('git недоступен на сервере.');
    }
    return false;
}

function ensure_config(string $root, string $host, string $user, string $pass, string $db, array &$errors): void {
    $configDir = $root . '/config';
    if (!is_dir($configDir) && !@mkdir($configDir, 0755, true)) {
        $errors[] = 'Не удалось создать директорию config/.';
        return;
    }
    $configPath = $configDir . '/config.php';
    $config = "<?php\n"
        . "$" . "db_host = '" . addslashes($host) . "';\n"
        . "$" . "db_user = '" . addslashes($user) . "';\n"
        . "$" . "db_pass = '" . addslashes($pass) . "';\n"
        . "$" . "db_name = '" . addslashes($db) . "';\n";
    $config .= "?>\n";
    if (@file_put_contents($configPath, $config) === false) {
        $errors[] = 'Не удалось записать файл config/config.php.';
    } else {
        logmsg('Создан config/config.php');
    }
}

function setup_database(string $host, string $user, string $pass, string $db, string $admin_user, string $admin_pass, array &$errors): void {
    $mysqli = @new mysqli($host, $user, $pass);
    if ($mysqli->connect_error) {
        $errors[] = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
        return;
    }
    $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if ($mysqli->error) {
        $errors[] = 'Ошибка создания БД: ' . $mysqli->error;
        $mysqli->close();
        return;
    }
    $mysqli->select_db($db);

    $mysqli->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        role ENUM('admin','client') DEFAULT 'client',
        balance DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы users: ' . $mysqli->error;
    }

    $mysqli->query("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        name VARCHAR(100),
        description TEXT,
        links TEXT,
        language VARCHAR(10) DEFAULT 'ru',
        wishes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы projects: ' . $mysqli->error;
    }

    // Publications table
    $mysqli->query("CREATE TABLE IF NOT EXISTS publications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        page_url TEXT NOT NULL,
        anchor VARCHAR(255) NULL,
        network VARCHAR(100) NULL,
        published_by VARCHAR(100) NULL,
        post_url TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (project_id),
        CONSTRAINT fk_publications_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы publications: ' . $mysqli->error;
    }

    $admin_pass_hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, 'admin')");
    if ($stmt) {
        $stmt->bind_param('ss', $admin_user, $admin_pass_hashed);
        $stmt->execute();
        if ($stmt->error) {
            $errors[] = 'Ошибка создания администратора: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $errors[] = 'Ошибка подготовки запроса для создания администратора.';
    }
    $mysqli->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $db   = trim($_POST['db'] ?? 'promopilot');
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = (string)($_POST['admin_pass'] ?? '');

    if ($host === '' || $user === '' || $db === '' || $admin_user === '' || $admin_pass === '') {
        $errors[] = 'Заполните все обязательные поля.';
    }

    // 1) Скачиваем файлы проекта
    if (!$errors) {
        if (download_repo_into($ROOT)) {
            logmsg('Файлы проекта успешно загружены.');
        } else {
            $errors[] = 'Не удалось загрузить файлы проекта из репозитория. Проверьте доступность ZipArchive/cURL или git на сервере.';
        }
    }

    // 2) Создаём config/config.php
    if (!$errors) {
        ensure_config($ROOT, $host, $user, $pass, $db, $errors);
    }

    // 3) Настраиваем БД
    if (!$errors) {
        setup_database($host, $user, $pass, $db, $admin_user, $admin_pass, $errors);
    }

    // 4) Автоподготовка node_runtime и Chromium (best-effort)
    if (!$errors) {
        $fn = __DIR__ . '/includes/functions.php';
        if (file_exists($fn)) {
            require_once $fn;
            $nr = function_exists('pp_ensure_node_runtime_installed') ? @pp_ensure_node_runtime_installed() : false;
            logmsg('node_runtime install: ' . ($nr ? 'OK' : 'SKIP/FAIL'));
            $cr = function_exists('pp_ensure_chromium_available') ? @pp_ensure_chromium_available() : false;
            logmsg('chromium ensure: ' . ($cr ? 'OK' : 'SKIP'));
        } else {
            logmsg('functions.php not found for runtime setup step.');
        }
    }

    if (!$errors) {
        $successOutput .= '<div style="padding:12px;border:1px solid #28a745;color:#155724;background:#d4edda;margin-bottom:12px;">Установка завершена!</div>';
        $successOutput .= '<p><a href="auth/login.php">Перейти на страницу входа</a></p>';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка PromoPilot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background:#f6f8fa; padding:24px; }
        .card { max-width: 820px; margin: 0 auto; background:#fff; border:1px solid #e1e4e8; border-radius:8px; }
        .card-header { padding:16px 20px; border-bottom:1px solid #e1e4e8; background:#fffbe6; }
        .card-body { padding:20px; }
        .row { display:flex; flex-wrap:wrap; gap:12px; }
        .col { flex:1 1 300px; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input[type=text], input[type=password] { width:100%; padding:10px; border:1px solid #ced4da; border-radius:6px; }
        .btn { display:inline-block; padding:10px 16px; border-radius:6px; border:1px solid #28a745; background:#28a745; color:#fff; text-decoration:none; cursor:pointer; }
        .btn:disabled { opacity:.6; cursor:not-allowed; }
        .btn-outline { background:#fff; color:#28a745; }
        .alert { padding:12px; border:1px solid #f5c2c7; background:#f8d7da; color:#842029; margin-bottom:12px; border-radius:6px; }
        pre { background:#f6f8fa; padding:12px; border:1px solid #e1e4e8; border-radius:6px; overflow:auto; max-height:300px; }
        .foot { padding:12px 20px; border-top:1px solid #e1e4e8; color:#6a737d; font-size:12px; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h2 style="margin:0;">Установка PromoPilot</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="alert">
                <?php foreach ($errors as $e) { echo '<div>' . htmlspecialchars((string)$e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'; } ?>
            </div>
        <?php endif; ?>
        <?php echo $successOutput; ?>

        <?php if (empty($successOutput)): ?>
        <form method="post">
            <div class="row">
                <div class="col">
                    <label>Хост БД</label>
                    <input type="text" name="host" value="<?php echo htmlspecialchars($_POST['host'] ?? 'localhost', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                </div>
                <div class="col">
                    <label>Имя БД</label>
                    <input type="text" name="db" value="<?php echo htmlspecialchars($_POST['db'] ?? 'promopilot', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                </div>
                <div class="col">
                    <label>Пользователь БД</label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($_POST['user'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                </div>
                <div class="col">
                    <label>Пароль БД</label>
                    <input type="password" name="pass" value="<?php echo htmlspecialchars($_POST['pass'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                </div>
                <div class="col">
                    <label>Логин админа</label>
                    <input type="text" name="admin_user" value="<?php echo htmlspecialchars($_POST['admin_user'] ?? 'admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                </div>
                <div class="col">
                    <label>Пароль админа</label>
                    <input type="password" name="admin_pass" required>
                </div>
            </div>
            <div style="margin-top:16px; display:flex; gap:8px;">
                <button type="submit" class="btn">Установить</button>
                <a href="auth/login.php" class="btn btn-outline">Страница входа</a>
            </div>
        </form>
        <?php endif; ?>

        <?php if (!empty($log)): ?>
            <h3>Журнал</h3>
            <pre><?php echo htmlspecialchars(implode("\n", $log), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
        <?php endif; ?>
    </div>
    <div class="foot">
        Если после установки всё работает — удалите installer.php с сервера из соображений безопасности.
    </div>
</div>
</body>
</html>