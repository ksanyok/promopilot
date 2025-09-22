<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

// Hide brand logo in top navbar to leave only the corner-brand in admin
$pp_hide_brand_logo = true;

$conn = connect_db();

// Получить пользователей
$users = $conn->query("SELECT id, username, role, balance, created_at FROM users ORDER BY id");

// Получить проекты
$projects = $conn->query("SELECT p.id, p.name, p.description, p.created_at, u.username FROM projects p JOIN users u ON p.user_id = u.id ORDER BY p.id");

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<!-- Логотип на пересечении навбара и сайдбара -->
<div class="corner-brand">
    <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="Logo">
</div>

<div class="sidebar">
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Обзор'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('users')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <circle cx="7" cy="8" r="3"/>
                        <circle cx="17" cy="8" r="3"/>
                        <path d="M2 20c0-3 3-5 5-5s5 2 5 5"/>
                        <path d="M12 20c0-3 3-5 5-5s5 2 5 5"/>
                    </svg>
                    <?php echo __('Пользователи'); ?>
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('projects')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="me-2" aria-hidden="true">
                        <rect x="3" y="4" width="6" height="16" rx="2"/>
                        <rect x="10" y="4" width="6" height="12" rx="2"/>
                        <rect x="17" y="4" width="4" height="8" rx="2"/>
                    </svg>
                    <?php echo __('Проекты'); ?>
                </a>
            </li>
        </ul>
    </div>

    <hr class="menu-separator">

    <div class="menu-block">
        <div class="menu-title"><?php echo __('Инструменты'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('public/scan.php'); ?>" class="menu-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <path d="M4 5h16"/>
                        <path d="M9 3v4"/>
                        <path d="M7 9c2 6 7 9 7 9"/>
                        <path d="M12 12h8"/>
                    </svg>
                    <?php echo __('Сканер локализации'); ?>
                </a>
            </li>
        </ul>
    </div>
</div>

<div class="main-content">
<h2><?php echo __('Админка PromoPilot'); ?></h2>

<div id="users-section">
<h3><?php echo __('Пользователи'); ?></h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php echo __('Логин'); ?></th>
            <th><?php echo __('Роль'); ?></th>
            <th><?php echo __('Баланс'); ?></th>
            <th><?php echo __('Дата регистрации'); ?></th>
            <th><?php echo __('Действия'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $users->fetch_assoc()): ?>
            <tr>
                <td><?php echo (int)$user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['role']); ?></td>
                <td><?php echo htmlspecialchars($user['balance']); ?> руб.</td>
                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                <td>
                    <?php $t = action_token('login_as', (string)$user['id']); ?>
                    <a href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>

<div id="projects-section" style="display:none;">
<h3><?php echo __('Проекты'); ?></h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php echo __('Пользователь'); ?></th>
            <th><?php echo __('Название'); ?></th>
            <th><?php echo __('Описание'); ?></th>
            <th><?php echo __('Дата создания'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($project = $projects->fetch_assoc()): ?>
            <tr>
                <td><?php echo (int)$project['id']; ?></td>
                <td><?php echo htmlspecialchars($project['username']); ?></td>
                <td><?php echo htmlspecialchars($project['name']); ?></td>
                <td><?php echo htmlspecialchars($project['description']); ?></td>
                <td><?php echo htmlspecialchars($project['created_at']); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>

</div><!-- /.main-content -->

<?php include '../includes/footer.php'; ?>