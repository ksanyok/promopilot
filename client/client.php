<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || is_admin()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$conn = connect_db();

// Получить баланс
$stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$balance = $user['balance'];

// Получить проекты
$stmt = $conn->prepare("SELECT id, name, description, created_at FROM projects WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$projects = $stmt->get_result();

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<div class="sidebar">
    <h3>Клиент</h3>
    <ul>
        <li><a href="client.php">Дашборд</a></li>
        <li><a href="add_project.php">Добавить проект</a></li>
        <li><a href="../logout.php">Выход</a></li>
    </ul>
</div>

<div class="main-content">
<h2>Клиентский дашборд</h2>

<div class="card mb-4">
    <div class="card-body">
        <h5>Ваш баланс: <?php echo $balance; ?> руб.</h5>
    </div>
</div>

<h3>Ваши проекты</h3>
<?php if ($projects->num_rows > 0): ?>
    <div class="row">
        <?php while ($project = $projects->fetch_assoc()): ?>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo $project['name']; ?></h5>
                        <p class="card-text"><?php echo $project['description']; ?></p>
                        <p class="text-muted">Создан: <?php echo $project['created_at']; ?></p>
                        <a href="project.php?id=<?php echo $project['id']; ?>" class="btn btn-primary">Просмотреть</a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <p>У вас пока нет проектов. <a href="add_project.php">Добавить проект</a></p>
<?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>