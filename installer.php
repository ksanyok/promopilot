<?php
require_once __DIR__ . '/includes/init.php';

$errors = [];
$successOutput = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $host = trim($_POST['host'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    $db   = trim($_POST['db'] ?? 'promopilot');
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = $_POST['admin_pass'] ?? '';
    $get_latest = !empty($_POST['get_latest']);

    if ($host === '' || $user === '' || $db === '' || $admin_user === '' || $admin_pass === '') {
        $errors[] = __('Заполните все обязательные поля.');
    }

    if (!$errors) {
        // Создать папку config если не существует
        if (!is_dir(PP_ROOT_PATH . '/config')) {
            @mkdir(PP_ROOT_PATH . '/config', 0755, true);
        }

        // Создать config.php
        $config = "<?php\n\$db_host = '" . addslashes($host) . "';\n\$db_user = '" . addslashes($user) . "';\n\$db_pass = '" . addslashes($pass) . "';\n\$db_name = '" . addslashes($db) . "';\n?>";
        file_put_contents(PP_ROOT_PATH . '/config/config.php', $config);

        // Подключиться к БД и создать структуру
        $mysqli = @new mysqli($host, $user, $pass);
        if ($mysqli->connect_error) {
            $errors[] = __('Ошибка подключения') . ': ' . $mysqli->connect_error;
        } else {
            $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($mysqli->error) {
                $errors[] = __('Ошибка подключения') . ': ' . $mysqli->error;
            } else {
                $mysqli->select_db($db);
                $mysqli->query("CREATE TABLE IF NOT EXISTS users (\n                    id INT AUTO_INCREMENT PRIMARY KEY,\n                    username VARCHAR(50) UNIQUE,\n                    password VARCHAR(255),\n                    role ENUM('admin','client') DEFAULT 'client',\n                    balance DECIMAL(10,2) DEFAULT 0.00,\n                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $mysqli->query("CREATE TABLE IF NOT EXISTS projects (\n                    id INT AUTO_INCREMENT PRIMARY KEY,\n                    user_id INT,\n                    name VARCHAR(100),\n                    description TEXT,\n                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                $admin_pass_hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, 'admin')");
                $stmt->bind_param('ss', $admin_user, $admin_pass_hashed);
                $stmt->execute();
            }
            $mysqli->close();
        }

        // Опционально скачать последнюю версию (если окружение поддерживает git)
        if (!$errors && $get_latest) {
            $gitAvailable = trim(shell_exec('which git')) !== '';
            if ($gitAvailable) {
                $project_path = PP_ROOT_PATH;
                $output = [];
                if (!is_dir($project_path . '/.git')) {
                    exec("cd " . escapeshellarg($project_path) . " && git init && git remote add origin https://github.com/ksanyok/promopilot.git 2>&1", $output);
                }
                exec("cd " . escapeshellarg($project_path) . " && git fetch origin main && git reset --hard origin/main 2>&1", $output);
                $successOutput .= '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
            } else {
                $successOutput .= '<div class="alert alert-warning">git недоступен на сервере. Пропускаю обновление.</div>';
            }
        }

        if (!$errors) {
            $successOutput .= '<div class="alert alert-success">' . __('Установка завершена!') . '</div>';
        }
    }
}
?>

<?php include __DIR__ . '/includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-warning text-dark d-flex align-items-center gap-2">
                <i class="bi bi-tools"></i>
                <h3 class="m-0"><?php echo __('Установка PromoPilot'); ?></h3>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $e) { echo '<div>' . htmlspecialchars($e) . '</div>'; } ?>
                    </div>
                <?php endif; ?>
                <?php echo $successOutput; ?>

                <form method="post" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Хост БД'); ?>:</label>
                        <input type="text" name="host" class="form-control" value="<?php echo htmlspecialchars($_POST['host'] ?? 'localhost'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Имя БД'); ?>:</label>
                        <input type="text" name="db" class="form-control" value="<?php echo htmlspecialchars($_POST['db'] ?? 'promopilot'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Пользователь БД'); ?>:</label>
                        <input type="text" name="user" class="form-control" value="<?php echo htmlspecialchars($_POST['user'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Пароль БД'); ?>:</label>
                        <input type="password" name="pass" class="form-control" value="<?php echo htmlspecialchars($_POST['pass'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Логин админа'); ?>:</label>
                        <input type="text" name="admin_user" class="form-control" value="<?php echo htmlspecialchars($_POST['admin_user'] ?? 'admin'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo __('Пароль админа'); ?>:</label>
                        <input type="password" name="admin_pass" class="form-control" required>
                    </div>
                    <div class="col-12 form-check">
                        <input class="form-check-input" type="checkbox" id="get_latest" name="get_latest" <?php echo !empty($_POST['get_latest']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="get_latest">Скачать последнюю версию из репозитория (требуется git)</label>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i><?php echo __('Установить'); ?></button>
                        <a href="<?php echo pp_url('auth/login.php'); ?>" class="btn btn-outline-primary"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Перейдите на страницу входа'); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>