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

?>

<?php include '../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="row justify-content-center">
        <div class="col-md-11 col-lg-10">
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
                <!-- Links section with statuses and inline edit -->
                <div class="card section">
                    <div class="section-header">
                        <div class="label"><i class="bi bi-link-45deg"></i><span><?php echo __('Ссылки'); ?></span></div>
                        <div class="toolbar">
                            <a href="#links-section" class="btn btn-ghost btn-sm"><i class="bi bi-plus-circle me-1"></i><?php echo __('Добавить'); ?></a>
                            <a href="<?php echo pp_url('client/history.php?id=' . (int)$project['id']); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clock-history me-1"></i><?php echo __('История'); ?></a>
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
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="url-cell">
                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="view-url"><?php echo htmlspecialchars($url); ?></a>
                                            <input type="url" class="form-control d-none edit-url" name="edited_links[<?php echo (int)$index; ?>][url]" value="<?php echo htmlspecialchars($url); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                        </td>
                                        <td class="anchor-cell">
                                            <span class="view-anchor"><?php echo htmlspecialchars($anchor); ?></span>
                                            <input type="text" class="form-control d-none edit-anchor" name="edited_links[<?php echo (int)$index; ?>][anchor]" value="<?php echo htmlspecialchars($anchor); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                        </td>
                                        <td>
                                            <?php if ($status === 'published'): ?>
                                                <span class="badge badge-success"><?php echo __('Опубликована'); ?></span>
                                            <?php elseif ($status === 'pending'): ?>
                                                <span class="badge badge-warning"><?php echo __('В ожидании'); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($canEdit): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm action-edit"><i class="bi bi-pencil me-1"></i><?php echo __('Редактировать'); ?></button>
                                                <button type="button" class="btn btn-outline-danger btn-sm action-remove" data-index="<?php echo (int)$index; ?>"><?php echo __('Удалить'); ?></button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-lock me-1"></i><?php echo __('Редактировать'); ?></button>
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

                <!-- Settings / add and preferences -->
                <div class="card section" id="links-section">
                    <div class="section-header">
                        <div class="label"><i class="bi bi-sliders2"></i><span><?php echo __('Настройки проекта'); ?></span></div>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>

                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-12 col-md-6">
                                <input type="url" name="new_link" class="form-control" placeholder="<?php echo __('Добавить новую ссылку'); ?>">
                            </div>
                            <div class="col-8 col-md-4">
                                <input type="text" name="new_anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>">
                            </div>
                            <div class="col-4 col-md-2 text-end">
                                <button type="button" class="btn btn-outline-success" id="add-link"><i class="bi bi-plus-lg me-1"></i><?php echo __('Добавить'); ?></button>
                            </div>
                        </div>
                        <div id="added-hidden"></div>

                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <label class="form-label"><?php echo __('Язык страницы'); ?></label>
                                <select name="language" class="form-select">
                                    <option value="ru" <?php echo ($project['language'] == 'ru' ? 'selected' : ''); ?>>Русский</option>
                                    <option value="en" <?php echo ($project['language'] == 'en' ? 'selected' : ''); ?>>English</option>
                                    <option value="es" <?php echo ($project['language'] == 'es' ? 'selected' : ''); ?>>Español</option>
                                    <option value="fr" <?php echo ($project['language'] == 'fr' ? 'selected' : ''); ?>>Français</option>
                                    <option value="de" <?php echo ($project['language'] == 'de' ? 'selected' : ''); ?>>Deutsch</option>
                                </select>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label"><?php echo __('Пожелания'); ?></label>
                                <textarea name="wishes" class="form-control" rows="6" placeholder="<?php echo __('Укажите ваши пожелания'); ?>"><?php echo htmlspecialchars($project['wishes'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="sticky-actions text-end mt-3">
                            <button type="submit" name="update_project" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo __('Сохранить изменения'); ?></button>
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

    function makeHidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    // Fix pairing: use explicit index for url+anchor
    addLinkBtn.addEventListener('click', function() {
        const url = newLinkInput.value.trim();
        const anchor = newAnchorInput.value.trim();
        if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
        const idx = addIndex++;
        const wrap = document.createElement('div');
        wrap.className = 'added-pair';
        wrap.id = 'added-' + idx;
        wrap.appendChild(makeHidden('added_links['+idx+'][url]', url));
        wrap.appendChild(makeHidden('added_links['+idx+'][anchor]', anchor));
        addedHidden.appendChild(wrap);
        newLinkInput.value = '';
        newAnchorInput.value = '';
        // Optional: show toast or visual row
    });

    // Inline edit toggle
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
                } else {
                    // Show editors
                    editUrl.classList.remove('d-none');
                    editAnchor.classList.remove('d-none');
                    viewUrl.classList.add('d-none');
                    viewAnchor.classList.add('d-none');
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
        });
    });

    function isValidUrl(string) { try { new URL(string); return true; } catch (_) { return false; } }
});
</script>

<?php include '../includes/footer.php'; ?>