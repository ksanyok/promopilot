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

// Filters
$network = trim((string)($_GET['network'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

// Fetch available networks for filter
$networks = [];
if ($stmt = $conn->prepare("SELECT DISTINCT network FROM publications WHERE project_id = ? ORDER BY network ASC")) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { if (!empty($row['network'])) { $networks[] = $row['network']; } }
    $stmt->close();
}

// Fetch publications with optional filters
$publications = [];
if ($network !== '' && $q !== '') {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id, created_at, network, published_by, anchor, page_url, post_url
                             FROM publications
                             WHERE project_id = ? AND network = ? AND (anchor LIKE ? OR page_url LIKE ? OR post_url LIKE ? OR published_by LIKE ?)
                             ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('isssss', $id, $network, $like, $like, $like, $like);
} elseif ($network !== '') {
    $stmt = $conn->prepare("SELECT id, created_at, network, published_by, anchor, page_url, post_url
                             FROM publications
                             WHERE project_id = ? AND network = ?
                             ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('is', $id, $network);
} elseif ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id, created_at, network, published_by, anchor, page_url, post_url
                             FROM publications
                             WHERE project_id = ? AND (anchor LIKE ? OR page_url LIKE ? OR post_url LIKE ? OR published_by LIKE ?)
                             ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('issss', $id, $like, $like, $like, $like);
} else {
    $stmt = $conn->prepare("SELECT id, created_at, network, published_by, anchor, page_url, post_url FROM publications WHERE project_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('i', $id);
}

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
          <div class="d-flex gap-2 align-items-center">
            <a class="btn btn-outline-primary" href="<?php echo pp_url('client/project.php?id=' . (int)$project['id']); ?>"><i class="bi bi-arrow-left me-1"></i><?php echo __('Вернуться'); ?></a>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card mb-3">
        <div class="card-body">
          <form class="row g-2 align-items-end" method="get" action="">
            <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
            <div class="col-12 col-md-4">
              <label class="form-label"><?php echo __('Сеть'); ?></label>
              <select class="form-select" name="network">
                <option value=""><?php echo __('Все сети'); ?></option>
                <?php foreach ($networks as $net): ?>
                  <option value="<?php echo htmlspecialchars($net); ?>" <?php echo ($network === $net ? 'selected' : ''); ?>><?php echo htmlspecialchars($net); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label"><?php echo __('Поиск'); ?></label>
              <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo __('Анкор, страница, ссылка на пост или автор'); ?>">
            </div>
            <div class="col-12 col-md-2 text-end">
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i><?php echo __('Фильтр'); ?></button>
            </div>
          </form>
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
                  <td>
                    <?php $purl = (string)$row['page_url']; ?>
                    <div class="d-flex align-items-center gap-2">
                      <a href="<?php echo htmlspecialchars($purl); ?>" target="_blank"><?php echo htmlspecialchars($purl); ?></a>
                      <button type="button" class="btn btn-outline-secondary btn-sm copy-btn" title="<?php echo __('Копировать'); ?>" data-copy="<?php echo htmlspecialchars($purl); ?>"><i class="bi bi-clipboard"></i></button>
                    </div>
                  </td>
                  <td>
                    <?php if (!empty($row['post_url'])): ?>
                      <?php $post = (string)$row['post_url']; ?>
                      <div class="d-flex align-items-center gap-2">
                        <a href="<?php echo htmlspecialchars($post); ?>" target="_blank"><?php echo htmlspecialchars($post); ?></a>
                        <button type="button" class="btn btn-outline-secondary btn-sm copy-btn" title="<?php echo __('Копировать'); ?>" data-copy="<?php echo htmlspecialchars($post); ?>"><i class="bi bi-clipboard"></i></button>
                      </div>
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

<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.copy-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        const text = btn.getAttribute('data-copy') || '';
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function(){
            btn.classList.add('text-success');
            setTimeout(function(){ btn.classList.remove('text-success'); }, 800);
          }).catch(function(){ alert('<?php echo __('Не удалось скопировать'); ?>'); });
        } else {
          // Fallback
          const ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); btn.classList.add('text-success'); setTimeout(function(){ btn.classList.remove('text-success'); }, 800); }
          catch(e){ alert('<?php echo __('Не удалось скопировать'); ?>'); }
          finally { document.body.removeChild(ta); }
        }
      });
    });
  });
</script>

<?php include '../includes/footer.php'; ?>
