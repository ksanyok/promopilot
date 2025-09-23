<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$message = '';

$migrations = [
    // Split initial columns into separate migrations to avoid all-or-nothing errors
    '1.0.11.1' => "ALTER TABLE `projects` ADD COLUMN `links` TEXT NULL;",
    '1.0.11.2' => "ALTER TABLE `projects` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru';",
    '1.0.11.3' => "ALTER TABLE `projects` ADD COLUMN `wishes` TEXT NULL;",
    // New: publications history table and safe column adds
    '1.0.18' => "CREATE TABLE IF NOT EXISTS `publications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `project_id` INT NOT NULL,
        `page_url` TEXT NOT NULL,
        `anchor` VARCHAR(255) NULL,
        `network` VARCHAR(100) NULL,
        `published_by` VARCHAR(100) NULL,
        `post_url` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (`project_id`),
        CONSTRAINT `fk_publications_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    '1.0.18.1' => "ALTER TABLE `publications` ADD COLUMN `anchor` VARCHAR(255) NULL;",
    '1.0.18.2' => "ALTER TABLE `publications` ADD COLUMN `network` VARCHAR(100) NULL;",
    '1.0.18.3' => "ALTER TABLE `publications` ADD COLUMN `published_by` VARCHAR(100) NULL;",
    '1.0.18.4' => "ALTER TABLE `publications` ADD COLUMN `post_url` TEXT NULL;",
    '1.0.18.5' => "ALTER TABLE `publications` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;",
    '1.0.18.6' => "ALTER TABLE `users` ADD COLUMN `balance` DECIMAL(12,2) NOT NULL DEFAULT 0;",
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $current_version = get_version();

        // Скачать и распаковать свежие файлы из GitHub
        $commitUrl = 'https://api.github.com/repos/ksanyok/promopilot/commits/main';
        $commitResponse = @file_get_contents($commitUrl, false, stream_context_create([
            'http' => [
                'header' => "User-Agent: PromoPilot\r\nAccept: application/vnd.github+json",
                'timeout' => 10,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]));
        if (!$commitResponse) {
            $message = __('Ошибка получения информации о коммите.');
        } else {
            $commitData = json_decode($commitResponse, true);
            if (!$commitData || !isset($commitData['sha'])) {
                $message = __('Ошибка парсинга данных коммита.');
            } else {
                $sha = $commitData['sha'];
                $zipUrl = "https://github.com/ksanyok/promopilot/archive/{$sha}.zip";
                $zipContent = @file_get_contents($zipUrl, false, stream_context_create([
                    'http' => ['timeout' => 60],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]));
                if (!$zipContent) {
                    $message = __('Ошибка скачивания архива.');
                } else {
                    $tempZip = tempnam(sys_get_temp_dir(), 'promo') . '.zip';
                    if (file_put_contents($tempZip, $zipContent) === false) {
                        $message = __('Ошибка сохранения архива.');
                    } else {
                        $zip = new ZipArchive();
                        if ($zip->open($tempZip)) {
                            $extractTo = PP_ROOT_PATH . '/temp_update_' . time();
                            if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
                            if ($zip->extractTo($extractTo)) {
                                $zip->close();
                                $source = $extractTo . '/promopilot-' . $sha;
                                if (is_dir($source)) {
                                    // Функция для копирования директории
                                    function copyDir($src, $dst) {
                                        $dir = opendir($src);
                                        if (!is_dir($dst)) mkdir($dst, 0755, true);
                                        while (false !== ($file = readdir($dir))) {
                                            if ($file != '.' && $file != '..') {
                                                $srcPath = $src . '/' . $file;
                                                $dstPath = $dst . '/' . $file;
                                                if (is_dir($srcPath)) {
                                                    copyDir($srcPath, $dstPath);
                                                } else {
                                                    copy($srcPath, $dstPath);
                                                }
                                            }
                                        }
                                        closedir($dir);
                                    }
                                    copyDir($source, PP_ROOT_PATH);
                                    rmdir_recursive($extractTo);
                                    unlink($tempZip);
                                    $message = __('Файлы обновлены успешно.');
                                } else {
                                    $message = __('Ошибка: директория с файлами не найдена в архиве.');
                                }
                            } else {
                                $zip->close();
                                $message = __('Ошибка распаковки архива.');
                            }
                        } else {
                            $message = __('Ошибка открытия архива.');
                        }
                    }
                }
            }
        }

        $new_version = get_version();

        $conn = connect_db();
        // Safe migration executor that tolerates duplicate/exists errors even when mysqli throws exceptions
        $apply = function(string $ver, string $sql) use ($conn, &$message) {
            try {
                $ok = $conn->query($sql);
                if ($ok) {
                    $message .= "<br>Applied migration for version $ver";
                    return;
                }
                // If mysqli isn't throwing exceptions, handle errno here
                $errno = (int)$conn->errno;
                if (in_array($errno, [1050, 1060, 1061, 1091], true)) {
                    $message .= "<br>Migration for version $ver already applied";
                } else {
                    $message .= "<br>Error in migration $ver: (#$errno) " . $conn->error;
                }
            } catch (Throwable $e) {
                $code = (int)$e->getCode();
                $msg  = (string)$e->getMessage();
                // Known benign cases: table exists, duplicate column/key, can't drop because doesn't exist
                $known = [1050, 1060, 1061, 1091];
                $isKnown = in_array($code, $known, true)
                    || stripos($msg, 'Duplicate') !== false
                    || stripos($msg, 'already exists') !== false
                    || stripos($msg, 'exists') !== false
                    || stripos($msg, 'Can\'t DROP') !== false;
                if ($isKnown) {
                    $message .= "<br>Migration for version $ver already applied";
                } else {
                    $message .= "<br>Error in migration $ver: (#$code) " . htmlspecialchars($msg);
                }
            }
        };
        foreach ($migrations as $ver => $sql) {
            if (version_compare($ver, $new_version, '<=')) {
                $apply($ver, $sql);
            }
        }
        $conn->close();
        $message .= '<br>Все миграции применены до версии ' . htmlspecialchars($new_version);
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