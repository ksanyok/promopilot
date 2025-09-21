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
            <a class="navbar-brand" href="../index.php">
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
                            <li class="nav-item"><a class="nav-link" href="admin.php"><?php echo __('Админка'); ?></a></li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="client.php"><?php echo __('Дашборд'); ?></a></li>
                            <li class="nav-item"><a class="nav-link" href="add_project.php"><?php echo __('Добавить проект'); ?></a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="logout.php"><?php echo __('Выход'); ?></a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php"><?php echo __('Вход'); ?></a></li>
                        <li class="nav-item"><a class="nav-link" href="register.php"><?php echo __('Регистрация'); ?></a></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <div class="btn-group" role="group">
                            <a href="set_lang.php?lang=ru" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'ru') ? 'active' : ''; ?>" title="Русский">RU</a>
                            <a href="set_lang.php?lang=en" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'en') ? 'active' : ''; ?>" title="English">EN</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    <div class="container mt-4">