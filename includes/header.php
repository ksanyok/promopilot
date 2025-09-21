<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PromoPilot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="../assets/img/favicon.ico">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="../assets/img/logo.png" alt="PromoPilot" width="30" height="30" class="d-inline-block align-top">
                PromoPilot
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (is_logged_in()): ?>
                        <?php if (is_admin()): ?>
                            <li class="nav-item"><a class="nav-link" href="admin.php">Админка</a></li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="client.php">Дашборд</a></li>
                            <li class="nav-item"><a class="nav-link" href="add_project.php">Добавить проект</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="logout.php">Выход</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Вход</a></li>
                        <li class="nav-item"><a class="nav-link" href="register.php">Регистрация</a></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <form method="post" action="set_lang.php" class="d-inline">
                            <select name="lang" class="form-select form-select-sm d-inline w-auto" onchange="this.form.submit()">
                                <?php
                                $current_lang = $_SESSION['lang'] ?? 'ru';
                                $langs = array_filter(scandir('../lang'), function($file) {
                                    return pathinfo($file, PATHINFO_EXTENSION) == 'php';
                                });
                                foreach ($langs as $file) {
                                    $code = pathinfo($file, PATHINFO_FILENAME);
                                    $selected = ($code == $current_lang) ? 'selected' : '';
                                    echo "<option value='$code' $selected>$code</option>";
                                }
                                ?>
                            </select>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">