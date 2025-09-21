<?php
session_start();

// Простой инсталлер для PromoPilot

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $host = $_POST['host'];
    $user = $_POST['user'];
    $pass = $_POST['pass'];
    $db = $_POST['db'];
    $admin_user = $_POST['admin_user'];
    $admin_pass = $_POST['admin_pass'];

    // Создать папку config если не существует
    if (!is_dir('config')) {
        mkdir('config', 0755, true);
    }

    // Создать config.php
    $config = "<?php\n\$db_host = '$host';\n\$db_user = '$user';\n\$db_pass = '$pass';\n\$db_name = '$db';\n?>";
    file_put_contents('config/config.php', $config);

    // Подключиться к БД
    $conn = new mysqli($host, $user, $pass);
    if ($conn->connect_error) {
        die("Ошибка подключения: " . $conn->connect_error);
    }

    // Создать БД
    $conn->query("CREATE DATABASE IF NOT EXISTS $db");

    // Выбрать БД
    $conn->select_db($db);

    // Создать таблицы
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        role ENUM('admin', 'client') DEFAULT 'client',
        balance DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        name VARCHAR(100),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Добавить админа
    $admin_pass_hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
    $conn->query("INSERT IGNORE INTO users (username, password, role) VALUES ('$admin_user', '$admin_pass_hashed', 'admin')");

    $conn->close();

    // Скачать/обновить файлы из репозитория
    $project_path = dirname(__FILE__);
    exec("cd /tmp && rm -rf promopilot-main main.zip && wget https://github.com/ksanyok/promopilot/archive/main.zip && unzip main.zip && cp -r promopilot-main/* $project_path/ && rm -rf promopilot-main main.zip 2>&1", $output);
    $output_str = implode("\n", $output);

    // Инициализировать git для будущих обновлений
    exec("cd $project_path && git init && git remote add origin https://github.com/ksanyok/promopilot.git 2>&1", $git_output);
    $output_str .= "\n" . implode("\n", $git_output);

    echo "Установка завершена! Файлы обновлены.<br><pre>" . $output_str . "</pre><br><a href='login.php'>Войти</a>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Установка PromoPilot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-primary">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h3>Установка PromoPilot</h3>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="mb-3">
                                <label>Хост БД:</label>
                                <input type="text" name="host" class="form-control" value="localhost" required>
                            </div>
                            <div class="mb-3">
                                <label>Пользователь БД:</label>
                                <input type="text" name="user" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Пароль БД:</label>
                                <input type="password" name="pass" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label>Имя БД:</label>
                                <input type="text" name="db" class="form-control" value="promopilot" required>
                            </div>
                            <div class="mb-3">
                                <label>Логин админа:</label>
                                <input type="text" name="admin_user" class="form-control" value="admin" required>
                            </div>
                            <div class="mb-3">
                                <label>Пароль админа:</label>
                                <input type="password" name="admin_pass" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-success">Установить</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>