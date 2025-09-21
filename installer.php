<?php
// installer.php — мастер установки/обновления PromoPilot
// Минимальные требования: PHP 7.4+, расширение ZipArchive для обновления через ZIP

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

$repoOwner = 'ksanyok';
$repoName  = 'promopilot';
$defaultBranch = 'main';
$versionFile = __DIR__ . '/version.txt';
$envFile = __DIR__ . '/.env';

// Полифилы для PHP < 8
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        if ($needle === '') return true;
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }
}

function env_write($path, $data) {
    $lines = [];
    foreach ($data as $k => $v) {
        // Экранируем специальные символы
        $escaped = str_replace(["\n", "\r"], ['\\n', ''], (string)$v);
        $lines[] = $k . '="' . addslashes($escaped) . '"';
    }
    $content = implode("\n", $lines) . "\n";
    return file_put_contents($path, $content) !== false;
}

function env_read($path) {
    if (!file_exists($path)) return [];
    $content = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $res = [];
    foreach ($content as $line) {
        if (strpos(ltrim($line), '#') === 0) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) && $v[0] === '"' && substr($v, -1) === '"') {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $res[$k] = $v;
    }
    return $res;
}

function fetch_remote($url) {
    // cURL с таймаутом и фолбэком на file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'PromoPilot-Installer'
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $data !== false) return $data;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: PromoPilot-Installer\r\n"]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data !== false) return $data;
    }
    return false;
}

function cmp_versions($a, $b) {
    // Простое сравнение семвер: возвращает -1,0,1
    $aParts = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$a)));
    $bParts = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$b)));
    $max = max(count($aParts), count($bParts));
    for ($i = 0; $i < $max; $i++) {
        $ai = $aParts[$i] ?? 0;
        $bi = $bParts[$i] ?? 0;
        if ($ai < $bi) return -1;
        if ($ai > $bi) return 1;
    }
    return 0;
}

function current_version($versionFile) {
    return file_exists($versionFile) ? trim((string)@file_get_contents($versionFile)) : '0.0.0';
}

function remote_version($owner, $repo, $branch) {
    $url = "https://raw.githubusercontent.com/$owner/$repo/$branch/version.txt";
    $data = fetch_remote($url);
    if ($data === false) return null;
    return trim($data);
}

function detect_branch_with_version($owner, $repo) {
    $branches = ['main', 'master'];
    foreach ($branches as $br) {
        $ver = remote_version($owner, $repo, $br);
        if ($ver !== null && $ver !== '') {
            return [$br, $ver];
        }
    }
    return [null, null];
}

function download_and_extract_zip($zipUrl, $destDir, $exclude = []) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Расширение ZipArchive не доступно на сервере.'];
    }

    $tmp = sys_get_temp_dir();
    $zipPath = tempnam($tmp, 'ppzip_');
    if (!$zipPath) return ['ok' => false, 'error' => 'Не удалось создать временный файл.'];

    $data = fetch_remote($zipUrl);
    if ($data === false) return ['ok' => false, 'error' => 'Не удалось скачать архив: ' . htmlspecialchars($zipUrl)];
    file_put_contents($zipPath, $data);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        return ['ok' => false, 'error' => 'Не удалось открыть архив.'];
    }

    // Архив GitHub содержит верхнюю папку <repo>-<branch>/
    $rootFolder = null;
    if ($zip->numFiles > 0) {
        $first = $zip->getNameIndex(0);
        if ($first && str_contains($first, '/')) {
            $rootFolder = explode('/', $first)[0] . '/';
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($rootFolder && str_starts_with($entry, $rootFolder)) {
            $relative = substr($entry, strlen($rootFolder));
        } else {
            $relative = $entry;
        }
        if ($relative === '' || $relative === false) continue;
        // Пропускаем служебные каталоги/файлы и исключения
        $skip = false;
        foreach ($exclude as $ex) {
            if ($relative === $ex || str_starts_with($relative, rtrim($ex, '/') . '/')) {
                $skip = true; break;
            }
        }
        if ($skip) continue;

        $targetPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $relative;
        if (str_ends_with($entry, '/')) {
            if (!is_dir($targetPath)) @mkdir($targetPath, 0775, true);
            continue;
        }
        // Обеспечиваем каталог
        $dir = dirname($targetPath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $fp = $zip->getStream($entry);
        if (!$fp) {
            $zip->close();
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Ошибка чтения из архива: ' . $entry];
        }
        $out = fopen($targetPath, 'w');
        if (!$out) {
            fclose($fp);
            $zip->close();
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Ошибка записи файла: ' . $targetPath];
        }
        while (!feof($fp)) {
            fwrite($out, fread($fp, 8192));
        }
        fclose($out);
        fclose($fp);
    }
    $zip->close();
    @unlink($zipPath);
    return ['ok' => true];
}

function try_pdo($host, $db, $user, $pass, $charset = 'utf8mb4') {
    try {
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        new PDO($dsn, $user, $pass, $opt);
        return [true, null];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

function run_composer_install($cwd) {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'note' => 'shell_exec недоступен на хостинге'];
    }
    $lastOut = '';
    $composerBin = trim((string)@shell_exec('command -v composer 2>/dev/null'));
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmds = [];
    if ($composerBin) {
        $cmds[] = $composerBin . ' install --no-dev --prefer-dist -n';
    }
    if (file_exists($cwd . '/composer.phar')) {
        $cmds[] = $phpBin . ' composer.phar install --no-dev --prefer-dist -n';
    }
    foreach ($cmds as $cmd) {
        $out = shell_exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1');
        $lastOut = $out ?: $lastOut;
        if (file_exists($cwd . '/vendor/autoload.php')) {
            return ['ok' => true, 'output' => $out];
        }
    }
    return ['ok' => false, 'output' => $lastOut];
}

function run_npm_install($cwd) {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'note' => 'shell_exec недоступен'];
    }
    $npmBin = trim((string)@shell_exec('command -v npm 2>/dev/null'));
    if (!$npmBin) return ['ok' => false, 'note' => 'npm не найден'];
    $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $npmBin . ' ci --only=prod 2>&1';
    $out = shell_exec($cmd);
    // проверим наличие node_modules
    if (is_dir($cwd . '/node_modules')) {
        return ['ok' => true, 'output' => $out];
    }
    return ['ok' => false, 'output' => $out];
}

$action = $_GET['action'] ?? '';
$installed = file_exists($envFile);
$messages = [];
$errors = [];

// Обновление из репозитория
if ($action === 'update') {
    [$branchToUse, $remoteVer] = detect_branch_with_version($repoOwner, $repoName);
    $localVer = current_version($versionFile);
    if (!$branchToUse) {
        $errors[] = 'Не удалось определить ветку и версию репозитория.';
    } else {
        // Скачиваем ZIP выбранной ветки
        $zipUrl = "https://github.com/$repoOwner/$repoName/archive/refs/heads/$branchToUse.zip";
        $exclude = [
            '.env',
            'installer.php',
            'images/uploads',
        ];
        $res = download_and_extract_zip($zipUrl, __DIR__, $exclude);
        if ($res['ok']) {
            // После обновления попробуем установить зависимости, если их нет
            if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                $cres = run_composer_install(__DIR__);
                if ($cres['ok']) {
                    $messages[] = 'Зависимости Composer установлены.';
                } else {
                    $messages[] = 'Не удалось автоматически установить зависимости Composer. Установите вручную: composer install';
                }
            }
            // npm зависимости для auto-publisher
            $apPath = __DIR__ . '/auto-publisher';
            if (is_dir($apPath) && file_exists($apPath . '/package.json')) {
                $nres = run_npm_install($apPath);
                if ($nres['ok']) {
                    $messages[] = 'NPM зависимости auto-publisher установлены.';
                } else {
                    $messages[] = 'Не удалось автоматически установить NPM зависимости (auto-publisher). Установите вручную в ' . $apPath;
                }
            }
            // Обновляем версию, если удаленная >= локальной
            if (cmp_versions($remoteVer, $localVer) >= 0) {
                @file_put_contents($versionFile, $remoteVer . "\n");
            }
            $messages[] = 'Файлы успешно обновлены до версии ' . htmlspecialchars($remoteVer) . '.';
        } else {
            $errors[] = $res['error'] ?? 'Неизвестная ошибка при обновлении.';
        }
    }
}

// Обработка инсталляции
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'update') {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $dbCharset = trim($_POST['db_charset'] ?? 'utf8mb4');

    $openaiKey = trim($_POST['openai_key'] ?? '');
    $adminLogin = trim($_POST['admin_login'] ?? '');
    $adminPass  = (string)($_POST['admin_pass'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') $errors[] = 'Заполните параметры базы данных.';
    if ($openaiKey === '') $errors[] = 'Укажите OpenAI API ключ.';
    if ($adminLogin === '' || $adminPass === '') $errors[] = 'Укажите логин и пароль администратора.';

    if (!$errors) {
        [$ok, $err] = try_pdo($dbHost, $dbName, $dbUser, $dbPass, $dbCharset);
        if (!$ok) {
            $errors[] = 'Не удалось подключиться к БД: ' . htmlspecialchars($err);
        } else {
            // Пишем .env
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $envData = [
                'DB_HOST' => $dbHost,
                'DB_NAME' => $dbName,
                'DB_USER' => $dbUser,
                'DB_PASS' => $dbPass,
                'DB_CHARSET' => $dbCharset,
                'OPENAI_API_KEY' => $openaiKey,
                'ADMIN_LOGIN' => $adminLogin,
                'ADMIN_PASSWORD_HASH' => $hash,
            ];
            if (!env_write($envFile, $envData)) {
                $errors[] = 'Не удалось записать .env.';
            } else {
                // Создаем version.txt, если отсутствует
                if (!file_exists($versionFile)) {
                    @file_put_contents($versionFile, "0.1.0\n");
                }

                // Скачиваем исходники из репозитория (если нужно обновить текущую копию)
                [$branchToUse, $repoVer] = detect_branch_with_version($repoOwner, $repoName);
                if (!$branchToUse) { $branchToUse = $defaultBranch; }
                $zipUrl = "https://github.com/$repoOwner/$repoName/archive/refs/heads/$branchToUse.zip";
                $exclude = [
                    '.env',
                    'installer.php',
                    'images/uploads',
                ];
                $res = download_and_extract_zip($zipUrl, __DIR__, $exclude);
                if (!$res['ok']) {
                    $errors[] = 'Не удалось скачать файлы из репозитория: ' . ($res['error'] ?? '');
                } else {
                    // Пробуем установить зависимости Composer, если их нет
                    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                        $cres = run_composer_install(__DIR__);
                        if ($cres['ok']) {
                            $messages[] = 'Зависимости Composer установлены.';
                        } else {
                            $messages[] = 'Не удалось автоматически установить зависимости Composer. Установите вручную: composer install';
                        }
                    }
                    // Устанавливаем NPM зависимости для auto-publisher
                    $apPath = __DIR__ . '/auto-publisher';
                    if (is_dir($apPath) && file_exists($apPath . '/package.json')) {
                        $nres = run_npm_install($apPath);
                        if ($nres['ok']) {
                            $messages[] = 'NPM зависимости auto-publisher установлены.';
                        } else {
                            $messages[] = 'Не удалось автоматически установить NPM зависимости (auto-publisher). Установите вручную в ' . $apPath;
                        }
                    }
                    $messages[] = 'Установка завершена. Конфигурация сохранена в .env.';
                    $installed = true;
                }
            }
        }
    }
}

$localVersion = current_version($versionFile);
[$branchDetected, $remoteVersion] = detect_branch_with_version($repoOwner, $repoName);
$updateAvailable = $remoteVersion && cmp_versions($remoteVersion, $localVersion) > 0;

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка PromoPilot</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 0; background:#f6f7f9; }
        .wrap { max-width: 880px; margin: 40px auto; background: #fff; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); overflow: hidden; }
        header { padding: 20px 24px; background: #0f62fe; color: #fff; }
        header h1 { margin: 0; font-size: 22px; }
        .inner { padding: 24px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        label { font-size: 13px; color: #333; display:block; margin-bottom: 6px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid #dfe3e8; border-radius: 8px; background:#fafbfc; outline: none; }
        input[type="text"]:focus, input[type="password"]:focus { border-color:#0f62fe; background:#fff; }
        .actions { margin-top: 16px; display:flex; gap:12px; align-items:center; }
        .btn { appearance: none; border:0; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 600; }
        .btn-primary { background:#0f62fe; color:#fff; }
        .btn-secondary { background:#e0e7ff; color:#0f1c4d; }
        .note { font-size:12px; color:#666; }
        .messages { margin: 12px 0; }
        .msg { padding:10px 12px; border-radius:8px; margin-bottom:8px; }
        .msg-ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .msg-err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        footer { padding: 16px 24px; background:#fafafa; color:#444; font-size:13px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eee; }
        .version { font-weight: 600; }
        .update-badge { background:#fff0c9; color:#7a2e0e; padding:4px 8px; border-radius:6px; border:1px solid #ffe69c; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Установка/обновление PromoPilot</h1>
    </header>
    <div class="inner">
        <div class="messages">
            <?php foreach ($messages as $m): ?>
                <div class="msg msg-ok"><?php echo $m; ?></div>
            <?php endforeach; ?>
            <?php foreach ($errors as $e): ?>
                <div class="msg msg-err"><?php echo $e; ?></div>
            <?php endforeach; ?>
        </div>

        <?php if (!$installed): ?>
            <form method="post">
                <h3>Параметры базы данных</h3>
                <div class="row">
                    <div>
                        <label>Хост БД</label>
                        <input type="text" name="db_host" placeholder="localhost" required>
                    </div>
                    <div>
                        <label>Имя базы данных</label>
                        <input type="text" name="db_name" placeholder="promopilot" required>
                    </div>
                    <div>
                        <label>Пользователь БД</label>
                        <input type="text" name="db_user" placeholder="db_user" required>
                    </div>
                    <div>
                        <label>Пароль БД</label>
                        <input type="password" name="db_pass" placeholder="••••••••">
                    </div>
                    <div>
                        <label>Кодировка</label>
                        <input type="text" name="db_charset" value="utf8mb4">
                    </div>
                </div>

                <h3 style="margin-top:18px;">Ключ OpenAI</h3>
                <div>
                    <label>OpenAI API Key</label>
                    <input type="text" name="openai_key" placeholder="sk-..." required>
                </div>

                <h3 style="margin-top:18px;">Администратор</h3>
                <div class="row">
                    <div>
                        <label>Логин администратора</label>
                        <input type="text" name="admin_login" placeholder="admin" required>
                    </div>
                    <div>
                        <label>Пароль администратора</label>
                        <input type="password" name="admin_pass" placeholder="Пароль" required>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Установить</button>
                    <span class="note">Будет создан файл .env и загружены файлы проекта из GitHub.</span>
                </div>
            </form>
        <?php else: ?>
            <p>Система уже установлена. Вы можете проверить наличие обновлений и выполнить обновление при необходимости.</p>
        <?php endif; ?>
    </div>
    <footer>
        <div>
            Текущая версия: <span class="version"><?php echo htmlspecialchars($localVersion); ?></span>
            <?php if ($remoteVersion): ?>
                <span style="margin-left:8px; color:#666;">Доступна в репозитории: <?php echo htmlspecialchars($remoteVersion); ?></span>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($updateAvailable): ?>
                <a class="btn btn-secondary" href="?action=update">Обновить до <?php echo htmlspecialchars($remoteVersion); ?></a>
                <span class="update-badge">Доступно обновление</span>
            <?php else: ?>
                <span style="color:#666;">Обновлений нет</span>
            <?php endif; ?>
        </div>
    </footer>
</div>
</body>
</html>
