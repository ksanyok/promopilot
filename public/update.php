<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    redirect('login.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Выполнить git pull
    $output = shell_exec('git pull origin main 2>&1');
    $message = 'Обновление выполнено:<br><pre>' . $output . '</pre>';
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h4>Обновление PromoPilot</h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <p>Нажмите кнопку для обновления до последней версии из репозитория.</p>
                <form method="post">
                    <button type="submit" class="btn btn-danger">Обновить</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>