<?php
require_once __DIR__ . '/init.php';
// Fetch current client user short info for navbar avatar/name
$pp_nav_user = null;
if (is_logged_in()) {
    try {
        $conn = connect_db();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $st = $conn->prepare("SELECT username, full_name, avatar FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $r = $st->get_result();
                $pp_nav_user = $r->fetch_assoc() ?: null;
                $st->close();
            }
        }
        $conn->close();
    } catch (Throwable $e) { /* ignore */ }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PromoPilot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css?v=' . rawurlencode(get_version())); ?>" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo asset_url('img/favicon.png'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <script>window.CSRF_TOKEN = '<?php echo htmlspecialchars(get_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?>';</script>
</head>
<body>
    <!-- Futuristic neutral background canvas -->
    <div id="bgfx" aria-hidden="true"><canvas id="bgfx-canvas"></canvas></div>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <?php if (empty($pp_hide_brand_logo)): ?>
            <a class="navbar-brand d-flex align-items-center" href="<?php echo pp_url(''); ?>" title="PromoPilot" aria-label="PromoPilot">
                <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="Logo" class="brand-logo">
            </a>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <?php if (is_logged_in()): ?>
                        <?php if (is_admin()): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin.php'); ?>"><i class="bi bi-speedometer2 me-1"></i><?php echo __('Админка'); ?></a></li>
                        <?php endif; ?>
                        <?php 
                            $dispName = '';
                            $avatarUrl = asset_url('img/logo.png');
                            if ($pp_nav_user) {
                                $dispName = trim((string)($pp_nav_user['full_name'] ?: $pp_nav_user['username']));
                                if (!empty($pp_nav_user['avatar'])) { $avatarUrl = pp_url($pp_nav_user['avatar']); }
                            }
                            if ($dispName === '') {
                                $dispName = is_admin() ? __('Администратор') : __('Профиль');
                            }
                        ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" class="nav-avatar">
                                <span class="d-none d-sm-inline"><?php echo htmlspecialchars($dispName); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                                <?php if (is_admin()): ?>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('admin/admin.php'); ?>"><i class="bi bi-speedometer2 me-2"></i><?php echo __('Админка'); ?></a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('client/client.php'); ?>"><i class="bi bi-grid me-2"></i><?php echo __('Дашборд'); ?></a></li>
                                    <li><a class="dropdown-item" href="<?php echo pp_url('client/settings.php'); ?>"><i class="bi bi-gear me-2"></i><?php echo __('Настройки'); ?></a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="<?php echo pp_url('auth/logout.php'); ?>" class="px-3 py-1">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-link p-0 text-start"><i class="bi bi-box-arrow-right me-2"></i><?php echo __('Выход'); ?></button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                        <?php if (isset($_SESSION['admin_user_id'])): ?>
                            <?php $retToken = action_token('admin_return', (string)$_SESSION['admin_user_id']); ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('admin/admin_return.php?t=' . urlencode($retToken)); ?>"><i class="bi bi-arrow-return-left me-1"></i><?php echo __('Вернуться в админку'); ?></a></li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/login.php'); ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?php echo __('Вход'); ?></a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo pp_url('auth/register.php'); ?>"><i class="bi bi-person-plus me-1"></i><?php echo __('Регистрация'); ?></a></li>
                    <?php endif; ?>
                    <li class="nav-item ms-lg-2">
                        <div class="btn-group" role="group">
                            <a href="<?php echo pp_url('public/set_lang.php?lang=ru'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'ru') ? 'active' : ''; ?>" title="Русский" <?php echo ($current_lang == 'ru') ? 'aria-current="true"' : ''; ?>>RU</a>
                            <a href="<?php echo pp_url('public/set_lang.php?lang=en'); ?>" class="btn btn-outline-light btn-sm <?php echo ($current_lang == 'en') ? 'active' : ''; ?>" title="English" <?php echo ($current_lang == 'en') ? 'aria-current="true"' : ''; ?>>EN</a>
                        </div>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <button id="themeToggle" type="button" class="btn btn-sm theme-toggle" title="<?php echo __('Переключить тему'); ?>" aria-label="<?php echo __('Переключить тему'); ?>" aria-pressed="false">
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
    $pp_container_class = isset($pp_container_class) && is_string($pp_container_class) ? trim($pp_container_class) : 'container';
    if ($useContainer): ?>
    <div class="<?php echo htmlspecialchars($pp_container_class); ?> mt-4">
    <?php endif; ?>
