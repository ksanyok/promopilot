<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $user_id = (int)$_SESSION['user_id'];

        $conn = connect_db();
        $stmt = $conn->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $name, $description);
        if ($stmt->execute()) {
            $message = __('Проект добавлен!') . ' <a href="' . pp_url('client/client.php') . '">' . __('Вернуться к дашборду') . '</a>';
        } else {
            $message = __('Ошибка добавления проекта.');
        }
        $conn->close();
    }
}

$pp_container = false;
?>

<?php include '../includes/header.php'; ?>

<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content">
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h4><?php echo __('Добавить новый проект'); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label><?php echo __('Название проекта'); ?>:</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label><?php echo __('Описание'); ?>:</label>
                        <textarea name="description" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning"><?php echo __('Добавить проект'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<?php include '../includes/footer.php'; ?>