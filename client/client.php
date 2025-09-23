<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
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

$pp_container = false;
?>

<?php include '../includes/header.php'; ?>

<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content">
<h2><?php echo __('Клиентский дашборд'); ?></h2>

<div class="card mb-4">
    <div class="card-body">
        <h5><?php echo __('Ваш баланс'); ?>: <?php echo htmlspecialchars(format_currency($balance)); ?></h5>
    </div>
</div>

<h3><?php echo __('Ваши проекты'); ?></h3>
<?php if ($projects->num_rows > 0): ?>
    <div class="row">
        <?php while ($project = $projects->fetch_assoc()): ?>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($project['name']); ?></h5>
                        <p class="card-text"><?php echo htmlspecialchars($project['description']); ?></p>
                        <p class="text-muted"><?php echo __('Создан'); ?>: <?php echo htmlspecialchars($project['created_at']); ?></p>
                        <a href="<?php echo pp_url('client/project.php?id=' . (int)$project['id']); ?>" class="btn btn-primary"><?php echo __('Просмотреть'); ?></a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <p><?php echo __('У вас пока нет проектов.'); ?> <a href="<?php echo pp_url('client/add_project.php'); ?>"><?php echo __('Добавить проект'); ?></a></p>
<?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>