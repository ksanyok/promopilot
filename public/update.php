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
        $owner = 'ksanyok';
        $repo = 'promopilot';
        $ua = 'PromoPilot/Updater (+https://github.com/ksanyok/promopilot)';

        // 1) Узнать текущий SHA ветки main
        $sha = '';
        $commitUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/main";
        $commitResponse = @file_get_contents($commitUrl, false, stream_context_create([
            'http' => [
                'header' => [
                    'User-Agent: ' . $ua,
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
                'timeout' => 12,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]));
        if ($commitResponse) {
            $commitData = json_decode($commitResponse, true);
            if (is_array($commitData) && isset($commitData['sha'])) {
                $sha = (string)$commitData['sha'];
            }
        }

        // 2) Сформировать список URL для загрузки архива (пробуем по очереди)
        $zipUrls = [];
        if ($sha !== '') {
            // Быстрый codeload по SHA
            $zipUrls[] = "https://codeload.github.com/{$owner}/{$repo}/zip/{$sha}";
            // Обычный archive по SHA
            $zipUrls[] = "https://github.com/{$owner}/{$repo}/archive/{$sha}.zip";
        }
        // Фоллбек: zip для ветки main
        $zipUrls[] = "https://github.com/{$owner}/{$repo}/archive/refs/heads/main.zip";
        $zipUrls[] = "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/main";

        $zipContent = false;
        $usedUrl = '';
        foreach ($zipUrls as $zurl) {
            $zipContent = @file_get_contents($zurl, false, stream_context_create([
                'http' => [
                    'header' => [
                        'User-Agent: ' . $ua,
                        'Accept: application/zip',
                    ],
                    'timeout' => 60,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]));
            if ($zipContent && strlen($zipContent) > 1024) { $usedUrl = $zurl; break; }
        }

        if (!$zipContent) {
            $message = __('Ошибка скачивания архива.');
        } else {
            $tempZip = tempnam(sys_get_temp_dir(), 'promo') . '.zip';
            if (file_put_contents($tempZip, $zipContent) === false) {
                $message = __('Ошибка сохранения архива.');
            } else {
                $zip = new ZipArchive();
                $extracted = false; $extractTo = PP_ROOT_PATH . '/temp_update_' . time();
                if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);

                if (class_exists('ZipArchive') && $zip->open($tempZip)) {
                    if ($zip->extractTo($extractTo)) { $extracted = true; }
                    $zip->close();
                } else {
                    // Fallback: try system unzip if available
                    $unzipCmd = 'unzip -q ' . escapeshellarg($tempZip) . ' -d ' . escapeshellarg($extractTo) . ' 2>&1';
                    @exec($unzipCmd, $outUnzip, $codeUnzip);
                    if ((int)$codeUnzip === 0) { $extracted = true; }
                }

                if ($extracted) {
                    // Определяем имя корневой директории внутри архива
                    $possibleDirs = [];
                    if ($sha !== '') { $possibleDirs[] = $extractTo . '/promopilot-' . $sha; }
                    $possibleDirs[] = $extractTo . '/promopilot-main';
                    $possibleDirs[] = $extractTo . '/ksanyok-promopilot-' . $sha; // иногда codeload так именует

                    $source = '';
                    foreach ($possibleDirs as $dir) { if ($sha === '' && strpos($dir, $sha) !== false) continue; if (is_dir($dir)) { $source = $dir; break; } }
                    if ($source === '') {
                        // попробуем найти первую директорию внутри распаковки
                        $entries = @scandir($extractTo) ?: [];
                        foreach ($entries as $e) { if ($e === '.' || $e === '..') continue; $p = $extractTo . '/' . $e; if (is_dir($p)) { $source = $p; break; } }
                    }

                    if ($source && is_dir($source)) {
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

                        // Очистить временные файлы и кэш статуса обновления
                        rmdir_recursive($extractTo);
                        @unlink($tempZip);
                        @unlink(PP_ROOT_PATH . '/.cache/update_status.json');

                        $message = __('Файлы обновлены успешно.');
                    } else {
                        $message = __('Ошибка: директория с файлами не найдена в архиве.');
                    }
                } else {
                    $message = __('Ошибка распаковки архива.');
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
        $indexExists = function(string $table, string $index) use ($conn): bool {
            try {
                $stmt = $conn->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
                if (!$stmt) return false;
                $stmt->bind_param('s', $index);
                $stmt->execute();
                $res = $stmt->get_result();
                $ok = ($res && $res->num_rows > 0);
                if ($res) $res->free();
                $stmt->close();
                return $ok;
            } catch (Throwable $e) { return false; }
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
            if (!$columnExists('projects','domain_host')) { $apply("ALTER TABLE `projects` ADD COLUMN `domain_host` VARCHAR(190) NULL AFTER `wishes`"); }
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

        // New in-place: ensure project_links table
        if (!$tableExists('project_links')) {
            $apply("CREATE TABLE IF NOT EXISTS `project_links` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `url` TEXT NOT NULL,
                `anchor` VARCHAR(255) NULL,
                `language` VARCHAR(10) NOT NULL DEFAULT 'ru',
                `wish` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`project_id`),
                CONSTRAINT `fk_project_links_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $message .= '<br>project_links: ' . __('Создана/обновлена таблица.');
        } else {
            // minimal columns checks
            if (!$columnExists('project_links','language'))   { $apply("ALTER TABLE `project_links` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'"); }
            if (!$columnExists('project_links','wish'))       { $apply("ALTER TABLE `project_links` ADD COLUMN `wish` TEXT NULL"); }
            if (!$columnExists('project_links','updated_at')) { $apply("ALTER TABLE `project_links` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); }
        }

        // Ensure page_meta table (for analyzed page metadata)
        if (!$tableExists('page_meta')) {
            $apply("CREATE TABLE IF NOT EXISTS `page_meta` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `url_hash` CHAR(64) NOT NULL,
                `page_url` TEXT NOT NULL,
                `final_url` TEXT NULL,
                `lang` VARCHAR(16) NULL,
                `region` VARCHAR(16) NULL,
                `title` VARCHAR(512) NULL,
                `description` TEXT NULL,
                `canonical` TEXT NULL,
                `published_time` VARCHAR(64) NULL,
                `modified_time` VARCHAR(64) NULL,
                `hreflang_json` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_page_meta_proj_hash` (`project_id`, `url_hash`),
                INDEX (`project_id`),
                CONSTRAINT `fk_page_meta_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $message .= '<br>page_meta: ' . __('Создана/обновлена таблица.');
        } else {
            // critical columns
            foreach ([
                'url_hash' => "ADD COLUMN `url_hash` CHAR(64) NOT NULL AFTER `project_id`",
                'page_url' => "ADD COLUMN `page_url` TEXT NOT NULL AFTER `url_hash`",
                'final_url' => "ADD COLUMN `final_url` TEXT NULL AFTER `page_url`",
                'lang' => "ADD COLUMN `lang` VARCHAR(16) NULL AFTER `final_url`",
                'region' => "ADD COLUMN `region` VARCHAR(16) NULL AFTER `lang`",
                'title' => "ADD COLUMN `title` VARCHAR(512) NULL AFTER `region`",
                'description' => "ADD COLUMN `description` TEXT NULL AFTER `title`",
                'canonical' => "ADD COLUMN `canonical` TEXT NULL AFTER `description`",
                'published_time' => "ADD COLUMN `published_time` VARCHAR(64) NULL AFTER `canonical`",
                'modified_time' => "ADD COLUMN `modified_time` VARCHAR(64) NULL AFTER `published_time`",
                'hreflang_json' => "ADD COLUMN `hreflang_json` TEXT NULL AFTER `modified_time`",
                'created_at' => "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            ] as $field => $ddl) {
                if (!$columnExists('page_meta', $field)) { $apply("ALTER TABLE `page_meta` {$ddl}"); }
            }
            // ensure unique index
            if (!$indexExists('page_meta', 'uniq_page_meta_proj_hash')) {
                $apply("CREATE UNIQUE INDEX `uniq_page_meta_proj_hash` ON `page_meta`(`project_id`,`url_hash`)");
            }
        }

        // Ensure networks table (registry of publishers)
        if (!$tableExists('networks')) {
            $apply("CREATE TABLE IF NOT EXISTS `networks` (
                `slug` VARCHAR(120) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `handler` VARCHAR(255) NOT NULL,
                `handler_type` VARCHAR(50) NOT NULL DEFAULT 'node',
                `meta` TEXT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `is_missing` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $message .= '<br>networks: ' . __('Создана/обновлена таблица.');
        } else {
            // add missing columns (best-effort)
            $netCols = ['description','handler','handler_type','meta','enabled','is_missing','created_at','updated_at'];
            foreach ($netCols as $c) { if (!$columnExists('networks', $c)) { $apply("ALTER TABLE `networks` ADD COLUMN `{$c}` TEXT NULL"); } }
            // adjust types where relevant (handler/handler_type)
            try { if ($columnExists('networks','handler')) { $apply("ALTER TABLE `networks` MODIFY COLUMN `handler` VARCHAR(255) NOT NULL"); } } catch (Throwable $e) {}
            try { if ($columnExists('networks','handler_type')) { $apply("ALTER TABLE `networks` MODIFY COLUMN `handler_type` VARCHAR(50) NOT NULL DEFAULT 'node'"); } } catch (Throwable $e) {}
        }

        // One-time migration: projects.links JSON -> project_links rows
        try {
            $res = $conn->query("SELECT id, language, links FROM projects WHERE links IS NOT NULL AND TRIM(links) <> ''");
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $pid = (int)$row['id'];
                    $cnt = 0;
                    $rc = $conn->query("SELECT COUNT(*) AS c FROM project_links WHERE project_id = " . $pid);
                    if ($rc && ($rrow = $rc->fetch_assoc())) { $cnt = (int)$rrow['c']; }
                    if ($rc) { $rc->close(); }
                    if ($cnt > 0) { continue; }
                    $arr = json_decode((string)$row['links'], true);
                    $defaultLang = trim((string)($row['language'] ?? 'ru')) ?: 'ru';
                    if (!is_array($arr) || empty($arr)) { continue; }
                    $stmt = $conn->prepare("INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        foreach ($arr as $it) {
                            $url = '';$anchor='';$lang=$defaultLang;$wish='';
                            if (is_string($it)) { $url = trim($it); }
                            elseif (is_array($it)) {
                                $url = trim((string)($it['url'] ?? ''));
                                $anchor = trim((string)($it['anchor'] ?? ''));
                                $lang = trim((string)($it['language'] ?? $lang)) ?: $defaultLang;
                                $wish = trim((string)($it['wish'] ?? ''));
                            }
                            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                                $stmt->bind_param('issss', $pid, $url, $anchor, $lang, $wish);
                                @$stmt->execute();
                            }
                        }
                        $stmt->close();
                    }
                }
                $res->close();
            }
        } catch (Throwable $e) { /* ignore */ }

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