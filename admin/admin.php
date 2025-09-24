<?php
require_once __DIR__ . '/../includes/init.php';
require_once PP_ROOT_PATH . '/autopost/loader.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }

$conn = connect_db();
$usersCount = 0; $projectsCount = 0; $pubCount = 0;
if ($res = $conn->query('SELECT COUNT(*) c FROM users')) { $usersCount = (int)$res->fetch_assoc()['c']; }
if ($res = $conn->query('SELECT COUNT(*) c FROM projects')) { $projectsCount = (int)$res->fetch_assoc()['c']; }
if ($res = $conn->query('SELECT COUNT(*) c FROM publications')) { $pubCount = (int)$res->fetch_assoc()['c']; }
$conn->close();
$updateStatus = get_update_status();
$allNetworks = autopost_list_networks();
$activeGlobal = get_global_active_network_slugs();
?>
<?php include '../includes/header.php'; ?>
<div class="sidebar">
  <div class="menu-block">
    <div class="menu-title"><?php echo __('Меню'); ?></div>
    <ul class="menu-list">
      <li><a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item"><i class="bi bi-speedometer2 me-2"></i><?php echo __('Обзор'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/users.php'); ?>" class="menu-item"><i class="bi bi-people me-2"></i><?php echo __('Пользователи'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item"><i class="bi bi-folder2-open me-2"></i><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item"><i class="bi bi-gear me-2"></i><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item"><i class="bi bi-diagram-3 me-2"></i><?php echo __('Сети автопостинга'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/diagnostics.php'); ?>" class="menu-item"><i class="bi bi-activity me-2"></i><?php echo __('Диагностика систем'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><i class="bi bi-arrow-repeat me-2"></i><?php echo __('Обновление'); ?></a></li><?php endif; ?>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Админка PromoPilot'); ?></h2>
  <?php if ($updateStatus['is_new']): ?>
    <div class="alert alert-warning fade-in">
      <strong><?php echo __('Доступно обновление'); ?>:</strong> <?php echo htmlspecialchars($updateStatus['latest']); ?> (<?php echo htmlspecialchars($updateStatus['published_at']); ?>)
      <a class="alert-link" href="<?php echo pp_url('public/update.php'); ?>"><?php echo __('Подробнее'); ?></a>
    </div>
  <?php endif; ?>
  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card p-3 h-100"><div class="h5 mb-1"><?php echo __('Пользователи'); ?></div><div class="display-6 fw-bold"><?php echo (int)$usersCount; ?></div><a class="small" href="<?php echo pp_url('admin/users.php'); ?>"><?php echo __('Перейти'); ?> →</a></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><div class="h5 mb-1"><?php echo __('Проекты'); ?></div><div class="display-6 fw-bold"><?php echo (int)$projectsCount; ?></div><a class="small" href="<?php echo pp_url('admin/projects.php'); ?>"><?php echo __('Перейти'); ?> →</a></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><div class="h5 mb-1"><?php echo __('Публикации'); ?></div><div class="display-6 fw-bold"><?php echo (int)$pubCount; ?></div><a class="small" href="<?php echo pp_url('client/history.php'); ?>"><?php echo __('История'); ?> →</a></div></div>
  </div>
  <div class="card p-3 mb-3">
    <div class="h5 mb-3"><?php echo __('Состояние сетей автопостинга'); ?></div>
    <?php if (empty($allNetworks)): ?>
      <div class="text-muted"><?php echo __('Плагины сетей не найдены.'); ?></div>
    <?php else: ?>
      <ul class="list-inline m-0">
        <?php foreach ($allNetworks as $n): $on = in_array($n['slug'],$activeGlobal,true); ?>
          <li class="list-inline-item badge bg-<?php echo $on?'success':'secondary'; ?>">
            <?php echo htmlspecialchars($n['slug']); ?><?php if (!$on) echo ' (off)'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="mt-2 small"><a href="<?php echo pp_url('admin/networks.php'); ?>"><?php echo __('Управление сетями'); ?> →</a></div>
    <?php endif; ?>
  </div>
  <div class="card p-3">
    <div class="h5 mb-3"><?php echo __('Быстрые действия'); ?></div>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?php echo pp_url('admin/settings.php'); ?>" class="btn btn-outline-primary btn-sm"><?php echo __('Настройки'); ?></a>
      <a href="<?php echo pp_url('admin/networks.php'); ?>" class="btn btn-outline-primary btn-sm"><?php echo __('Сети'); ?></a>
      <a href="<?php echo pp_url('admin/projects.php'); ?>" class="btn btn-outline-primary btn-sm"><?php echo __('Проекты'); ?></a>
      <a href="<?php echo pp_url('admin/users.php'); ?>" class="btn btn-outline-primary btn-sm"><?php echo __('Пользователи'); ?></a>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>