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
<h2><?php echo __('Клиентский дашборд'); ?> <i class="bi bi-info-circle info-help" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo __('Обзор ключевых показателей и быстрый доступ к проектам.'); ?>"></i></h2>

<div class="card mb-4">
    <div class="card-body d-flex align-items-start justify-content-between gap-3">
        <div>
            <h5 class="mb-1"><?php echo __('Ваш баланс'); ?>: <?php echo htmlspecialchars(format_currency($balance)); ?></h5>
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i><?php echo __('Баланс используется для запуска и масштабирования публикационных каскадов.'); ?>
            </div>
        </div>
        <div>
            <a href="<?php echo pp_url('client/add_project.php'); ?>" class="btn btn-gradient btn-sm"><i class="bi bi-plus-lg me-1"></i><?php echo __('Новый проект'); ?></a>
        </div>
    </div>
</div>

<h3 class="d-flex align-items-center gap-2 mb-3"><?php echo __('Ваши проекты'); ?> <i class="bi bi-question-circle info-help" data-bs-toggle="tooltip" title="<?php echo __('Каждый проект имеет собственный набор ссылок и историю публикаций.'); ?>"></i></h3>
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