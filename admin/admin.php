<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

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
    <img src="<?php echo asset_url('img/logo.png'); ?>" alt="Logo">
</div>

<div class="sidebar">
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Обзор'); ?></div>
        <ul class="menu-list">
            <li><a href="#" class="menu-item" onclick="ppShowSection('users')"><i class="bi bi-people me-2"></i><?php echo __('Пользователи'); ?></a></li>
            <li><a href="#" class="menu-item" onclick="ppShowSection('projects')"><i class="bi bi-kanban me-2"></i><?php echo __('Проекты'); ?></a></li>
        </ul>
    </div>

    <hr class="menu-separator">

    <div class="menu-block">
        <div class="menu-title"><?php echo __('Инструменты'); ?></div>
        <ul class="menu-list">
            <li><a href="<?php echo pp_url('public/scan.php'); ?>" class="menu-item"><i class="bi bi-translate me-2"></i><?php echo __('Сканер локализации'); ?></a></li>
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