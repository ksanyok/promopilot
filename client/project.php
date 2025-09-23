<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

$conn = connect_db();
$stmt = $conn->prepare("SELECT p.*, u.username FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    include '../includes/header.php';
    echo '<div class="alert alert-warning">' . __('Проект не найден.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

$project = $result->fetch_assoc();
$conn->close();

// Проверить доступ: админ или владелец
if (!is_admin() && $project['user_id'] != $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

// Обработка формы
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $links = json_decode($project['links'] ?? '[]', true) ?: [];
        if (!is_array($links)) { $links = []; }
        // Нормализация
        $norm = [];
        foreach ($links as $item) {
            if (is_string($item)) { $norm[] = ['url' => $item, 'anchor' => '']; }
            elseif (is_array($item)) { $norm[] = ['url' => trim((string)($item['url'] ?? '')), 'anchor' => trim((string)($item['anchor'] ?? ''))]; }
        }
        $links = $norm;

        // Удаление
        $removeIdx = array_map('intval', ($_POST['remove_links'] ?? []));
        rsort($removeIdx);
        foreach ($removeIdx as $ri) { if (isset($links[$ri])) array_splice($links, $ri, 1); }

        // Редактирование существующих ссылок
        if (!empty($_POST['edited_links']) && is_array($_POST['edited_links'])) {
            foreach ($_POST['edited_links'] as $idx => $row) {
                $i = (int)$idx;
                if (!isset($links[$i])) continue;
                $url = trim($row['url'] ?? '');
                $anchor = trim($row['anchor'] ?? '');
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[$i]['url'] = $url;
                    $links[$i]['anchor'] = $anchor;
                }
            }
        }

        // Добавление новых
        if (!empty($_POST['added_links']) && is_array($_POST['added_links'])) {
            foreach ($_POST['added_links'] as $row) {
                if (!is_array($row)) continue;
                $url = trim($row['url'] ?? '');
                $anchor = trim($row['anchor'] ?? '');
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[] = ['url' => $url, 'anchor' => $anchor];
                }
            }
        } else {
            $new_link = trim($_POST['new_link'] ?? '');
            $new_anchor = trim($_POST['new_anchor'] ?? '');
            if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
                $links[] = ['url' => $new_link, 'anchor' => $new_anchor];
            }
        }

        $language = trim($_POST['language'] ?? 'ru');
        $wishes = trim($_POST['wishes'] ?? '');

        $conn = connect_db();
        $stmt = $conn->prepare("UPDATE projects SET links = ?, language = ?, wishes = ? WHERE id = ?");
        $links_json = json_encode(array_values($links));
        $stmt->bind_param('sssi', $links_json, $language, $wishes, $id);
        if ($stmt->execute()) {
            $message = __('Проект обновлен.');
            $project['links'] = $links_json;
            $project['language'] = $language;
            $project['wishes'] = $wishes;
        } else {
            $message = __('Ошибка обновления проекта.');
        }
        $stmt->close();
        $conn->close();
    }
}

$links = json_decode($project['links'] ?? '[]', true) ?: [];
if (!is_array($links)) { $links = []; }
$links = array_map(function($it){
    if (is_string($it)) return ['url'=>$it,'anchor'=>''];
    return ['url'=>trim((string)($it['url'] ?? '')),'anchor'=>trim((string)($it['anchor'] ?? ''))];
}, $links);

// Получить статусы публикаций по URL
$pubStatusByUrl = [];
try {
    $conn = connect_db();
    if ($conn) {
        $stmt = $conn->prepare("SELECT page_url, post_url FROM publications WHERE project_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $url = (string)$row['page_url'];
                $hasPost = !empty($row['post_url']);
                if (!isset($pubStatusByUrl[$url])) { $pubStatusByUrl[$url] = 'pending'; }
                if ($hasPost) { $pubStatusByUrl[$url] = 'published'; }
            }
            $stmt->close();
        }
        $conn->close();
    }
} catch (Throwable $e) { /* ignore */ }

// Make this page full-width (no Bootstrap container wrapper from header)
$pp_container = false;
$pp_container_class = '';
// Provide current project context for sidebar highlighting (optional)
$pp_current_project = ['id' => (int)$project['id'], 'name' => (string)$project['name']];

?>

<?php include '../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="row">
        <div class="col-12">
            <!-- Project hero -->
            <div class="card project-hero mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div>
                            <div class="title"><?php echo htmlspecialchars($project['name']); ?></div>
                            <div class="subtitle">@<?php echo htmlspecialchars($project['username']); ?></div>
                            <div class="meta-list">
                                <div class="meta-item"><i class="bi bi-calendar3"></i><span><?php echo __('Дата создания'); ?>: <?php echo htmlspecialchars($project['created_at']); ?></span></div>
                                <div class="meta-item"><i class="bi bi-translate"></i><span><?php echo __('Язык страницы'); ?>: <?php echo htmlspecialchars($project['language'] ?? 'ru'); ?></span></div>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="chip"><i class="bi bi-folder2-open"></i>ID <?php echo (int)$project['id']; ?></span>
                        </div>
                    </div>
                    <?php if (!empty($project['description'])): ?>
                        <div class="mt-3 help"><?php echo nl2br(htmlspecialchars($project['description'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" id="project-form" class="form-grid">
                <?php echo csrf_field(); ?>

                <div class="left-col">
                    <!-- Top Add Link card -->
                    <div class="card section link-adder-card mb-3">
                        <div class="section-header">
                            <div class="label"><i class="bi bi-link-45deg"></i><span><?php echo __('Добавить ссылку'); ?></span></div>
                            <div class="toolbar">
                                <a href="<?php echo pp_url('client/history.php?id=' . (int)$project['id']); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clock-history me-1"></i><?php echo __('История'); ?></a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="link-adder">
                                <input type="url" name="new_link" class="form-control" placeholder="<?php echo __('Добавить новую ссылку'); ?>">
                                <input type="text" name="new_anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>">
                                <button type="button" class="btn btn-gradient btn-add" id="add-link">
                                    <i class="bi bi-plus-lg"></i>
                                    <span class="btn-text ms-1"><?php echo __('Добавить'); ?></span>
                                </button>
                            </div>
                            <div id="added-hidden"></div>
                        </div>
                    </div>

                    <!-- Links table card -->
                    <div class="card section table-card" id="links-card">
                        <div class="section-header">
                            <div class="label"><i class="bi bi-list-task"></i><span><?php echo __('Ссылки'); ?></span></div>
                            <div class="toolbar">
                                <!-- reserved for future controls -->
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($links)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm align-middle table-links">
                                    <thead>
                                        <tr>
                                            <th style="width:44px;">#</th>
                                            <th><?php echo __('Ссылка'); ?></th>
                                            <th><?php echo __('Анкор'); ?></th>
                                            <th><?php echo __('Статус'); ?></th>
                                            <th class="text-end" style="width:220px;">&nbsp;</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($links as $index => $item):
                                            $url = $item['url']; $anchor = $item['anchor'];
                                            $status = $pubStatusByUrl[$url] ?? 'not_published';
                                            $canEdit = ($status === 'not_published');
                                        ?>
                                        <tr data-index="<?php echo (int)$index; ?>">
                                            <td data-label="#"><?php echo $index + 1; ?></td>
                                            <td class="url-cell" data-label="<?php echo __('Ссылка'); ?>">
                                                <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="view-url"><?php echo htmlspecialchars($url); ?></a>
                                                <input type="url" class="form-control d-none edit-url" name="edited_links[<?php echo (int)$index; ?>][url]" value="<?php echo htmlspecialchars($url); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                            </td>
                                            <td class="anchor-cell" data-label="<?php echo __('Анкор'); ?>">
                                                <span class="view-anchor"><?php echo htmlspecialchars($anchor); ?></span>
                                                <input type="text" class="form-control d-none edit-anchor" name="edited_links[<?php echo (int)$index; ?>][anchor]" value="<?php echo htmlspecialchars($anchor); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                            </td>
                                            <td data-label="<?php echo __('Статус'); ?>">
                                                <?php if ($status === 'published'): ?>
                                                    <span class="badge badge-success"><?php echo __('Опубликована'); ?></span>
                                                <?php elseif ($status === 'pending'): ?>
                                                    <span class="badge badge-warning"><?php echo __('В ожидании'); ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end" data-label="<?php echo __('Действия'); ?>">
                                                <?php if ($canEdit): ?>
                                                    <button type="button" class="btn btn-outline-primary btn-sm action-edit"><i class="bi bi-pencil me-1"></i><span class="btn-text"><?php echo __('Редактировать'); ?></span></button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm action-remove" data-index="<?php echo (int)$index; ?>"><i class="bi bi-trash me-1"></i><span class="btn-text"><?php echo __('Удалить'); ?></span></button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-lock me-1"></i><span class="btn-text"><?php echo __('Редактировать'); ?></span></button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <div class="empty-state"><?php echo __('Ссылок нет.'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <!-- Settings / preferences -->
                    <div class="card section" id="links-section">
                        <div class="section-header">
                            <div class="label"><i class="bi bi-sliders2"></i><span><?php echo __('Настройки проекта'); ?></span></div>
                        </div>
                        <div class="card-body">
                            <?php if ($message): ?>
                                <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('Язык страницы'); ?></label>
                                    <select name="language" class="form-select">
                                        <option value="ru" <?php echo ($project['language'] == 'ru' ? 'selected' : ''); ?>>Русский</option>
                                        <option value="en" <?php echo ($project['language'] == 'en' ? 'selected' : ''); ?>>English</option>
                                        <option value="es" <?php echo ($project['language'] == 'es' ? 'selected' : ''); ?>>Español</option>
                                        <option value="fr" <?php echo ($project['language'] == 'fr' ? 'selected' : ''); ?>>Français</option>
                                        <option value="de" <?php echo ($project['language'] == 'de' ? 'selected' : ''); ?>>Deutsch</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo __('Пожелания'); ?></label>
                                    <textarea name="wishes" class="form-control" rows="6" placeholder="<?php echo __('Укажите ваши пожелания'); ?>"><?php echo htmlspecialchars($project['wishes'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="sticky-actions text-end mt-3">
                                <button type="submit" name="update_project" class="btn btn-gradient"><i class="bi bi-check2-circle me-1"></i><span class="btn-text"><?php echo __('Сохранить изменения'); ?></span></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('project-form');
    const addLinkBtn = document.getElementById('add-link');
    const addedHidden = document.getElementById('added-hidden');
    const newLinkInput = form.querySelector('input[name="new_link"]');
    const newAnchorInput = form.querySelector('input[name="new_anchor"]');
    let addIndex = 0;

    // New: references for links table (may not exist initially)
    let linksTable = document.querySelector('.table-links');
    let linksTbody = linksTable ? linksTable.querySelector('tbody') : null;

    function makeHidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    // Create the links table if it doesn't exist (when first link is added)
    function ensureLinksTable() {
        if (linksTbody) return linksTbody;
        const empty = document.querySelector('.card.section .card-body .empty-state');
        const cardBody = empty ? empty.closest('.card-body') : document.querySelector('.card.section .card-body');
        if (!cardBody) return null;
        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        wrapper.innerHTML = `
            <table class="table table-striped table-hover table-sm align-middle table-links">
                <thead>
                    <tr>
                        <th style="width:44px;">#</th>
                        <th><?php echo __('Ссылка'); ?></th>
                        <th><?php echo __('Анкор'); ?></th>
                        <th><?php echo __('Статус'); ?></th>
                        <th class="text-end" style="width:220px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>`;
        if (empty) empty.replaceWith(wrapper);
        else cardBody.prepend(wrapper);
        linksTable = wrapper.querySelector('table');
        linksTbody = linksTable.querySelector('tbody');
        return linksTbody;
    }

    function refreshRowNumbers() {
        if (!linksTbody) return;
        let i = 1;
        linksTbody.querySelectorAll('tr').forEach(tr => {
            const cell = tr.querySelector('td');
            if (cell) cell.textContent = i++;
        });
    }

    // Fix pairing: use explicit index for url+anchor
    addLinkBtn.addEventListener('click', function() {
        const url = newLinkInput.value.trim();
        const anchor = newAnchorInput.value.trim();
        if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
        const idx = addIndex++;

        // Hidden inputs stored separately for submission
        const wrap = document.createElement('div');
        wrap.className = 'added-pair';
        wrap.id = 'added-' + idx;
        wrap.appendChild(makeHidden('added_links['+idx+'][url]', url));
        wrap.appendChild(makeHidden('added_links['+idx+'][anchor]', anchor));
        addedHidden.appendChild(wrap);

        // New: also render a visual row into the links table
        const tbody = ensureLinksTable();
        if (tbody) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-index', 'new');
            tr.setAttribute('data-added-index', String(idx));
            tr.innerHTML = `
                <td></td>
                <td class="url-cell">
                    <a href="${escapeHtml(url)}" target="_blank" class="view-url">${escapeHtml(url)}</a>
                    <input type="url" class="form-control d-none edit-url" value="${escapeAttribute(url)}" />
                </td>
                <td class="anchor-cell">
                    <span class="view-anchor">${escapeHtml(anchor)}</span>
                    <input type="text" class="form-control d-none edit-anchor" value="${escapeAttribute(anchor)}" />
                </td>
                <td>
                    <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-primary btn-sm action-edit"><i class="bi bi-pencil me-1"></i><?php echo __('Редактировать'); ?></button>
                    <button type="button" class="btn btn-outline-danger btn-sm action-remove-new"><?php echo __('Удалить'); ?></button>
                </td>`;
            tbody.appendChild(tr);

            // Attach listeners for this new row
            const editBtn = tr.querySelector('.action-edit');
            const removeBtn = tr.querySelector('.action-remove-new');
            const urlCell = tr.querySelector('.url-cell');
            const anchorCell = tr.querySelector('.anchor-cell');
            const viewUrl = urlCell.querySelector('.view-url');
            const viewAnchor = anchorCell.querySelector('.view-anchor');
            const editUrl = urlCell.querySelector('.edit-url');
            const editAnchor = anchorCell.querySelector('.edit-anchor');

            function syncHidden() {
                const holder = document.getElementById('added-' + idx);
                if (!holder) return;
                const urlInput = holder.querySelector(`input[name="added_links[${idx}][url]"]`);
                const anchorInput = holder.querySelector(`input[name="added_links[${idx}][anchor]"]`);
                if (urlInput) urlInput.value = editUrl.value.trim();
                if (anchorInput) anchorInput.value = editAnchor.value.trim();
                viewUrl.textContent = editUrl.value.trim();
                viewUrl.href = editUrl.value.trim();
                viewAnchor.textContent = editAnchor.value.trim();
            }

            editUrl.addEventListener('input', syncHidden);
            editAnchor.addEventListener('input', syncHidden);

            editBtn.addEventListener('click', function() {
                const editing = !editUrl.classList.contains('d-none');
                if (editing) {
                    editUrl.classList.add('d-none');
                    editAnchor.classList.add('d-none');
                    viewUrl.classList.remove('d-none');
                    viewAnchor.classList.remove('d-none');
                    this.innerHTML = '<i class="bi bi-pencil me-1"></i><?php echo __('Редактировать'); ?>';
                } else {
                    editUrl.classList.remove('d-none');
                    editAnchor.classList.remove('d-none');
                    viewUrl.classList.add('d-none');
                    viewAnchor.classList.add('d-none');
                    this.innerHTML = '<i class="bi bi-check2 me-1"></i><?php echo __('Готово'); ?>';
                }
            });

            removeBtn.addEventListener('click', function() {
                // Remove hidden inputs and the row
                const holder = document.getElementById('added-' + idx);
                if (holder) holder.remove();
                tr.remove();
                refreshRowNumbers();
            });

            refreshRowNumbers();
        }

        newLinkInput.value = '';
        newAnchorInput.value = '';
    });

    // Inline edit toggle (existing rows)
    document.querySelectorAll('.action-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            const tr = btn.closest('tr');
            if (!tr) return;
            const urlCell = tr.querySelector('.url-cell');
            const anchorCell = tr.querySelector('.anchor-cell');
            const viewUrl = urlCell.querySelector('.view-url');
            const viewAnchor = anchorCell.querySelector('.view-anchor');
            const editUrl = urlCell.querySelector('.edit-url');
            const editAnchor = anchorCell.querySelector('.edit-anchor');
            if (editUrl && editAnchor) {
                const editing = !editUrl.classList.contains('d-none');
                if (editing) {
                    // Hide editors
                    editUrl.classList.add('d-none');
                    editAnchor.classList.add('d-none');
                    viewUrl.classList.remove('d-none');
                    viewAnchor.classList.remove('d-none');
                    btn.innerHTML = '<i class="bi bi-pencil me-1"></i><?php echo __('Редактировать'); ?>';
                } else {
                    // Show editors
                    editUrl.classList.remove('d-none');
                    editAnchor.classList.remove('d-none');
                    viewUrl.classList.add('d-none');
                    viewAnchor.classList.add('d-none');
                    btn.innerHTML = '<i class="bi bi-check2 me-1"></i><?php echo __('Готово'); ?>';
                }
            }
        });
    });

    // Remove existing link by index
    document.querySelectorAll('.action-remove').forEach(btn => {
        btn.addEventListener('click', function() {
            const idx = btn.getAttribute('data-index');
            const hidden = makeHidden('remove_links[]', idx);
            form.appendChild(hidden);
            // Optionally hide row immediately
            const tr = btn.closest('tr');
            if (tr) tr.remove();
            refreshRowNumbers();
        });
    });

    function isValidUrl(string) { try { new URL(string); return true; } catch (_) { return false; } }

    // escaping helpers for safe HTML/attribute insertion
    function escapeHtml(s){
        return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
    }
    function escapeAttribute(s){
        return s.replace(/["']/g, c => ({'"':'&quot;','\'':'&#39;'}[c]));
    }
});
</script>

<?php include '../includes/footer.php'; ?>