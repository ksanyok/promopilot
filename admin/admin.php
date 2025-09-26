<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

$conn = connect_db();

// Ensure settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS settings (\n  k VARCHAR(191) PRIMARY KEY,\n  v TEXT,\n  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$settingsMsg = '';
$networksMsg = '';
$diagnosticsMsg = '';
$allowedCurrencies = ['RUB','USD','EUR','GBP','UAH'];
$settingsKeys = ['currency','openai_api_key','telegram_token','telegram_channel'];
// Extend settings keys: AI provider and Google OAuth
$settingsKeys = array_merge($settingsKeys, [
    'ai_provider',              // openai | byoa
    'custom_ai_endpoint',       // URL of custom AI endpoint
    'custom_ai_token',          // auth token/key for custom AI
    'google_oauth_enabled',     // 0/1
    'google_client_id',
    'google_client_secret',
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['settings_submit'])) {
        if (!verify_csrf()) {
            $settingsMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
            if (!in_array($currency, $allowedCurrencies, true)) { $currency = 'RUB'; }
            $openai = trim((string)($_POST['openai_api_key'] ?? ''));
            $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
            $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));

            // AI provider selection
            $aiProvider = in_array(($_POST['ai_provider'] ?? 'openai'), ['openai','byoa'], true) ? $_POST['ai_provider'] : 'openai';
            $customAiEndpoint = trim((string)($_POST['custom_ai_endpoint'] ?? ''));
            $customAiToken = trim((string)($_POST['custom_ai_token'] ?? ''));

            // Google OAuth config
            $googleEnabled = isset($_POST['google_oauth_enabled']) ? '1' : '0';
            $googleClientId = trim((string)($_POST['google_client_id'] ?? ''));
            $googleClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));

            $pairs = [
                ['currency', $currency],
                ['openai_api_key', $openai],
                ['telegram_token', $tgToken],
                ['telegram_channel', $tgChannel],
                // AI provider
                ['ai_provider', $aiProvider],
                ['custom_ai_endpoint', $customAiEndpoint],
                ['custom_ai_token', $customAiToken],
                // Google OAuth
                ['google_oauth_enabled', $googleEnabled],
                ['google_client_id', $googleClientId],
                ['google_client_secret', $googleClientSecret],
            ];
            $stmt = $conn->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP");
            if ($stmt) {
                foreach ($pairs as [$k, $v]) {
                    $stmt->bind_param('ss', $k, $v);
                    $stmt->execute();
                }
                $stmt->close();
                $settingsMsg = __('Настройки сохранены.');
            } else {
                $settingsMsg = __('Ошибка сохранения настроек.');
            }
        }
    } elseif (isset($_POST['refresh_networks'])) {
        if (!verify_csrf()) {
            $networksMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            try {
                pp_refresh_networks(true);
                $networksMsg = __('Список сетей обновлён.');
            } catch (Throwable $e) {
                $networksMsg = __('Ошибка обновления списка сетей.');
            }
        }
    } elseif (isset($_POST['networks_submit'])) {
        if (!verify_csrf()) {
            $networksMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $enabledSlugs = [];
            if (!empty($_POST['enable']) && is_array($_POST['enable'])) {
                $enabledSlugs = array_keys($_POST['enable']);
            }
            $nodeBinaryNew = trim((string)($_POST['node_binary'] ?? ''));
            $puppeteerExecNew = trim((string)($_POST['puppeteer_executable_path'] ?? ''));
            $puppeteerArgsNew = trim((string)($_POST['puppeteer_args'] ?? ''));
            set_settings([
                'node_binary' => $nodeBinaryNew,
                'puppeteer_executable_path' => $puppeteerExecNew,
                'puppeteer_args' => $puppeteerArgsNew,
            ]);
            if (pp_set_networks_enabled($enabledSlugs)) {
                $networksMsg = __('Параметры сетей сохранены.');
            } else {
                $networksMsg = __('Не удалось обновить параметры сетей.');
            }
        }
    } elseif (isset($_POST['detect_node'])) {
        if (!verify_csrf()) {
            $networksMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $resolved = pp_resolve_node_binary(5, true);
            if ($resolved) {
                $path = $resolved['path'];
                $ver = $resolved['version'] ?? __('Неизвестно');
                $networksMsg = sprintf(__('Node.js найден: %s (версия %s).'), $path, $ver);
            } else {
                $candidates = pp_collect_node_candidates();
                $msg = __('Не удалось автоматически определить Node.js.');
                if (!empty($candidates)) {
                    $msg .= ' ' . __('Проверенные пути:') . ' ' . implode(', ', array_slice($candidates, 0, 10));
                }
                $networksMsg = $msg;
            }
        }
    } elseif (isset($_POST['detect_chrome'])) {
        if (!verify_csrf()) {
            $diagnosticsMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $found = pp_resolve_chrome_path();
            if ($found) {
                set_setting('puppeteer_executable_path', $found);
                $diagnosticsMsg = sprintf(__('Chrome найден: %s. Путь сохранён в настройках.'), $found);
            } else {
                $diagnosticsMsg = __('Не удалось автоматически определить Chrome. Проверьте подсказки ниже и установите браузер в корне проекта.');
            }
        }
    }
}

// Load settings
$settings = ['currency' => 'RUB', 'openai_api_key' => '', 'telegram_token' => '', 'telegram_channel' => ''];
// Defaults for new settings
$settings += [
    'ai_provider' => 'openai',
    'custom_ai_endpoint' => '',
    'custom_ai_token' => '',
    'google_oauth_enabled' => '0',
    'google_client_id' => '',
    'google_client_secret' => '',
];
$in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $settingsKeys)) . "'";
$res = $conn->query("SELECT k, v FROM settings WHERE k IN ($in)");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['k']] = (string)$row['v'];
    }
}

// Получить пользователей
$users = $conn->query("SELECT id, username, role, balance, created_at FROM users ORDER BY id");

// Получить проекты
$projects = $conn->query("SELECT p.id, p.name, p.description, p.created_at, u.username FROM projects p JOIN users u ON p.user_id = u.id ORDER BY p.id");

$conn->close();

$updateStatus = get_update_status();

pp_refresh_networks(false);
$networks = pp_get_networks(false, true);
$nodeBinaryStored = trim((string)get_setting('node_binary', ''));
$puppeteerExecStored = trim((string)get_setting('puppeteer_executable_path', ''));
$puppeteerArgsStored = trim((string)get_setting('puppeteer_args', ''));
$resolvedNode = pp_resolve_node_binary(2, true);
$nodeBinaryEffective = $resolvedNode['path'] ?? pp_get_node_binary();
$canRunShell = function_exists('shell_exec');
$nodeAutoDetected = (bool)$resolvedNode;

if (!$nodeAutoDetected) {
    if ($nodeBinaryStored !== '') {
        $nodeBinaryEffective = $nodeBinaryStored;
    } elseif ($nodeBinaryEffective === 'node') {
        $nodeBinaryEffective = __('Не найден');
    }
}

$nodeVersionRaw = $resolvedNode['version'] ?? '';
if ($nodeVersionRaw === '' && $canRunShell && $nodeAutoDetected && $nodeBinaryEffective !== '') {
    $nodeVersionRaw = trim((string)@shell_exec(escapeshellarg($nodeBinaryEffective) . ' -v 2>&1'));
}
if (stripos($nodeVersionRaw, 'not found') !== false) { $nodeVersionRaw = __('Не найден'); }
$nodeVersion = $nodeVersionRaw !== '' ? $nodeVersionRaw : __('Недоступно');

$npmVersionRaw = '';
if ($canRunShell) {
    $npmVersionRaw = trim((string)@shell_exec('npm -v 2>&1'));
}
if (stripos($npmVersionRaw, 'not found') !== false) { $npmVersionRaw = __('Не найден'); }
elseif ($npmVersionRaw === '') { $npmVersionRaw = __('Недоступно'); }

$puppeteerInstalled = is_dir(PP_ROOT_PATH . '/node_modules/puppeteer');
$nodeFetchInstalled = is_dir(PP_ROOT_PATH . '/node_modules/node-fetch');
$packageJsonExists = is_file(PP_ROOT_PATH . '/package.json');
$networksDir = pp_networks_dir();
$networksDirWritable = is_writable($networksDir);
$openAiConfigured = trim($settings['openai_api_key'] ?? '') !== '';
$networksLastRefreshTs = (int)get_setting('networks_last_refresh', 0);
$networksLastRefresh = $networksLastRefreshTs ? date('Y-m-d H:i:s', $networksLastRefreshTs) : __('Не выполнялось');
$chromeResolved = pp_resolve_chrome_path();
$chromeInstalled = $chromeResolved !== null;

$diagnostics = [
    ['label' => __('PHP версия'), 'value' => PHP_VERSION],
    ['label' => __('PHP бинарник'), 'value' => PHP_BINARY],
    ['label' => __('Node.js бинарь'), 'value' => $nodeBinaryEffective ?: __('Не задан')],
    ['label' => __('Node.js версия'), 'value' => $nodeVersion],
    ['label' => __('NPM версия'), 'value' => $npmVersionRaw],
    ['label' => __('package.json'), 'value' => $packageJsonExists ? __('Найден') : __('Не найден')],
    ['label' => __('Puppeteer установлен'), 'value' => $puppeteerInstalled ? __('Да') : __('Нет')],
    ['label' => __('Chrome установлен'), 'value' => $chromeInstalled ? __('Да') : __('Нет')],
    ['label' => __('Путь до Chrome'), 'value' => $chromeInstalled ? $chromeResolved : __('Не найден')],
    ['label' => __('node-fetch установлен'), 'value' => $nodeFetchInstalled ? __('Да') : __('Нет')],
    ['label' => __('Директория сетей'), 'value' => $networksDir],
    ['label' => __('Директория сетей доступна на запись'), 'value' => $networksDirWritable ? __('Да') : __('Нет')],
    ['label' => __('OpenAI API Key настроен'), 'value' => $openAiConfigured ? __('Да') : __('Нет')],
    ['label' => __('Последнее обновление сетей'), 'value' => $networksLastRefresh],
];
?>

<?php include '../includes/header.php'; ?>

<div class="sidebar">
    <div class="menu-block">
        <div class="menu-title"><?php echo __('Обзор'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('users')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <circle cx="7" cy="8" r="3"/>
                        <circle cx="17" cy="8" r="3"/>
                        <path d="M2 20c0-3 3-5 5-5s5 2 5 5"/>
                        <path d="M12 20c0-3 3-5 5-5s5 2 5 5"/>
                    </svg>
                    <?php echo __('Пользователи'); ?>
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('projects')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="me-2" aria-hidden="true">
                        <rect x="3" y="4" width="6" height="16" rx="2"/>
                        <rect x="10" y="4" width="6" height="12" rx="2"/>
                        <rect x="17" y="4" width="4" height="8" rx="2"/>
                    </svg>
                    <?php echo __('Проекты'); ?>
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('settings')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 7.04 3.4l.06.06a1.65 1.65 0 0 0 1.82.33h.01A1.65 1.65 0 0 0 10.5 2.28V2a2 2 0 1 1 4 0v.09c0 .67.39 1.27 1 1.51h.01c.63.25 1.35.11 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06c-.44.47-.58 1.19-.33 1.82v.01c.24.61.84 1 1.51 1H22a2 2 0 1 1 0 4h-.09c-.67 0-1.27.39-1.51 1z"/>
                    </svg>
                    <?php echo __('Основные настройки'); ?>
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('networks')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <circle cx="12" cy="5" r="2"/>
                        <circle cx="5" cy="19" r="2"/>
                        <circle cx="19" cy="19" r="2"/>
                        <path d="M12 7v6"/>
                        <path d="M5 17l5-4"/>
                        <path d="M19 17l-5-4"/>
                    </svg>
                    <?php echo __('Сети'); ?>
                </a>
            </li>
            <li>
                <a href="#" class="menu-item" onclick="ppShowSection('diagnostics')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <polyline points="4 14 10 14 12 10 16 18 20 8"/>
                        <circle cx="4" cy="14" r="1"/>
                        <circle cx="10" cy="14" r="1"/>
                        <circle cx="12" cy="10" r="1"/>
                        <circle cx="16" cy="18" r="1"/>
                        <circle cx="20" cy="8" r="1"/>
                    </svg>
                    <?php echo __('Диагностика'); ?>
                </a>
            </li>
        </ul>
    </div>

    <hr class="menu-separator">

    <div class="menu-block">
        <div class="menu-title"><?php echo __('Инструменты'); ?></div>
        <ul class="menu-list">
            <li>
                <a href="<?php echo pp_url('public/scan.php'); ?>" class="menu-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <path d="M4 5h16"/>
                        <path d="M9 3v4"/>
                        <path d="M7 9c2 6 7 9 7 9"/>
                        <path d="M12 12h8"/>
                    </svg>
                    <?php echo __('Сканер локализации'); ?>
                </a>
            </li>
            <?php if ($updateStatus['is_new']): ?>
            <li>
                <a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true">
                        <path d="M4 5h16"/>
                        <path d="M9 3v4"/>
                        <path d="M7 9c2 6 7 9 7 9"/>
                        <path d="M12 12h8"/>
                    </svg>
                    <?php echo __('Обновление'); ?> (<?php echo htmlspecialchars($updateStatus['latest']); ?>)
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div class="main-content">
<h2><?php echo __('Админка PromoPilot'); ?></h2>
<?php if ($updateStatus['is_new']): ?>
<div class="alert alert-warning fade-in">
    <strong><?php echo __('Доступно обновление'); ?>:</strong> <?php echo htmlspecialchars($updateStatus['latest']); ?> (опубликовано <?php echo htmlspecialchars($updateStatus['published_at']); ?>).
    <a href="<?php echo pp_url('public/update.php'); ?>" class="alert-link"><?php echo __('Перейти к обновлению'); ?></a>.
</div>
<?php endif; ?>

<div id="users-section">
<h3><?php echo __('Пользователи'); ?></h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php echo __('Логин'); ?></th>
            <th><?php echo __('Роль'); ?></th>
            <th><?php echo __('Баланс'); ?></th>
            <th><?php echo __('Дата регистрации'); ?></th>
            <th><?php echo __('Действия'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $users->fetch_assoc()): ?>
            <tr>
                <td><?php echo (int)$user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['role']); ?></td>
                <td><?php echo htmlspecialchars(format_currency($user['balance'])); ?></td>
                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                <td>
                    <?php $t = action_token('login_as', (string)$user['id']); ?>
                    <a href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>

<div id="projects-section" style="display:none;">
<h3><?php echo __('Проекты'); ?></h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php echo __('Пользователь'); ?></th>
            <th><?php echo __('Название'); ?></th>
            <th><?php echo __('Описание'); ?></th>
            <th><?php echo __('Ссылки'); ?></th>
            <th><?php echo __('Дата создания'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($project = $projects->fetch_assoc()): ?>
            <tr>
                <td><?php echo (int)$project['id']; ?></td>
                <td><?php echo htmlspecialchars($project['username']); ?></td>
                <td><?php echo htmlspecialchars($project['name']); ?></td>
                <td><?php echo htmlspecialchars($project['description']); ?></td>
                <td><?php $links = json_decode($project['links'] ?? '[]', true); echo count($links); ?></td>
                <td><?php echo htmlspecialchars($project['created_at']); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>

<div id="settings-section" style="display:none;">
    <h3><?php echo __('Основные настройки'); ?></h3>
    <?php if ($settingsMsg): ?>
        <div class="alert alert-success fade-in"><?php echo htmlspecialchars($settingsMsg); ?></div>
    <?php endif; ?>
    <form method="post" class="card p-3" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo __('Валюта'); ?></label>
                <select name="currency" class="form-select form-control" required>
                    <?php foreach ($allowedCurrencies as $cur): ?>
                        <option value="<?php echo $cur; ?>" <?php echo ($settings['currency'] === $cur ? 'selected' : ''); ?>><?php echo $cur; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8"></div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Провайдер ИИ'); ?></label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="ai_provider" id="aiOpenAi" value="openai" <?php echo ($settings['ai_provider']==='openai'?'checked':''); ?>>
                    <label class="form-check-label" for="aiOpenAi">OpenAI (рекомендуется)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="ai_provider" id="aiByoa" value="byoa" <?php echo ($settings['ai_provider']==='byoa'?'checked':''); ?>>
                    <label class="form-check-label" for="aiByoa">Свой ИИ (Bring Your Own AI)</label>
                </div>
                <div class="form-text"><?php echo __('Выберите источник для генерации контента.'); ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">OpenAI API Key</label>
                <div class="input-group">
                    <input type="text" name="openai_api_key" class="form-control" id="openaiApiKeyInput" value="<?php echo htmlspecialchars($settings['openai_api_key']); ?>" placeholder="sk-...">
                    <button type="button" class="btn btn-outline-secondary" id="checkOpenAiKey" data-check-url="<?php echo pp_url('public/check_openai.php'); ?>">
                        <i class="bi bi-shield-check me-1"></i><?php echo __('Проверить'); ?>
                    </button>
                </div>
                <div class="form-text" id="openaiCheckStatus"></div>
                <div id="openaiCheckMessages" class="d-none"
                     data-empty="<?php echo htmlspecialchars(__('Введите ключ перед проверкой.')); ?>"
                     data-checking="<?php echo htmlspecialchars(__('Проверяем ключ...')); ?>"
                     data-ok="<?php echo htmlspecialchars(__('Ключ подтверждён. Доступно моделей:')); ?>"
                     data-error="<?php echo htmlspecialchars(__('Ошибка сервиса OpenAI.')); ?>"
                     data-request="<?php echo htmlspecialchars(__('Не удалось выполнить запрос.')); ?>"
                     data-no-curl="<?php echo htmlspecialchars(__('Расширение cURL недоступно.')); ?>"
                     data-unauthorized="<?php echo htmlspecialchars(__('Неверный ключ или нет доступа.')); ?>"
                     data-forbidden="<?php echo htmlspecialchars(__('Нет прав.')); ?>"
                     data-connection="<?php echo htmlspecialchars(__('Не удалось соединиться с OpenAI.')); ?>"
                     data-checking-short="<?php echo htmlspecialchars(__('Проверка...')); ?>"
                ></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Свой ИИ — Endpoint'); ?></label>
                <input type="text" name="custom_ai_endpoint" class="form-control" value="<?php echo htmlspecialchars($settings['custom_ai_endpoint']); ?>" placeholder="https://api.example.com/generate">
                <div class="form-text"><?php echo __('Укажите URL вашего сервиса генерации (POST JSON).'); ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Свой ИИ — Токен'); ?></label>
                <input type="text" name="custom_ai_token" class="form-control" value="<?php echo htmlspecialchars($settings['custom_ai_token']); ?>" placeholder="Bearer ...">
                <div class="form-text"><?php echo __('Токен авторизации для вашего сервиса.'); ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Google OAuth'); ?></label>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="google_oauth_enabled" id="googleEnabled" <?php echo ($settings['google_oauth_enabled']==='1'?'checked':''); ?>>
                    <label class="form-check-label" for="googleEnabled"><?php echo __('Разрешить вход через Google'); ?></label>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="text" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_id']); ?>" placeholder="Google Client ID">
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="google_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_secret']); ?>" placeholder="Google Client Secret">
                    </div>
                </div>
                <div class="form-text mt-1">
                    <?php echo __('Redirect URI'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2" onclick="document.getElementById('googleHelpModal').style.display='block'"><?php echo __('Как настроить?'); ?></button>
                </div>
            </div>
            <div class="col-md-6"></div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Telegram токен'); ?></label>
                <input type="text" name="telegram_token" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_token']); ?>" placeholder="1234567890:ABCDEF...">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Telegram канал'); ?></label>
                <input type="text" name="telegram_channel" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_channel']); ?>" placeholder="@your_channel или chat_id">
            </div>
        </div>
        <div class="mt-3">
            <button type="submit" name="settings_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
        </div>
    </form>

    <!-- Google Help Modal (simple) -->
    <div id="googleHelpModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000;">
        <div style="max-width:720px; margin:5% auto; background:#fff; border-radius:8px; padding:20px; position:relative;">
            <button type="button" onclick="document.getElementById('googleHelpModal').style.display='none'" style="position:absolute; right:12px; top:12px;" class="btn btn-sm btn-outline-secondary">×</button>
            <h5 class="mb-3"><?php echo __('Настройка входа через Google'); ?></h5>
            <ol class="mb-3">
                <li><?php echo __('Откройте'); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → APIs & Services → Credentials</a>.</li>
                <li><?php echo __('Создайте OAuth 2.0 Client ID (тип Web application).'); ?></li>
                <li><?php echo __('В Authorized redirect URIs добавьте'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code></li>
                <li><?php echo __('Скопируйте Client ID и Client Secret и вставьте в поля настроек.'); ?></li>
                <li><?php echo __('Включите переключатель «Разрешить вход через Google» и сохраните.'); ?></li>
                <li><?php echo __('На страницах входа/регистрации появится кнопка «Войти через Google».'); ?></li>
            </ol>
            <div class="text-end">
                <button type="button" class="btn btn-primary" onclick="document.getElementById('googleHelpModal').style.display='none'"><?php echo __('Понятно'); ?></button>
            </div>
        </div>
    </div>
</div>

<div id="networks-section" style="display:none;">
    <h3><?php echo __('Сети публикации'); ?></h3>
    <?php if ($networksMsg): ?>
        <div class="alert alert-info fade-in"><?php echo htmlspecialchars($networksMsg); ?></div>
    <?php endif; ?>
    <form method="post" class="card p-3 mb-3" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Путь до Node.js'); ?></label>
                <input type="text" name="node_binary" class="form-control" value="<?php echo htmlspecialchars($nodeBinaryStored); ?>" placeholder="node">
                <div class="form-text"><?php echo __('Оставьте пустым, чтобы использовать системный путь по умолчанию.'); ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Путь до Chrome/Chromium (необязательно)'); ?></label>
                <input type="text" name="puppeteer_executable_path" class="form-control" value="<?php echo htmlspecialchars($puppeteerExecStored); ?>" placeholder="/home/user/promopilot/node_runtime/chrome/chrome">
                <div class="form-text"><?php echo __('Если пусто — используется авто‑поиск или браузер Puppeteer.'); ?></div>
            </div>
        </div>
        <div class="row g-3 align-items-end mt-0">
            <div class="col-md-12">
                <label class="form-label"><?php echo __('Доп. аргументы для Puppeteer'); ?></label>
                <input type="text" name="puppeteer_args" class="form-control" value="<?php echo htmlspecialchars($puppeteerArgsStored); ?>" placeholder="--no-sandbox --disable-setuid-sandbox">
                <div class="form-text"><?php echo __('Аргументы будут добавлены к запуску браузера.'); ?></div>
            </div>
        </div>
        <div class="table-responsive mt-3">
            <?php if (!empty($networks)): ?>
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th style="width:70px;">&nbsp;</th>
                    <th><?php echo __('Сеть'); ?></th>
                    <th><?php echo __('Описание'); ?></th>
                    <th><?php echo __('Обработчик'); ?></th>
                    <th><?php echo __('Статус'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($networks as $network): ?>
                    <?php $isMissing = $network['is_missing']; ?>
                    <tr class="<?php echo $isMissing ? 'table-warning' : ''; ?>">
                        <td>
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" name="enable[<?php echo htmlspecialchars($network['slug']); ?>]" value="1" id="net-<?php echo htmlspecialchars($network['slug']); ?>" <?php echo ($network['enabled'] && !$isMissing) ? 'checked' : ''; ?> <?php echo $isMissing ? 'disabled' : ''; ?>>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($network['title']); ?></strong>
                            <div class="text-muted small"><?php echo htmlspecialchars($network['slug']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($network['description']); ?></td>
                        <td><code><?php echo htmlspecialchars($network['handler']); ?></code></td>
                        <td>
                            <?php if ($isMissing): ?>
                                <span class="badge bg-warning text-dark"><?php echo __('Файл не найден'); ?></span>
                            <?php elseif ($network['enabled']): ?>
                                <span class="badge badge-success"><?php echo __('Активна'); ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?php echo __('Отключена'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="text-muted mb-0"><?php echo __('Сети не обнаружены. Добавьте файлы в директорию networks и обновите список.'); ?></p>
            <?php endif; ?>
        </div>
        <div class="mt-3 text-md-end">
            <button type="submit" name="networks_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
        </div>
    </form>
    <form method="post" class="d-inline me-2">
        <?php echo csrf_field(); ?>
        <button type="submit" name="refresh_networks" value="1" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i><?php echo __('Обновить список сетей'); ?></button>
    </form>
    <form method="post" class="d-inline">
        <?php echo csrf_field(); ?>
        <button type="submit" name="detect_node" value="1" class="btn btn-outline-success"><i class="bi bi-magic me-1"></i><?php echo __('Автоопределение Node.js'); ?></button>
    </form>
</div>

<div id="diagnostics-section" style="display:none;">
    <h3><?php echo __('Диагностика системы'); ?></h3>
    <?php if ($diagnosticsMsg): ?>
        <div class="alert alert-info fade-in"><?php echo htmlspecialchars($diagnosticsMsg); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <tbody>
                    <?php foreach ($diagnostics as $row): ?>
                        <tr>
                            <th style="width:260px;" scope="row"><?php echo htmlspecialchars($row['label']); ?></th>
                            <td><?php echo htmlspecialchars($row['value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3 d-flex gap-2">
                <form method="post" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="detect_chrome" value="1" class="btn btn-outline-primary">
                        <i class="bi bi-search"></i> <?php echo __('Автоопределение Chrome'); ?>
                    </button>
                </form>
            </div>
            <?php if (!$chromeInstalled): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <div class="mb-1"><strong><?php echo __('Chrome не найден.'); ?></strong> <?php echo __('Установите Chrome for Testing в корне проекта и повторите автоопределение. Команды выполнять в каталоге проекта.'); ?></div>
                <ol class="mb-2">
                    <li><?php echo __('Перейдите в каталог проекта'); ?>: <code>cd /path/to/promopilot</code></li>
                    <li><?php echo __('Установите кеш‑папку'); ?>: <code>export PUPPETEER_CACHE_DIR="$PWD/node_runtime"</code></li>
                    <li><?php echo __('Укажите продукт'); ?>: <code>export PUPPETEER_PRODUCT=chrome</code></li>
                    <li><?php echo __('Установите браузер'); ?>: <code>npm exec puppeteer browsers install chrome</code></li>
                </ol>
                <div class="mb-1"><?php echo __('После установки укажите путь в'); ?> «<?php echo __('Путь до Chrome/Chromium'); ?>» <?php echo __('или нажмите'); ?> «<?php echo __('Автоопределение Chrome'); ?>».</div>
                <div class="small text-muted"><?php echo __('Примечание: при отсутствии npm exec попробуйте'); ?> <code>npx --yes puppeteer browsers install chrome</code>.</div>
            </div>
            <?php endif; ?>
            <?php if (!$puppeteerInstalled || !$nodeFetchInstalled): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i><?php echo __('Установите зависимости Node.js командой'); ?> <code>npm install</code> <?php echo __('в корне проекта.'); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /.main-content -->

<?php include '../includes/footer.php'; ?>
