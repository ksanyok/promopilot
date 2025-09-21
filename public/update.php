<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$message = '';

$migrations = [
    // Add future migrations here as 'version' => 'SQL'
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $current_version = get_version();

        // Выполнить git pull
        exec('git pull origin main 2>&1', $output);
        $message = __('Обновление выполнено') . ':<br><pre>' . implode("\n", $output) . '</pre>';

        $new_version = get_version();

        if (version_compare($new_version, $current_version, '>')) {
            $conn = connect_db();
            foreach ($migrations as $ver => $sql) {
                if (version_compare($ver, $current_version, '>') && version_compare($ver, $new_version, '<=')) {
                    if ($conn->query($sql)) {
                        $message .= "<br>Applied migration for version $ver";
                    } else {
                        $message .= "<br>Error in migration $ver: " . $conn->error;
                    }
                }
            }
            $conn->close();
            $message .= '<br>Все миграции применены до версии ' . htmlspecialchars($new_version);
        } else {
            $message .= '<br>Версия уже актуальная.';
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h4><?php echo __('Обновление PromoPilot'); ?></h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <p><?php echo __('Нажмите кнопку для обновления'); ?>.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>