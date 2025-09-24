<?php
require_once __DIR__ . '/../includes/init.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }
$conn = connect_db();
$projects = $conn->query("SELECT p.id, p.name, p.description, p.links, p.created_at, u.username FROM projects p JOIN users u ON p.user_id = u.id ORDER BY p.id DESC");
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
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item active"><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item"><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item"><?php echo __('Сети автопостинга'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><?php echo __('Обновление'); ?></a></li><?php endif; ?>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Проекты'); ?></h2>
  <table class="table table-striped">
    <thead><tr><th>ID</th><th><?php echo __('Пользователь'); ?></th><th><?php echo __('Название'); ?></th><th><?php echo __('Описание'); ?></th><th><?php echo __('Ссылки'); ?></th><th><?php echo __('Дата создания'); ?></th></tr></thead>
    <tbody>
    <?php while ($p = $projects->fetch_assoc()): $links = json_decode($p['links'] ?? '[]', true); ?>
      <tr>
        <td><?php echo (int)$p['id']; ?></td>
        <td><?php echo htmlspecialchars($p['username']); ?></td>
        <td><?php echo htmlspecialchars($p['name']); ?></td>
        <td><?php echo htmlspecialchars($p['description']); ?></td>
        <td><?php echo is_array($links)?count($links):0; ?></td>
        <td><?php echo htmlspecialchars($p['created_at']); ?></td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php include '../includes/footer.php'; ?>