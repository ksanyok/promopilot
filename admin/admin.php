<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || !is_admin()) {
    redirect('auth/login.php');
}

// Hide brand logo in top navbar to leave only the corner-brand in admin
$pp_hide_brand_logo = true;

$conn = connect_db();

// Ensure settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS settings (\n  k VARCHAR(191) PRIMARY KEY,\n  v TEXT,\n  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$settingsMsg = '';
$allowedCurrencies = ['RUB','USD','EUR','GBP','UAH'];
$settingsKeys = ['currency','openai_api_key','telegram_token','telegram_channel'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_submit'])) {
    if (!verify_csrf()) {
        $settingsMsg = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
        if (!in_array($currency, $allowedCurrencies, true)) { $currency = 'RUB'; }
        $openai = trim((string)($_POST['openai_api_key'] ?? ''));
        $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
        $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));

        $pairs = [
            ['currency', $currency],
            ['openai_api_key', $openai],
            ['telegram_token', $tgToken],
            ['telegram_channel', $tgChannel],
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
}

// Load settings
$settings = ['currency' => 'RUB', 'openai_api_key' => '', 'telegram_token' => '', 'telegram_channel' => ''];
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
?>

<?php include '../includes/header.php'; ?>

<!-- Логотип на пересечении навбара и сайдбара -->
<div class="corner-brand">
    <img src="<?php echo asset_url('img/logo.svg'); ?>" alt="Logo">
</div>

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
        </ul>
    </div>
</div>

<div class="main-content">
<h2><?php echo __('Админка PromoPilot'); ?></h2>

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
                <label class="form-label">OpenAI API Key</label>
                <input type="text" name="openai_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['openai_api_key']); ?>" placeholder="sk-...">
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
</div>

</div><!-- /.main-content -->

<?php include '../includes/footer.php'; ?>