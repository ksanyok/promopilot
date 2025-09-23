<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in()) { redirect('auth/login.php'); }

$id = (int)($_GET['id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

$conn = connect_db();
$stmt = $conn->prepare("SELECT p.*, u.username FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    include '../includes/header.php';
    echo '<div class="alert alert-warning">' . __('Проект не найден.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}
$project = $res->fetch_assoc();
$stmt->close();

if (!is_admin() && (int)$project['user_id'] !== $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

// Sidebar context
$pp_current_project = ['id' => (int)$project['id'], 'name' => (string)$project['name']];

// Fetch publications
$publications = [];
$stmt = $conn->prepare("SELECT id, created_at, network, published_by, anchor, page_url, post_url FROM publications WHERE project_id = ? ORDER BY created_at DESC, id DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $publications[] = $row; }
$stmt->close();
$conn->close();

include '../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';
?>

<div class="main-content fade-in">
  <div class="row justify-content-center">
    <div class="col-md-11 col-lg-10">
      <div class="card mb-3">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="title mb-1"><?php echo __('История публикаций'); ?></div>
            <div class="help">#<?php echo (int)$project['id']; ?> · <?php echo htmlspecialchars($project['name']); ?></div>
          </div>
          <div>
            <a class="btn btn-outline-primary" href="<?php echo pp_url('client/project.php?id=' . (int)$project['id']); ?>"><i class="bi bi-arrow-left me-1"></i><?php echo __('Вернуться'); ?></a>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <?php if (!empty($publications)): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
              <thead class="table-secondary">
                <tr>
                  <th>#</th>
                  <th><?php echo __('Дата'); ?></th>
                  <th><?php echo __('Сеть'); ?></th>
                  <th><?php echo __('Опубликовано'); ?></th>
                  <th><?php echo __('Анкор'); ?></th>
                  <th><?php echo __('Страница'); ?></th>
                  <th><?php echo __('Ссылка на публикацию'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($publications as $i => $row): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                  <td><?php echo htmlspecialchars($row['network']); ?></td>
                  <td><?php echo htmlspecialchars($row['published_by']); ?></td>
                  <td><?php echo htmlspecialchars($row['anchor']); ?></td>
                  <td><a href="<?php echo htmlspecialchars($row['page_url']); ?>" target="_blank"><?php echo htmlspecialchars($row['page_url']); ?></a></td>
                  <td>
                    <?php if (!empty($row['post_url'])): ?>
                      <a href="<?php echo htmlspecialchars($row['post_url']); ?>" target="_blank"><?php echo htmlspecialchars($row['post_url']); ?></a>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
            <div class="empty-state"><?php echo __('Нет записей истории.'); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
