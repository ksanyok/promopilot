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
        // Decode links as array of ['url'=>..., 'anchor'=>...]
        $links = json_decode($project['links'] ?? '[]', true) ?: [];
        if (!is_array($links)) { $links = []; }
        // Normalize legacy entries
        $norm = [];
        foreach ($links as $idx => $item) {
            if (is_string($item)) { $norm[] = ['url' => $item, 'anchor' => '']; }
            elseif (is_array($item)) { $norm[] = ['url' => trim((string)($item['url'] ?? '')), 'anchor' => trim((string)($item['anchor'] ?? ''))]; }
        }
        $links = $norm;

        // Remove links by index
        $remove = $_POST['remove_links'] ?? [];
        if (!is_array($remove)) { $remove = []; }
        $removeIdx = array_map('intval', $remove);
        rsort($removeIdx); // remove from highest index to lowest
        foreach ($removeIdx as $ri) {
            if (isset($links[$ri])) { array_splice($links, $ri, 1); }
        }

        // Add new links from batched hidden inputs
        if (!empty($_POST['added_links']) && is_array($_POST['added_links'])) {
            foreach ($_POST['added_links'] as $row) {
                $url = trim($row['url'] ?? '');
                $anchor = trim($row['anchor'] ?? '');
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[] = ['url' => $url, 'anchor' => $anchor];
                }
            }
        } else {
            // Fallback: single fields if user typed and clicked Save directly
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

// Fetch publication history
$publications = [];
try {
    $conn = connect_db();
    if ($conn) {
        $stmt = $conn->prepare("SELECT id, page_url, anchor, network, published_by, post_url, created_at FROM publications WHERE project_id = ? ORDER BY created_at DESC");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $publications[] = $row; }
            $stmt->close();
        }
        $conn->close();
    }
} catch (Throwable $e) { /* ignore if table missing */ }

$pp_container = false;
$pp_current_project = ['id' => (int)$project['id'], 'name' => $project['name'] ?? ('ID ' . (int)$project['id'])];
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

            <!-- Links section -->
            <div class="card section">
                <div class="section-header">
                    <div class="label"><i class="bi bi-link-45deg"></i><span><?php echo __('Информация о проекте'); ?></span></div>
                    <div class="toolbar">
                        <a href="#links-section" class="btn btn-ghost btn-sm"><i class="bi bi-plus-circle me-1"></i><?php echo __('Добавить'); ?></a>
                    </div>
                </div>
                <div class="card-body">
                    <h6 class="mb-3"><?php echo __('Ссылки'); ?></h6>
                    <?php if (!empty($links)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-sm align-middle table-links">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th><?php echo __('Ссылка'); ?></th>
                                        <th><?php echo __('Анкор'); ?></th>
                                        <th style="width:180px;" class="text-end"><?php echo __('Действия'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($links as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank"><?php echo htmlspecialchars($item['url']); ?></a></td>
                                            <td><?php echo htmlspecialchars($item['anchor']); ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i><?php echo __('Опубликовать'); ?></button>
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

            <!-- Publications history -->
            <div class="card section">
                <div class="section-header">
                    <div class="label"><i class="bi bi-clock-history"></i><span><?php echo __('История публикаций'); ?></span></div>
                </div>
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

            <!-- Settings / manage links & preferences -->
            <div class="card section" id="links-section">
                <div class="section-header">
                    <div class="label"><i class="bi bi-sliders2"></i><span><?php echo __('Настройки проекта'); ?></span></div>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <form method="post" class="form-grid">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="form-label"><?php echo __('Ссылки'); ?></label>
                            <div id="links-list" class="mb-2">
                                <?php foreach ($links as $idx => $item): ?>
                                    <div class="row g-2 align-items-center mb-2" data-index="<?php echo (int)$idx; ?>">
                                        <div class="col-12 col-md-6"><input type="text" class="form-control" value="<?php echo htmlspecialchars($item['url']); ?>" readonly></div>
                                        <div class="col-8 col-md-4"><input type="text" class="form-control" value="<?php echo htmlspecialchars($item['anchor']); ?>" readonly></div>
                                        <div class="col-4 col-md-2 text-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-link" data-index="<?php echo (int)$idx; ?>"><?php echo __('Удалить'); ?></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="row g-2 align-items-center">
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
                            <div class="help mt-2"><?php echo __('Якорный текст будет использован при публикации'); ?>.</div>
                            <div id="added-hidden"></div>
                        </div>

                        <div>
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('Язык страницы'); ?></label>
                                <select name="language" class="form-select">
                                    <option value="ru" <?php echo ($project['language'] == 'ru' ? 'selected' : ''); ?>>Русский</option>
                                    <option value="en" <?php echo ($project['language'] == 'en' ? 'selected' : ''); ?>>English</option>
                                    <option value="es" <?php echo ($project['language'] == 'es' ? 'selected' : ''); ?>>Español</option>
                                    <option value="fr" <?php echo ($project['language'] == 'fr' ? 'selected' : ''); ?>>Français</option>
                                    <option value="de" <?php echo ($project['language'] == 'de' ? 'selected' : ''); ?>>Deutsch</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('Пожелания'); ?></label>
                                <textarea name="wishes" class="form-control" rows="6" placeholder="<?php echo __('Укажите ваши пожелания'); ?>"><?php echo htmlspecialchars($project['wishes'] ?? ''); ?></textarea>
                            </div>
                            <div class="sticky-actions text-end">
                                <button type="submit" name="update_project" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo __('Сохранить изменения'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const linksList = document.getElementById('links-list');
    const addLinkBtn = document.getElementById('add-link');
    const newLinkInput = document.querySelector('input[name="new_link"]');
    const newAnchorInput = document.querySelector('input[name="new_anchor"]');
    const addedHidden = document.getElementById('added-hidden');

    function makeHidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    // Add link (with anchor)
    addLinkBtn.addEventListener('click', function() {
        const url = newLinkInput.value.trim();
        const anchor = newAnchorInput.value.trim();
        if (url && isValidUrl(url)) {
            // Visual preview row
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2';
            row.innerHTML = `
                <div class="col-12 col-md-6"><input type="text" class="form-control" value="${escapeHtml(url)}" readonly></div>
                <div class="col-8 col-md-4"><input type="text" class="form-control" value="${escapeHtml(anchor)}" readonly></div>
                <div class="col-4 col-md-2 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-added"><?php echo __('Удалить'); ?></button>
                </div>
            `;
            // Hidden inputs for server
            const wrap = document.createElement('div');
            wrap.className = 'added-pair';
            wrap.appendChild(makeHidden('added_links[][url]', url));
            wrap.appendChild(makeHidden('added_links[][anchor]', anchor));

            row.dataset.hiddenRefId = 'ref-' + Math.random().toString(36).slice(2);
            wrap.id = row.dataset.hiddenRefId;

            linksList.appendChild(row);
            addedHidden.appendChild(wrap);

            newLinkInput.value = '';
            newAnchorInput.value = '';
        } else {
            alert('<?php echo __('Введите корректный URL'); ?>');
        }
    });

    // Remove existing link by index OR remove just-added row
    linksList.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-link')) {
            const idx = e.target.getAttribute('data-index');
            const row = e.target.closest('.row');
            if (row) row.remove();
            const hidden = makeHidden('remove_links[]', idx);
            // Append hidden to the form
            const form = e.target.closest('form') || document.querySelector('#links-section form');
            if (form) form.appendChild(hidden);
        }
        if (e.target.classList.contains('remove-added')) {
            const row = e.target.closest('.row');
            if (row && row.dataset.hiddenRefId) {
                const wrap = document.getElementById(row.dataset.hiddenRefId);
                if (wrap) wrap.remove();
            }
            if (row) row.remove();
        }
    });

    function isValidUrl(string) {
        try { new URL(string); return true; } catch (_) { return false; }
    }
    function escapeHtml(s){
        return s.replace(/[&<>"]+/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); });
    }
});
</script>

<?php include '../includes/footer.php'; ?>