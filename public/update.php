<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$currentVersion = get_version();
$remoteInfo = fetch_latest_version_info();
$remoteVersion = $remoteInfo['version'] ?? null;
$canUpdate = $remoteVersion && version_compare($remoteVersion, $currentVersion, '>');
$message = '';
$errors = [];

function pp_http_get(string $url, int $timeout = 15) {
    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: PromoPilot\r\nAccept: application/vnd.github+json",
            'timeout' => $timeout,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res !== false) return $res;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>$timeout, CURLOPT_HTTPHEADER=>['User-Agent: PromoPilot','Accept: application/vnd.github+json']]);
        $data = curl_exec($ch);
        curl_close($ch);
        if ($data !== false) return $data;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    if (!verify_csrf()) {
        $errors[] = 'CSRF';
    } elseif (!$canUpdate) {
        $errors[] = __('Нет доступного обновления или версия не новее.');
    } else {
        $commitJson = pp_http_get('https://api.github.com/repos/ksanyok/promopilot/commits/main');
        if ($commitJson === false) {
            $errors[] = __('Не удалось получить информацию о коммите (сетевое соединение).');
        } else {
            $commitData = json_decode($commitJson, true);
            $sha = $commitData['sha'] ?? null;
            if (!$sha) {
                $errors[] = __('Ответ GitHub без SHA.');
            } else {
                $zipUrl = "https://github.com/ksanyok/promopilot/archive/{$sha}.zip";
                $zipData = pp_http_get($zipUrl, 60);
                if ($zipData === false) {
                    $errors[] = __('Не удалось скачать архив.');
                } else {
                    $tmpZip = tempnam(sys_get_temp_dir(), 'ppupd_');
                    if (!@file_put_contents($tmpZip, $zipData)) {
                        $errors[] = __('Не удалось сохранить архив во временный файл.');
                    } else {
                        $zip = new ZipArchive();
                        if ($zip->open($tmpZip) !== true) {
                            $errors[] = __('Не удалось открыть архив.');
                        } else {
                            $extractDir = PP_ROOT_PATH . '/_update_' . $sha;
                            if (!is_dir($extractDir) && !@mkdir($extractDir, 0755, true)) {
                                $errors[] = __('Не удалось создать директорию распаковки.');
                            } else {
                                if (!$zip->extractTo($extractDir)) {
                                    $errors[] = __('Ошибка распаковки архива.');
                                }
                                $zip->close();
                                if (empty($errors)) {
                                    $srcRoot = $extractDir . '/promopilot-' . $sha;
                                    if (!is_dir($srcRoot)) { $errors[] = __('Структура архива неожиданна.'); }
                                    else {
                                        $exclude = [
                                            '/config/config.php',
                                            '/logs',
                                            '/uploads',
                                        ];
                                        $copyDir = function($src, $dst) use (&$copyDir, $exclude) {
                                            $dir = opendir($src);
                                            if (!is_dir($dst)) { @mkdir($dst, 0755, true); }
                                            while (($f = readdir($dir)) !== false) {
                                                if ($f === '.' || $f === '..') continue;
                                                $srcPath = $src . '/' . $f;
                                                $rel = substr($srcPath, strlen($GLOBALS['__update_src_root']));
                                                foreach ($exclude as $ex) { if (strpos($rel, $ex) === 0) continue 2; }
                                                $dstPath = $dst . '/' . $f;
                                                if (is_dir($srcPath)) {
                                                    $copyDir($srcPath, $dstPath);
                                                } else {
                                                    @copy($srcPath, $dstPath);
                                                }
                                            }
                                            closedir($dir);
                                        };
                                        $GLOBALS['__update_src_root'] = $srcRoot;
                                        $copyDir($srcRoot, PP_ROOT_PATH);
                                    }
                                }
                            }
                        }
                        @unlink($tmpZip);
                        if (is_dir($extractDir)) { rmdir_recursive($extractDir); }
                    }
                }
            }
        }
        if (empty($errors)) {
            ensure_schema();
            $message = __('Обновление завершено.') . ' ' . __('Текущая версия') . ': ' . htmlspecialchars(get_version());
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
                <?php elseif (!empty($errors)): ?>
                    <div class="alert alert-danger"><?php echo implode('<br>', $errors); ?></div>
                <?php endif; ?>
                <p><?php echo __('Текущая версия') . ': ' . htmlspecialchars($currentVersion); ?></p>
                <p><?php echo __('Доступная версия') . ': ' . htmlspecialchars($remoteVersion ?? __('неизвестно')); ?></p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <?php if ($message): ?>
                        <button type="submit" class="btn btn-danger" disabled><i class="bi bi-check2-circle me-1"></i><?php echo __('Обновлено'); ?></button>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a href="<?php echo pp_url('index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i><?php echo __('На главную'); ?></a>
                            <a href="<?php echo pp_url('admin/admin.php'); ?>" class="btn btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i><?php echo __('В админку'); ?></a>
                        </div>
                    <?php else: ?>
                        <button type="submit" name="do_update" class="btn btn-danger" <?php echo $canUpdate ? '' : 'disabled'; ?>><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить'); ?></button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>