<?php
// installer.php — мастер установки/обновления PromoPilot
// Минимальные требования: PHP 7.4+, расширение ZipArchive для обновления через ZIP

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

// Start session for post-install auto-login
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$repoOwner = 'ksanyok';
$repoName  = 'promopilot';
$defaultBranch = 'main';
$versionPhp  = __DIR__ . '/version.php';
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

function current_version($versionPhpPath) {
    if (file_exists($versionPhpPath)) {
        $v = @include $versionPhpPath;
        if (is_string($v) && $v !== '') return trim($v);
    }
    return '0.0.0';
}

function parse_version_php_code($code) {
    if (!is_string($code) || $code === '') return null;
    if (preg_match('/return\s*[\'\"]([^\'\"]+)[\'\"];?/m', $code, $m)) {
        return trim($m[1]);
    }
    return null;
}

function remote_version_from_branch($owner, $repo, $branch) {
    // Ищем только version.php в репозитории
    $urlPhp = "https://raw.githubusercontent.com/$owner/$repo/$branch/version.php";
    $dataPhp = fetch_remote($urlPhp);
    if ($dataPhp !== false) {
        $pv = parse_version_php_code($dataPhp);
        if ($pv) return $pv;
    }
    return null;
}

function detect_branch_with_version($owner, $repo) {
    $branches = ['main', 'master'];
    foreach ($branches as $br) {
        $ver = remote_version_from_branch($owner, $repo, $br);
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

// Обновление из репозитория (поддерживаем, но кнопку на UI не показываем)
if ($action === 'update') {
    [$branchToUse, $remoteVer] = detect_branch_with_version($repoOwner, $repoName);
    $localVer = current_version($versionPhp);
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
            // Обновляем только version.php
            if (cmp_versions($remoteVer, $localVer) >= 0) {
                @file_put_contents($versionPhp, "<?php\nreturn '" . addslashes($remoteVer) . "';\n");
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

    // Новые поля Google OAuth
    $googleClientId = trim($_POST['google_client_id'] ?? '');
    $googleClientSecret = trim($_POST['google_client_secret'] ?? '');
    $googleRedirectUri = trim($_POST['google_redirect_uri'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') $errors[] = 'Заполните параметры базы данных.';
    if ($openaiKey === '') $errors[] = 'Укажите OpenAI API ключ.';
    if ($adminLogin === '' || $adminPass === '') $errors[] = 'Укажите логин и пароль администратора.';
    if ($googleClientId === '' || $googleClientSecret === '' || $googleRedirectUri === '') $errors[] = 'Заполните параметры Google OAuth (Client ID, Secret, Redirect URI).';

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
                // Google OAuth
                'GOOGLE_CLIENT_ID' => $googleClientId,
                'GOOGLE_CLIENT_SECRET' => $googleClientSecret,
                'GOOGLE_REDIRECT_URI' => $googleRedirectUri,
            ];
            if (!env_write($envFile, $envData)) {
                $errors[] = 'Не удалось записать .env.';
            } else {
                // Создаем файлы версий, если отсутствуют
                if (!file_exists($versionPhp)) {
                    @file_put_contents($versionPhp, "<?php\nreturn '0.1.0';\n");
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
                    // Автовход в админку и удаление инсталлера
                    $_SESSION['is_admin'] = true;
                    $_SESSION['admin_login'] = $adminLogin;
                    $installerPath = __FILE__;
                    @unlink($installerPath);
                    if (file_exists($installerPath)) { @rename($installerPath, __DIR__ . '/installer.removed'); }
                    header('Location: /admin.php');
                    exit;
                }
            }
        }
    }
}

$localVersion = current_version($versionPhp);
[$branchDetected, $remoteVersion] = detect_branch_with_version($repoOwner, $repoName);
$updateAvailable = $remoteVersion && cmp_versions($remoteVersion, $localVersion) > 0;

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка PromoPilot</title>
    <!-- ВАЖНО: Не подключаем внешние стили. Все стили инлайн, так как при установке vendor/assets могут отсутствовать. -->
    <style>
        body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#f7fafc; color:#0f172a; }
        .wrap { max-width: 960px; margin: 32px auto; background:#fff; border:1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 12px 28px rgba(0,0,0,.06); overflow:hidden; }
        header { padding: 18px 22px; background: linear-gradient(120deg, #6a11cb, #2575fc); color:#fff; }
        header h1 { margin:0; font-size: 20px; }
        .inner { padding: 20px 22px; }
        .row { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        label { font-size: 13px; color:#334155; display:block; margin-bottom: 6px; }
        input[type=text], input[type=password] { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius: 8px; background:#f9fafb; }
        input[type=text]:focus, input[type=password]:focus { outline:none; border-color:#2575fc; box-shadow: 0 0 0 3px rgba(37,117,252,.2); background:#fff; }
        .actions { margin-top: 16px; display:flex; gap:12px; align-items:center; }
        .btn { appearance:none; border:0; background: linear-gradient(120deg, #2575fc, #6a11cb); color:#fff; padding:10px 16px; border-radius: 10px; font-weight:600; cursor:pointer; }
        .messages { margin: 12px 0; }
        .msg { padding: 10px 12px; border-radius: 10px; margin-bottom: 10px; border:1px solid #e5e7eb; }
        .msg-ok { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
        .msg-err { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        h3 { margin: 16px 0 10px; font-size: 16px; }
        .help { background:#f8fafc; border:1px dashed #e5e7eb; padding:12px; border-radius: 10px; font-size: 13px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; padding:2px 6px; }
        @media (max-width: 760px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Установка PromoPilot</h1>
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

                <h3>Ключ OpenAI</h3>
                <div>
                    <label>OpenAI API Key</label>
                    <input type="text" name="openai_key" placeholder="sk-..." required>
                </div>

                <h3>Администратор</h3>
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

                <h3>Google OAuth (для входа через Google)</h3>
                <div class="row">
                    <div>
                        <label>Client ID</label>
                        <input type="text" name="google_client_id" placeholder="xxxxxxxxxxxx-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.apps.googleusercontent.com" required>
                    </div>
                    <div>
                        <label>Client Secret</label>
                        <input type="text" name="google_client_secret" placeholder="GOCSPX-..." required>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label>Redirect URI</label>
                        <input type="text" name="google_redirect_uri" placeholder="https://your-domain.com/login.php" required>
                    </div>
                </div>
                <div class="help">
                    <strong>Как настроить вход через Google:</strong>
                    <ol>
                        <li>Откройте <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>.</li>
                        <li>Создайте проект или выберите существующий.</li>
                        <li>Перейдите в "Credentials" → "Create credentials" → "OAuth client ID".</li>
                        <li>Выберите тип "Web application" и добавьте:
                            <ul>
                                <li>Authorized redirect URIs: <span class="mono">https://ВАШ_ДОМЕН/login.php</span></li>
                            </ul>
                        </li>
                        <li>Сохраните <em>Client ID</em> и <em>Client Secret</em> и укажите их выше.</li>
                        <li>Убедитесь, что файл <span class="mono">.env</span> содержит GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI.</li>
                    </ol>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Установить</button>
                    <span style="font-size:12px; color:#64748b;">Будет создан файл <span class="mono">.env</span> и загружены файлы проекта.</span>
                </div>
            </form>
        <?php else: ?>
            <p>Система уже установлена. При необходимости вы можете переустановить настройки .env, запустив мастер заново.</p>
        <?php endif; ?>
    </div>
    <footer style="padding:12px 16px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-start; align-items:center; background:#fafafa; font-size:13px; color:#334155; gap:12px;">
        <span>Текущая версия:</span>
        <strong><?php echo htmlspecialchars($localVersion); ?></strong>
        <?php if ($remoteVersion): ?>
            <span style="color:#64748b;">(в репозитории: <?php echo htmlspecialchars($remoteVersion); ?>)</span>
        <?php endif; ?>
    </footer>
</div>
</body>
</html>
