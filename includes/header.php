<?php
require_once __DIR__ . '/init.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PromoPilot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css'); ?>" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo asset_url('img/favicon.png'); ?>">
</head>
<body>
    <!-- Futuristic neutral background canvas -->
    <div id="bgfx" aria-hidden="true"><canvas id="bgfx-canvas"></canvas></div>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <?php if (empty($pp_hide_brand_logo)): ?>
            <a class="navbar-brand d-flex align-items-center" href="<?php echo pp_url(''); ?>" title="PromoPilot">
                <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="Logo" class="brand-logo">
            </a>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <?php if (is_logged_in()): ?>
                        <?php if (is_admin()): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin.php'); ?>"><i class="bi bi-speedometer2 me-1"></i><?php echo __('Админка'); ?></a></li>
                        <?php else: ?>
                            <!-- Пункты меню для клиентов теперь в sidebar -->
                        <?php endif; ?>
                        <?php if (isset($_SESSION['admin_user_id'])): ?>
                            <?php $retToken = action_token('admin_return', (string)$_SESSION['admin_user_id']); ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin_return.php?t=' . urlencode($retToken)); ?>"><i class="bi bi-arrow-return-left me-1"></i><?php echo __('Вернуться в админку'); ?></a></li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <form method="post" action="<?php echo pp_url('auth/logout.php'); ?>" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="nav-link btn btn-link p-0"><i class="bi bi-box-arrow-right me-1"></i><?php echo __('Выход'); ?></button>
                            </form>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/login.php'); ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Вход'); ?></a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/register.php'); ?>"><i class="bi bi-person-plus me-1"></i><?php echo __('Регистрация'); ?></a></li>
                    <?php endif; ?>
                    <li class="nav-item ms-lg-2">
                        <div class="btn-group" role="group">
                            <a href="<?php echo pp_url('public/set_lang.php?lang=ru'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'ru') ? 'active' : ''; ?>" title="Русский">RU</a>
                            <a href="<?php echo pp_url('public/set_lang.php?lang=en'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'en') ? 'active' : ''; ?>" title="English">EN</a>
                        </div>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <button id="themeToggle" type="button" class="btn btn-sm theme-toggle" title="Переключить тему">
                            <i class="bi bi-moon-stars"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="page-wrap">
    <?php 
    $useContainer = isset($pp_container) ? (bool)$pp_container : !is_admin();
    if ($useContainer): ?>
    <div class="container mt-4">
    <?php endif; ?>