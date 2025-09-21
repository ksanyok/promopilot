<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

$conn = connect_db();
$stmt = $conn->prepare("SELECT p.*, u.username FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    include '../includes/header.php';
    echo '<div class="alert alert-warning">' . __('Проект не найден.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

$project = $result->fetch_assoc();
$conn->close();

// Проверить доступ: админ или владелец
if (!is_admin() && $project['user_id'] != $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h4><?php echo htmlspecialchars($project['name']); ?></h4>
            </div>
            <div class="card-body">
                <p><strong><?php echo __('Пользователь'); ?>:</strong> <?php echo htmlspecialchars($project['username']); ?></p>
                <p><strong><?php echo __('Описание'); ?>:</strong></p>
                <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                <p><strong><?php echo __('Дата создания'); ?>:</strong> <?php echo htmlspecialchars($project['created_at']); ?></p>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>