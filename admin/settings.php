<?php
require_once __DIR__ . '/../includes/init.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }
$conn = connect_db();
// Ensure table
$conn->query("CREATE TABLE IF NOT EXISTS settings ( k VARCHAR(191) PRIMARY KEY, v TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$allowedCurrencies = ['RUB','USD','EUR','GBP','UAH'];
$settingsKeys = ['currency','openai_api_key','telegram_token','telegram_channel','chrome_binary'];
$settingsMsg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['settings_submit'])) {
    if (!verify_csrf()) { $settingsMsg = __('Ошибка обновления.') . ' (CSRF)'; }
    else {
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
        if (!in_array($currency,$allowedCurrencies,true)) { $currency='RUB'; }
        $openai = trim((string)($_POST['openai_api_key'] ?? ''));
        $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
        $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));
        $chromeBin = trim((string)($_POST['chrome_binary'] ?? ''));
        $pairs = [ ['currency',$currency], ['openai_api_key',$openai], ['telegram_token',$tgToken], ['telegram_channel',$tgChannel], ['chrome_binary',$chromeBin] ];
        $stmt = $conn->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=CURRENT_TIMESTAMP");
        if ($stmt) {
            foreach ($pairs as [$k,$v]) { $stmt->bind_param('ss',$k,$v); $stmt->execute(); }
            $stmt->close();
            $settingsMsg = __('Настройки сохранены.');
        } else { $settingsMsg = __('Ошибка сохранения настроек.'); }
    }
}
$settings = ['currency'=>'RUB','openai_api_key'=>'','telegram_token'=>'','telegram_channel'=>'','chrome_binary'=>''];
$in = "'" . implode("','", array_map([$conn,'real_escape_string'],$settingsKeys)) . "'";
$res = $conn->query("SELECT k,v FROM settings WHERE k IN ($in)");
if ($res) { while ($row=$res->fetch_assoc()) { $settings[$row['k']] = (string)$row['v']; } }
$conn->close();
$updateStatus = get_update_status();
include '../includes/header.php';
?>
<div class="sidebar">
  <div class="menu-block">
    <div class="menu-title"><?php echo __('Меню'); ?></div>
    <ul class="menu-list">
      <li><a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item"><?php echo __('Обзор'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/users.php'); ?>" class="menu-item"><?php echo __('Пользователи'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item"><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item active"><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item"><?php echo __('Сети автопостинга'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><?php echo __('Обновление'); ?></a></li><?php endif; ?>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Основные настройки'); ?></h2>
  <?php if ($settingsMsg): ?><div class="alert alert-success fade-in"><?php echo htmlspecialchars($settingsMsg); ?></div><?php endif; ?>
  <form method="post" class="card p-3" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label"><?php echo __('Валюта'); ?></label>
        <select name="currency" class="form-select form-control" required>
          <?php foreach ($allowedCurrencies as $cur): ?>
            <option value="<?php echo $cur; ?>" <?php echo ($settings['currency']===$cur?'selected':''); ?>><?php echo $cur; ?></option>
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
        <input type="text" name="telegram_channel" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_channel']); ?>" placeholder="@channel или chat_id">
      </div>
      <div class="col-md-12">
        <label class="form-label"><?php echo __('Путь к Chrome/Chromium'); ?></label>
        <input type="text" name="chrome_binary" class="form-control" value="<?php echo htmlspecialchars($settings['chrome_binary']); ?>" placeholder="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome">
        <div class="form-text"><?php echo __('Если автозапуск браузера не работает, укажите полный путь к бинарнику Chrome/Chromium.'); ?></div>
      </div>
    </div>
    <div class="mt-3">
      <button type="submit" name="settings_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
    </div>
  </form>
</div>
<?php include '../includes/footer.php'; ?>