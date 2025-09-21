<?php
session_start();
include '../includes/functions.php';

if (!is_logged_in() || !is_admin()) {
    redirect('login.php');
}

$message = '';

$migrations = [
    // Пример миграций для будущих версий
    // '1.0.1' => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL;",
    // '1.1.0' => "CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id));",
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_version = include '../config/version.php';

    // Выполнить git pull
    exec('git pull origin main 2>&1', $output);
    $message = 'Обновление выполнено:<br><pre>' . implode("\n", $output) . '</pre>';

    $new_version = include '../config/version.php';

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
        $message .= '<br>Все миграции применены до версии ' . $new_version;
    } else {
        $message .= '<br>Версия уже актуальная.';
    }
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