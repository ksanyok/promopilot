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

// Sidebar context and layout flags
$pp_current_project = ['id' => (int)$project['id'], 'name' => (string)$project['name']];
$pp_container = false; $pp_container_class = '';
$GLOBALS['pp_layout_has_sidebar'] = true;

// Filters
$network = trim((string)($_GET['network'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$levelFilterRaw = trim((string)($_GET['level'] ?? ''));
$levelFilter = ($levelFilterRaw !== '' && is_numeric($levelFilterRaw)) ? (int)$levelFilterRaw : null;
if ($levelFilter !== null && $levelFilter <= 0) { $levelFilter = null; }

$selectedNetwork = $network;

// Summary defaults
$historySummary = [
  'total' => 0,
  'published' => 0,
  'uniqueNetworks' => 0,
  'lastDate' => null,
];
$networkCounts = [];
$levelsUsed = [];
$levelsList = [];

// Project-wide summary stats
if ($stmt = $conn->prepare("SELECT
    COUNT(*) AS total,
    COUNT(*) AS published,
    MAX(p.created_at) AS lastDate
  FROM publications p
  WHERE p.project_id = ?
    AND p.status IN ('success','completed','partial')
    AND p.post_url IS NOT NULL
    AND p.post_url <> ''")) {
  $stmt->bind_param('i', $id);
  $stmt->execute();
  if ($summaryRes = $stmt->get_result()) {
    if ($row = $summaryRes->fetch_assoc()) {
      $historySummary['total'] = (int)$row['total'];
      $historySummary['published'] = (int)$row['published'];
      $historySummary['lastDate'] = $row['lastDate'] ?? null;
    }
  }
  $stmt->close();
}

// Network counts for chips
if ($stmt = $conn->prepare("SELECT p.network, COUNT(*) AS cnt
  FROM publications p
  WHERE p.project_id = ?
    AND p.network <> ''
    AND p.status IN ('success','completed','partial')
    AND p.post_url IS NOT NULL
    AND p.post_url <> ''
  GROUP BY p.network
  ORDER BY cnt DESC")) {
  $stmt->bind_param('i', $id);
  $stmt->execute();
  if ($netRes = $stmt->get_result()) {
    while ($row = $netRes->fetch_assoc()) {
      $networkKey = (string)$row['network'];
      $networkCounts[$networkKey] = (int)$row['cnt'];
    }
  }
  $stmt->close();
}
$historySummary['uniqueNetworks'] = count($networkCounts);

// Promotion level distribution
if ($stmt = $conn->prepare("SELECT COALESCE(pn.level, 0) AS lvl, COUNT(*) AS cnt
  FROM publications p
  LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
  WHERE p.project_id = ?
    AND p.status IN ('success','completed','partial')
    AND p.post_url IS NOT NULL
    AND p.post_url <> ''
  GROUP BY lvl
  ORDER BY lvl ASC")) {
  $stmt->bind_param('i', $id);
  $stmt->execute();
  if ($lvlRes = $stmt->get_result()) {
    while ($row = $lvlRes->fetch_assoc()) {
      $lvl = (int)$row['lvl'];
      if ($lvl > 0) {
        $levelsUsed[$lvl] = (int)$row['cnt'];
        $levelsList[] = $lvl;
      }
    }
  }
  $stmt->close();
}
$levelsList = array_values(array_unique($levelsList));
sort($levelsList);
foreach ($levelsList as $lvl) {
  if (!isset($levelsUsed[$lvl])) { $levelsUsed[$lvl] = 0; }
}

// Publications query with filters
$publications = [];
$sql = "SELECT p.id, p.created_at, p.network, p.published_by, p.anchor, p.page_url, p.post_url, pn.level AS promotion_level
  FROM publications p
  LEFT JOIN promotion_nodes pn ON pn.publication_id = p.id
  WHERE p.project_id = ?
    AND p.status IN ('success','completed','partial')
    AND p.post_url IS NOT NULL
    AND p.post_url <> ''";
$types = 'i';
$params = [$id];

if ($selectedNetwork !== '') {
  $sql .= " AND p.network = ?";
  $types .= 's';
  $params[] = $selectedNetwork;
}
if ($levelFilter !== null) {
  $sql .= " AND COALESCE(pn.level, 0) = ?";
  $types .= 'i';
  $params[] = $levelFilter;
}
if ($q !== '') {
  $sql .= " AND (p.anchor LIKE ? OR p.page_url LIKE ? OR p.post_url LIKE ? OR p.published_by LIKE ?)";
  $like = '%' . $q . '%';
  $types .= 'ssss';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY p.created_at DESC, p.id DESC";

if ($stmt = $conn->prepare($sql)) {
  $bind = [];
  $bind[] = &$types;
  foreach ($params as $k => $param) {
    $bind[] = &$params[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
  $stmt->execute();
  if ($pubRes = $stmt->get_result()) {
    while ($row = $pubRes->fetch_assoc()) { $publications[] = $row; }
  }
  $stmt->close();
}

$conn->close();

include '../includes/header.php';
include __DIR__ . '/../includes/client_sidebar.php';
?>

<div class="main-content fade-in">
  <div class="history-shell">
    <div class="card history-hero mb-4">
      <div class="card-body history-hero__body">
        <div class="history-hero__header">
          <div>
            <h2 class="history-hero__heading mb-1 d-flex align-items-center gap-2">
              <i class="bi bi-clock-history"></i>
              <?php echo __('История публикаций'); ?>
            </h2>
            <div class="history-hero__meta text-muted small">
              <span><i class="bi bi-hash"></i> <?php echo (int)$project['id']; ?></span>
              <span class="dot">•</span>
              <span class="fw-semibold text-truncate d-inline-block" style="max-width: clamp(160px, 32vw, 340px);"><?php echo htmlspecialchars($project['name']); ?></span>
            </div>
            <div class="history-hero__desc text-muted small">
              <?php echo __('Мониторинг живых ссылок и статуса публикаций по выбранному проекту.'); ?>
            </div>
          </div>
          <div class="history-hero__actions">
            <a class="btn btn-outline-light btn-sm" href="<?php echo pp_url('client/project.php?id=' . (int)$project['id']); ?>"><i class="bi bi-arrow-left me-1"></i><?php echo __('Назад к проекту'); ?></a>
          </div>
        </div>
        <div class="history-hero__stats">
          <div class="history-stat">
            <div class="history-stat__label"><?php echo __('Всего записей'); ?></div>
            <div class="history-stat__value"><?php echo number_format($historySummary['total'], 0, '.', ' '); ?></div>
          </div>
          <div class="history-stat">
            <div class="history-stat__label"><?php echo __('Опубликовано'); ?></div>
            <div class="history-stat__value"><?php echo number_format($historySummary['published'], 0, '.', ' '); ?></div>
          </div>
          <div class="history-stat">
            <div class="history-stat__label"><?php echo __('Сетей'); ?></div>
            <div class="history-stat__value"><?php echo number_format($historySummary['uniqueNetworks'], 0, '.', ' '); ?></div>
          </div>
          <div class="history-stat">
            <div class="history-stat__label"><?php echo __('Последняя запись'); ?></div>
            <div class="history-stat__value history-stat__value--muted">
              <?php echo $historySummary['lastDate'] ? htmlspecialchars($historySummary['lastDate']) : '—'; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card history-filters mb-4">
      <div class="card-body">
        <div class="history-filters__header d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
          <div>
            <div class="h5 mb-1 d-flex align-items-center gap-2"><i class="bi bi-funnel"></i><?php echo __('Фильтры'); ?></div>
            <p class="text-muted small mb-0"><?php echo __('Используйте быстрые теги или поиск, чтобы найти нужную публикацию.'); ?></p>
          </div>
          <div>
            <?php $base = pp_url('client/history.php?id=' . (int)$project['id']); ?>
            <a href="<?php echo $base; ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-x-circle me-1"></i><?php echo __('Сбросить'); ?></a>
          </div>
        </div>
        <form class="history-filter-form" method="get" action="">
          <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
          <input type="hidden" name="network" value="<?php echo htmlspecialchars($selectedNetwork); ?>" id="history-network">
          <div class="history-filter-chips mb-3">
            <button type="button" class="filter-chip <?php echo ($selectedNetwork === '' ? 'active' : ''); ?>" data-network="">
              <span class="label"><?php echo __('Все сети'); ?></span>
              <span class="count"><?php echo number_format($historySummary['total'], 0, '.', ' '); ?></span>
            </button>
            <?php foreach ($networkCounts as $net => $count): ?>
              <button type="button" class="filter-chip <?php echo ($selectedNetwork === $net ? 'active' : ''); ?>" data-network="<?php echo htmlspecialchars($net); ?>">
                <span class="label"><?php echo htmlspecialchars($net); ?></span>
                <span class="count"><?php echo number_format($count, 0, '.', ' '); ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="history-filter-controls row g-2 align-items-center">
            <div class="col-12 col-lg">
              <label class="form-label small text-uppercase fw-semibold mb-1"><?php echo __('Поиск'); ?></label>
              <div class="input-group input-group-sm history-filter-search">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo __('Анкор, страница, ссылка на пост или автор'); ?>">
              </div>
            </div>
            <div class="col-12 col-md-auto">
              <label class="form-label small text-uppercase fw-semibold mb-1"><?php echo __('Уровень'); ?></label>
              <div class="history-level-select">
                <button type="button" class="filter-chip <?php echo ($levelFilter === null ? 'active' : ''); ?>" data-level="">
                  <span class="label"><?php echo __('Все'); ?></span>
                </button>
                <?php foreach ($levelsList as $lvl): ?>
                  <button type="button" class="filter-chip<?php echo ($levelFilter !== null && $levelFilter === (int)$lvl) ? ' active' : ''; ?>" data-level="<?php echo (int)$lvl; ?>">
                    <span class="label"><?php echo __('L'); ?><?php echo (int)$lvl; ?></span>
                    <span class="count"><?php echo number_format($levelsUsed[$lvl] ?? 0, 0, '.', ' '); ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="level" id="history-level" value="<?php echo $levelFilter !== null ? (int)$levelFilter : ''; ?>">
            </div>
            <div class="col-12 col-md-auto text-md-end">
              <button type="submit" class="btn btn-gradient w-100 w-md-auto"><i class="bi bi-filter me-1"></i><?php echo __('Применить'); ?></button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="history-timeline">
      <?php if (!empty($publications)): ?>
        <?php foreach ($publications as $i => $row): ?>
          <?php
            $purl = (string)$row['page_url'];
            $post = (string)($row['post_url'] ?? '');
            $lvl = isset($row['promotion_level']) ? (int)$row['promotion_level'] : 0;
            $published = $post !== '';
          ?>
          <article class="history-entry" data-network="<?php echo htmlspecialchars($row['network']); ?>" data-level="<?php echo $lvl; ?>" data-published="<?php echo $published ? '1' : '0'; ?>">
            <div class="history-entry__header">
              <div class="history-entry__stamp">
                <span class="date"><?php echo htmlspecialchars($row['created_at']); ?></span>
                <span class="network badge bg-primary-subtle text-primary-emphasis"><?php echo htmlspecialchars($row['network']); ?></span>
                <?php if ($lvl > 0): ?><span class="level-tag">L<?php echo $lvl; ?></span><?php endif; ?>
              </div>
              <div class="history-entry__author text-muted small">
                <i class="bi bi-person"></i> <?php echo htmlspecialchars($row['published_by'] ?: __('Неизвестно')); ?>
              </div>
            </div>
            <div class="history-entry__title">
              <span class="anchor-label text-muted small"><?php echo __('Анкор'); ?></span>
              <span class="anchor"><?php echo $row['anchor'] !== '' ? htmlspecialchars($row['anchor']) : __('Без анкоров'); ?></span>
            </div>
            <div class="history-entry__links">
              <div class="history-link-block">
                <div class="label text-muted small"><?php echo __('Целевая страница'); ?></div>
                <div class="value">
                  <a href="<?php echo htmlspecialchars($purl); ?>" target="_blank" class="history-url"><?php echo htmlspecialchars($purl); ?></a>
                  <button type="button" class="btn btn-outline-secondary btn-sm copy-btn" title="<?php echo __('Копировать'); ?>" data-copy="<?php echo htmlspecialchars($purl); ?>"><i class="bi bi-clipboard"></i></button>
                </div>
              </div>
              <div class="history-link-block">
                <div class="label text-muted small d-flex align-items-center gap-1">
                  <?php echo __('Публикация'); ?>
                  <?php if ($published): ?><span class="status-dot status-dot--success" title="<?php echo __('Опубликовано'); ?>"></span><?php endif; ?>
                </div>
                <div class="value">
                  <?php if ($published): ?>
                    <a href="<?php echo htmlspecialchars($post); ?>" target="_blank" class="history-url"><?php echo htmlspecialchars($post); ?></a>
                    <button type="button" class="btn btn-outline-secondary btn-sm copy-btn" title="<?php echo __('Копировать'); ?>" data-copy="<?php echo htmlspecialchars($post); ?>"><i class="bi bi-clipboard"></i></button>
                  <?php else: ?>
                    <span class="text-muted"><?php echo __('Ссылка появится после публикации'); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state history-empty">
          <i class="bi bi-stars"></i>
          <div><?php echo __('История пока пуста.'); ?></div>
          <p class="text-muted small mb-0"><?php echo __('Запустите продвижение или добавьте ссылки, чтобы увидеть первые записи.'); ?></p>
        </div>
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
          const ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); btn.classList.add('text-success'); setTimeout(function(){ btn.classList.remove('text-success'); }, 800); }
          catch(e){ alert('<?php echo __('Не удалось скопировать'); ?>'); }
          finally { document.body.removeChild(ta); }
        }
      });
    });

    const filterForm = document.querySelector('.history-filter-form');
    const networkInput = document.getElementById('history-network');
    const levelInput = document.getElementById('history-level');
    if (filterForm && networkInput && levelInput) {
      filterForm.querySelectorAll('.filter-chip').forEach(function(chip){
        chip.addEventListener('click', function(){
          if (chip.dataset.network !== undefined) {
            networkInput.value = chip.dataset.network || '';
            filterForm.submit();
          }
          if (chip.dataset.level !== undefined) {
            levelInput.value = chip.dataset.level || '';
            filterForm.submit();
          }
        });
      });
    }
  });
</script>

<?php include '../includes/footer.php'; ?>
