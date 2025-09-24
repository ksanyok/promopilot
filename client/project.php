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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_info'])) {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $newName = trim($_POST['project_name'] ?? '');
        $newDesc = trim($_POST['project_description'] ?? '');
        $newWishes = trim($_POST['project_wishes'] ?? '');
        if ($newName) {
            $conn = connect_db();
            $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ?, wishes = ? WHERE id = ?");
            $stmt->bind_param('sssi', $newName, $newDesc, $newWishes, $id);
            if ($stmt->execute()) {
                $message = __('Основная информация обновлена.');
                $project['name'] = $newName; $project['description'] = $newDesc; $project['wishes'] = $newWishes;
            } else {
                $message = __('Ошибка сохранения основной информации.');
            }
            $stmt->close(); $conn->close();
        } else {
            $message = __('Название не может быть пустым.');
        }
    }
// Завершаем ветку основной информации
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $links = json_decode($project['links'] ?? '[]', true) ?: [];
        if (!is_array($links)) { $links = []; }
        // Нормализация ссылок теперь учитывает language и wish
        $links = array_map(function($it) use ($project){
            if (is_string($it)) return ['url'=>$it,'anchor'=>'','language'=>$project['language'] ?? 'ru','wish'=>''];
            return [
                'url'=>trim((string)($it['url'] ?? '')),
                'anchor'=>trim((string)($it['anchor'] ?? '')),
                'language'=>trim((string)($it['language'] ?? ($project['language'] ?? 'ru'))),
                'wish'=>trim((string)($it['wish'] ?? ''))
            ];
        }, $links);

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
                $lang = trim($row['language'] ?? $links[$i]['language']);
                $wish = trim($row['wish'] ?? $links[$i]['wish']);
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[$i]['url'] = $url;
                    $links[$i]['anchor'] = $anchor;
                    $links[$i]['language'] = $lang ?: 'ru';
                    $links[$i]['wish'] = $wish;
                }
            }
        }

        // Добавление новых
        if (!empty($_POST['added_links']) && is_array($_POST['added_links'])) {
            foreach ($_POST['added_links'] as $row) {
                if (!is_array($row)) continue;
                $url = trim($row['url'] ?? '');
                $anchor = trim($row['anchor'] ?? '');
                $lang = trim($row['language'] ?? 'ru');
                $wish = trim($row['wish'] ?? '');
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[] = ['url' => $url, 'anchor' => $anchor, 'language'=>$lang ?: 'ru', 'wish'=>$wish];
                }
            }
        } else {
            $new_link = trim($_POST['new_link'] ?? '');
            $new_anchor = trim($_POST['new_anchor'] ?? '');
            $new_language = trim($_POST['new_language'] ?? 'ru');
            $new_wish = trim($_POST['new_wish'] ?? '');
            if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
                $links[] = ['url' => $new_link, 'anchor' => $new_anchor, 'language'=>$new_language ?: 'ru', 'wish'=>$new_wish];
            }
        }

        // Глобальное пожелание проекта
        if (isset($_POST['wishes'])) {
            $wishes = trim($_POST['wishes']);
        } else {
            $wishes = $project['wishes'] ?? '';
        }
        $language = $project['language'] ?? 'ru'; // язык проекта не редактируется здесь

        $conn = connect_db();
        $stmt = $conn->prepare("UPDATE projects SET links = ?, language = ?, wishes = ? WHERE id = ?");
        $links_json = json_encode(array_values($links), JSON_UNESCAPED_UNICODE);
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
$links = array_map(function($it) use ($project){
    if (is_string($it)) return ['url'=>$it,'anchor'=>'','language'=>$project['language'] ?? 'ru','wish'=>''];
    return [
        'url'=>trim((string)($it['url'] ?? '')),
        'anchor'=>trim((string)($it['anchor'] ?? '')),
        'language'=>trim((string)($it['language'] ?? ($project['language'] ?? 'ru'))),
        'wish'=>trim((string)($it['wish'] ?? ''))
    ];
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
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                        <div>
                            <div class="title d-flex align-items-center gap-2">
                                <span><?php echo htmlspecialchars($project['name']); ?></span>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#projectInfoModal" title="<?php echo __('Редактировать основную информацию'); ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <i class="bi bi-info-circle ms-1 text-primary" data-bs-toggle="tooltip" title="<?php echo __('Страница проекта: управляйте ссылками и пожеланиями.'); ?>"></i>
                            </div>
                            <div class="subtitle">@<?php echo htmlspecialchars($project['username']); ?></div>
                            <div class="meta-list">
                                <div class="meta-item"><i class="bi bi-calendar3"></i><span><?php echo __('Дата создания'); ?>: <?php echo htmlspecialchars($project['created_at']); ?></span></div>
                                <div class="meta-item"><i class="bi bi-translate"></i><span><?php echo __('Язык страницы'); ?>: <?php echo htmlspecialchars($project['language'] ?? 'ru'); ?></span></div>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="chip" data-bs-toggle="tooltip" title="<?php echo __('Внутренний идентификатор проекта'); ?>"><i class="bi bi-folder2-open"></i>ID <?php echo (int)$project['id']; ?></span>
                        </div>
                    </div>
                    <?php if (!empty($project['description'])): ?>
                        <div class="mt-3 help">&zwj;<?php echo nl2br(htmlspecialchars($project['description'])); ?></div>
                    <?php else: ?>
                        <div class="mt-3 small text-muted"><i class="bi bi-lightbulb me-1"></i><?php echo __('Добавьте описание проекту для контекстуализации семантики.'); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($project['wishes'])): ?>
                        <div class="mt-2 small text-muted"><i class="bi bi-stars me-1"></i><span class="text-truncate d-inline-block" style="max-width:100%" title="<?php echo htmlspecialchars($project['wishes']); ?>"><?php echo htmlspecialchars(mb_substr($project['wishes'],0,160)); ?><?php echo mb_strlen($project['wishes'])>160?'…':''; ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Modal: Project Info -->
            <div class="modal fade" id="projectInfoModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                  <form method="post" id="project-info-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="update_project_info" value="1" />
                    <div class="modal-header">
                      <h5 class="modal-title"><i class="bi bi-sliders2 me-2"></i><?php echo __('Основная информация проекта'); ?></h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Название'); ?> *</label>
                        <input type="text" name="project_name" class="form-control" value="<?php echo htmlspecialchars($project['name']); ?>" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Описание'); ?></label>
                        <textarea name="project_description" class="form-control" rows="3" placeholder="<?php echo __('Кратко о проекте'); ?>"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                      </div>
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Глобальное пожелание (тон, стиль, ограничения)'); ?></label>
                        <textarea name="project_wishes" class="form-control" rows="5" placeholder="<?php echo __('Стиль, тематика, распределение анкоров, брендовые упоминания...'); ?>"><?php echo htmlspecialchars($project['wishes'] ?? ''); ?></textarea>
                        <div class="form-text"><?php echo __('Используется по умолчанию при добавлении новых ссылок (можно вставить в индивидуальное поле).'); ?></div>
                      </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                      <div class="text-muted small"><i class="bi bi-info-circle me-1"></i><?php echo __('Изменения применяются после сохранения.'); ?></div>
                      <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?php echo __('Сохранить'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <form method="post" id="project-form" class="mb-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="update_project" value="1" />
                <!-- Скрытое глобальное пожелание для синхронизации -->
                <input type="hidden" id="global_wishes" name="wishes" value="<?php echo htmlspecialchars($project['wishes'] ?? ''); ?>" />
                <!-- Добавление ссылки -->
                <div class="card section link-adder-card mb-3">
                    <div class="section-header">
                        <div class="label"><i class="bi bi-link-45deg"></i><span><?php echo __('Добавить ссылку'); ?></span></div>
                        <div class="toolbar">
                            <a href="<?php echo pp_url('client/history.php?id=' . (int)$project['id']); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clock-history me-1"></i><?php echo __('История'); ?></a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 align-items-stretch mb-3">
                            <div class="col-lg-5"><input type="url" name="new_link" class="form-control" placeholder="<?php echo __('URL'); ?> *"></div>
                            <div class="col-lg-3"><input type="text" name="new_anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>"></div>
                            <div class="col-lg-2">
                                <select name="new_language" class="form-select">
                                    <option value="ru">RU</option>
                                    <option value="en">EN</option>
                                    <option value="es">ES</option>
                                    <option value="fr">FR</option>
                                    <option value="de">DE</option>
                                </select>
                            </div>
                            <div class="col-lg-2 d-grid">
                                <button type="button" class="btn btn-gradient w-100" id="add-link"><i class="bi bi-plus-lg me-1"></i><?php echo __('Добавить'); ?></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label mb-1"><?php echo __('Пожелание для этой ссылки'); ?></label>
                            <textarea name="new_wish" id="new_wish" rows="3" class="form-control" placeholder="<?php echo __('Если нужно индивидуальное ТЗ (иначе можно использовать глобальное)'); ?>"></textarea>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="use_global_wish">
                                <label class="form-check-label" for="use_global_wish"><?php echo __('Использовать глобальное пожелание проекта'); ?></label>
                            </div>
                        </div>
                        <div id="added-hidden"></div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-check2-circle me-1"></i><?php echo __('Сохранить изменения'); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Таблица ссылок -->
                <div class="card section table-card" id="links-card">
                    <div class="section-header">
                        <div class="label"><i class="bi bi-list-task"></i><span><?php echo __('Ссылки'); ?></span> <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="<?php echo __('Ссылки можно редактировать и удалять пока не началась публикация. После появления статуса \'В ожидании\' ссылка закрепляется.'); ?>"></i></div>
                        <div class="toolbar">
                            <span class="d-none d-md-inline small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Легенда статусов'); ?>">🟢 <?php echo __('Опубликована'); ?> · 🟡 <?php echo __('В ожидании'); ?> · ⚪ <?php echo __('Не опубликована'); ?></span>
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
                                        <th><?php echo __('Язык'); ?></th>
                                        <th><?php echo __('Пожелание'); ?></th>
                                        <th><?php echo __('Статус'); ?></th>
                                        <th class="text-end" style="width:160px;">&nbsp;</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($links as $index => $item):
                                        $url = $item['url']; $anchor = $item['anchor']; $lang = $item['language'];
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
                                        <td class="language-cell" data-label="<?php echo __('Язык'); ?>">
                                            <span class="badge bg-secondary-subtle text-light-emphasis view-language"><?php echo htmlspecialchars($lang); ?></span>
                                            <select class="form-select form-select-sm d-none edit-language" name="edited_links[<?php echo (int)$index; ?>][language]" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                                <?php foreach (['ru'=>'RU','en'=>'EN','es'=>'ES','fr'=>'FR','de'=>'DE'] as $lv=>$lt): ?>
                                                    <option value="<?php echo $lv; ?>" <?php echo $lv===$lang?'selected':''; ?>><?php echo $lt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="wish-cell" data-label="<?php echo __('Пожелание'); ?>">
                                            <?php $wishPreview = mb_substr($item['wish'] ?? '',0,40); ?>
                                            <div class="view-wish small text-truncate" style="max-width:180px;" title="<?php echo htmlspecialchars($item['wish'] ?? ''); ?>"><?php echo htmlspecialchars($wishPreview); ?><?php echo (isset($item['wish']) && mb_strlen($item['wish'])>40)?'…':''; ?></div>
                                            <textarea class="form-control d-none edit-wish" rows="2" name="edited_links[<?php echo (int)$index; ?>][wish]" <?php echo $canEdit ? '' : 'disabled'; ?>><?php echo htmlspecialchars($item['wish'] ?? ''); ?></textarea>
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
                                            <?php if ($status === 'pending'): ?>
                                                <button type="button" class="btn btn-outline-warning btn-sm me-1 action-cancel" data-url="<?php echo htmlspecialchars($url); ?>" data-index="<?php echo (int)$index; ?>" title="<?php echo __('Отменить публикацию'); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отменить'); ?></span></button>
                                            <?php elseif ($status === 'not_published'): ?>
                                                <button type="button" class="btn btn-sm btn-publish me-1 action-publish" data-url="<?php echo htmlspecialchars($url); ?>" data-index="<?php echo (int)$index; ?>">
                                                    <i class="bi bi-rocket-takeoff"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm me-1" disabled><i class="bi bi-rocket-takeoff me-1"></i><span class="d-none d-lg-inline"><?php echo __('Опубликована'); ?></span></button>
                                            <?php endif; ?>
                                            <?php if ($canEdit): ?>
                                                <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="icon-btn action-remove" data-index="<?php echo (int)$index; ?>" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                                            <?php elseif ($status === 'pending'): ?>
                                                <button type="button" class="icon-btn disabled" disabled title="<?php echo __('Редактировать'); ?>"><i class="bi bi-lock"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="empty-state"><?php echo __('Ссылок нет.'); ?> <span class="d-inline-block ms-1" data-bs-toggle="tooltip" title="<?php echo __('Добавьте первую целевую ссылку выше.'); ?>"><i class="bi bi-info-circle"></i></span></div>
                        <?php endif; ?>
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
    const newLangSelect = form.querySelector('select[name="new_language"]');
    const newWish = form.querySelector('#new_wish');
    const globalWish = document.querySelector('#global_wishes'); // теперь hidden
    const useGlobal = form.querySelector('#use_global_wish');
    const projectInfoForm = document.getElementById('project-info-form');
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
                        <th><?php echo __('Язык'); ?></th>
                        <th><?php echo __('Пожелание'); ?></th>
                        <th><?php echo __('Статус'); ?></th>
                        <th class="text-end" style="width:160px;">&nbsp;</th>
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
        const lang = newLangSelect.value.trim();
        const wish = newWish.value.trim();
        if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
        const idx = addIndex++;

        // Hidden inputs stored separately for submission
        const wrap = document.createElement('div');
        wrap.className = 'added-pair';
        wrap.id = 'added-' + idx;
        wrap.appendChild(makeHidden('added_links['+idx+'][url]', url));
        wrap.appendChild(makeHidden('added_links['+idx+'][anchor]', anchor));
        wrap.appendChild(makeHidden('added_links['+idx+'][language]', lang));
        wrap.appendChild(makeHidden('added_links['+idx+'][wish]', wish));
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
                    <span class="badge bg-secondary-subtle text-light-emphasis">${lang}</span>
                </td>
                <td>
                    <span class="small text-truncate d-inline-block" style="max-width:160px" title="${escapeHtml(wish)}">${escapeHtml(wish.length>40?wish.slice(0,40)+'…':wish)}</span>
                </td>
                <td>
                    <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-publish me-1 action-publish-new" data-url=""><i class="bi bi-rocket-takeoff"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span></button>
                    <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="icon-btn action-remove-new" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                </td>`;
            tbody.appendChild(tr);

            // Attach listeners for this new row
            const editBtn = tr.querySelector('.action-edit');
            const removeBtn = tr.querySelector('.action-remove-new');
            const publishBtn = tr.querySelector('.action-publish-new');
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

            publishBtn.addEventListener('click', function() {
                alert('<?php echo __('Сохраните проект перед публикацией новой ссылки.'); ?>');
            });

            refreshRowNumbers();
        }

        newLinkInput.value = '';
        newAnchorInput.value = '';
        newWish.value = '';
    });

    // Inline edit toggle (existing rows)
    document.querySelectorAll('.action-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            const tr = btn.closest('tr');
            if (!tr) return;
            const urlCell = tr.querySelector('.url-cell');
            const anchorCell = tr.querySelector('.anchor-cell');
            const langCell = tr.querySelector('.language-cell');
            const wishCell = tr.querySelector('.wish-cell');
            const viewUrl = urlCell.querySelector('.view-url');
            const viewAnchor = anchorCell.querySelector('.view-anchor');
            const editUrl = urlCell.querySelector('.edit-url');
            const editAnchor = anchorCell.querySelector('.edit-anchor'); // FIX: раньше искали в urlCell
            const viewLang = langCell ? langCell.querySelector('.view-language') : null;
            const editLang = langCell ? langCell.querySelector('.edit-language') : null;
            const viewWish = wishCell ? wishCell.querySelector('.view-wish') : null;
            const editWish = wishCell ? wishCell.querySelector('.edit-wish') : null;
            if (editUrl && editAnchor) {
                const editing = !editUrl.classList.contains('d-none');
                if (editing) {
                    editUrl.classList.add('d-none');
                    editAnchor.classList.add('d-none');
                    viewUrl.classList.remove('d-none');
                    viewAnchor.classList.remove('d-none');
                    if (editLang) { editLang.classList.add('d-none'); if (viewLang) viewLang.classList.remove('d-none'); }
                    if (editWish) { editWish.classList.add('d-none'); if (viewWish) viewWish.classList.remove('d-none'); }
                    btn.innerHTML = '<i class="bi bi-pencil me-1"></i><?php echo __('Редактировать'); ?>';
                } else {
                    editUrl.classList.remove('d-none');
                    editAnchor.classList.remove('d-none');
                    viewUrl.classList.add('d-none');
                    viewAnchor.classList.add('d-none');
                    if (editLang) { editLang.classList.remove('d-none'); if (viewLang) viewLang.classList.add('d-none'); }
                    if (editWish) { editWish.classList.remove('d-none'); if (viewWish) viewWish.classList.add('d-none'); }
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

    // Заглушка для кнопок публикации
    document.querySelectorAll('.action-publish-new').forEach(btn => {
        btn.addEventListener('click', () => {
            alert('<?php echo __('Сохраните проект перед публикацией новой ссылки.'); ?>');
        });
    });

    if (projectInfoForm) {
        projectInfoForm.addEventListener('submit', () => {
            // При submit модалки значение hidden синхронизируется после перезагрузки страницы сервером
        });
    }
    useGlobal.addEventListener('change', () => {
        if (useGlobal.checked) {
            newWish.value = globalWish.value;
            newWish.setAttribute('readonly','readonly');
            newWish.classList.add('bg-light');
        } else {
            newWish.removeAttribute('readonly');
            newWish.classList.remove('bg-light');
        }
    });
    globalWish.addEventListener('input', () => { if (useGlobal.checked) { newWish.value = globalWish.value; } });

    function isValidUrl(string) { try { new URL(string); return true; } catch (_) { return false; } }

    // escaping helpers for safe HTML/attribute insertion
    function escapeHtml(s){
        return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
    }
    function escapeAttribute(s){
        return s.replace(/["']/g, c => ({'"':'&quot;','\'':'&#39;'}[c]));
    }

    const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
    const PROJECT_ID = <?php echo (int)$project['id']; ?>;

    function setButtonLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (btn.dataset.loadingText || btn.textContent.trim());
        } else {
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
            btn.disabled = false;
        }
    }

    async function sendPublishAction(btn, url, action) {
        if (!csrfTokenInput) { alert('CSRF missing'); return; }
        if (!url) { alert('<?php echo __('Сначала сохраните проект чтобы опубликовать новую ссылку.'); ?>'); return; }
        setButtonLoading(btn, true);
        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfTokenInput.value);
            formData.append('project_id', PROJECT_ID);
            formData.append('url', url);
            formData.append('action', action);
            const res = await fetch('<?php echo pp_url('public/publish_link.php'); ?>', { method: 'POST', body: formData, credentials: 'same-origin' });
            const data = await res.json().catch(()=>({ok:false,error:'BAD_JSON'}));
            if (!data.ok) {
                let msg = data.error || 'ERROR';
                const map = { 'ALREADY_PUBLISHED':'<?php echo __('Уже опубликована'); ?>', 'DB_ERROR':'<?php echo __('Ошибка базы данных'); ?>', 'PROJECT_NOT_FOUND':'<?php echo __('Проект не найден'); ?>', 'FORBIDDEN':'<?php echo __('Нет прав'); ?>', 'NOT_PENDING':'<?php echo __('Не в ожидании'); ?>', 'BAD_ACTION':'<?php echo __('Неверное действие'); ?>'};
                if (map[msg]) msg = map[msg];
                alert('<?php echo __('Ошибка'); ?>: ' + msg);
                return;
            }
            updateRowUI(url, data.status);
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    function updateRowUI(url, status) {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        rows.forEach(tr => {
            const linkEl = tr.querySelector('.url-cell .view-url');
            if (!linkEl) return;
            if (linkEl.getAttribute('href') === url) {
                const statusCell = tr.querySelector('td:nth-child(6)'); // status column
                const actionsCell = tr.querySelector('td.text-end');
                if (statusCell) {
                    if (status === 'pending') {
                        statusCell.innerHTML = '<span class="badge badge-warning"><?php echo __('В ожидании'); ?></span>';
                    } else if (status === 'not_published') {
                        statusCell.innerHTML = '<span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>';
                    }
                }
                if (actionsCell) {
                    if (status === 'pending') {
                        actionsCell.querySelectorAll('.action-edit,.action-remove').forEach(b=>{ b.disabled = true; b.classList.add('disabled'); });
                        const pubBtn = actionsCell.querySelector('.action-publish');
                        if (pubBtn) {
                            pubBtn.outerHTML = '<button type="button" class="btn btn-outline-warning btn-sm me-1 action-cancel" data-url="'+escapeHtml(url)+'" title="<?php echo __('Отменить публикацию'); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отменить'); ?></span></button>' + actionsCell.innerHTML;
                        }
                    } else if (status === 'not_published') {
                        const cancelBtn = actionsCell.querySelector('.action-cancel');
                        if (cancelBtn) {
                            cancelBtn.outerHTML = '<button type="button" class="btn btn-sm btn-publish me-1 action-publish" data-url="'+escapeHtml(url)+'"><i class="bi bi-rocket-takeoff"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span></button>';
                        }
                        actionsCell.querySelectorAll('.action-edit,.action-remove').forEach(b=>{ b.disabled = false; b.classList.remove('disabled'); });
                    }
                    // Re-bind handlers after DOM changes
                    bindDynamicPublishButtons();
                }
            }
        });
    }

    function bindDynamicPublishButtons() {
        document.querySelectorAll('.action-publish').forEach(btn => {
            if (btn.dataset.bound==='1') return;
            btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                sendPublishAction(btn, url, 'publish');
            });
        });
        document.querySelectorAll('.action-cancel').forEach(btn => {
            if (btn.dataset.bound==='1') return;
            btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                if (!confirm('<?php echo __('Отменить публикацию ссылки?'); ?>')) return;
                sendPublishAction(btn, url, 'cancel');
            });
        });
        document.querySelectorAll('.action-publish-new').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => { alert('<?php echo __('Сохраните проект перед публикацией новой ссылки.'); ?>'); });
        });
    }

    // Initial bind
    bindDynamicPublishButtons();
});
</script>

<?php include '../includes/footer.php'; ?>