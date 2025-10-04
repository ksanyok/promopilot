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
$crowdMsg = '';
$crowdImportSummary = null;
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
    'captcha_provider',         // none | 2captcha | anti-captcha | capsolver
    'captcha_api_key',
    'captcha_fallback_provider', // none | 2captcha | anti-captcha | capsolver
    'captcha_fallback_api_key',
    // Promotion pricing and levels
    'promotion_price_per_link',
    'promotion_level1_count',
    'promotion_level2_per_level1',
    'promotion_level3_per_level2',
    'promotion_crowd_per_article',
    'promotion_level1_enabled',
    'promotion_level2_enabled',
    'promotion_level3_enabled',
    'promotion_crowd_enabled',
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

            $priceRaw = str_replace(',', '.', (string)($_POST['promotion_price_per_link'] ?? '0'));
            $promotionPrice = max(0, round((float)$priceRaw, 2));
            $level1Count = max(1, min(500, (int)($_POST['promotion_level1_count'] ?? 5)));
            $level2PerLevel1 = max(1, min(500, (int)($_POST['promotion_level2_per_level1'] ?? 10)));
            $level3PerLevel2 = max(1, min(500, (int)($_POST['promotion_level3_per_level2'] ?? 5)));
            $crowdPerArticle = max(0, min(5000, (int)($_POST['promotion_crowd_per_article'] ?? 100)));
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
                ['captcha_provider', in_array(($_POST['captcha_provider'] ?? 'none'), ['none','2captcha','anti-captcha','capsolver'], true) ? $_POST['captcha_provider'] : 'none'],
                ['captcha_api_key', trim((string)($_POST['captcha_api_key'] ?? ''))],
                ['captcha_fallback_provider', in_array(($_POST['captcha_fallback_provider'] ?? 'none'), ['none','2captcha','anti-captcha','capsolver'], true) ? $_POST['captcha_fallback_provider'] : 'none'],
                ['captcha_fallback_api_key', trim((string)($_POST['captcha_fallback_api_key'] ?? ''))],
                // Promotion
                ['promotion_price_per_link', number_format($promotionPrice, 2, '.', '')],
                ['promotion_level1_count', (string)$level1Count],
                ['promotion_level2_per_level1', (string)$level2PerLevel1],
                ['promotion_level3_per_level2', (string)$level3PerLevel2],
                ['promotion_crowd_per_article', (string)$crowdPerArticle],
                ['promotion_level1_enabled', isset($_POST['promotion_level1_enabled']) ? '1' : '0'],
                ['promotion_level2_enabled', isset($_POST['promotion_level2_enabled']) ? '1' : '0'],
                ['promotion_level3_enabled', isset($_POST['promotion_level3_enabled']) ? '1' : '0'],
                ['promotion_crowd_enabled', isset($_POST['promotion_crowd_enabled']) ? '1' : '0'],
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
    } elseif (isset($_POST['delete_network'])) {
        if (!verify_csrf()) {
            $networksMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $slugRaw = (string)($_POST['delete_network'] ?? '');
            $slugNorm = pp_normalize_slug($slugRaw);
            if ($slugNorm === '') {
                $networksMsg = __('Не удалось удалить сеть.');
            } elseif (pp_delete_network($slugNorm)) {
                $networksMsg = __('Сеть удалена из списка.');
            } else {
                $networksMsg = __('Не удалось удалить сеть.');
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
            $priorityInput = $_POST['priority'] ?? [];
            $priorityMap = [];
            if (is_array($priorityInput)) {
                foreach ($priorityInput as $slug => $value) {
                    $slugNorm = pp_normalize_slug((string)$slug);
                    if ($slugNorm === '') { continue; }
                    $priorityMap[$slugNorm] = (int)$value;
                }
            }
            $levelInput = $_POST['level'] ?? [];
            $levelMap = [];
            if (is_array($levelInput)) {
                foreach ($levelInput as $slug => $value) {
                    $slugNorm = pp_normalize_slug((string)$slug);
                    if ($slugNorm === '') { continue; }
                    $levelMap[$slugNorm] = $value;
                }
            }
            $defaultPriority = isset($_POST['default_priority']) ? (int)$_POST['default_priority'] : $networkDefaultPriority;
            if ($defaultPriority < 0) { $defaultPriority = 0; }
            if ($defaultPriority > 999) { $defaultPriority = 999; }
            $defaultLevelsRaw = $_POST['default_levels'] ?? [];
            if (!is_array($defaultLevelsRaw)) { $defaultLevelsRaw = [$defaultLevelsRaw]; }
            $defaultLevelsValue = pp_normalize_network_levels($defaultLevelsRaw);
            $nodeBinaryNew = trim((string)($_POST['node_binary'] ?? ''));
            $puppeteerExecNew = trim((string)($_POST['puppeteer_executable_path'] ?? ''));
            $puppeteerArgsNew = trim((string)($_POST['puppeteer_args'] ?? ''));
            set_settings([
                'node_binary' => $nodeBinaryNew,
                'puppeteer_executable_path' => $puppeteerExecNew,
                'puppeteer_args' => $puppeteerArgsNew,
                'network_default_priority' => $defaultPriority,
                'network_default_levels' => $defaultLevelsValue,
            ]);
            $networkDefaultPriority = $defaultPriority;
            $networkDefaultLevels = $defaultLevelsValue;
            $networkDefaultLevelsList = $networkDefaultLevels !== '' ? explode(',', $networkDefaultLevels) : [];
            if (pp_set_networks_enabled($enabledSlugs, $priorityMap, $levelMap)) {
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
    } elseif (isset($_POST['crowd_import'])) {
        if (!verify_csrf()) {
            $crowdMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            try {
                $crowdImportSummary = pp_crowd_links_import_files($_FILES['crowd_files'] ?? []);
                if (!empty($crowdImportSummary['ok'])) {
                    $crowdMsg = sprintf(
                        __('Импорт завершён. Добавлено: %1$d, дубликатов: %2$d, пропущено: %3$d.'),
                        (int)($crowdImportSummary['imported'] ?? 0),
                        (int)($crowdImportSummary['duplicates'] ?? 0),
                        (int)($crowdImportSummary['invalid'] ?? 0)
                    );
                } else {
                    $errors = (array)($crowdImportSummary['errors'] ?? []);
                    $crowdMsg = !empty($errors) ? implode(' ', $errors) : __('Не удалось выполнить импорт ссылок.');
                }
            } catch (Throwable $e) {
                $crowdMsg = __('Не удалось выполнить импорт ссылок.');
            }
        }
    } elseif (isset($_POST['crowd_clear_all'])) {
        if (!verify_csrf()) {
            $crowdMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $deleted = pp_crowd_links_delete_all();
            $crowdMsg = $deleted > 0
                ? sprintf(__('Удалено %d ссылок.'), $deleted)
                : __('Ссылки не были удалены.');
        }
    } elseif (isset($_POST['crowd_delete_errors'])) {
        if (!verify_csrf()) {
            $crowdMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $deleted = pp_crowd_links_delete_errors();
            $crowdMsg = $deleted > 0
                ? sprintf(__('Удалено ссылок с ошибками: %d.'), $deleted)
                : __('Не найдено ссылок с ошибками.');
        }
    } elseif (isset($_POST['crowd_delete_selected'])) {
        if (!verify_csrf()) {
            $crowdMsg = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $selected = $_POST['crowd_selected'] ?? [];
            if (!is_array($selected)) {
                $selected = [$selected];
            }
            $deleted = pp_crowd_links_delete_selected($selected);
            $crowdMsg = $deleted > 0
                ? sprintf(__('Удалено выбранных ссылок: %d.'), $deleted)
                : __('Не удалось удалить выбранные ссылки.');
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
$settings = [
    'currency' => 'RUB',
    'openai_api_key' => '',
    'telegram_token' => '',
    'telegram_channel' => '',
    'promotion_price_per_link' => '0.00',
    'promotion_level1_count' => '5',
    'promotion_level2_per_level1' => '10',
    'promotion_level3_per_level2' => '5',
    'promotion_crowd_per_article' => '100',
    'promotion_level1_enabled' => '1',
    'promotion_level2_enabled' => '1',
    'promotion_level3_enabled' => '0',
    'promotion_crowd_enabled' => '1',
];
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
    'captcha_fallback_provider' => $settings['captcha_fallback_provider'] ?? 'none',
    'captcha_fallback_api_key' => $settings['captcha_fallback_api_key'] ?? '',
];
$in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $settingsKeys)) . "'";
$res = $conn->query("SELECT k, v FROM settings WHERE k IN ($in)");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['k']] = (string)$row['v'];
    }
}

$promoDefaults = pp_promotion_settings();
$settings['promotion_price_per_link'] = $settings['promotion_price_per_link'] ?? number_format((float)($promoDefaults['price_per_link'] ?? 0), 2, '.', '');
$settings['promotion_level1_enabled'] = $settings['promotion_level1_enabled'] ?? ($promoDefaults['level1_enabled'] ? '1' : '0');
$settings['promotion_level2_enabled'] = $settings['promotion_level2_enabled'] ?? ($promoDefaults['level2_enabled'] ? '1' : '0');
$settings['promotion_level3_enabled'] = $settings['promotion_level3_enabled'] ?? ($promoDefaults['level3_enabled'] ? '1' : '0');
$settings['promotion_crowd_enabled'] = $settings['promotion_crowd_enabled'] ?? ($promoDefaults['crowd_enabled'] ? '1' : '0');
$settings['promotion_level1_count'] = (string)max(1, (int)($settings['promotion_level1_count'] ?? ($promoDefaults['level1_count'] ?? 5)));
$settings['promotion_level2_per_level1'] = (string)max(1, (int)($settings['promotion_level2_per_level1'] ?? ($promoDefaults['level2_per_level1'] ?? 10)));
$settings['promotion_level3_per_level2'] = (string)max(1, (int)($settings['promotion_level3_per_level2'] ?? ($promoDefaults['level3_per_level2'] ?? 5)));
$settings['promotion_crowd_per_article'] = (string)max(0, (int)($settings['promotion_crowd_per_article'] ?? ($promoDefaults['crowd_per_article'] ?? 0)));


// Получить пользователей
$users = $conn->query("SELECT u.id, u.username, u.role, u.email, u.balance, u.promotion_discount, u.created_at, COUNT(p.id) AS projects_count FROM users u LEFT JOIN projects p ON p.user_id = u.id GROUP BY u.id ORDER BY u.id");

// Получить проекты
$projects = $conn->query("SELECT p.id, p.name, p.description, p.links, p.created_at, u.username, COUNT(pb.id) AS published_count FROM projects p JOIN users u ON p.user_id = u.id LEFT JOIN publications pb ON pb.project_id = p.id GROUP BY p.id ORDER BY p.id");

$conn->close();

$updateStatus = get_update_status();

$pp_admin_sidebar_tools = [
    [
        'label' => __('Сканер локализации'),
        'href' => pp_url('public/scan.php'),
        'icon' => 'bi-search',
    ],
];
if ($updateStatus['is_new']) {
    $pp_admin_sidebar_tools[] = [
        'label' => sprintf('%s (%s)', __('Обновление'), $updateStatus['latest']),
        'href' => pp_url('public/update.php'),
        'icon' => 'bi-arrow-repeat',
    ];
}

pp_refresh_networks(false);
$networks = pp_get_networks(false, true);
$nodeBinaryStored = trim((string)get_setting('node_binary', ''));
$puppeteerExecStored = trim((string)get_setting('puppeteer_executable_path', ''));
$puppeteerArgsStored = trim((string)get_setting('puppeteer_args', ''));
$networkDefaultPriority = (int)get_setting('network_default_priority', 10);
if ($networkDefaultPriority < 0) { $networkDefaultPriority = 0; }
if ($networkDefaultPriority > 999) { $networkDefaultPriority = 999; }
$networkDefaultLevelsRaw = (string)get_setting('network_default_levels', '');
$networkDefaultLevels = pp_normalize_network_levels($networkDefaultLevelsRaw);
$networkDefaultLevelsList = $networkDefaultLevels !== '' ? explode(',', $networkDefaultLevels) : [];
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

$crowdFilters = [
    'page' => max(1, (int)($_GET['crowd_page'] ?? 1)),
    'per_page' => (int)($_GET['crowd_per_page'] ?? 25),
    'group' => (string)($_GET['crowd_group'] ?? 'links'),
    'status' => (string)($_GET['crowd_status'] ?? ''),
    'domain' => trim((string)($_GET['crowd_domain'] ?? '')),
    'language' => trim((string)($_GET['crowd_language'] ?? '')),
    'region' => trim((string)($_GET['crowd_region'] ?? '')),
    'search' => trim((string)($_GET['crowd_search'] ?? '')),
    'order' => (string)($_GET['crowd_order'] ?? 'recent'),
];
if ($crowdFilters['per_page'] < 10) { $crowdFilters['per_page'] = 10; }
if ($crowdFilters['per_page'] > 200) { $crowdFilters['per_page'] = 200; }
$crowdFilters['group'] = in_array($crowdFilters['group'], ['links','domains'], true) ? $crowdFilters['group'] : 'links';
$crowdFilters['status'] = trim($crowdFilters['status']);
$crowdFilters['order'] = in_array($crowdFilters['order'], ['recent','oldest','status','domain','checked'], true) ? $crowdFilters['order'] : 'recent';

$crowdList = pp_crowd_links_list($crowdFilters);
$crowdStats = pp_crowd_links_get_stats();
$crowdStatusMeta = pp_crowd_links_status_meta();
$crowdScopeOptions = pp_crowd_links_scope_options();
$crowdStatusData = pp_crowd_links_get_status();
$crowdCurrentRun = ($crowdStatusData['ok'] ?? false) ? ($crowdStatusData['run'] ?? null) : null;
$crowdStatusError = ($crowdStatusData['ok'] ?? false) ? null : ($crowdStatusData['error'] ?? null);
$crowdSelectedIds = isset($_POST['crowd_selected']) && is_array($_POST['crowd_selected']) ? array_values($_POST['crowd_selected']) : [];

$crowdDeepStatusMeta = pp_crowd_deep_status_meta();
$crowdDeepScopeOptions = pp_crowd_deep_scope_options();
$crowdDeepDefaults = pp_crowd_deep_default_options();
$crowdDeepStatusData = pp_crowd_deep_get_status();
$crowdDeepCurrentRun = ($crowdDeepStatusData['ok'] ?? false) ? ($crowdDeepStatusData['run'] ?? null) : null;
$crowdDeepStatusError = ($crowdDeepStatusData['ok'] ?? false) ? null : ($crowdDeepStatusData['error'] ?? null);
$crowdDeepRecentResults = pp_crowd_deep_get_recent_results($crowdDeepCurrentRun['id'] ?? null, 15);

$pp_admin_sidebar_active = 'users';
$pp_admin_sidebar_section_mode = true;
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
include '../includes/header.php';
include __DIR__ . '/../includes/admin_sidebar.php';
?>

<div class="main-content fade-in">
<h2><?php echo __('Админка PromoPilot'); ?></h2>
<?php if ($updateStatus['is_new']): ?>
<div class="alert alert-warning fade-in">
    <strong><?php echo __('Доступно обновление'); ?>:</strong> <?php echo htmlspecialchars($updateStatus['latest']); ?> (опубликовано <?php echo htmlspecialchars($updateStatus['published_at']); ?>).
    <a href="<?php echo pp_url('public/update.php'); ?>" class="alert-link"><?php echo __('Перейти к обновлению'); ?></a>.
</div>
<?php endif; ?>

<?php include __DIR__ . '/partials/users_section.php'; ?>

<?php include __DIR__ . '/partials/projects_section.php'; ?>

<?php include __DIR__ . '/partials/settings_section.php'; ?>

<?php include __DIR__ . '/partials/crowd_links_section.php'; ?>

<?php include __DIR__ . '/partials/networks_section.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    let checkSelectedBtnRef = null;
    let activateSelectedBtnRef = null;
    let deactivateSelectedBtnRef = null;
    let clearSelectedBtnRef = null;
    let updateSelectionActionsState = function(){};
    let checkSelectedDisabledByRun = false;
    let setNetworkFiltersMessage = function(message) { void message; };
    let queueNetworkSaveForRow = function(row, options) { void row; void options; };
    let triggerNetworkSaveFlush = function(force) { void force; };
    let triggerNetworkFiltersRefresh = function(){};

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
    applySuccessHint: <?php echo json_encode(__('Активированы только успешные сети. Изменения сохранятся автоматически.'), JSON_UNESCAPED_UNICODE); ?>,
        bulkStarted: <?php echo json_encode(__('Запущена комплексная проверка всех активных сетей.'), JSON_UNESCAPED_UNICODE); ?>,
        singleStarted: <?php echo json_encode(__('Проверка запущена для сети: %s'), JSON_UNESCAPED_UNICODE); ?>,
        canceling: <?php echo json_encode(__('Останавливаем проверку...'), JSON_UNESCAPED_UNICODE); ?>,
        cancelRequested: <?php echo json_encode(__('Остановка запрошена. Подождите завершения.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelSuccess: <?php echo json_encode(__('Проверка остановлена.'), JSON_UNESCAPED_UNICODE); ?>,
        selectionMode: <?php echo json_encode(__('Выборочная проверка'), JSON_UNESCAPED_UNICODE); ?>,
        selectionStarted: <?php echo json_encode(__('Запущена проверка выбранных сетей (%d).'), JSON_UNESCAPED_UNICODE); ?>,
        selectionEmpty: <?php echo json_encode(__('Отметьте хотя бы одну сеть.'), JSON_UNESCAPED_UNICODE); ?>,
        selectionCleared: <?php echo json_encode(__('Выбор очищен.'), JSON_UNESCAPED_UNICODE); ?>,
        selectedCount: <?php echo json_encode(__('Выбрано сетей: %d'), JSON_UNESCAPED_UNICODE); ?>,
        noteSaving: <?php echo json_encode(__('Сохраняем...'), JSON_UNESCAPED_UNICODE); ?>,
        noteSaved: <?php echo json_encode(__('Заметка сохранена.'), JSON_UNESCAPED_UNICODE); ?>,
        noteCleared: <?php echo json_encode(__('Заметка очищена.'), JSON_UNESCAPED_UNICODE); ?>,
        notePending: <?php echo json_encode(__('Изменения не сохранены'), JSON_UNESCAPED_UNICODE); ?>,
        noteError: <?php echo json_encode(__('Не удалось сохранить заметку.'), JSON_UNESCAPED_UNICODE); ?>,
        noteErrorCsrf: <?php echo json_encode(__('Сессия устарела. Обновите страницу.'), JSON_UNESCAPED_UNICODE); ?>,
        noteErrorNotFound: <?php echo json_encode(__('Сеть не найдена или недоступна.'), JSON_UNESCAPED_UNICODE); ?>,
        noteErrorGeneric: <?php echo json_encode(__('Произошла ошибка при сохранении заметки.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsSaving: <?php echo json_encode(__('Сохраняем изменения...'), JSON_UNESCAPED_UNICODE); ?>,
        settingsSavedSingle: <?php echo json_encode(__('Сеть «%s» сохранена.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsSavedMany: <?php echo json_encode(__('Сохранено сетей: %d.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsSaved: <?php echo json_encode(__('Изменения сетей сохранены.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsError: <?php echo json_encode(__('Не удалось сохранить изменения сетей.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsErrorCsrf: <?php echo json_encode(__('Сессия устарела. Обновите страницу.'), JSON_UNESCAPED_UNICODE); ?>,
        settingsErrorNotFound: <?php echo json_encode(__('Сеть недоступна для сохранения.'), JSON_UNESCAPED_UNICODE); ?>
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
    const apiNote = <?php echo json_encode(pp_url('admin/network_note.php'), JSON_UNESCAPED_UNICODE); ?>;
    const apiNetworkSettings = <?php echo json_encode(pp_url('admin/network_settings.php'), JSON_UNESCAPED_UNICODE); ?>;

    let pollTimer = 0;
    let currentRunId = null;
    let modalOpen = false;
    let latestData = null;

    function initNetworkNotes(container) {
        const root = container || document;
        const noteInputs = Array.from(root.querySelectorAll('.network-note-input'));
        if (!noteInputs.length) { return; }

        const getLabel = (key, fallback = '') => {
            if (labels && Object.prototype.hasOwnProperty.call(labels, key)) {
                return labels[key];
            }
            if (fallback) { return fallback; }
            if (errorMessages && Object.prototype.hasOwnProperty.call(errorMessages, key)) {
                return errorMessages[key];
            }
            return fallback;
        };

        noteInputs.forEach((textarea) => {
            if (!(textarea instanceof HTMLTextAreaElement)) { return; }
            const slug = (textarea.dataset && textarea.dataset.slug) ? textarea.dataset.slug.trim() : '';
            if (!slug) { return; }
            const statusEl = textarea.closest('.network-note-cell')
                ? textarea.closest('.network-note-cell').querySelector('[data-note-status]')
                : null;
            let debounceTimer = 0;
            let hideTimer = 0;
            let isSaving = false;
            let pendingValue = null;
            let lastSavedValue = (textarea.dataset && typeof textarea.dataset.initialValue !== 'undefined')
                ? String(textarea.dataset.initialValue)
                : String(textarea.value || '');

            const showStatus = (type, message) => {
                if (!statusEl) { return; }
                if (hideTimer) {
                    window.clearTimeout(hideTimer);
                    hideTimer = 0;
                }
                const msg = (message || '').trim();
                statusEl.classList.remove('d-none', 'text-success', 'text-danger', 'text-muted');
                if (!msg) {
                    statusEl.classList.add('d-none');
                    statusEl.classList.add('text-muted');
                    statusEl.textContent = '';
                    return;
                }
                let cls = 'text-muted';
                if (type === 'success') {
                    cls = 'text-success';
                } else if (type === 'error') {
                    cls = 'text-danger';
                }
                statusEl.classList.add(cls);
                statusEl.textContent = msg;
                if (type === 'success') {
                    hideTimer = window.setTimeout(() => {
                        statusEl.classList.add('d-none');
                        statusEl.classList.remove('text-success', 'text-danger');
                        statusEl.classList.add('text-muted');
                        statusEl.textContent = '';
                        hideTimer = 0;
                    }, 2500);
                }
            };

            const triggerSave = () => {
                if (isSaving) {
                    if (!debounceTimer) {
                        debounceTimer = window.setTimeout(() => {
                            debounceTimer = 0;
                            triggerSave();
                        }, 400);
                    }
                    return;
                }
                const valueToSave = pendingValue;
                if (valueToSave === null || typeof valueToSave === 'undefined') {
                    return;
                }
                if (valueToSave === lastSavedValue) {
                    showStatus('idle', '');
                    pendingValue = null;
                    return;
                }
                pendingValue = null;
                void saveValue(valueToSave);
            };

            const scheduleSave = (immediate = false) => {
                if (debounceTimer) {
                    window.clearTimeout(debounceTimer);
                    debounceTimer = 0;
                }
                pendingValue = String(textarea.value || '');
                if (pendingValue === lastSavedValue) {
                    showStatus('idle', '');
                    return;
                }
                showStatus('pending', getLabel('notePending', ''));
                const delay = immediate ? 0 : 800;
                debounceTimer = window.setTimeout(() => {
                    debounceTimer = 0;
                    triggerSave();
                }, delay);
            };

            async function saveValue(rawValue) {
                isSaving = true;
                showStatus('saving', getLabel('noteSaving', ''));
                const body = new URLSearchParams();
                body.set('csrf_token', window.CSRF_TOKEN || '');
                body.set('slug', slug);
                body.set('note', rawValue);
                try {
                    const response = await fetch(apiNote, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                        credentials: 'same-origin'
                    });
                    const data = await response.json().catch(() => null);
                    if (!response.ok || !data || !data.ok) {
                        const errCode = data && data.error ? data.error : 'DEFAULT';
                        let message = getLabel('noteErrorGeneric', getLabel('noteError', errorMessages.DEFAULT || ''));
                        if (errCode === 'CSRF') {
                            message = getLabel('noteErrorCsrf', message);
                        } else if (errCode === 'NETWORK_NOT_FOUND' || errCode === 'INVALID_SLUG') {
                            message = getLabel('noteErrorNotFound', message);
                        } else if (errCode === 'SAVE_FAILED') {
                            message = getLabel('noteError', message);
                        }
                        throw new Error(message || (errorMessages.DEFAULT || 'Error'));
                    }
                    const savedNote = typeof data.note === 'string' ? data.note : rawValue;
                    if (textarea.value !== savedNote) {
                        textarea.value = savedNote;
                    }
                    textarea.dataset.initialValue = savedNote;
                    lastSavedValue = savedNote;
                    if (savedNote === '') {
                        showStatus('success', getLabel('noteCleared', getLabel('noteSaved', '')));
                    } else {
                        showStatus('success', getLabel('noteSaved', ''));
                    }
                } catch (err) {
                    pendingValue = rawValue;
                    showStatus('error', err && err.message ? err.message : getLabel('noteError', errorMessages.DEFAULT || ''));
                } finally {
                    isSaving = false;
                    if (pendingValue !== null && pendingValue !== lastSavedValue) {
                        scheduleSave(true);
                    }
                }
            }

            textarea.addEventListener('input', () => {
                scheduleSave(false);
            });

            textarea.addEventListener('blur', () => {
                if (textarea.value !== lastSavedValue) {
                    scheduleSave(true);
                }
            });

            textarea.addEventListener('keydown', (evt) => {
                if ((evt.metaKey || evt.ctrlKey) && evt.key === 'Enter') {
                    evt.preventDefault();
                    scheduleSave(true);
                }
            });
        });
    }

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
        checkSelectedDisabledByRun = !!disabled;
        updateSelectionActionsState();
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
            const row = input.closest('tr');
            const prevState = row && row.dataset ? (row.dataset.active || '0') : '0';
            input.checked = successful.has(slug);
            if (row) {
                const nextState = (input.checked && !input.disabled) ? '1' : '0';
                row.dataset.active = nextState;
                if (prevState !== nextState) {
                    queueNetworkSaveForRow(row, { delay: 200 });
                }
            }
        });
        triggerNetworkFiltersRefresh();
        setNetworkFiltersMessage(labels.applySuccessHint, { duration: 3200 });
        setMessage('success', labels.applySuccessHint);
        triggerNetworkSaveFlush(true);
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
                let modeLabel = labels.bulkMode;
                if (run.run_mode === 'single') {
                    modeLabel = labels.singleMode;
                } else if (run.run_mode === 'selection') {
                    modeLabel = labels.selectionMode || labels.bulkMode;
                }
                summaryMode.textContent = modeLabel;
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
                let modeLabel = labels.bulkMode;
                if (run.run_mode === 'single') {
                    modeLabel = labels.singleMode;
                } else if (run.run_mode === 'selection') {
                    modeLabel = labels.selectionMode || labels.bulkMode;
                }
                metaParts.push(labels.mode + ': ' + modeLabel);
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

    async function startCheck(slug = '', triggerBtn = null, options = null) {
        clearMessage();
        const button = triggerBtn || startBtn;
        const prevHtml = button ? button.innerHTML : '';
        let runStarted = false;
        const opts = (options && typeof options === 'object') ? options : {};
        const normalizedSelection = Array.isArray(opts.slugs) ? Array.from(new Set(opts.slugs.filter(function(item){
            return typeof item === 'string' && item.trim() !== '';
        }).map(function(item){ return item.trim(); }))) : [];
        let mode = 'bulk';
        if (slug) {
            mode = 'single';
        } else if (typeof opts.mode === 'string') {
            const rawMode = opts.mode.toLowerCase();
            if (rawMode === 'single') { mode = 'single'; }
            else if (rawMode === 'selection') { mode = 'selection'; }
        }
        if (mode === 'selection' && normalizedSelection.length === 0) {
            setMessage('warning', labels.selectionEmpty || errorMessages.DEFAULT);
            return;
        }
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
            body.set('mode', mode);
            if (mode === 'selection') {
                normalizedSelection.forEach(function(selSlug){
                    body.append('slugs[]', selSlug);
                });
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
            } else if (mode === 'single') {
                const networkName = (triggerBtn && triggerBtn.dataset && triggerBtn.dataset.networkTitle) ? triggerBtn.dataset.networkTitle : slug;
                setMessage('info', (labels.singleStarted || '').replace('%s', networkName));
            } else if (mode === 'selection') {
                const template = labels.selectionStarted || '';
                setMessage('info', template ? template.replace('%d', normalizedSelection.length) : '');
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

    if (networksTable) {
        initNetworkNotes(networksTable);
    }
    if (networksTable && filtersBar) {
        const filterStatus = document.getElementById('filterStatus');
        const filterActive = document.getElementById('filterActive');
        const filterRegion = document.getElementById('filterRegion');
    const filterTopic = document.getElementById('filterTopic');
    const filterLevelInputs = Array.from(filtersBar.querySelectorAll('.filter-level-checkbox'));
        const resetFiltersBtn = document.getElementById('resetNetworkFilters');
        const activateVerifiedBtn = document.getElementById('activateVerifiedBtn');
        activateSelectedBtnRef = document.getElementById('activateSelectedBtn');
        deactivateSelectedBtnRef = document.getElementById('deactivateSelectedBtn');
        clearSelectedBtnRef = document.getElementById('clearSelectedBtn');
        checkSelectedBtnRef = document.getElementById('checkSelectedBtn');
        selectAllCheckboxRef = document.getElementById('networkSelectAll');
        const filtersInfo = document.getElementById('networkFiltersInfo');
        const rows = Array.from(networksTable.querySelectorAll('tbody tr'));

        const networkRowsMap = new Map();
        const rowStateTimers = new WeakMap();
        const networkSaveQueue = new Map();
        let networkSaveTimer = 0;
        let networkSaveInFlight = false;

        const getRowSlug = (row) => (row && row.dataset ? (row.dataset.slug || '').trim() : '');

        rows.forEach((row) => {
            const slug = getRowSlug(row);
            if (slug) {
                networkRowsMap.set(slug, row);
            }
        });

        const normalizePriorityValue = (value) => {
            let num = parseInt(String(value), 10);
            if (!Number.isFinite(num) || Number.isNaN(num)) { num = 0; }
            if (num < 0) { num = 0; }
            if (num > 999) { num = 999; }
            return num;
        };

        const markRowPending = (row) => {
            if (!row) { return; }
            row.classList.add('network-row-saving');
            row.classList.remove('network-row-error', 'network-row-saved');
            const timer = rowStateTimers.get(row);
            if (timer) {
                window.clearTimeout(timer);
                rowStateTimers.delete(row);
            }
        };

        const markRowSaved = (row) => {
            if (!row) { return; }
            row.classList.remove('network-row-saving', 'network-row-error');
            row.classList.add('network-row-saved');
            const prev = rowStateTimers.get(row);
            if (prev) {
                window.clearTimeout(prev);
            }
            const timer = window.setTimeout(() => {
                row.classList.remove('network-row-saved');
                rowStateTimers.delete(row);
            }, 1800);
            rowStateTimers.set(row, timer);
        };

        const markRowError = (row) => {
            if (!row) { return; }
            row.classList.remove('network-row-saving', 'network-row-saved');
            row.classList.add('network-row-error');
            const prev = rowStateTimers.get(row);
            if (prev) {
                window.clearTimeout(prev);
            }
            const timer = window.setTimeout(() => {
                row.classList.remove('network-row-error');
                rowStateTimers.delete(row);
            }, 4000);
            rowStateTimers.set(row, timer);
        };

        const collectRowState = (row, opts = {}) => {
            const slug = getRowSlug(row);
            if (!slug) { return null; }
            const enableInput = activationCheckboxFromRow(row);
            const priorityInput = row.querySelector('input[name^="priority"]');
            const levelInputs = Array.from(row.querySelectorAll('.level-checkbox'));
            const enabled = enableInput && !enableInput.disabled ? (enableInput.checked ? 1 : 0) : 0;
            const priorityRaw = priorityInput ? priorityInput.value : (row.dataset.priority || '0');
            const priority = normalizePriorityValue(priorityRaw);
            if (priorityInput && opts.syncInputs !== false) {
                const current = parseInt(priorityInput.value, 10);
                if (!Number.isFinite(current) || current !== priority) {
                    priorityInput.value = String(priority);
                }
            }
            row.dataset.priority = String(priority);
            const levels = levelInputs.filter((cb) => cb.checked && !cb.disabled).map((cb) => cb.value);
            row.dataset.levels = levels.join('|');
            return { slug, enabled, priority, levels };
        };

        const scheduleNetworkSaveFlushInternal = (delay) => {
            const ms = typeof delay === 'number' && delay >= 0 ? delay : 700;
            if (networkSaveTimer) {
                window.clearTimeout(networkSaveTimer);
                networkSaveTimer = 0;
            }
            networkSaveTimer = window.setTimeout(() => {
                networkSaveTimer = 0;
                void flushNetworkSaveQueueInternal();
            }, ms);
        };

        const handleNetworkSaveFailure = (payload, code = '') => {
            payload.forEach((item) => {
                const row = networkRowsMap.get(item.slug || '');
                if (row) {
                    markRowError(row);
                }
            });
            let message = labels.settingsError || errorMessages.DEFAULT;
            if (code === 'CSRF') {
                message = labels.settingsErrorCsrf || message;
            }
            setInfoMessage(message);
        };

        const processNetworkSaveResponse = (payload, data) => {
            const updated = Array.isArray(data.updated) ? data.updated : [];
            const failedList = Array.isArray(data.failed) ? data.failed : (Array.isArray(data.errors) ? data.errors : []);

            if (updated.length > 0) {
                const names = [];
                updated.forEach((item) => {
                    const slug = item && item.slug ? item.slug : '';
                    const row = networkRowsMap.get(slug);
                    if (!row) { return; }
                    if (typeof item.enabled !== 'undefined') {
                        row.dataset.active = item.enabled ? '1' : '0';
                        const toggle = activationCheckboxFromRow(row);
                        if (toggle && !toggle.disabled) {
                            toggle.checked = !!item.enabled;
                        }
                    }
                    if (typeof item.priority !== 'undefined') {
                        row.dataset.priority = String(item.priority);
                        const priorityInput = row.querySelector('input[name^="priority"]');
                        if (priorityInput) {
                            priorityInput.value = String(item.priority);
                        }
                    }
                    if (typeof item.level === 'string') {
                        row.dataset.levels = item.level;
                    }
                    markRowSaved(row);
                    if (row.dataset && row.dataset.title) {
                        names.push(row.dataset.title);
                    } else if (slug) {
                        names.push(slug);
                    }
                });

                let successMessage = '';
                if (updated.length === 1 && labels.settingsSavedSingle) {
                    successMessage = labels.settingsSavedSingle.replace('%s', names[0] || updated[0].slug || '');
                } else if (updated.length > 1 && labels.settingsSavedMany) {
                    successMessage = labels.settingsSavedMany.replace('%d', updated.length);
                } else if (labels.settingsSaved) {
                    successMessage = labels.settingsSaved;
                }
                if (successMessage) {
                    setInfoMessage(successMessage, { duration: 2600 });
                } else {
                    setInfoMessage('', {});
                }
            } else if (!failedList.length) {
                setInfoMessage('', {});
            }

            if (failedList.length) {
                failedList.forEach((item) => {
                    const row = networkRowsMap.get(item && item.slug ? item.slug : '');
                    if (row) {
                        markRowError(row);
                    }
                });
                let errMessage = labels.settingsError || errorMessages.DEFAULT;
                const first = failedList[0] || {};
                if (first.code === 'CSRF') {
                    errMessage = labels.settingsErrorCsrf || errMessage;
                } else if (first.code === 'NOT_FOUND' || first.code === 'MISSING') {
                    errMessage = labels.settingsErrorNotFound || errMessage;
                } else if (first.message) {
                    errMessage = first.message;
                }
                setInfoMessage(errMessage);
            }
        };

        const flushNetworkSaveQueueInternal = async () => {
            if (networkSaveInFlight) { return; }
            if (networkSaveQueue.size === 0) { return; }
            const payload = Array.from(networkSaveQueue.values()).map((item) => ({
                slug: item.slug,
                enabled: item.enabled,
                priority: item.priority,
                levels: item.levels,
            }));
            networkSaveQueue.clear();
            networkSaveInFlight = true;
            if (!infoMessage && labels.settingsSaving) {
                setInfoMessage(labels.settingsSaving);
            }
            const body = new URLSearchParams();
            body.set('csrf_token', window.CSRF_TOKEN || '');
            body.set('payload', JSON.stringify(payload));
            try {
                const response = await fetch(apiNetworkSettings, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                });
                const data = await response.json().catch(() => null);
                if (!data) {
                    handleNetworkSaveFailure(payload);
                } else if (data.error === 'CSRF') {
                    handleNetworkSaveFailure(payload, 'CSRF');
                } else if (data.ok === false && (!Array.isArray(data.updated) || data.updated.length === 0)) {
                    handleNetworkSaveFailure(payload, data.error || '');
                } else {
                    processNetworkSaveResponse(payload, data);
                }
            } catch (err) {
                handleNetworkSaveFailure(payload);
            } finally {
                networkSaveInFlight = false;
                if (networkSaveQueue.size > 0) {
                    scheduleNetworkSaveFlushInternal(600);
                }
            }
        };

        const queueRowState = (row, options = {}) => {
            const state = collectRowState(row, { syncInputs: options.syncInputs });
            if (!state) { return; }
            const existing = networkSaveQueue.get(state.slug) || { slug: state.slug };
            networkSaveQueue.set(state.slug, {
                ...existing,
                enabled: state.enabled,
                priority: state.priority,
                levels: state.levels,
            });
            markRowPending(row);
            if (options.immediate) {
                scheduleNetworkSaveFlushInternal(0);
            } else if (typeof options.delay === 'number') {
                scheduleNetworkSaveFlushInternal(options.delay);
            } else {
                scheduleNetworkSaveFlushInternal(700);
            }
        };

        queueNetworkSaveForRow = queueRowState;
        triggerNetworkSaveFlush = function(force) {
            if (force) {
                scheduleNetworkSaveFlushInternal(0);
            } else {
                scheduleNetworkSaveFlushInternal(600);
            }
        };

        const normalizeValue = (val) => (val || '').toString().trim().toLowerCase();
        const getVisibleRows = () => rows.filter((row) => row.style.display !== 'none');
        const getSelectedRows = () => rows.filter((row) => row.dataset.selected === '1');
        const selectionCheckboxFromRow = (row) => row.querySelector('.network-select');
        const activationCheckboxFromRow = (row) => row.querySelector('input.form-check-input[name^="enable"]');

    let infoMessage = '';
    let infoMessageTimer = 0;

        const updateFiltersInfo = () => {
            if (!filtersInfo) { return; }
            const parts = [];
            const total = rows.length;
            const visibleCount = getVisibleRows().length;
            if (filtersInfo.dataset && filtersInfo.dataset.labelVisible && visibleCount !== total) {
                parts.push(filtersInfo.dataset.labelVisible.replace('%d', visibleCount));
            }
            const selectedCount = getSelectedRows().length;
            if (filtersInfo.dataset && filtersInfo.dataset.labelSelected && selectedCount > 0) {
                parts.push(filtersInfo.dataset.labelSelected.replace('%d', selectedCount));
            }
            if (infoMessage) {
                parts.push(infoMessage);
            }
            filtersInfo.textContent = parts.join(' | ');
        };

        const setInfoMessage = (message, options = {}) => {
            if (infoMessageTimer) {
                window.clearTimeout(infoMessageTimer);
                infoMessageTimer = 0;
            }
            infoMessage = message ? String(message) : '';
            updateFiltersInfo();
            const duration = (options && typeof options.duration === 'number') ? options.duration : 0;
            if (infoMessage && duration > 0) {
                infoMessageTimer = window.setTimeout(() => {
                    infoMessageTimer = 0;
                    infoMessage = '';
                    updateFiltersInfo();
                }, duration);
            }
        };

        setNetworkFiltersMessage = setInfoMessage;

        const refreshSelectAllState = () => {
            if (!selectAllCheckboxRef) { return; }
            const visibleRows = getVisibleRows();
            const selectableVisible = visibleRows.filter((row) => {
                const checkbox = selectionCheckboxFromRow(row);
                return checkbox && !checkbox.disabled;
            });
            const selectedVisible = selectableVisible.filter((row) => row.dataset.selected === '1');
            if (selectableVisible.length === 0) {
                selectAllCheckboxRef.checked = false;
                selectAllCheckboxRef.indeterminate = false;
                return;
            }
            if (selectedVisible.length === selectableVisible.length) {
                selectAllCheckboxRef.checked = true;
                selectAllCheckboxRef.indeterminate = false;
            } else if (selectedVisible.length === 0) {
                selectAllCheckboxRef.checked = false;
                selectAllCheckboxRef.indeterminate = false;
            } else {
                selectAllCheckboxRef.checked = false;
                selectAllCheckboxRef.indeterminate = true;
            }
        };

        let updateSelectionActionsStateLocal = function() {
            const selectedCount = getSelectedRows().length;
            const disabledByRun = checkSelectedDisabledByRun;
            if (activateSelectedBtnRef) {
                activateSelectedBtnRef.disabled = disabledByRun || selectedCount === 0;
            }
            if (deactivateSelectedBtnRef) {
                deactivateSelectedBtnRef.disabled = disabledByRun || selectedCount === 0;
            }
            if (clearSelectedBtnRef) {
                clearSelectedBtnRef.disabled = disabledByRun || selectedCount === 0;
            }
            if (checkSelectedBtnRef) {
                checkSelectedBtnRef.disabled = disabledByRun || selectedCount === 0;
            }
        };

        updateSelectionActionsState = updateSelectionActionsStateLocal;

        const setRowSelected = (row, selected, silent = false) => {
            let state = !!selected;
            const checkbox = selectionCheckboxFromRow(row);
            if (checkbox) {
                if (checkbox.disabled) {
                    state = false;
                    checkbox.checked = false;
                } else {
                    checkbox.checked = state;
                }
            } else {
                state = false;
            }
            row.dataset.selected = state ? '1' : '0';
            row.classList.toggle('table-active', state);
            if (!silent) {
                refreshSelectAllState();
                updateSelectionActionsStateLocal();
                updateFiltersInfo();
            }
        };

        const applyNetworkFilters = () => {
            const statusVal = normalizeValue(filterStatus ? filterStatus.value : 'all');
            const activeVal = normalizeValue(filterActive ? filterActive.value : 'all');
            const regionVal = normalizeValue(filterRegion ? filterRegion.value : 'all');
            const topicVal = normalizeValue(filterTopic ? filterTopic.value : 'all');
            const selectedLevels = filterLevelInputs.filter((cb) => cb.checked).map((cb) => cb.value);

            rows.forEach((row) => {
                let visible = true;
                const rowStatus = normalizeValue(row.dataset.status || '');
                const rowActive = row.dataset.active || '0';
                const rowMissing = row.dataset.missing || '0';
                const rowRegions = (row.dataset.regions || '').split('|').filter(Boolean);
                const rowTopics = (row.dataset.topics || '').split('|').filter(Boolean);
                const rowLevels = (row.dataset.levels || '').split('|').filter(Boolean);

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

                if (visible && regionVal && regionVal !== 'all' && !rowRegions.includes(regionVal)) {
                    visible = false;
                }

                if (visible && topicVal && topicVal !== 'all' && !rowTopics.includes(topicVal)) {
                    visible = false;
                }

                if (visible && selectedLevels.length > 0) {
                    const intersects = rowLevels.some((lvl) => selectedLevels.includes(lvl));
                    if (!intersects) {
                        visible = false;
                    }
                }

                row.style.display = visible ? '' : 'none';
            });

            refreshSelectAllState();
            updateSelectionActionsStateLocal();
            updateFiltersInfo();
        };

        triggerNetworkFiltersRefresh = applyNetworkFilters;

        rows.forEach((row) => {
            row.dataset.selected = row.dataset.selected === '1' ? '1' : '0';
            row.classList.toggle('table-active', row.dataset.selected === '1');
            const selectionInput = selectionCheckboxFromRow(row);
            if (selectionInput) {
                selectionInput.addEventListener('change', () => {
                    setRowSelected(row, selectionInput.checked);
                });
            }
            const enableInput = activationCheckboxFromRow(row);
            if (enableInput) {
                enableInput.addEventListener('change', () => {
                    row.dataset.active = (enableInput.checked && !enableInput.disabled) ? '1' : '0';
                    applyNetworkFilters();
                    queueNetworkSaveForRow(row, { immediate: true });
                });
            }
            const priorityInput = row.querySelector('input[name^="priority"]');
            if (priorityInput) {
                let priorityDebounce = 0;
                priorityInput.addEventListener('input', () => {
                    row.dataset.priority = priorityInput.value;
                    if (priorityDebounce) {
                        window.clearTimeout(priorityDebounce);
                    }
                    priorityDebounce = window.setTimeout(() => {
                        priorityDebounce = 0;
                        queueNetworkSaveForRow(row);
                    }, 700);
                });
                const commitPriority = () => {
                    if (priorityDebounce) {
                        window.clearTimeout(priorityDebounce);
                        priorityDebounce = 0;
                    }
                    queueNetworkSaveForRow(row, { immediate: true });
                };
                priorityInput.addEventListener('change', commitPriority);
                priorityInput.addEventListener('blur', commitPriority);
            }
            const levelInputs = Array.from(row.querySelectorAll('.level-checkbox'));
            const updateRowLevelsDataset = () => {
                const selected = levelInputs.filter((cb) => cb.checked).map((cb) => cb.value);
                row.dataset.levels = selected.join('|');
            };
            if (levelInputs.length) {
                levelInputs.forEach((cb) => {
                    cb.addEventListener('change', () => {
                        updateRowLevelsDataset();
                        applyNetworkFilters();
                        queueNetworkSaveForRow(row);
                    });
                });
                updateRowLevelsDataset();
            }
            collectRowState(row, { syncInputs: false });
        });

        if (selectAllCheckboxRef) {
            selectAllCheckboxRef.addEventListener('change', () => {
                const targetState = selectAllCheckboxRef.checked;
                rows.forEach((row) => {
                    if (row.style.display === 'none') { return; }
                    const selectionInput = selectionCheckboxFromRow(row);
                    if (!selectionInput || selectionInput.disabled) { return; }
                    setRowSelected(row, targetState, true);
                });
                refreshSelectAllState();
                updateSelectionActionsStateLocal();
                updateFiltersInfo();
            });
        }

        ['change', 'input'].forEach((evt) => {
            if (filterStatus) filterStatus.addEventListener(evt, applyNetworkFilters);
            if (filterActive) filterActive.addEventListener(evt, applyNetworkFilters);
            if (filterRegion) filterRegion.addEventListener(evt, applyNetworkFilters);
            if (filterTopic) filterTopic.addEventListener(evt, applyNetworkFilters);
            filterLevelInputs.forEach((cb) => cb.addEventListener(evt, applyNetworkFilters));
        });

        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', () => {
                if (filterStatus) filterStatus.value = 'all';
                if (filterActive) filterActive.value = 'all';
                if (filterRegion) filterRegion.value = 'all';
                if (filterTopic) filterTopic.value = 'all';
                filterLevelInputs.forEach((cb) => { cb.checked = false; });
                setInfoMessage('');
                applyNetworkFilters();
            });
        }

        if (activateVerifiedBtn) {
            activateVerifiedBtn.addEventListener('click', () => {
                let activated = 0;
                rows.forEach((row) => {
                    const checkbox = activationCheckboxFromRow(row);
                    if (!checkbox || checkbox.disabled) { return; }
                    const isSuccess = normalizeValue(row.dataset.status || '') === 'success';
                    const prev = row.dataset.active || '0';
                    checkbox.checked = isSuccess;
                    const nextState = (checkbox.checked && !checkbox.disabled) ? '1' : '0';
                    row.dataset.active = nextState;
                    if (isSuccess) { activated++; }
                    if (prev !== nextState) {
                        queueNetworkSaveForRow(row, { delay: 300 });
                    }
                });
                applyNetworkFilters();
                const template = activateVerifiedBtn.getAttribute('data-message-template') || '';
                if (template) {
                    setInfoMessage(template.replace('%d', activated), { duration: 3200 });
                } else {
                    setInfoMessage('', {});
                }
                triggerNetworkSaveFlush(true);
            });
        }

        const selectionEmptyMessage = (filtersInfo && filtersInfo.dataset && filtersInfo.dataset.labelSelectionEmpty)
            ? filtersInfo.dataset.labelSelectionEmpty
            : (labels.selectionEmpty || '');
        const selectionClearedMessage = (filtersInfo && filtersInfo.dataset && filtersInfo.dataset.labelSelectionCleared)
            ? filtersInfo.dataset.labelSelectionCleared
            : (labels.selectionCleared || '');
        const activatedTemplateDefault = (filtersInfo && filtersInfo.dataset && filtersInfo.dataset.labelActivated)
            ? filtersInfo.dataset.labelActivated
            : (labels.selectedCount || '');
        const deactivatedTemplateDefault = (filtersInfo && filtersInfo.dataset && filtersInfo.dataset.labelDeactivated)
            ? filtersInfo.dataset.labelDeactivated
            : (labels.selectedCount || '');

        if (activateSelectedBtnRef) {
            activateSelectedBtnRef.addEventListener('click', () => {
                const selectedRows = getSelectedRows();
                if (!selectedRows.length) {
                    setInfoMessage(selectionEmptyMessage, { duration: 2600 });
                    return;
                }
                let affected = 0;
                selectedRows.forEach((row) => {
                    const checkbox = activationCheckboxFromRow(row);
                    if (!checkbox || checkbox.disabled) { return; }
                    const prev = row.dataset.active || '0';
                    checkbox.checked = true;
                    const nextState = (checkbox.checked && !checkbox.disabled) ? '1' : '0';
                    row.dataset.active = nextState;
                    affected++;
                    if (prev !== nextState) {
                        queueNetworkSaveForRow(row, { delay: 200 });
                    }
                });
                applyNetworkFilters();
                const template = activateSelectedBtnRef.getAttribute('data-message-template') || activatedTemplateDefault;
                const msg = template ? template.replace('%d', affected) : '';
                if (msg) {
                    setInfoMessage(msg, { duration: 3200 });
                } else {
                    setInfoMessage('', {});
                }
                triggerNetworkSaveFlush(true);
            });
        }

        if (deactivateSelectedBtnRef) {
            deactivateSelectedBtnRef.addEventListener('click', () => {
                const selectedRows = getSelectedRows();
                if (!selectedRows.length) {
                    setInfoMessage(selectionEmptyMessage, { duration: 2600 });
                    return;
                }
                let affected = 0;
                selectedRows.forEach((row) => {
                    const checkbox = activationCheckboxFromRow(row);
                    if (!checkbox || checkbox.disabled) { return; }
                    const prev = row.dataset.active || '0';
                    checkbox.checked = false;
                    const nextState = (checkbox.checked && !checkbox.disabled) ? '1' : '0';
                    row.dataset.active = nextState;
                    affected++;
                    if (prev !== nextState) {
                        queueNetworkSaveForRow(row, { delay: 200 });
                    }
                });
                applyNetworkFilters();
                const template = deactivateSelectedBtnRef.getAttribute('data-message-template') || deactivatedTemplateDefault;
                const msg = template ? template.replace('%d', affected) : '';
                if (msg) {
                    setInfoMessage(msg, { duration: 3200 });
                } else {
                    setInfoMessage('', {});
                }
                triggerNetworkSaveFlush(true);
            });
        }

        if (clearSelectedBtnRef) {
            clearSelectedBtnRef.addEventListener('click', () => {
                const selectedRows = getSelectedRows();
                if (!selectedRows.length) {
                    setInfoMessage(selectionEmptyMessage);
                    return;
                }
                selectedRows.forEach((row) => {
                    setRowSelected(row, false, true);
                });
                refreshSelectAllState();
                updateSelectionActionsStateLocal();
                updateFiltersInfo();
                const template = clearSelectedBtnRef.getAttribute('data-message-template') || selectionClearedMessage;
                setInfoMessage(template, { duration: 2400 });
            });
        }

        if (checkSelectedBtnRef) {
            checkSelectedBtnRef.addEventListener('click', () => {
                const selectedRows = getSelectedRows();
                if (!selectedRows.length) {
                    const msg = labels.selectionEmpty || selectionEmptyMessage || errorMessages.DEFAULT;
                    setMessage('warning', msg);
                    setInfoMessage(selectionEmptyMessage, { duration: 2600 });
                    return;
                }
                const slugs = selectedRows.map((row) => (row.dataset.slug || '').trim()).filter(Boolean);
                if (!slugs.length) {
                    const msg = labels.selectionEmpty || errorMessages.DEFAULT;
                    setMessage('warning', msg);
                    return;
                }
                startCheck('', checkSelectedBtnRef, { mode: 'selection', slugs: slugs });
            });
        }

        applyNetworkFilters();
        updateSelectionActionsStateLocal();
        updateFiltersInfo();

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
