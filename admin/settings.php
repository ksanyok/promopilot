<?php
require_once __DIR__ . '/../includes/init.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }
$conn = connect_db();
// Ensure table
$conn->query("CREATE TABLE IF NOT EXISTS settings ( k VARCHAR(191) PRIMARY KEY, v TEXT, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$allowedCurrencies = ['RUB','USD','EUR','GBP','UAH'];
$settingsKeys = ['currency','generator_mode','openai_api_key','telegram_token','telegram_channel'];
$settingsMsg='';
$settingsErr='';
$openaiCheckMsg='';
$openaiCheckOk=null; // null=no check, true=ok, false=fail
$postedOverride = null;

// Handle OpenAI key check without saving
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['check_openai'])) {
    if (!verify_csrf()) { $settingsErr = __('Ошибка проверки.') . ' (CSRF)'; }
    else {
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
        if (!in_array($currency,$allowedCurrencies,true)) { $currency='RUB'; }
        $mode = strtolower(trim((string)($_POST['generator_mode'] ?? 'local')));
        if (!in_array($mode, ['local','openai'], true)) { $mode = 'local'; }
        $openai = trim((string)($_POST['openai_api_key'] ?? ''));
        $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
        $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));
        $postedOverride = [
            'currency'=>$currency,
            'generator_mode'=>$mode,
            'openai_api_key'=>$openai,
            'telegram_token'=>$tgToken,
            'telegram_channel'=>$tgChannel,
        ];

        if ($openai === '') { $openaiCheckOk = false; $openaiCheckMsg = __('Введите OpenAI API ключ.'); }
        else {
            $valErr = null;
            if (validate_openai_api_key($openai, $valErr)) {
                $openaiCheckOk = true; $openaiCheckMsg = __('Ключ подтверждён.');
            } else {
                $openaiCheckOk = false; $openaiCheckMsg = $valErr ?: __('Не удалось подтвердить ключ OpenAI.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['settings_submit'])) {
    if (!verify_csrf()) { $settingsErr = __('Ошибка обновления.') . ' (CSRF)'; }
    else {
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'RUB')));
        if (!in_array($currency,$allowedCurrencies,true)) { $currency='RUB'; }
        $mode = strtolower(trim((string)($_POST['generator_mode'] ?? 'local')));
        if (!in_array($mode, ['local','openai'], true)) { $mode = 'local'; }
        $openai = trim((string)($_POST['openai_api_key'] ?? ''));
        $tgToken = trim((string)($_POST['telegram_token'] ?? ''));
        $tgChannel = trim((string)($_POST['telegram_channel'] ?? ''));

        // Validate OpenAI key if OpenAI mode selected
        if ($mode === 'openai') {
            if ($openai === '') {
                $settingsErr = __('Для режима OpenAI требуется указать API ключ.');
            } else {
                $valErr = null;
                if (!validate_openai_api_key($openai, $valErr)) {
                    $settingsErr = $valErr ?: __('Не удалось подтвердить ключ OpenAI.');
                }
            }
        }

        if ($settingsErr === '') {
            $pairs = [
                ['currency',$currency],
                ['generator_mode',$mode],
                ['openai_api_key',$openai],
                ['telegram_token',$tgToken],
                ['telegram_channel',$tgChannel]
            ];
            $stmt = $conn->prepare("REPLACE INTO settings (k,v) VALUES (?,?)");
            if ($stmt) {
                foreach ($pairs as [$k,$v]) { $stmt->bind_param('ss',$k,$v); $stmt->execute(); }
                $stmt->close();
                $settingsMsg = __('Настройки сохранены.');
            } else { $settingsErr = __('Ошибка сохранения настроек.'); }
        }
    }
}
$settings = ['currency'=>'RUB','generator_mode'=>'local','openai_api_key'=>'','telegram_token'=>'','telegram_channel'=>''];
$in = "'" . implode("','", array_map([$conn,'real_escape_string'],$settingsKeys)) . "'";
$res = $conn->query("SELECT k,v FROM settings WHERE k IN ($in)");
if ($res) { while ($row=$res->fetch_assoc()) { $settings[$row['k']] = (string)$row['v']; } }

// If check was performed, keep posted values in the form
if (is_array($postedOverride)) {
    $settings = array_merge($settings, $postedOverride);
}

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
  <?php if ($settingsErr): ?><div class="alert alert-danger fade-in"><?php echo htmlspecialchars($settingsErr); ?></div><?php endif; ?>
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
      <div class="col-md-8">
        <label class="form-label"><?php echo __('Режим генерации'); ?></label>
        <div class="d-flex flex-column gap-2">
          <label class="form-check">
            <input class="form-check-input" type="radio" name="generator_mode" value="local" <?php echo ($settings['generator_mode']==='local'?'checked':''); ?>>
            <span class="form-check-label"><?php echo __('Наш ИИ — локальная генерация без внешних сервисов.'); ?></span>
          </label>
          <label class="form-check">
            <input class="form-check-input" type="radio" name="generator_mode" value="openai" <?php echo ($settings['generator_mode']==='openai'?'checked':''); ?>>
            <span class="form-check-label">OpenAI — <?php echo __('использует OpenAI API, требуется API ключ.'); ?></span>
          </label>
        </div>
      </div>

      <div class="col-md-8">
        <label class="form-label">OpenAI API Key</label>
        <div class="input-group">
          <input type="text" name="openai_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['openai_api_key']); ?>" placeholder="sk-...">
          <button type="submit" name="check_openai" value="1" class="btn btn-outline-secondary"><?php echo __('Проверить'); ?></button>
        </div>
        <small class="text-muted"><?php echo __('Требуется при выборе режима OpenAI. Можно проверить без сохранения.'); ?></small>
        <?php if ($openaiCheckOk !== null): ?>
          <div class="mt-2">
            <?php if ($openaiCheckOk): ?>
              <span class="badge bg-success"><?php echo htmlspecialchars($openaiCheckMsg); ?></span>
            <?php else: ?>
              <span class="badge bg-danger"><?php echo htmlspecialchars($openaiCheckMsg); ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="col-md-4"></div>

      <div class="col-md-6">
        <label class="form-label"><?php echo __('Telegram токен'); ?></label>
        <input type="text" name="telegram_token" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_token']); ?>" placeholder="1234567890:ABCDEF...">
      </div>
      <div class="col-md-6">
        <label class="form-label"><?php echo __('Telegram канал'); ?></label>
        <input type="text" name="telegram_channel" class="form-control" value="<?php echo htmlspecialchars($settings['telegram_channel']); ?>" placeholder="@channel или chat_id">
      </div>
    </div>
    <div class="mt-3 d-flex gap-2">
      <button type="submit" name="settings_submit" value="1" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
    </div>
  </form>

  <script>
    // Optional UX: scroll to feedback when OpenAI check done
    (function(){
      var checked = <?php echo json_encode($openaiCheckOk !== null); ?>;
      if (checked) {
        var el = document.querySelector('.badge.bg-success, .badge.bg-danger');
        if (el && el.scrollIntoView) { el.scrollIntoView({behavior:'smooth', block:'center'}); }
      }
    })();
  </script>
</div>
<?php include '../includes/footer.php'; ?>