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
        promotion_discount DECIMAL(5,2) NOT NULL DEFAULT 0.00,
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

    // Normalized project links
    $mysqli->query("CREATE TABLE IF NOT EXISTS project_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        url TEXT NOT NULL,
        anchor VARCHAR(255) NULL,
        language VARCHAR(10) NOT NULL DEFAULT 'ru',
        wish TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_project_links_project (project_id),
        CONSTRAINT fk_project_links_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы project_links: ' . $mysqli->error;
    }

    // Settings key/value storage
    $mysqli->query("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(191) PRIMARY KEY,
        v TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы settings: ' . $mysqli->error;
    }

    // Payment gateways
    $mysqli->query("CREATE TABLE IF NOT EXISTS payment_gateways (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(60) NOT NULL,
        title VARCHAR(191) NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        config LONGTEXT NULL,
        instructions LONGTEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payment_gateways_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы payment_gateways: ' . $mysqli->error;
    }

    // Payment transactions
    $mysqli->query("CREATE TABLE IF NOT EXISTS payment_transactions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        gateway_code VARCHAR(60) NOT NULL,
        amount DECIMAL(16,2) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        provider_reference VARCHAR(191) NULL,
        provider_payload LONGTEXT NULL,
        customer_payload LONGTEXT NULL,
        error_message TEXT NULL,
        confirmed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payment_tx_user (user_id),
        INDEX idx_payment_tx_status (status),
        INDEX idx_payment_tx_gateway (gateway_code),
        INDEX idx_payment_tx_reference (provider_reference),
        CONSTRAINT fk_payment_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы payment_transactions: ' . $mysqli->error;
    }

    // Balance history
    $mysqli->query("CREATE TABLE IF NOT EXISTS balance_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        delta DECIMAL(12,2) NOT NULL,
        balance_before DECIMAL(12,2) NOT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        source VARCHAR(50) NOT NULL,
        meta_json LONGTEXT NULL,
        created_by_admin_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_balance_history_user (user_id),
        INDEX idx_balance_history_source (source),
        CONSTRAINT fk_balance_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_balance_history_admin FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы balance_history: ' . $mysqli->error;
    }

    // Promotion runs (cascade execution root)
    $mysqli->query("CREATE TABLE IF NOT EXISTS promotion_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        link_id INT NOT NULL,
        target_url TEXT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        stage VARCHAR(32) NOT NULL DEFAULT 'pending_level1',
        initiated_by INT NULL,
        settings_snapshot TEXT NULL,
        charged_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        progress_total INT NOT NULL DEFAULT 0,
        progress_done INT NOT NULL DEFAULT 0,
        error TEXT NULL,
        report_json LONGTEXT NULL,
        started_at TIMESTAMP NULL DEFAULT NULL,
        finished_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_promotion_runs_project (project_id),
        INDEX idx_promotion_runs_link (link_id),
        INDEX idx_promotion_runs_status (status),
        CONSTRAINT fk_promotion_runs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        CONSTRAINT fk_promotion_runs_link FOREIGN KEY (link_id) REFERENCES project_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы promotion_runs: ' . $mysqli->error;
    }

    // Promotion nodes (individual tasks within a run)
    $mysqli->query("CREATE TABLE IF NOT EXISTS promotion_nodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        level INT NOT NULL DEFAULT 1,
        parent_id INT NULL,
        target_url TEXT NOT NULL,
        result_url TEXT NULL,
        network_slug VARCHAR(100) NOT NULL,
        publication_id INT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        anchor_text VARCHAR(255) NULL,
        initiated_by INT NULL,
        queued_at TIMESTAMP NULL DEFAULT NULL,
        started_at TIMESTAMP NULL DEFAULT NULL,
        finished_at TIMESTAMP NULL DEFAULT NULL,
        error TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_promotion_nodes_run (run_id),
        INDEX idx_promotion_nodes_publication (publication_id),
        INDEX idx_promotion_nodes_status (status),
        CONSTRAINT fk_promotion_nodes_run FOREIGN KEY (run_id) REFERENCES promotion_runs(id) ON DELETE CASCADE,
        CONSTRAINT fk_promotion_nodes_parent FOREIGN KEY (parent_id) REFERENCES promotion_nodes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы promotion_nodes: ' . $mysqli->error;
    }

    // Promotion crowd tasks (external submissions)
    $mysqli->query("CREATE TABLE IF NOT EXISTS promotion_crowd_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        node_id INT NULL,
        crowd_link_id INT NULL,
        target_url TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'planned',
        result_url TEXT NULL,
        payload_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_promotion_crowd_run (run_id),
        INDEX idx_promotion_crowd_node (node_id),
        CONSTRAINT fk_promotion_crowd_run FOREIGN KEY (run_id) REFERENCES promotion_runs(id) ON DELETE CASCADE,
        CONSTRAINT fk_promotion_crowd_node FOREIGN KEY (node_id) REFERENCES promotion_nodes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    if ($mysqli->error) {
        $errors[] = 'Ошибка создания таблицы promotion_crowd_tasks: ' . $mysqli->error;
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

    // 4) Применяем актуальную структуру БД и миграции (idempotent)
    if (!$errors) {
        // ensure functions available without full init bootstrap
        require_once __DIR__ . '/includes/functions.php';
        if (function_exists('ensure_schema')) {
            try { ensure_schema(); } catch (Throwable $e) { /* ignore during install */ }
        }
    }

    if (!$errors) {
        $successOutput = '<div class="alert alert-success">Установка успешно завершена. Теперь вы можете <a href="auth/login.php">войти</a>.</div>';
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