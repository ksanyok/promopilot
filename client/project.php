<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

$conn = connect_db();
$stmt = $conn->prepare("SELECT p.*, u.username FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "Проект не найден.";
    exit;
}

$project = $result->fetch_assoc();
$conn->close();

// Проверить доступ: админ или владелец
if (!is_admin() && $project['user_id'] != $user_id) {
    echo "Доступ запрещен.";
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h4><?php echo $project['name']; ?></h4>
            </div>
            <div class="card-body">
                <p><strong>Пользователь:</strong> <?php echo $project['username']; ?></p>
                <p><strong>Описание:</strong></p>
                <p><?php echo nl2br($project['description']); ?></p>
                <p><strong>Дата создания:</strong> <?php echo $project['created_at']; ?></p>
                <?php if (is_admin() || $project['user_id'] == $user_id): ?>
                    <!-- Здесь можно добавить кнопки редактирования, удаления и т.д. -->
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>