<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$message = '';

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
                        if (class_exists('ZipArchive') && $zip->open($tempZip)) {
                            $extractTo = PP_ROOT_PATH . '/temp_update_' . time();
                            if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
                            if ($zip->extractTo($extractTo)) {
                                $zip->close();
                                $source = $extractTo . '/promopilot-' . $sha;
                                if (is_dir($source)) {
                                    // Функция для копирования директории с исключениями
                                    if (!function_exists('pp_copy_dir_update')) {
                                        function pp_copy_dir_update($src, $dst, array $skip = []) {
                                            $src = rtrim($src, '/');
                                            $dst = rtrim($dst, '/');
                                            $dir = opendir($src);
                                            if (!is_dir($dst)) mkdir($dst, 0755, true);
                                            while (false !== ($file = readdir($dir))) {
                                                if ($file === '.' || $file === '..') continue;
                                                $srcPath = $src . '/' . $file;
                                                $dstPath = $dst . '/' . $file;
                                                // Список исключений (относительные пути от корня проекта)
                                                $rel = trim(str_replace(PP_ROOT_PATH, '', $dstPath), '/');
                                                $relLower = strtolower($rel);
                                                $isSkipped = in_array($relLower, $skip, true)
                                                    || (substr($relLower, 0, 15) === 'config/sessions')
                                                    || (substr($relLower, 0, 4) === 'logs')
                                                    || (substr($relLower, 0, 12) === 'node_modules');
                                                if ($isSkipped) { continue; }
                                                if (is_dir($srcPath)) {
                                                    pp_copy_dir_update($srcPath, $dstPath, $skip);
                                                } else {
                                                    // Не перезаписывать локальный конфиг и установщик
                                                    if (in_array($relLower, ['config/config.php','installer.php'], true)) continue;
                                                    copy($srcPath, $dstPath);
                                                }
                                            }
                                            closedir($dir);
                                        }
                                    }
                                    // Применяем копирование с безопасными исключениями
                                    pp_copy_dir_update($source, PP_ROOT_PATH, ['config/config.php','installer.php']);
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
                            // Fallback: try system unzip if available
                            $extractTo = PP_ROOT_PATH . '/temp_update_' . time();
                            if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
                            $unzipCmd = 'unzip -q ' . escapeshellarg($tempZip) . ' -d ' . escapeshellarg($extractTo) . ' 2>&1';
                            @exec($unzipCmd, $outUnzip, $codeUnzip);
                            if ($codeUnzip === 0) {
                                $source = $extractTo . '/promopilot-' . $sha;
                                if (is_dir($source)) {
                                    if (!function_exists('pp_copy_dir_update')) {
                                        function pp_copy_dir_update($src, $dst, array $skip = []) {
                                            $src = rtrim($src, '/');
                                            $dst = rtrim($dst, '/');
                                            $dir = opendir($src);
                                            if (!is_dir($dst)) mkdir($dst, 0755, true);
                                            while (false !== ($file = readdir($dir))) {
                                                if ($file === '.' || $file === '..') continue;
                                                $srcPath = $src . '/' . $file;
                                                $dstPath = $dst . '/' . $file;
                                                $rel = trim(str_replace(PP_ROOT_PATH, '', $dstPath), '/');
                                                $relLower = strtolower($rel);
                                                $isSkipped = in_array($relLower, $skip, true)
                                                    || (substr($relLower, 0, 15) === 'config/sessions')
                                                    || (substr($relLower, 0, 4) === 'logs')
                                                    || (substr($relLower, 0, 12) === 'node_modules');
                                                if ($isSkipped) { continue; }
                                                if (is_dir($srcPath)) {
                                                    pp_copy_dir_update($srcPath, $dstPath, $skip);
                                                } else {
                                                    if (in_array($relLower, ['config/config.php','installer.php'], true)) continue;
                                                    copy($srcPath, $dstPath);
                                                }
                                            }
                                            closedir($dir);
                                        }
                                    }
                                    pp_copy_dir_update($source, PP_ROOT_PATH, ['config/config.php','installer.php']);
                                    rmdir_recursive($extractTo);
                                    unlink($tempZip);
                                    $message = __('Файлы обновлены успешно.');
                                } else {
                                    $message = __('Ошибка: директория с файлами не найдена в архиве.');
                                }
                            } else {
                                $message = __('Ошибка открытия архива.');
                            }
                        }
                    }
                }
            }
        }

        $new_version = get_version();

        $conn = connect_db();
        // Helpers: existence checks
        $tableExists = function(string $table) use ($conn): bool {
            try {
                $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
                if (!$stmt) return false;
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $stmt->store_result();
                $ok = $stmt->num_rows > 0;
                $stmt->close();
                return $ok;
            } catch (Throwable $e) { return false; }
        };
        $columnExists = function(string $table, string $column) use ($conn): bool {
            try {
                $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
                if (!$stmt) return false;
                $stmt->bind_param('ss', $table, $column);
                $stmt->execute();
                $stmt->store_result();
                $ok = $stmt->num_rows > 0;
                $stmt->close();
                return $ok;
            } catch (Throwable $e) { return false; }
        };
        $apply = function(string $sql) use ($conn, &$message): void {
            try {
                if ($conn->query($sql) === true) return;
                $errno = (int)$conn->errno;
                if (!in_array($errno, [1050,1060,1061,1091], true)) {
                    $message .= '<br>SQL error (#' . $errno . '): ' . htmlspecialchars($conn->error);
                }
            } catch (Throwable $e) {
                $code = (int)$e->getCode();
                $msg = $e->getMessage();
                if (!in_array($code, [1050,1060,1061,1091], true) && stripos($msg, 'exists') === false && stripos($msg, 'Duplicate') === false) {
                    $message .= '<br>SQL exception (#' . $code . '): ' . htmlspecialchars($msg);
                }
            }
        };

        // Ensure projects table and columns
        if (!$tableExists('projects')) {
            $apply("CREATE TABLE IF NOT EXISTS `projects` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `links` TEXT NULL,
                `language` VARCHAR(10) NOT NULL DEFAULT 'ru',
                `wishes` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (`user_id`),
                CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $message .= '<br>projects: ' . __('Создана/обновлена таблица.');
        } else {
            if (!$columnExists('projects','links'))    { $apply("ALTER TABLE `projects` ADD COLUMN `links` TEXT NULL"); }
            if (!$columnExists('projects','language')) { $apply("ALTER TABLE `projects` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'"); }
            if (!$columnExists('projects','wishes'))   { $apply("ALTER TABLE `projects` ADD COLUMN `wishes` TEXT NULL"); }
            // tighten language column null/default if needed
            try { $apply("ALTER TABLE `projects` MODIFY COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'"); } catch (Throwable $e) { /* ignore */ }
        }

        // Ensure users.balance
        if (!$columnExists('users','balance')) {
            $apply("ALTER TABLE `users` ADD COLUMN `balance` DECIMAL(12,2) NOT NULL DEFAULT 0");
        }

        // Ensure publications table and columns
        if (!$tableExists('publications')) {
            $apply("CREATE TABLE IF NOT EXISTS `publications` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $message .= '<br>publications: ' . __('Создана/обновлена таблица.');
        } else {
            if (!$columnExists('publications','anchor'))       { $apply("ALTER TABLE `publications` ADD COLUMN `anchor` VARCHAR(255) NULL"); }
            if (!$columnExists('publications','network'))      { $apply("ALTER TABLE `publications` ADD COLUMN `network` VARCHAR(100) NULL"); }
            if (!$columnExists('publications','published_by')) { $apply("ALTER TABLE `publications` ADD COLUMN `published_by` VARCHAR(100) NULL"); }
            if (!$columnExists('publications','post_url'))     { $apply("ALTER TABLE `publications` ADD COLUMN `post_url` TEXT NULL"); }
            if (!$columnExists('publications','created_at'))   { $apply("ALTER TABLE `publications` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"); }
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
                    <?php if ($message): ?>
                        <button type="submit" class="btn btn-danger" disabled><i class="bi bi-check2-circle me-1"></i><?php echo __('Обновлено'); ?></button>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a href="<?php echo pp_url('index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i><?php echo __('На главную'); ?></a>
                            <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i><?php echo __('В админку'); ?></a>
                        </div>
                    <?php else: ?>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить'); ?></button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>