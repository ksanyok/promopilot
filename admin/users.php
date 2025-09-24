<?php
require_once __DIR__ . '/../includes/init.php';
if (!is_logged_in() || !is_admin()) { redirect('auth/login.php'); }
$conn = connect_db();
$users = $conn->query("SELECT id, username, role, balance, created_at FROM users ORDER BY id");
$conn->close();
$updateStatus = get_update_status();
include '../includes/header.php';
?>
<div class="sidebar">
  <div class="menu-block">
    <div class="menu-title"><?php echo __('Меню'); ?></div>
    <ul class="menu-list">
      <li><a href="<?php echo pp_url('admin/admin.php'); ?>" class="menu-item"><?php echo __('Обзор'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/users.php'); ?>" class="menu-item active"><?php echo __('Пользователи'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/projects.php'); ?>" class="menu-item"><?php echo __('Проекты'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/settings.php'); ?>" class="menu-item"><?php echo __('Основные настройки'); ?></a></li>
      <li><a href="<?php echo pp_url('admin/networks.php'); ?>" class="menu-item"><?php echo __('Сети автопостинга'); ?></a></li>
      <?php if ($updateStatus['is_new']): ?><li><a href="<?php echo pp_url('public/update.php'); ?>" class="menu-item"><?php echo __('Обновление'); ?></a></li><?php endif; ?>
    </ul>
  </div>
</div>
<div class="main-content">
  <h2><?php echo __('Пользователи'); ?></h2>
  <table class="table table-striped">
    <thead><tr><th>ID</th><th><?php echo __('Логин'); ?></th><th><?php echo __('Роль'); ?></th><th><?php echo __('Баланс'); ?></th><th><?php echo __('Дата регистрации'); ?></th><th><?php echo __('Действия'); ?></th></tr></thead>
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
          <a class="btn btn-warning btn-sm" href="admin_login_as.php?user_id=<?php echo (int)$user['id']; ?>&t=<?php echo urlencode($t); ?>"><?php echo __('Войти как'); ?></a>
          <a class="btn btn-info btn-sm" href="edit_balance.php?user_id=<?php echo (int)$user['id']; ?>"><?php echo __('Изменить баланс'); ?></a>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php include '../includes/footer.php'; ?>