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

// Sidebar context and full-width page
$pp_current_project = ['id' => (int)$project['id'], 'name' => (string)$project['name']];
$pp_container = false; $pp_container_class = '';

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
  $stmt = $conn->prepare("SELECT p.id, p.created_at, p.network, p.published_by, p.anchor, p.page_url, p.post_url, pn.level AS promotion_level
               FROM publications p
               LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
               WHERE p.project_id = ? AND p.network = ? AND (p.anchor LIKE ? OR p.page_url LIKE ? OR p.post_url LIKE ? OR p.published_by LIKE ?)
               ORDER BY p.created_at DESC, p.id DESC");
  $stmt->bind_param('isssss', $id, $network, $like, $like, $like, $like);
} elseif ($network !== '') {
  $stmt = $conn->prepare("SELECT p.id, p.created_at, p.network, p.published_by, p.anchor, p.page_url, p.post_url, pn.level AS promotion_level
               FROM publications p
               LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
               WHERE p.project_id = ? AND p.network = ?
               ORDER BY p.created_at DESC, p.id DESC");
  $stmt->bind_param('is', $id, $network);
} elseif ($q !== '') {
    $like = '%' . $q . '%';
  $stmt = $conn->prepare("SELECT p.id, p.created_at, p.network, p.published_by, p.anchor, p.page_url, p.post_url, pn.level AS promotion_level
               FROM publications p
               LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
               WHERE p.project_id = ? AND (p.anchor LIKE ? OR p.page_url LIKE ? OR p.post_url LIKE ? OR p.published_by LIKE ?)
               ORDER BY p.created_at DESC, p.id DESC");
  $stmt->bind_param('issss', $id, $like, $like, $like, $like);
} else {
  $stmt = $conn->prepare("SELECT p.id, p.created_at, p.network, p.published_by, p.anchor, p.page_url, p.post_url, pn.level AS promotion_level
               FROM publications p
               LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
               WHERE p.project_id = ?
               ORDER BY p.created_at DESC, p.id DESC");
  $stmt->bind_param('i', $id);
}

$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) { $publications[] = $row; }
$stmt->close();
$conn->close();
$GLOBALS['pp_layout_has_sidebar'] = true;

include '../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';
?>

<div class="main-content fade-in">
  <!-- Header card -->
  <div class="card section project-hero mb-3">
    <div class="card-body d-flex align-items-center justify-content-between gap-3">
      <div>
        <div class="title mb-1 d-flex align-items-center gap-2">
          <?php echo __('История публикаций'); ?>
          <i class="bi bi-info-circle text-primary" data-bs-toggle="tooltip" title="<?php echo __('Хронология размещённых и ожидающих публикаций по выбранному проекту.'); ?>"></i>
        </div>
        <div class="help">#<?php echo (int)$project['id']; ?> · <?php echo htmlspecialchars($project['name']); ?></div>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-outline-primary" href="<?php echo pp_url('client/project.php?id=' . (int)$project['id']); ?>"><i class="bi bi-arrow-left me-1"></i><span class="btn-text"><?php echo __('Вернуться'); ?></span></a>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card section mb-3">
    <div class="section-header">
      <div class="label"><i class="bi bi-funnel"></i><span><?php echo __('Фильтры'); ?></span> <i class="bi bi-question-circle ms-1" data-bs-toggle="tooltip" title="<?php echo __('Сужайте список по сети или ключевому фрагменту: анкору, URL или автору.'); ?>"></i></div>
      <div class="toolbar">
        <?php $base = pp_url('client/history.php?id=' . (int)$project['id']); ?>
        <a href="<?php echo $base; ?>" class="btn btn-outline-light btn-sm" data-bs-toggle="tooltip" title="<?php echo __('Сбросить фильтры'); ?>"><i class="bi bi-x-circle me-1"></i><span class="btn-text"><?php echo __('Сбросить'); ?></span></a>
      </div>
    </div>
    <div class="card-body">
      <form class="row g-3 align-items-end" method="get" action="">
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
          <button type="submit" class="btn btn-gradient w-100"><i class="bi bi-filter me-1"></i><span class="btn-text"><?php echo __('Фильтр'); ?></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- History table -->
  <div class="card section table-card">
    <div class="section-header">
      <div class="label"><i class="bi bi-clock-history"></i><span><?php echo __('Записи'); ?></span></div>
      <div class="toolbar"></div>
    </div>
    <div class="card-body">
      <?php if (!empty($publications)): ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle table-history">
          <thead>
            <tr>
              <th style="width:60px;">#</th>
              <th><?php echo __('Дата'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Время регистрации публикации в системе.'); ?>"></i></th>
              <th><?php echo __('Сеть'); ?> <i class="bi bi-question-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Тип площадки / группа ресурсов.'); ?>"></i></th>
              <th><?php echo __('Уровень'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('На каком уровне продвижения была размещена статья.'); ?>"></i></th>
              <th><?php echo __('Опубликовано'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Сервис или оператор разместивший запись.'); ?>"></i></th>
              <th><?php echo __('Анкор'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Текст ссылки использованный в публикации (если применимо).'); ?>"></i></th>
              <th><?php echo __('Страница'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Целевая страница проекта, на которую ведёт публикация.'); ?>"></i></th>
              <th><?php echo __('Ссылка на публикацию'); ?> <i class="bi bi-info-circle small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Прямая URL размещённого материала (если уже доступен).'); ?>"></i></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($publications as $i => $row): ?>
            <tr>
              <td data-label="#"><?php echo $i + 1; ?></td>
              <td data-label="<?php echo __('Дата'); ?>"><?php echo htmlspecialchars($row['created_at']); ?></td>
              <td data-label="<?php echo __('Сеть'); ?>"><?php echo htmlspecialchars($row['network']); ?></td>
              <td data-label="<?php echo __('Уровень'); ?>">
                <?php $lvl = isset($row['promotion_level']) ? (int)$row['promotion_level'] : 0; echo $lvl > 0 ? $lvl : '—'; ?>
              </td>
              <td data-label="<?php echo __('Опубликовано'); ?>"><?php echo htmlspecialchars($row['published_by']); ?></td>
              <td data-label="<?php echo __('Анкор'); ?>"><?php echo htmlspecialchars($row['anchor']); ?></td>
              <td data-label="<?php echo __('Страница'); ?>" class="url-cell">
                <?php $purl = (string)$row['page_url']; ?>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <a href="<?php echo htmlspecialchars($purl); ?>" target="_blank" class="view-url"><?php echo htmlspecialchars($purl); ?></a>
                  <button type="button" class="btn btn-outline-secondary btn-sm copy-btn" title="<?php echo __('Копировать'); ?>" data-copy="<?php echo htmlspecialchars($purl); ?>"><i class="bi bi-clipboard"></i></button>
                </div>
              </td>
              <td data-label="<?php echo __('Ссылка на публикацию'); ?>" class="url-cell">
                <?php if (!empty($row['post_url'])): ?>
                  <?php $post = (string)$row['post_url']; ?>
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="<?php echo htmlspecialchars($post); ?>" target="_blank" class="view-url"><?php echo htmlspecialchars($post); ?></a>
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
