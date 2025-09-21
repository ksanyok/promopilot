<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || is_admin()) {
    redirect('login.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $user_id = $_SESSION['user_id'];

    $conn = connect_db();
    $stmt = $conn->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $name, $description);
    if ($stmt->execute()) {
        $message = 'Проект добавлен! <a href="client.php">Вернуться к дашборду</a>';
    } else {
        $message = 'Ошибка добавления проекта.';
    }
    $conn->close();
}
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
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h4>Добавить новый проект</h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label>Название проекта:</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Описание:</label>
                        <textarea name="description" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning">Добавить проект</button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<?php include '../includes/footer.php'; ?>