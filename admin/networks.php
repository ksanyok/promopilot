<?php
require_once __DIR__ . '/../includes/init.php';
require_once PP_ROOT_PATH . '/autopost/loader.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }
$updateStatus = get_update_status();
$msg = '';
$cache = autopost_get_cached_network_metadata(false); // do not auto-init
$allNetworks = $cache['items'] ?? [];
$refreshedAt = isset($cache['refreshed_at']) && $cache['refreshed_at'] ? date('Y-m-d H:i:s', $cache['refreshed_at']) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['networks_refresh'])) {
    $allNetworks = autopost_refresh_network_cache();
    $refreshedAt = date('Y-m-d H:i:s');
    $msg = __('Список сетей обновлён.');
}
$activeGlobal = get_global_active_network_slugs();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['networks_submit'])) {
    if (!verify_csrf()) { $msg = __('Ошибка обновления.') . ' (CSRF)'; }
    else {
        $sel = isset($_POST['networks']) && is_array($_POST['networks']) ? $_POST['networks'] : [];
        if (set_global_active_network_slugs($sel)) { $activeGlobal = get_global_active_network_slugs(); $msg = __('Сети сохранены.'); }
        else { $msg = __('Ошибка сохранения.'); }
    }
}
$netDebug = function_exists('autopost_debug_info') ? autopost_debug_info() : [];
include '../includes/header.php';
?>
<div class="sidebar">
  <div class="menu-block">
    <div class="menu-title"><?php echo __('Меню'); ?></div>
    <ul class="menu-list">
      <li><a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item"><?php echo __('Обзор'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/users.php'); ?>" class="menu-item"><?php echo __('Пользователи'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item"><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item"><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item active"><?php echo __('Сети автопостинга'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><?php echo __('Обновление'); ?></a></li><?php endif; ?>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Сети автопостинга'); ?></h2>
  <?php if ($msg): ?><div class="alert alert-info fade-in"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <form method="post" class="card p-3 mb-3">
    <?php echo csrf_field(); ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="small text-muted">
        <?php if ($refreshedAt): ?><?php echo __('Последнее обновление списка'); ?>: <?php echo htmlspecialchars($refreshedAt); ?><?php else: ?><?php echo __('Список ещё не загружен. Нажмите Обновить список.'); ?><?php endif; ?>
      </div>
      <div class="btn-group">
        <button type="submit" name="networks_refresh" value="1" class="btn btn-sm btn-outline-secondary"><?php echo __('Обновить список'); ?></button>
        <button type="submit" name="networks_submit" value="1" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i><?php echo __('Сохранить'); ?></button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead><tr><th><?php echo __('Активна'); ?></th><th>Slug</th><th><?php echo __('Название'); ?></th><th><?php echo __('Описание'); ?></th></tr></thead>
        <tbody>
        <?php if (empty($allNetworks)): ?>
          <tr><td colspan="4" class="text-muted"><?php echo __('Плагины сетей не найдены.'); ?></td></tr>
        <?php else: foreach ($allNetworks as $net): $slug = htmlspecialchars($net['slug']); ?>
          <tr>
            <td><input type="checkbox" name="networks[]" value="<?php echo $slug; ?>" <?php echo in_array($net['slug'],$activeGlobal,true)?'checked':''; ?>></td>
            <td><code><?php echo $slug; ?></code></td>
            <td><?php echo htmlspecialchars($net['name'] ?? $net['slug']); ?></td>
            <td class="small text-muted"><?php echo htmlspecialchars($net['description'] ?? ''); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </form>
  <div class="small text-muted mb-3"><?php echo __('Список формируется автоматически из файлов network_*.php в папке autopost.'); ?></div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="var d=document.getElementById('net-debug'); d.style.display=d.style.display==='none'?'block':'none';">Debug</button>
  <div id="net-debug" style="display:none;" class="mt-2 small">
    <pre class="p-2 bg-light border rounded" style="white-space:pre-wrap;">
<?php echo htmlspecialchars(json_encode($netDebug, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?>
    </pre>
  </div>
</div>
<?php include '../includes/footer.php'; ?>