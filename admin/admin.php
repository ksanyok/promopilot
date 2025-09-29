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
    'openai_model',             // selected OpenAI model
    'google_oauth_enabled',     // 0/1
    'google_client_id',
    'google_client_secret',
    // Anti-captcha settings
    'captcha_provider',         // none | 2captcha | anti-captcha
    'captcha_api_key',
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['settings_submit'])) {
        if (!verify_csrf()) {
            $settingsMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
            if (!in_array($currency, $allowedCurrencies, true)) { $currency = 'RUB'; }
            $openai = trim((string)($_POST['openai_api_key'] ?? ''));
            $openaiModel = trim((string)($_POST['openai_model'] ?? 'gpt-3.5-turbo'));
            $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
            $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));

            // AI provider selection (OpenAI or BYOA only; no custom fields)
            $aiProvider = in_array(($_POST['ai_provider'] ?? 'openai'), ['openai','byoa'], true) ? $_POST['ai_provider'] : 'openai';

            // Google OAuth config
            $googleEnabled = isset($_POST['google_oauth_enabled']) ? '1' : '0';
            $googleClientId = trim((string)($_POST['google_client_id'] ?? ''));
            $googleClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));

            $pairs = [
                ['currency', $currency],
                ['openai_api_key', $openai],
                ['openai_model', $openaiModel],
                ['telegram_token', $tgToken],
                ['telegram_channel', $tgChannel],
                ['ai_provider', $aiProvider],
                ['google_oauth_enabled', $googleEnabled],
                ['google_client_id', $googleClientId],
                ['google_client_secret', $googleClientSecret],
                // Anti-captcha
                ['captcha_provider', in_array(($_POST['captcha_provider'] ?? 'none'), ['none','2captcha','anti-captcha'], true) ? $_POST['captcha_provider'] : 'none'],
                ['captcha_api_key', trim((string)($_POST['captcha_api_key'] ?? ''))],
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
    'ai_provider' => $settings['ai_provider'] ?? 'openai',
    'openai_model' => $settings['openai_model'] ?? 'gpt-3.5-turbo',
    'google_oauth_enabled' => $settings['google_oauth_enabled'] ?? '0',
    'google_client_id' => $settings['google_client_id'] ?? '',
    'google_client_secret' => $settings['google_client_secret'] ?? '',
    // Anti-captcha defaults
    'captcha_provider' => $settings['captcha_provider'] ?? 'none',
    'captcha_api_key' => $settings['captcha_api_key'] ?? '',
];
$in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $settingsKeys)) . "'";
$res = $conn->query("SELECT k, v FROM settings WHERE k IN ($in)");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['k']] = (string)$row['v'];
    }
}

// Получить пользователей
$users = $conn->query("SELECT u.id, u.username, u.role, u.email, u.balance, u.created_at, COUNT(p.id) AS projects_count FROM users u LEFT JOIN projects p ON p.user_id = u.id GROUP BY u.id ORDER BY u.id");

// Получить проекты
$projects = $conn->query("SELECT p.id, p.name, p.description, p.links, p.created_at, u.username, COUNT(pb.id) AS published_count FROM projects p JOIN users u ON p.user_id = u.id LEFT JOIN publications pb ON pb.project_id = p.id GROUP BY p.id ORDER BY p.id");

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
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
        <tr>
            <th class="text-nowrap">ID</th>
            <th><?php echo __('Пользователь'); ?></th>
            <th class="d-none d-md-table-cell text-center"><?php echo __('Проекты'); ?></th>
            <th class="d-none d-sm-table-cell"><?php echo __('Роль'); ?></th>
            <th class="d-none d-lg-table-cell"><?php echo __('Баланс'); ?></th>
            <th class="text-nowrap"><?php echo __('Дата регистрации'); ?></th>
            <th class="text-end"><?php echo __('Действия'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $users->fetch_assoc()): ?>
            <tr>
                <td class="text-muted">#<?php echo (int)$user['id']; ?></td>
                <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($user['username']); ?></div>
                    <?php if (!empty($user['email'])): ?>
                        <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="d-none d-md-table-cell text-center">
                    <span class="badge bg-secondary"><?php echo (int)$user['projects_count']; ?></span>
                </td>
                <td class="d-none d-sm-table-cell text-muted"><?php echo htmlspecialchars($user['role']); ?></td>
                <td class="d-none d-lg-table-cell"><?php echo htmlspecialchars(format_currency($user['balance'])); ?></td>
                <td class="text-muted">
                    <?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$user['created_at']))); ?>
                </td>
                <td class="text-end">
                    <?php $t = action_token('login_as', (string)$user['id']); ?>
                    <a href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>" class="btn btn-warning btn-sm"><?php echo __('Войти как'); ?></a>
                    <a href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-info btn-sm"><?php echo __('Изменить баланс'); ?></a>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>

<div id="projects-section" style="display:none;">
<h3><?php echo __('Проекты'); ?></h3>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
        <tr>
            <th class="text-nowrap">ID</th>
            <th><?php echo __('Название'); ?></th>
            <th class="d-none d-sm-table-cell"><?php echo __('Владелец'); ?></th>
            <th class="text-center"><?php echo __('Ссылки'); ?></th>
            <th style="min-width:220px;">&percnt; <?php echo __('публикаций'); ?></th>
            <th class="text-nowrap d-none d-md-table-cell"><?php echo __('Дата создания'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php while ($project = $projects->fetch_assoc()): ?>
            <?php $linksArr = json_decode($project['links'] ?? '[]', true) ?: []; $total = count($linksArr); $pub = (int)$project['published_count']; $pub = max(0, min($pub, $total)); $pct = $total > 0 ? (int)round(100 * $pub / $total) : 0; ?>
            <tr>
                <td class="text-muted">#<?php echo (int)$project['id']; ?></td>
                <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($project['name']); ?></div>
                    <?php if (!empty($project['description'])): ?>
                        <div class="text-muted small d-none d-lg-block"><?php echo htmlspecialchars($project['description']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="d-none d-sm-table-cell">
                    <span class="badge bg-dark-subtle text-dark"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($project['username']); ?></span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary"><?php echo (int)$total; ?></span>
                </td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="text-nowrap small text-muted"><?php echo $pub; ?>/<?php echo $total; ?> (<?php echo $pct; ?>%)</div>
                    </div>
                </td>
                <td class="text-muted d-none d-md-table-cell"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$project['created_at']))); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>

<div id="settings-section" style="display:none;">
    <h3><?php echo __('Основные настройки'); ?></h3>
    <?php if ($settingsMsg): ?>
        <div class="alert alert-success fade-in"><?php echo htmlspecialchars($settingsMsg); ?></div>
    <?php endif; ?>
    <form method="post" class="card settings-card p-3" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="row g-4">
            <div class="col-md-4">
                <label class="form-label"><?php echo __('Валюта'); ?></label>
                <select name="currency" class="form-select form-control" required>
                    <?php foreach ($allowedCurrencies as $cur): ?>
                        <option value="<?php echo $cur; ?>" <?php echo ($settings['currency'] === $cur ? 'selected' : ''); ?>><?php echo $cur; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?php echo __('Используется в биллинге и отчетах.'); ?></div>
            </div>
            <div class="col-md-8"></div>

            <div class="col-md-6">
                <label class="form-label"><?php echo __('Провайдер ИИ'); ?></label>
                <div class="pp-segmented" role="group" aria-label="AI provider">
                    <input type="radio" id="provOpenAI" name="ai_provider" value="openai" <?php echo ($settings['ai_provider']==='openai'?'checked':''); ?>>
                    <label for="provOpenAI">OpenAI</label>
                    <input type="radio" id="provByoa" name="ai_provider" value="byoa" <?php echo ($settings['ai_provider']==='byoa'?'checked':''); ?>>
                    <label for="provByoa"><?php echo __('Свой ИИ'); ?></label>
                </div>
                <div class="form-text"><?php echo __('Выберите источник генерации. Для OpenAI укажите API ключ ниже.'); ?></div>
            </div>
            <div class="col-md-6" id="openaiFields">
                <label class="form-label">OpenAI API Key</label>
                <div class="input-group mb-2">
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

                <label class="form-label mt-3"><?php echo __('Модель OpenAI'); ?></label>
                <select name="openai_model" class="form-select form-control" id="openaiModelSelect">
                    <?php
                    $suggested = [
                        'gpt-3.5-turbo' => 'gpt-3.5-turbo',
                        'gpt-4o-mini' => 'gpt-4o-mini',
                        'gpt-4o-mini-translate' => 'gpt-4o-mini-translate',
                        'gpt-5-mini' => 'gpt-5-mini',
                        'gpt-5-nano' => 'gpt-5-nano',
                    ];
                    $selModel = $settings['openai_model'] ?? 'gpt-3.5-turbo';
                    foreach ($suggested as $val => $label) {
                        $sel = ($selModel === $val) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($val) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                    }
                    if (!isset($suggested[$selModel])) {
                        echo '<option value="' . htmlspecialchars($selModel) . '" selected>' . htmlspecialchars($selModel) . '</option>';
                    }
                    ?>
                </select>
                <div class="form-text"><?php echo __('Выберите недорогую модель. Можно указать произвольную строку модели.'); ?></div>
            </div>

            <div class="col-md-6">
                <label class="form-label"><?php echo __('Google OAuth'); ?></label>
                <div class="pp-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="google_oauth_enabled" id="googleEnabled" <?php echo ($settings['google_oauth_enabled']==='1'?'checked':''); ?>>
                    <span class="track"><span class="thumb"></span></span>
                    <label for="googleEnabled" class="ms-1"><?php echo __('Разрешить вход через Google'); ?></label>
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
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2" id="openGoogleHelp"><?php echo __('Как настроить?'); ?></button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo __('Антикапча'); ?></label>
                <div class="row g-2">
                    <div class="col-md-6">
                        <select name="captcha_provider" class="form-select form-control">
                            <?php $cp = $settings['captcha_provider'] ?? 'none'; ?>
                            <option value="none" <?php echo ($cp==='none'?'selected':''); ?>><?php echo __('Выключено'); ?></option>
                            <option value="2captcha" <?php echo ($cp==='2captcha'?'selected':''); ?>>2Captcha</option>
                            <option value="anti-captcha" <?php echo ($cp==='anti-captcha'?'selected':''); ?>>Anti-Captcha</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="captcha_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['captcha_api_key']); ?>" placeholder="API key">
                    </div>
                </div>
                <div class="form-text"><?php echo __('Будет использоваться для автоматического решения reCAPTCHA/hCaptcha при публикации (например, JustPaste.it).'); ?></div>
            </div>

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

    <!-- Google Help Modal (scrollable, high-contrast) -->
    <div id="googleHelpModal" class="pp-modal" aria-hidden="true" role="dialog" aria-labelledby="googleHelpTitle">
        <div class="pp-modal-dialog">
            <div class="pp-modal-header">
                <div class="pp-modal-title" id="googleHelpTitle"><?php echo __('Настройка входа через Google'); ?></div>
                <button type="button" class="pp-close" data-pp-close>&times;</button>
            </div>
            <div class="pp-modal-body">
                <p><?php echo __('Эта инструкция поможет подключить вход через Google в несколько шагов. Выполняйте в Google Cloud Console.'); ?></p>
                <ol>
                    <li><?php echo __('Откройте'); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">APIs &amp; Services → Credentials</a> (<?php echo __('создайте проект при необходимости'); ?>).</li>
                    <li><?php echo __('На вкладке OAuth consent screen задайте тип (External), название приложения и email поддержки. Сохраните.'); ?></li>
                    <li><?php echo __('Перейдите в Credentials → Create Credentials → OAuth client ID и выберите Web application.'); ?></li>
                    <li><?php echo __('В Authorized redirect URIs добавьте'); ?>: <code><?php echo htmlspecialchars(pp_google_redirect_url()); ?></code></li>
                    <li><?php echo __('Сохраните и скопируйте Client ID и Client Secret. Вставьте их в поля настроек на этой странице.'); ?></li>
                    <li><?php echo __('Включите переключатель «Разрешить вход через Google» и нажмите «Сохранить».'); ?></li>
                </ol>
                <h6 class="mt-3"><?php echo __('Проверка'); ?></h6>
                <p><?php echo __('Откройте страницу входа. Появится кнопка «Войти через Google». Авторизуйтесь и подтвердите права.'); ?></p>
                <h6 class="mt-3"><?php echo __('Советы и устранение неполадок'); ?></h6>
                <ul>
                    <li><?php echo __('Если видите ошибку redirect_uri_mismatch — проверьте точное совпадение Redirect URI.'); ?></li>
                    <li><?php echo __('Для публикации за пределами тестовых пользователей переведите приложение в статус In production на странице OAuth consent screen.'); ?></li>
                    <li><?php echo __('Проверьте, что время на сервере синхронизировано (NTP), иначе подпись токена может считаться просроченной.'); ?></li>
                    <li><?php echo __('Ограничьте доступ при необходимости, проверяя домен email в обработчике после входа.'); ?></li>
                </ul>
            </div>
            <div class="pp-modal-footer">
                <button type="button" class="btn btn-outline-primary" data-pp-close><?php echo __('Готово'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
  const modal = document.getElementById('googleHelpModal');
  const openBtn = document.getElementById('openGoogleHelp');
  function close(){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }
  function open(){ modal.classList.add('show'); modal.removeAttribute('aria-hidden'); }
  if (openBtn) openBtn.addEventListener('click', open);
  modal?.addEventListener('click', function(e){ if (e.target === modal || e.target.closest('[data-pp-close]')) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
</script>

<script>
(function(){
  // Toggle OpenAI fields by provider selection
  const fields = document.getElementById('openaiFields');
  const radios = document.querySelectorAll('input[name="ai_provider"]');
  function apply(){
    const val = document.querySelector('input[name="ai_provider"]:checked')?.value || 'openai';
    if (val === 'openai') {
      fields?.classList.remove('d-none');
    } else {
      fields?.classList.add('d-none');
    }
  }
  radios.forEach(r => r.addEventListener('change', apply));
  apply();
})();
</script>

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
        <?php
        $normalizeFilterToken = static function ($value) {
            $value = trim((string)$value);
            if ($value === '') {
                return '';
            }
            if (function_exists('mb_strtolower')) {
                $value = mb_strtolower($value, 'UTF-8');
            } else {
                $value = strtolower($value);
            }
            return preg_replace('~\s+~', ' ', $value);
        };
        $regionOptions = [];
        $topicOptions = [];
        if (!empty($networks)) {
            foreach ($networks as $netMeta) {
                foreach (($netMeta['regions'] ?? []) as $regionItem) {
                    $key = $normalizeFilterToken($regionItem);
                    if ($key !== '' && !isset($regionOptions[$key])) {
                        $regionOptions[$key] = $regionItem;
                    }
                }
                foreach (($netMeta['topics'] ?? []) as $topicItem) {
                    $key = $normalizeFilterToken($topicItem);
                    if ($key !== '' && !isset($topicOptions[$key])) {
                        $topicOptions[$key] = $topicItem;
                    }
                }
            }
            ksort($regionOptions, SORT_STRING);
            ksort($topicOptions, SORT_STRING);
        }
        ?>
        <?php if (!empty($networks)): ?>
        <div class="bg-light border rounded p-3 mt-3" id="networkFiltersBar">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label form-label-sm" for="filterStatus"><?php echo __('Статус проверки'); ?></label>
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="all"><?php echo __('Все'); ?></option>
                        <option value="success"><?php echo __('Успешно'); ?></option>
                        <option value="failed"><?php echo __('С ошибками'); ?></option>
                        <option value="progress"><?php echo __('В процессе'); ?></option>
                        <option value="cancelled"><?php echo __('Отменено'); ?></option>
                        <option value="none"><?php echo __('Нет данных'); ?></option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label form-label-sm" for="filterActive"><?php echo __('Активность'); ?></label>
                    <select id="filterActive" class="form-select form-select-sm">
                        <option value="all"><?php echo __('Все'); ?></option>
                        <option value="active"><?php echo __('Активные'); ?></option>
                        <option value="inactive"><?php echo __('Неактивные'); ?></option>
                        <option value="missing"><?php echo __('Файл недоступен'); ?></option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label form-label-sm" for="filterRegion"><?php echo __('Регион'); ?></label>
                    <select id="filterRegion" class="form-select form-select-sm">
                        <option value="all"><?php echo __('Все'); ?></option>
                        <?php foreach ($regionOptions as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label form-label-sm" for="filterTopic"><?php echo __('Тематика'); ?></label>
                    <select id="filterTopic" class="form-select form-select-sm">
                        <option value="all"><?php echo __('Все'); ?></option>
                        <?php foreach ($topicOptions as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="activateVerifiedBtn" data-message-template="<?php echo htmlspecialchars(__('Выбрано проверенных сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi bi-toggle2-on me-1"></i><?php echo __('Активировать проверенные'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="resetNetworkFilters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo __('Сбросить фильтры'); ?>
                    </button>
                </div>
                <div class="col-12">
                    <div class="small text-muted" id="networkFiltersInfo" data-label-visible="<?php echo htmlspecialchars(__('Показано сетей: %d'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="table-responsive mt-3">
            <?php if (!empty($networks)): ?>
            <table class="table table-striped align-middle" id="networksTable">
                <thead>
                <tr>
                    <th style="width:70px;">&nbsp;</th>
                    <th><?php echo __('Сеть'); ?></th>
                    <th class="text-center" style="width:80px;">&nbsp;</th>
                    <th><?php echo __('Описание'); ?></th>
                    <th><?php echo __('Обработчик'); ?></th>
                    <th><?php echo __('Статус'); ?></th>
                    <th><?php echo __('Последняя проверка'); ?></th>
                    <th class="text-end" style="width:180px;"><?php echo __('Диагностика'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($networks as $network): ?>
                    <?php
                    $isMissing = $network['is_missing'];
                    $regionTokens = array_values(array_filter(array_map($normalizeFilterToken, $network['regions'] ?? [])));
                    $topicTokens = array_values(array_filter(array_map($normalizeFilterToken, $network['topics'] ?? [])));
                    $tooltipParts = [];
                    if (!empty($network['regions'])) {
                        $tooltipParts[] = __('Регионы') . ': ' . implode(', ', $network['regions']);
                    }
                    if (!empty($network['topics'])) {
                        $tooltipParts[] = __('Тематики') . ': ' . implode(', ', $network['topics']);
                    }
                    $tooltipText = implode("\n", $tooltipParts);
                    $rowStatus = (string)($network['last_check_status'] ?? '');
                    $rowStatusAttr = $rowStatus !== '' ? $rowStatus : 'none';
                    ?>
                    <tr class="<?php echo $isMissing ? 'table-warning' : ''; ?>"
                        data-status="<?php echo htmlspecialchars($rowStatusAttr); ?>"
                        data-active="<?php echo ($network['enabled'] && !$isMissing) ? '1' : '0'; ?>"
                        data-missing="<?php echo $isMissing ? '1' : '0'; ?>"
                        data-regions="<?php echo htmlspecialchars(implode('|', $regionTokens)); ?>"
                        data-topics="<?php echo htmlspecialchars(implode('|', $topicTokens)); ?>">
                        <td>
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" name="enable[<?php echo htmlspecialchars($network['slug']); ?>]" value="1" id="net-<?php echo htmlspecialchars($network['slug']); ?>" <?php echo ($network['enabled'] && !$isMissing) ? 'checked' : ''; ?> <?php echo $isMissing ? 'disabled' : ''; ?>>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($network['title']); ?></strong>
                            <div class="text-muted small"><?php echo htmlspecialchars($network['slug']); ?></div>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($tooltipText)): ?>
                                <span class="text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="bi bi-info-circle"></i>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
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
                        <td>
                            <?php
                            $lastStatus = (string)($network['last_check_status'] ?? '');
                            $lastStarted = $network['last_check_started_at'] ?? null;
                            $lastFinished = $network['last_check_finished_at'] ?? null;
                            $lastUrl = trim((string)($network['last_check_url'] ?? ''));
                            $lastError = trim((string)($network['last_check_error'] ?? ''));
                            $lastRunId = $network['last_check_run_id'] ?? null;
                            $statusMap = [
                                'success' => ['label' => __('Успешно'), 'class' => 'bg-success'],
                                'failed' => ['label' => __('С ошибками'), 'class' => 'bg-danger'],
                                'running' => ['label' => __('Выполняется'), 'class' => 'bg-primary'],
                                'queued' => ['label' => __('В ожидании'), 'class' => 'bg-secondary'],
                                'cancelled' => ['label' => __('Отменено'), 'class' => 'bg-warning text-dark'],
                            ];
                            $badge = $statusMap[$lastStatus] ?? null;
                            if ($badge): ?>
                                <span class="badge <?php echo htmlspecialchars($badge['class']); ?>"><?php echo htmlspecialchars($badge['label']); ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><?php echo __('Нет данных'); ?></span>
                            <?php endif; ?>
                            <?php if ($lastFinished || $lastStarted): ?>
                                <div class="text-muted small">
                                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($lastFinished ?: $lastStarted))); ?>
                                    <?php if ($lastRunId): ?>
                                        <span class="text-muted">#<?php echo (int)$lastRunId; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($lastUrl): ?>
                                <a href="<?php echo htmlspecialchars($lastUrl); ?>" target="_blank" rel="noopener" class="small d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-box-arrow-up-right"></i><?php echo __('Открыть'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($lastError): ?>
                                <div class="small text-danger mt-1" title="<?php echo htmlspecialchars($lastError); ?>"><?php echo htmlspecialchars(mb_strimwidth($lastError, 0, 80, '…')); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-warning" data-network-check-single="1" data-network-slug="<?php echo htmlspecialchars($network['slug']); ?>" data-network-title="<?php echo htmlspecialchars($network['title']); ?>" <?php echo $isMissing ? 'disabled' : ''; ?>>
                                <i class="bi bi-play-circle me-1"></i><?php echo __('Проверить'); ?>
                            </button>
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

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-warning" id="networkCheckButton" data-label="<?php echo __('Проверить сети'); ?>"><i class="bi bi-activity me-1"></i><?php echo __('Проверить сети'); ?></button>
        <button type="button" class="btn btn-outline-danger" id="networkCheckStopButton" style="display:none;"
            data-label-html="<?php echo htmlspecialchars('<i class=\"bi bi-stop-circle me-1\"></i>' . __('Остановить проверку'), ENT_QUOTES, 'UTF-8'); ?>"
            data-wait-label="<?php echo htmlspecialchars(__('Ожидаем остановки…'), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить проверку'); ?>
        </button>
        <button type="button" class="btn btn-outline-light" id="networkCheckHistoryButton" style="display:none;" data-label="<?php echo __('Показать последний результат'); ?>"><i class="bi bi-clock-history me-1"></i><?php echo __('Показать последний результат'); ?></button>
    </div>
    <div class="alert alert-warning mt-3 d-none" id="networkCheckMessage"></div>

    <div class="card network-check-summary-card mt-3" id="networkCheckLastRun" style="display:none;">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <div class="text-muted small mb-1"><?php echo __('Последняя проверка сетей'); ?></div>
                    <div class="fw-semibold" data-summary-status>—</div>
                    <div class="text-muted small" data-summary-time>—</div>
                    <div class="text-muted small" data-summary-mode style="display:none;">—</div>
                </div>
                <div class="text-lg-end">
                    <div class="small mb-1">
                        <?php echo __('Успешно'); ?>: <span class="fw-semibold" data-summary-success>0</span> / <span data-summary-total>0</span>
                    </div>
                    <div class="small">
                        <?php echo __('С ошибками'); ?>: <span class="fw-semibold" data-summary-failed>0</span>
                    </div>
                    <div class="small text-muted" data-summary-note></div>
                </div>
            </div>
        </div>
    </div>

    <div id="networkCheckModal" class="pp-modal" aria-hidden="true" role="dialog" aria-labelledby="networkCheckModalTitle">
        <div class="pp-modal-dialog">
            <div class="pp-modal-header">
                <div class="pp-modal-title" id="networkCheckModalTitle"><?php echo __('Результаты проверки сетей'); ?></div>
                <button type="button" class="pp-close" data-pp-close>&times;</button>
            </div>
            <div class="pp-modal-body">
                <div id="networkCheckModalMeta" class="network-check-meta mb-3 text-muted small">&nbsp;</div>
                <div class="progress network-check-progress mb-3" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" id="networkCheckProgressBar" style="width:0%;">0%</div>
                </div>
                <div id="networkCheckModalNote" class="network-check-note mb-2"></div>
                <div id="networkCheckCurrent" class="network-check-current mb-3"></div>
                <div id="networkCheckResults" class="network-check-results"></div>
            </div>
            <div class="pp-modal-footer">
                <button type="button" class="btn btn-outline-success me-auto" id="networkCheckApplySuccess" style="display:none;">
                    <i class="bi bi-toggle-on me-1"></i><?php echo __('Активировать успешные'); ?>
                </button>
                <button type="button" class="btn btn-outline-primary" data-pp-close><?php echo __('Закрыть'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const startBtn = document.getElementById('networkCheckButton');
    const stopBtn = document.getElementById('networkCheckStopButton');
    if (!startBtn) return;
    const historyBtn = document.getElementById('networkCheckHistoryButton');
    const messageBox = document.getElementById('networkCheckMessage');
    const summaryCard = document.getElementById('networkCheckLastRun');
    const summaryStatus = summaryCard ? summaryCard.querySelector('[data-summary-status]') : null;
    const summaryTime = summaryCard ? summaryCard.querySelector('[data-summary-time]') : null;
    const summarySuccess = summaryCard ? summaryCard.querySelector('[data-summary-success]') : null;
    const summaryTotal = summaryCard ? summaryCard.querySelector('[data-summary-total]') : null;
    const summaryFailed = summaryCard ? summaryCard.querySelector('[data-summary-failed]') : null;
    const summaryNote = summaryCard ? summaryCard.querySelector('[data-summary-note]') : null;
    const summaryMode = summaryCard ? summaryCard.querySelector('[data-summary-mode]') : null;

    const modal = document.getElementById('networkCheckModal');
    const progressBar = document.getElementById('networkCheckProgressBar');
    const resultsContainer = document.getElementById('networkCheckResults');
    const currentBox = document.getElementById('networkCheckCurrent');
    const metaBox = document.getElementById('networkCheckModalMeta');
    const noteBox = document.getElementById('networkCheckModalNote');
    const applySuccessBtn = document.getElementById('networkCheckApplySuccess');
    const stopBtnDefaultHtml = stopBtn ? (stopBtn.getAttribute('data-label-html') || stopBtn.innerHTML) : '';
    const stopBtnWaitLabel = stopBtn ? (stopBtn.getAttribute('data-wait-label') || '') : '';
    const singleButtons = Array.from(document.querySelectorAll('[data-network-check-single]'));
    const singleButtonInitialDisabled = new WeakMap();
    singleButtons.forEach(function(btn){ singleButtonInitialDisabled.set(btn, btn.disabled); });

    const labels = {
        success: <?php echo json_encode(__('Успешно'), JSON_UNESCAPED_UNICODE); ?>,
        failed: <?php echo json_encode(__('С ошибками'), JSON_UNESCAPED_UNICODE); ?>,
        running: <?php echo json_encode(__('Выполняется'), JSON_UNESCAPED_UNICODE); ?>,
        queued: <?php echo json_encode(__('В ожидании'), JSON_UNESCAPED_UNICODE); ?>,
        cancelled: <?php echo json_encode(__('Отменено'), JSON_UNESCAPED_UNICODE); ?>,
        allGood: <?php echo json_encode(__('Все сети опубликованы успешно'), JSON_UNESCAPED_UNICODE); ?>,
        partial: <?php echo json_encode(__('Часть сетей завершилась с ошибками'), JSON_UNESCAPED_UNICODE); ?>,
        failedSummary: <?php echo json_encode(__('Проверка не выполнена'), JSON_UNESCAPED_UNICODE); ?>,
        cancelledSummary: <?php echo json_encode(__('Проверка остановлена'), JSON_UNESCAPED_UNICODE); ?>,
        noData: <?php echo json_encode(__('Проверка ещё не запускалась'), JSON_UNESCAPED_UNICODE); ?>,
        current: <?php echo json_encode(__('Сейчас выполняется'), JSON_UNESCAPED_UNICODE); ?>,
        startedAt: <?php echo json_encode(__('Запущено'), JSON_UNESCAPED_UNICODE); ?>,
        finishedAt: <?php echo json_encode(__('Завершено'), JSON_UNESCAPED_UNICODE); ?>,
        total: <?php echo json_encode(__('Всего сетей'), JSON_UNESCAPED_UNICODE); ?>,
        open: <?php echo json_encode(__('Открыть'), JSON_UNESCAPED_UNICODE); ?>,
        notAvailable: <?php echo json_encode(__('Недоступно'), JSON_UNESCAPED_UNICODE); ?>,
        modalEmpty: <?php echo json_encode(__('Результаты отсутствуют.'), JSON_UNESCAPED_UNICODE); ?>,
        alreadyRunning: <?php echo json_encode(__('Проверка уже выполняется.'), JSON_UNESCAPED_UNICODE); ?>,
        mode: <?php echo json_encode(__('Режим'), JSON_UNESCAPED_UNICODE); ?>,
        bulkMode: <?php echo json_encode(__('Комплексная проверка'), JSON_UNESCAPED_UNICODE); ?>,
        singleMode: <?php echo json_encode(__('Проверка одной сети'), JSON_UNESCAPED_UNICODE); ?>,
        applySuccessHint: <?php echo json_encode(__('Активированы только успешные сети. Проверьте список ниже и сохраните изменения.'), JSON_UNESCAPED_UNICODE); ?>,
        bulkStarted: <?php echo json_encode(__('Запущена комплексная проверка всех активных сетей.'), JSON_UNESCAPED_UNICODE); ?>,
        singleStarted: <?php echo json_encode(__('Проверка запущена для сети: %s'), JSON_UNESCAPED_UNICODE); ?>,
        canceling: <?php echo json_encode(__('Останавливаем проверку...'), JSON_UNESCAPED_UNICODE); ?>,
        cancelRequested: <?php echo json_encode(__('Остановка запрошена. Подождите завершения.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelSuccess: <?php echo json_encode(__('Проверка остановлена.'), JSON_UNESCAPED_UNICODE); ?>
    };

    const errorMessages = {
        'NO_ENABLED_NETWORKS': <?php echo json_encode(__('Нет активных сетей для проверки.'), JSON_UNESCAPED_UNICODE); ?>,
        'WORKER_LAUNCH_FAILED': <?php echo json_encode(__('Не удалось запустить фоновый процесс.'), JSON_UNESCAPED_UNICODE); ?>,
        'DB_CONNECTION': <?php echo json_encode(__('Ошибка подключения к базе данных.'), JSON_UNESCAPED_UNICODE); ?>,
        'DB_WRITE': <?php echo json_encode(__('Не удалось сохранить данные.'), JSON_UNESCAPED_UNICODE); ?>,
        'DB_READ': <?php echo json_encode(__('Не удалось прочитать данные.'), JSON_UNESCAPED_UNICODE); ?>,
        'RUN_NOT_FOUND': <?php echo json_encode(__('Проверка не найдена.'), JSON_UNESCAPED_UNICODE); ?>,
        'CSRF': <?php echo json_encode(__('Ошибка безопасности.'), JSON_UNESCAPED_UNICODE); ?>,
        'MISSING_SLUG': <?php echo json_encode(__('Не указана сеть для проверки.'), JSON_UNESCAPED_UNICODE); ?>,
        'NETWORK_NOT_FOUND': <?php echo json_encode(__('Выбранная сеть недоступна для проверки.'), JSON_UNESCAPED_UNICODE); ?>,
        'DEFAULT': <?php echo json_encode(__('Произошла ошибка. Попробуйте снова.'), JSON_UNESCAPED_UNICODE); ?>
    };

    const apiStart = <?php echo json_encode(pp_url('admin/network_check.php?action=start'), JSON_UNESCAPED_UNICODE); ?>;
    const apiStatus = <?php echo json_encode(pp_url('admin/network_check.php?action=status'), JSON_UNESCAPED_UNICODE); ?>;
    const apiCancel = <?php echo json_encode(pp_url('admin/network_check.php?action=cancel'), JSON_UNESCAPED_UNICODE); ?>;

    let pollTimer = 0;
    let currentRunId = null;
    let modalOpen = false;
    let latestData = null;

    function clearMessage() {
        if (!messageBox) return;
        messageBox.classList.add('d-none');
        messageBox.classList.remove('alert-warning', 'alert-danger', 'alert-success', 'alert-info');
        messageBox.textContent = '';
    }

    function setMessage(type, text) {
        if (!messageBox) return;
        if (!text) { clearMessage(); return; }
        messageBox.classList.remove('alert-warning', 'alert-danger', 'alert-success', 'alert-info');
        let cls = 'alert-warning';
        if (type === 'danger') cls = 'alert-danger';
        else if (type === 'success') cls = 'alert-success';
        else if (type === 'info') cls = 'alert-info';
        messageBox.classList.remove('d-none');
        messageBox.classList.add(cls);
        messageBox.textContent = text;
    }

    function setControlsDisabled(disabled) {
        if (startBtn) {
            startBtn.disabled = disabled;
        }
        if (historyBtn) {
            historyBtn.disabled = disabled && !modalOpen;
        }
        singleButtons.forEach(function(btn){
            if (disabled) {
                btn.disabled = true;
            } else {
                if (!singleButtonInitialDisabled.get(btn)) {
                    btn.disabled = false;
                }
            }
        });
        if (applySuccessBtn && disabled) {
            applySuccessBtn.disabled = true;
            applySuccessBtn.style.display = 'none';
        }
    }

    function updateStopButton(run) {
        if (!stopBtn) return;
        const restoreDefault = function() {
            if (stopBtnDefaultHtml) {
                stopBtn.innerHTML = stopBtnDefaultHtml;
            }
        };
        if (run && (run.status === 'queued' || run.status === 'running')) {
            stopBtn.style.display = '';
            if (run.cancel_requested) {
                const waitText = stopBtnWaitLabel || labels.canceling || '';
                stopBtn.disabled = true;
                stopBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + escapeHtml(waitText);
            } else {
                stopBtn.disabled = false;
                restoreDefault();
            }
        } else {
            stopBtn.style.display = 'none';
            stopBtn.disabled = true;
            restoreDefault();
        }
    }

    function updateApplySuccessButton(data) {
        if (!applySuccessBtn) return;
        const run = data && data.run ? data.run : null;
        const results = data && Array.isArray(data.results) ? data.results : [];
        const show = run && !run.in_progress && run.run_mode === 'bulk' && results.length > 0;
        if (show) {
            applySuccessBtn.style.display = '';
            applySuccessBtn.disabled = false;
        } else {
            applySuccessBtn.style.display = 'none';
        }
    }

    function applySuccessfulNetworks() {
        if (!latestData || !Array.isArray(latestData.results)) {
            setMessage('info', labels.modalEmpty);
            return;
        }
        const observed = new Set();
        const successful = new Set();
        latestData.results.forEach(function(item){
            const slug = (item.network_slug || '').trim();
            if (!slug) return;
            observed.add(slug);
            if (item.status === 'success') {
                successful.add(slug);
            }
        });
        if (observed.size === 0) {
            setMessage('info', labels.modalEmpty);
            return;
        }
        document.querySelectorAll('input[name^="enable["]').forEach(function(input){
            if (!(input instanceof HTMLInputElement)) return;
            const match = input.name.match(/^enable\[(.+)\]$/);
            if (!match) return;
            const slug = match[1];
            if (!observed.has(slug)) return;
            input.checked = successful.has(slug);
        });
        setMessage('success', labels.applySuccessHint);
    }

    function escapeHtml(str) {
        return (str ?? '').toString().replace(/[&<>"']/g, function(ch) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return map[ch] || ch;
        });
    }

    function formatDate(iso) {
        if (!iso) return '—';
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return iso;
        try { return date.toLocaleString(); } catch (e) { return iso; }
    }

    function buildBadge(status) {
        switch (status) {
            case 'success': return { cls: 'success', label: labels.success };
            case 'failed': return { cls: 'failed', label: labels.failed };
            case 'running': return { cls: 'running', label: labels.running };
            case 'cancelled': return { cls: 'cancelled', label: labels.cancelled };
            default: return { cls: 'queued', label: labels.queued };
        }
    }

    function updateProgress(run) {
        if (!progressBar) return;
        const total = run ? Math.max(0, run.total_networks) : 0;
        const completed = run ? Math.max(0, run.completed_count) : 0;
        const percent = total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 0;
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', String(percent));
        progressBar.textContent = percent + '%';
        progressBar.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-secondary');
        if (!run) return;
        if (run.status === 'success') progressBar.classList.add('bg-success');
        else if (run.status === 'completed') progressBar.classList.add('bg-warning');
        else if (run.status === 'failed') progressBar.classList.add('bg-danger');
        else if (run.status === 'cancelled') progressBar.classList.add('bg-secondary');
    }

    function summaryStatusText(run) {
        if (!run) return labels.noData;
        switch (run.status) {
            case 'running':
            case 'queued':
                return labels.running;
            case 'success':
                return labels.allGood;
            case 'completed':
                return labels.partial;
            case 'cancelled':
                return labels.cancelledSummary;
            default:
                return labels.failedSummary;
        }
    }

    function updateSummary(run) {
        if (!summaryCard) return;
        if (!run) {
            summaryCard.style.display = 'none';
            if (historyBtn) historyBtn.style.display = 'none';
            if (summaryMode) {
                summaryMode.style.display = 'none';
                summaryMode.textContent = '';
            }
            return;
        }
        summaryCard.style.display = '';
        if (historyBtn) {
            historyBtn.style.display = '';
            historyBtn.disabled = false;
        }
        if (summaryStatus) summaryStatus.textContent = summaryStatusText(run);
        if (summaryTime) {
            const ts = run.finished_at_iso || run.started_at_iso || run.created_at_iso;
            summaryTime.textContent = ts ? formatDate(ts) : labels.notAvailable;
        }
        if (summaryMode) {
            if (run.run_mode) {
                summaryMode.textContent = run.run_mode === 'single' ? labels.singleMode : labels.bulkMode;
                summaryMode.style.display = '';
            } else {
                summaryMode.style.display = 'none';
                summaryMode.textContent = '';
            }
        }
        if (summarySuccess) summarySuccess.textContent = run.success_count;
        if (summaryTotal) summaryTotal.textContent = run.total_networks;
        if (summaryFailed) summaryFailed.textContent = run.failure_count;
        if (summaryNote) {
            if (run.notes) {
                summaryNote.textContent = run.notes;
                summaryNote.style.display = '';
            } else {
                summaryNote.textContent = '';
                summaryNote.style.display = 'none';
            }
        }
    }

    function renderResults(results, run) {
        if (!resultsContainer) return;
        if (!Array.isArray(results) || results.length === 0) {
            resultsContainer.innerHTML = '<div class="text-muted small">' + escapeHtml(labels.modalEmpty) + '</div>';
            return;
        }
        const items = results.map(function(item){
            const badge = buildBadge(item.status);
            const classes = ['network-check-item'];
            if (item.status === 'running') classes.push('running');
            if (item.status === 'cancelled') classes.push('cancelled');
            const parts = [];
            if (item.published_url && item.status === 'success') {
                parts.push('<a class="network-check-link" href="' + escapeHtml(item.published_url) + '" target="_blank" rel="noopener">' + escapeHtml(labels.open) + '</a>');
            }
            const times = [];
            if (item.started_at_iso) { times.push(labels.startedAt + ': ' + escapeHtml(formatDate(item.started_at_iso))); }
            if (item.finished_at_iso) { times.push(labels.finishedAt + ': ' + escapeHtml(formatDate(item.finished_at_iso))); }
            if (times.length) {
                parts.push('<div class="network-check-times">' + times.join(' · ') + '</div>');
            }
            if (item.status === 'failed' && item.error) {
                parts.push('<div class="network-check-error">' + escapeHtml(item.error) + '</div>');
            }
            if (item.status === 'cancelled') {
                parts.push('<div class="network-check-error">' + escapeHtml(labels.cancelled) + '</div>');
            }
            return '<div class="' + classes.join(' ') + '">' +
                '<div class="network-check-item-header">' +
                    '<div class="network-check-item-title">' + escapeHtml(item.network_title || item.network_slug) + '</div>' +
                    '<span class="network-check-badge ' + badge.cls + '">' + escapeHtml(badge.label) + '</span>' +
                '</div>' +
                (parts.length ? '<div class="network-check-item-body">' + parts.join('') + '</div>' : '') +
            '</div>';
        }).join('');
        resultsContainer.innerHTML = items;
    }

    function updateModal(data) {
        if (!modalOpen) return;
        const run = data ? data.run : null;
        updateProgress(run);
        if (!run) {
            if (metaBox) metaBox.textContent = labels.noData;
            if (currentBox) currentBox.textContent = '';
            renderResults([], run);
            if (noteBox) noteBox.textContent = '';
            return;
        }
        if (metaBox) {
            const metaParts = [];
            if (run.run_mode) {
                metaParts.push(labels.mode + ': ' + (run.run_mode === 'single' ? labels.singleMode : labels.bulkMode));
            }
            metaParts.push(labels.total + ': ' + run.total_networks);
            metaParts.push(labels.success + ': ' + run.success_count);
            metaParts.push(labels.failed + ': ' + run.failure_count);
            metaParts.push(labels.startedAt + ': ' + formatDate(run.started_at_iso));
            if (run.finished_at_iso) {
                metaParts.push(labels.finishedAt + ': ' + formatDate(run.finished_at_iso));
            }
            metaBox.textContent = metaParts.join(' · ');
        }
        if (noteBox) {
            if (run.notes) {
                noteBox.textContent = run.notes;
                noteBox.style.display = '';
            } else {
                noteBox.textContent = '';
                noteBox.style.display = 'none';
            }
        }
        if (currentBox) {
            const runningItem = Array.isArray(data.results) ? data.results.find(r => r.status === 'running') : null;
            if (runningItem) {
                currentBox.textContent = labels.current + ': ' + (runningItem.network_title || runningItem.network_slug);
                currentBox.style.display = '';
            } else {
                currentBox.textContent = '';
                currentBox.style.display = 'none';
            }
        }
        renderResults(Array.isArray(data.results) ? data.results : [], run);
    }

    function updateUI(data) {
        latestData = data;
        const run = data ? data.run : null;
        updateSummary(run);
        updateModal(data || null);
        setControlsDisabled(run ? run.in_progress : false);
        updateStopButton(run);
        updateApplySuccessButton(data || null);
    }

    function scheduleNext(intervalMs) {
        clearTimeout(pollTimer);
        if (!currentRunId) return;
        pollTimer = window.setTimeout(function(){ fetchRunStatus(currentRunId); }, intervalMs);
    }

    async function requestStatus(runId) {
        let url = apiStatus;
        if (runId) {
            url += '&run_id=' + encodeURIComponent(runId);
        }
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(errorMessages.DEFAULT);
        }
        const data = await response.json().catch(() => null);
        if (!data || !data.ok) {
            const errCode = data && data.error ? data.error : 'DEFAULT';
            const msg = errorMessages[errCode] || errorMessages.DEFAULT;
            throw new Error(msg);
        }
        return data;
    }

    async function fetchRunStatus(runId) {
        if (!runId) {
            await fetchLatestStatus();
            return;
        }
        try {
            const data = await requestStatus(runId);
            updateUI(data);
            if (data.run && data.run.in_progress) {
                const delay = data.run.completed_count === 0 ? 1500 : 3000;
                scheduleNext(delay);
            } else {
                clearTimeout(pollTimer);
            }
        } catch (err) {
            setMessage('danger', err.message || errorMessages.DEFAULT);
            clearTimeout(pollTimer);
        }
    }

    async function fetchLatestStatus() {
        try {
            const data = await requestStatus(null);
            updateUI(data);
            if (data.run) {
                if (!currentRunId) {
                    currentRunId = data.run.id;
                }
                if (data.run.in_progress) {
                    currentRunId = data.run.id;
                    const delay = data.run.completed_count === 0 ? 1500 : 3000;
                    scheduleNext(delay);
                }
            }
        } catch (err) {
            // ignore background errors for latest summary
        }
    }

    async function cancelRun(force = false) {
        clearMessage();
        if (!currentRunId && latestData && latestData.run) {
            currentRunId = latestData.run.id;
        }
        if (!currentRunId) {
            setMessage('warning', errorMessages.RUN_NOT_FOUND || errorMessages.DEFAULT);
            return;
        }
        if (!stopBtn) {
            setMessage('danger', errorMessages.DEFAULT);
            return;
        }
        const previousHtml = stopBtn.innerHTML;
        stopBtn.disabled = true;
        stopBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + escapeHtml(labels.canceling || stopBtnWaitLabel || '');
        try {
            const body = new URLSearchParams();
            body.set('csrf_token', window.CSRF_TOKEN || '');
            body.set('run_id', String(currentRunId));
            if (force) {
                body.set('force', '1');
            }
            const response = await fetch(apiCancel, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({ ok: false, error: 'DEFAULT' }));
            if (!data.ok) {
                const msg = errorMessages[data.error || 'DEFAULT'] || errorMessages.DEFAULT;
                throw new Error(msg);
            }
            setMessage('info', labels.cancelRequested);
            if (data.finished || data.status === 'cancelled') {
                setMessage('success', labels.cancelSuccess);
            }
            if (data.runId) {
                currentRunId = data.runId;
            }
            await fetchRunStatus(currentRunId);
        } catch (err) {
            stopBtn.innerHTML = previousHtml;
            stopBtn.disabled = false;
            setMessage('danger', err.message || errorMessages.DEFAULT);
            updateStopButton(latestData && latestData.run ? latestData.run : null);
            return;
        }
        updateStopButton(latestData && latestData.run ? latestData.run : null);
    }

    function openModal() {
        if (!modal) return;
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        modalOpen = true;
        if (latestData) {
            updateModal(latestData);
        }
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modalOpen = false;
    }

    if (modal) {
        modal.addEventListener('click', function(e){
            if (e.target === modal || (e.target && e.target.closest('[data-pp-close]'))) {
                closeModal();
            }
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                closeModal();
            }
        });
    }

    async function startCheck(slug = '', triggerBtn = null) {
        clearMessage();
        const button = triggerBtn || startBtn;
        const prevHtml = button ? button.innerHTML : '';
        let runStarted = false;
        if (button) {
            button.disabled = true;
            const label = button.dataset && button.dataset.label ? button.dataset.label : button.textContent;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + escapeHtml(label || '');
        }
        setControlsDisabled(true);
        try {
            const body = new URLSearchParams();
            body.set('csrf_token', window.CSRF_TOKEN || '');
            if (slug) {
                body.set('slug', slug);
            }
            const response = await fetch(apiStart, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({ ok: false, error: 'DEFAULT' }));
            if (!data.ok) {
                const msg = errorMessages[data.error || 'DEFAULT'] || errorMessages.DEFAULT;
                throw new Error(msg);
            }
            currentRunId = data.runId || null;
            runStarted = true;
            if (data.alreadyRunning) {
                setMessage('info', labels.alreadyRunning);
            } else if (slug) {
                const networkName = (triggerBtn && triggerBtn.dataset && triggerBtn.dataset.networkTitle) ? triggerBtn.dataset.networkTitle : slug;
                setMessage('info', (labels.singleStarted || '').replace('%s', networkName));
            } else {
                setMessage('info', labels.bulkStarted);
            }
            openModal();
            await fetchRunStatus(currentRunId);
        } catch (err) {
            setMessage('danger', err.message || errorMessages.DEFAULT);
            setControlsDisabled(false);
        } finally {
            if (button) {
                button.innerHTML = prevHtml;
                if (!runStarted) {
                    if (button === startBtn) {
                        startBtn.disabled = false;
                    } else if (!singleButtonInitialDisabled.get(button)) {
                        button.disabled = false;
                    }
                }
            }
            if (!runStarted) {
                setControlsDisabled(false);
            }
        }
    }

    function showHistory() {
        if (!latestData || !latestData.run) {
            setMessage('warning', labels.noData);
            return;
        }
        clearMessage();
        currentRunId = latestData.run.id;
        openModal();
        fetchRunStatus(currentRunId);
    }

    if (startBtn) {
        startBtn.addEventListener('click', function(){ startCheck('', startBtn); });
    }
    singleButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
            const slug = btn.dataset ? (btn.dataset.networkSlug || '') : '';
            if (!slug) {
                setMessage('danger', errorMessages.MISSING_SLUG || errorMessages.DEFAULT);
                return;
            }
            startCheck(slug, btn);
        });
    });
    if (historyBtn) {
        historyBtn.addEventListener('click', showHistory);
    }
    if (stopBtn) {
        stopBtn.addEventListener('click', function(e){
            const force = !!(e && (e.shiftKey || e.altKey));
            cancelRun(force);
        });
    }

    if (applySuccessBtn) {
        applySuccessBtn.addEventListener('click', function(){
            applySuccessfulNetworks();
        });
    }

    const networksTable = document.getElementById('networksTable');
    const filtersBar = document.getElementById('networkFiltersBar');
    if (networksTable && filtersBar) {
        const filterStatus = document.getElementById('filterStatus');
        const filterActive = document.getElementById('filterActive');
        const filterRegion = document.getElementById('filterRegion');
        const filterTopic = document.getElementById('filterTopic');
        const resetFiltersBtn = document.getElementById('resetNetworkFilters');
        const activateVerifiedBtn = document.getElementById('activateVerifiedBtn');
        const filtersInfo = document.getElementById('networkFiltersInfo');
        const rows = Array.from(networksTable.querySelectorAll('tbody tr'));

        const normalizeValue = (val) => (val || '').toString().trim().toLowerCase();

        const applyNetworkFilters = () => {
            const statusVal = normalizeValue(filterStatus ? filterStatus.value : 'all');
            const activeVal = normalizeValue(filterActive ? filterActive.value : 'all');
            const regionVal = normalizeValue(filterRegion ? filterRegion.value : 'all');
            const topicVal = normalizeValue(filterTopic ? filterTopic.value : 'all');
            let visibleCount = 0;

            rows.forEach((row) => {
                let visible = true;
                const rowStatus = normalizeValue(row.dataset.status || '');
                const rowActive = row.dataset.active || '0';
                const rowMissing = row.dataset.missing || '0';
                const rowRegions = (row.dataset.regions || '').split('|').filter(Boolean);
                const rowTopics = (row.dataset.topics || '').split('|').filter(Boolean);

                if (statusVal && statusVal !== 'all') {
                    if (statusVal === 'success' && rowStatus !== 'success') {
                        visible = false;
                    } else if (statusVal === 'failed' && rowStatus !== 'failed') {
                        visible = false;
                    } else if (statusVal === 'progress' && !(rowStatus === 'running' || rowStatus === 'queued')) {
                        visible = false;
                    } else if (statusVal === 'cancelled' && rowStatus !== 'cancelled') {
                        visible = false;
                    } else if (statusVal === 'none' && rowStatus !== '' && rowStatus !== 'none') {
                        visible = false;
                    }
                }

                if (visible && activeVal && activeVal !== 'all') {
                    if (activeVal === 'active' && rowActive !== '1') {
                        visible = false;
                    } else if (activeVal === 'inactive' && !(rowActive === '0' && rowMissing === '0')) {
                        visible = false;
                    } else if (activeVal === 'missing' && rowMissing !== '1') {
                        visible = false;
                    }
                }

                if (visible && regionVal && regionVal !== 'all') {
                    if (!rowRegions.includes(regionVal)) {
                        visible = false;
                    }
                }

                if (visible && topicVal && topicVal !== 'all') {
                    if (!rowTopics.includes(topicVal)) {
                        visible = false;
                    }
                }

                row.style.display = visible ? '' : 'none';
                if (visible) {
                    visibleCount++;
                }
            });

            if (filtersInfo) {
                filtersInfo.textContent = visibleCount === rows.length
                    ? ''
                    : filtersInfo.dataset && filtersInfo.dataset.labelVisible
                        ? filtersInfo.dataset.labelVisible.replace('%d', visibleCount)
                        : '';
            }
        };

        ['change', 'input'].forEach((evt) => {
            if (filterStatus) filterStatus.addEventListener(evt, applyNetworkFilters);
            if (filterActive) filterActive.addEventListener(evt, applyNetworkFilters);
            if (filterRegion) filterRegion.addEventListener(evt, applyNetworkFilters);
            if (filterTopic) filterTopic.addEventListener(evt, applyNetworkFilters);
        });

        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', () => {
                if (filterStatus) filterStatus.value = 'all';
                if (filterActive) filterActive.value = 'all';
                if (filterRegion) filterRegion.value = 'all';
                if (filterTopic) filterTopic.value = 'all';
                if (filtersInfo) {
                    filtersInfo.textContent = '';
                }
                applyNetworkFilters();
            });
        }

        if (activateVerifiedBtn) {
            activateVerifiedBtn.addEventListener('click', () => {
                let activated = 0;
                rows.forEach((row) => {
                    const checkbox = row.querySelector('input[type="checkbox"]');
                    if (!checkbox || checkbox.disabled) {
                        return;
                    }
                    const isSuccess = normalizeValue(row.dataset.status || '') === 'success';
                    checkbox.checked = isSuccess;
                    if (isSuccess) {
                        activated++;
                    }
                });
                if (filtersInfo) {
                    const template = activateVerifiedBtn.getAttribute('data-message-template') || '';
                    if (template) {
                        filtersInfo.textContent = template.replace('%d', activated);
                    } else {
                        filtersInfo.textContent = activated ? String(activated) : '';
                    }
                }
            });
        }

        applyNetworkFilters();

        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                try { new bootstrap.Tooltip(tooltipTriggerEl); } catch (err) {}
            });
        }
    } else if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            try { new bootstrap.Tooltip(tooltipTriggerEl); } catch (err) {}
        });
    }

    fetchLatestStatus();
});
</script>

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
