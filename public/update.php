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
        } elseif ($message == __('Файлы обновлены успешно.')) {
            $message .= '<br>Версия уже актуальная или обновлена.';
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