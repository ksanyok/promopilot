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
<h2>Админка PromoPilot</h2>

<h3>Пользователи</h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th>Логин</th>
            <th>Роль</th>
            <th>Баланс</th>
            <th>Дата регистрации</th>
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
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<h3>Проекты</h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th>Пользователь</th>
            <th>Название</th>
            <th>Описание</th>
            <th>Дата создания</th>
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