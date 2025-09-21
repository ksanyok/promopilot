<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    redirect('login.php');
}

$conn = connect_db();

// Получить пользователей
$users = $conn->query("SELECT id, username, role, balance, created_at FROM users ORDER BY id");

// Получить проекты
$projects = $conn->query("SELECT p.id, p.name, p.description, p.created_at, u.username FROM projects p JOIN users u ON p.user_id = u.id ORDER BY p.id");

$conn->close();
?>

<?php include '../includes/header.php'; ?>
<h2><?php echo __('Админка PromoPilot'); ?></h2>

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
                <td><?php echo $user['id']; ?></td>
                <td><?php echo $user['username']; ?></td>
                <td><?php echo $user['role']; ?></td>
                <td><?php echo $user['balance']; ?> руб.</td>
                <td><?php echo $user['created_at']; ?></td>
                <td>
                    <a href="admin_login_as.php?user_id=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo $user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

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
                <td><?php echo $project['id']; ?></td>
                <td><?php echo $project['username']; ?></td>
                <td><?php echo $project['name']; ?></td>
                <td><?php echo $project['description']; ?></td>
                <td><?php echo $project['created_at']; ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<?php include '../includes/footer.php'; ?>