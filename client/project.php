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
$stmt->close();

// Build taxonomy (regions/topics) from enabled networks for selectors
$taxonomy = pp_get_network_taxonomy(true);
$availableRegions = $taxonomy['regions'] ?? [];
$availableTopics  = $taxonomy['topics'] ?? [];
if (empty($availableRegions)) { $availableRegions = ['Global']; }
if (empty($availableTopics))  { $availableTopics  = ['General']; }

// Define extended language codes for link operations/UI
$pp_lang_codes = [
    'ru','en','uk','de','fr','es','it','pt','pt-br','pl','tr','nl','cs','sk','bg','ro','el','hu','sv','da','no','fi','et','lv','lt','ka','az','kk','uz','sr','sl','hr','he','ar','fa','hi','id','ms','vi','th','zh','zh-cn','zh-tw','ja','ko'
];

// Load links from normalized table
$links = [];
if ($pl = $conn->prepare('SELECT id, url, anchor, language, wish FROM project_links WHERE project_id = ? ORDER BY id ASC')) {
    $pl->bind_param('i', $id);
    $pl->execute();
    $res = $pl->get_result();
    while ($row = $res->fetch_assoc()) {
        $links[] = [
            'id' => (int)$row['id'],
            'url' => (string)$row['url'],
            'anchor' => (string)$row['anchor'],
            'language' => (string)($row['language'] ?? ($project['language'] ?? 'ru')),
            'wish' => (string)($row['wish'] ?? ''),
        ];
    }
    $pl->close();
}
$conn->close();

// Helper: normalize host (strip www)
$pp_normalize_host = function(?string $host): string {
    $h = strtolower(trim((string)$host));
    if (strpos($h, 'www.') === 0) { $h = substr($h, 4); }
    return $h;
};

// Проверить доступ: админ или владелец
if (!is_admin() && $project['user_id'] != $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

// Определяем, является ли запрос AJAX (для автосохранения при добавлении)
$pp_is_ajax = (
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
    (isset($_POST['ajax']) && $_POST['ajax'] == '1') ||
    (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

// Обработка формы
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_info'])) {
    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        $newName = trim($_POST['project_name'] ?? '');
        $newDesc = trim($_POST['project_description'] ?? '');
        $newWishes = trim($_POST['project_wishes'] ?? '');
        // New: allow changing project language from modal
        $allowedLangs = ['ru','en','es','fr','de'];
        $newLang = trim($_POST['project_language'] ?? ($project['language'] ?? 'ru'));
        if (!in_array($newLang, $allowedLangs, true)) { $newLang = $project['language'] ?? 'ru'; }
        // New: region/topic from taxonomy
        $newRegion = trim((string)($_POST['project_region'] ?? ($project['region'] ?? '')));
        $newTopic  = trim((string)($_POST['project_topic']  ?? ($project['topic']  ?? '')));
        if (!in_array($newRegion, $availableRegions, true)) { $newRegion = $project['region'] ?? ($availableRegions[0] ?? ''); }
        if (!in_array($newTopic,  $availableTopics,  true)) { $newTopic  = $project['topic']  ?? ($availableTopics[0]  ?? ''); }
        if ($newName) {
            $conn = connect_db();
            // include language, region, topic in update
            $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ?, wishes = ?, language = ?, region = ?, topic = ? WHERE id = ?");
            $stmt->bind_param('ssssssi', $newName, $newDesc, $newWishes, $newLang, $newRegion, $newTopic, $id);
            if ($stmt->execute()) {
                $message = __('Основная информация обновлена.');
                $project['name'] = $newName; $project['description'] = $newDesc; $project['wishes'] = $newWishes; $project['language'] = $newLang; $project['region'] = $newRegion; $project['topic'] = $newTopic;
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
    $pp_update_ok = false;
    $pp_domain_errors = 0;
    $pp_domain_set = '';

    if (!verify_csrf()) {
        $message = __('Ошибка обновления.') . ' (CSRF)';
    } else {
        // Language validator and defaults
        $pp_is_valid_lang = function($code): bool {
            $code = trim((string)$code);
            if ($code === '') return false;
            if (strlen($code) > 10) return false;
            return (bool)preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $code);
        };
        $defaultLang = strtolower((string)($project['language'] ?? 'ru')) ?: 'ru';

        // Enforce single-domain policy
        $projectHost = $pp_normalize_host($project['domain_host'] ?? '');
        $domainToSet = '';
        $domainErrors = 0;

        $conn = connect_db();

        // Удаление ссылок по ID
        $removeIds = array_map('intval', (array)($_POST['remove_links'] ?? []));
        $removeIds = array_values(array_filter($removeIds, fn($v) => $v > 0));
        if (!empty($removeIds)) {
            $ph = implode(',', array_fill(0, count($removeIds), '?'));
            $types = str_repeat('i', count($removeIds) + 1);
            $sql = "DELETE FROM project_links WHERE project_id = ? AND id IN ($ph)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $params = array_merge([$id], $removeIds);
                $stmt->bind_param($types, ...$params);
                @$stmt->execute();
                $stmt->close();
            }
        }

        // Редактирование существующих ссылок по ID
        if (!empty($_POST['edited_links']) && is_array($_POST['edited_links'])) {
            foreach ($_POST['edited_links'] as $lid => $row) {
                $linkId = (int)$lid;
                if ($linkId <= 0) continue;
                $url = trim((string)($row['url'] ?? ''));
                $anchor = trim((string)($row['anchor'] ?? ''));
                $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                $wish = trim((string)($row['wish'] ?? ''));
                if ($lang === 'auto' || !$pp_is_valid_lang($lang)) { $lang = $defaultLang; }
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $uHost = $pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                    if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                        $domainErrors++;
                        continue;
                    }
                    // Update row
                    $st = $conn->prepare('UPDATE project_links SET url = ?, anchor = ?, language = ?, wish = ? WHERE id = ? AND project_id = ?');
                    if ($st) {
                        $st->bind_param('ssssii', $url, $anchor, $lang, $wish, $linkId, $id);
                        @$st->execute();
                        $st->close();
                    }
                    if ($projectHost === '' && $uHost !== '') { $domainToSet = $uHost; $projectHost = $uHost; }
                }
            }
        }

        // Добавление новых ссылок
        $newLinkPayload = null; // to return to client
        if (!empty($_POST['added_links']) && is_array($_POST['added_links'])) {
            foreach ($_POST['added_links'] as $row) {
                if (!is_array($row)) continue;
                $url = trim((string)($row['url'] ?? ''));
                $anchor = trim((string)($row['anchor'] ?? ''));
                $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                $wish = trim((string)($row['wish'] ?? ''));
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    $uHost = $pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                    if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                    if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                        $domainErrors++;
                        continue;
                    }
                    // Auto-detect language from page meta/hreflang if requested or invalid
                    $meta = null;
                    if ($lang === 'auto' || !$pp_is_valid_lang($lang)) {
                        if (function_exists('pp_analyze_url_data')) {
                            try { $meta = pp_analyze_url_data($url); } catch (Throwable $e) { $meta = null; }
                            $detected = '';
                            if (is_array($meta)) {
                                $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                    // Prefer hreflang matching project language, otherwise first
                                    foreach ($meta['hreflang'] as $hl) {
                                        $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                        if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                    }
                                    if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                        $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                    }
                                }
                            }
                            if ($detected !== '' && $pp_is_valid_lang($detected)) { $lang = $detected; }
                            else { $lang = $defaultLang; }
                        } else {
                            $lang = $defaultLang;
                        }
                    }
                    $lang = substr($lang, 0, 10);

                    $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                    if ($ins) {
                        $ins->bind_param('issss', $id, $url, $anchor, $lang, $wish);
                        if (@$ins->execute()) {
                            $newId = (int)$conn->insert_id;
                            $newLinkPayload = ['id'=>$newId,'url'=>$url,'anchor'=>$anchor,'language'=>$lang,'wish'=>$wish];
                            // Analyze and save microdata best-effort (reuse meta if available)
                            try {
                                if (function_exists('pp_save_page_meta')) {
                                    if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($url); }
                                    if (is_array($meta)) { @pp_save_page_meta($id, $url, $meta); }
                                }
                            } catch (Throwable $e) { /* ignore */ }
                        }
                        $ins->close();
                    }
                }
            }
        } else {
            // legacy single add fields
            $new_link = trim($_POST['new_link'] ?? '');
            $new_anchor = trim($_POST['new_anchor'] ?? '');
            $new_language = strtolower(trim($_POST['new_language'] ?? $defaultLang));
            $new_wish = trim($_POST['new_wish'] ?? '');
            if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
                $uHost = $pp_normalize_host(parse_url($new_link, PHP_URL_HOST) ?: '');
                if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                    $domainErrors++;
                } else {
                    // Auto-detect if needed
                    $meta = null;
                    if ($new_language === 'auto' || !$pp_is_valid_lang($new_language)) {
                        if (function_exists('pp_analyze_url_data')) {
                            try { $meta = pp_analyze_url_data($new_link); } catch (Throwable $e) { $meta = null; }
                            $detected = '';
                            if (is_array($meta)) {
                                $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                    foreach ($meta['hreflang'] as $hl) {
                                        $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                        if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                    }
                                    if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                        $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                    }
                                }
                            }
                            if ($detected !== '' && $pp_is_valid_lang($detected)) { $new_language = $detected; }
                            else { $new_language = $defaultLang; }
                        } else {
                            $new_language = $defaultLang;
                        }
                    }
                    $new_language = substr($new_language, 0, 10);

                    $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                    if ($ins) {
                        $ins->bind_param('issss', $id, $new_link, $new_anchor, $new_language, $new_wish);
                        if (@$ins->execute()) {
                            $newId = (int)$conn->insert_id;
                            $newLinkPayload = ['id'=>$newId,'url'=>$new_link,'anchor'=>$new_anchor,'language'=>$new_language,'wish'=>$new_wish];
                            try {
                                if (function_exists('pp_save_page_meta')) {
                                    if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($new_link); }
                                    if (is_array($meta)) { @pp_save_page_meta($id, $new_link, $meta); }
                                }
                            } catch (Throwable $e) { /* ignore */ }
                        }
                        $ins->close();
                    }
                }
            }
        }

        // Глобальное пожелание проекта
        if (isset($_POST['wishes'])) {
            $wishes = trim((string)$_POST['wishes']);
        } else {
            $wishes = (string)($project['wishes'] ?? '');
        }
        // язык проекта не редактируется здесь
        $language = $project['language'] ?? 'ru';

        // Apply project updates
        if ($domainToSet !== '') {
            $stmt = $conn->prepare("UPDATE projects SET wishes = ?, language = ?, domain_host = ? WHERE id = ?");
            $stmt->bind_param('sssi', $wishes, $language, $domainToSet, $id);
            $project['domain_host'] = $domainToSet;
        } else {
            $stmt = $conn->prepare("UPDATE projects SET wishes = ?, language = ? WHERE id = ?");
            $stmt->bind_param('ssi', $wishes, $language, $id);
        }
        if ($stmt) {
            if ($stmt->execute()) {
                $pp_update_ok = true;
                $message = __('Проект обновлен.');
                if ($domainErrors > 0) { $message .= ' ' . sprintf(__('Отклонено ссылок с другим доменом: %d.'), $domainErrors); }
                $project['language'] = $language;
                $project['wishes'] = $wishes;
            } else {
                $message = __('Ошибка обновления проекта.');
            }
            $stmt->close();
        }

        // Count links after operations
        $linksCount = 0;
        if ($cst = $conn->prepare('SELECT COUNT(*) FROM project_links WHERE project_id = ?')) {
            $cst->bind_param('i', $id);
            $cst->execute();
            $cst->bind_result($linksCount);
            $cst->fetch();
            $cst->close();
        }

        $conn->close();

        // Ответ для AJAX
        if ($pp_is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => (bool)$pp_update_ok,
                'message' => (string)$message,
                'domain_errors' => (int)$domainErrors,
                'domain_host' => (string)($project['domain_host'] ?? ''),
                'links_count' => (int)$linksCount,
                'new_link' => $newLinkPayload,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// Получить статусы публикаций по URL
$pubStatusByUrl = [];
try {
    $conn = connect_db();
    if ($conn) {
        $stmt = $conn->prepare("SELECT page_url, post_url, network, status FROM publications WHERE project_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $url = (string)$row['page_url'];
                $postUrl = (string)($row['post_url'] ?? '');
                $st = trim((string)($row['status'] ?? ''));
                $status = 'not_published';
                if ($postUrl !== '' || $st === 'success') { $status = 'published'; }
                elseif ($st === 'failed' || $st === 'cancelled') { $status = 'not_published'; }
                elseif ($st === 'queued' || $st === 'running') { $status = 'pending'; }
                $info = [ 'status' => $status, 'post_url' => $postUrl, 'network' => trim((string)($row['network'] ?? '')), ];
                if (!isset($pubStatusByUrl[$url])) {
                    $pubStatusByUrl[$url] = $info;
                } elseif ($status === 'published') {
                    $pubStatusByUrl[$url] = $info;
                }
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
            <?php if (!empty($message)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
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
                                <?php if (!empty($project['region'])): ?>
                                  <div class="meta-item"><i class="bi bi-geo-alt"></i><span><?php echo __('Регион'); ?>: <?php echo htmlspecialchars($project['region']); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($project['topic'])): ?>
                                  <div class="meta-item"><i class="bi bi-tags"></i><span><?php echo __('Тематика'); ?>: <?php echo htmlspecialchars($project['topic']); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($project['domain_host'])): ?>
                                <div class="meta-item"><i class="bi bi-globe2"></i><span><?php echo __('Домен'); ?>: <?php echo htmlspecialchars($project['domain_host']); ?></span></div>
                                <?php else: ?>
                                <div class="meta-item"><i class="bi bi-globe2"></i><span class="text-warning"><?php echo __('Домен будет зафиксирован по первой добавленной ссылке.'); ?></span></div>
                                <?php endif; ?>
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
            <div class="modal fade modal-fixed-center" id="projectInfoModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
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
                      <!-- New: language selector -->
                      <div class="mb-3">
                        <label class="form-label"><?php echo __('Язык страницы'); ?></label>
                        <select name="project_language" class="form-select">
                          <?php foreach (['ru'=>'RU','en'=>'EN','es'=>'ES','fr'=>'FR','de'=>'DE'] as $lv=>$lt): ?>
                            <option value="<?php echo $lv; ?>" <?php echo ($project['language'] ?? 'ru')===$lv?'selected':''; ?>><?php echo $lt; ?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?php echo __('Влияет на язык по умолчанию для новых ссылок.'); ?></div>
                      </div>
                      <!-- New: region/topic selectors -->
                      <div class="row g-3 mb-3">
                        <div class="col-md-6">
                          <label class="form-label"><?php echo __('Регион проекта'); ?></label>
                          <select name="project_region" class="form-select">
                            <?php $curR = (string)($project['region'] ?? ''); foreach ($availableRegions as $r): ?>
                              <option value="<?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo ($curR===$r?'selected':''); ?>><?php echo htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label"><?php echo __('Тематика проекта'); ?></label>
                          <select name="project_topic" class="form-select">
                            <?php $curT = (string)($project['topic'] ?? ''); foreach ($availableTopics as $t): ?>
                              <option value="<?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo ($curT===$t?'selected':''); ?>><?php echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
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
                            <div class="col-lg-5"><input type="url" name="new_link" class="form-control" placeholder="<?php echo !empty($project['domain_host']) ? htmlspecialchars('https://' . $project['domain_host'] . '/...') : __('URL'); ?> *"></div>
                            <div class="col-lg-3"><input type="text" name="new_anchor" class="form-control" placeholder="<?php echo __('Анкор'); ?>"></div>
                            <div class="col-lg-2">
                                <select name="new_language" class="form-select">
                                    <?php $opts = array_merge(['auto'], $pp_lang_codes); $def = 'auto'; foreach ($opts as $l): ?>
                                        <option value="<?php echo htmlspecialchars($l); ?>" <?php echo ($def===$l?'selected':''); ?>><?php echo strtoupper($l); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 d-grid">
                                <button type="button" id="add-link" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i><?php echo __('Добавить'); ?></button>
                            </div>
                        </div>
                        <?php if (!empty($project['domain_host'])): ?>
                        <div class="small text-muted mb-3" id="domain-hint"><i class="bi bi-shield-lock me-1"></i><?php echo __('Добавлять ссылки можно только в рамках домена проекта'); ?>: <code id="domain-host-code"><?php echo htmlspecialchars($project['domain_host']); ?></code></div>
                        <?php else: ?>
                        <div class="small text-muted mb-3" id="domain-hint" style="display:none"><i class="bi bi-shield-lock me-1"></i><?php echo __('Добавлять ссылки можно только в рамках домена проекта'); ?>: <code id="domain-host-code"></code></div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label mb-1"><?php echo __('Пожелание для этой ссылки'); ?></label>
                            <textarea name="new_wish" id="new_wish" rows="3" class="form-control" placeholder="<?php echo __('Если нужно индивидуальное ТЗ (иначе можно использовать глобальное)'); ?>"></textarea>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="use_global_wish">
                                <label class="form-check-label" for="use_global_wish"><?php echo __('Использовать глобальное пожелание проекта'); ?></label>
                            </div>
                        </div>
                        <div id="added-hidden"></div>
                        <!-- Кнопка сохранения больше не нужна: автосохранение при добавлении -->
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
                                        <th class="text-end" style="width:200px;">&nbsp;</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($links as $index => $item):
                                        $linkId = (int)$item['id'];
                                        $url = $item['url'];
                                        $anchor = $item['anchor'];
                                        $lang = $item['language'];
                                        $pubInfo = $pubStatusByUrl[$url] ?? null;
                                        if (is_array($pubInfo)) {
                                            $status = $pubInfo['status'] ?? 'not_published';
                                            $postUrl = $pubInfo['post_url'] ?? '';
                                            $networkSlug = $pubInfo['network'] ?? '';
                                        } else {
                                            $status = is_string($pubInfo) ? $pubInfo : 'not_published';
                                            $postUrl = '';
                                            $networkSlug = '';
                                        }
                                        $canEdit = ($status === 'not_published');
                                        $pu = @parse_url($url);
                                        $hostDisp = $pp_normalize_host($pu['host'] ?? '');
                                        $pathDisp = (string)($pu['path'] ?? '/');
                                        if (!empty($pu['query'])) { $pathDisp .= '?' . $pu['query']; }
                                        if (!empty($pu['fragment'])) { $pathDisp .= '#' . $pu['fragment']; }
                                        if ($pathDisp === '') { $pathDisp = '/'; }
                                    ?>
                                    <tr data-id="<?php echo (int)$linkId; ?>" data-index="<?php echo (int)$index; ?>" data-post-url="<?php echo htmlspecialchars($postUrl); ?>" data-network="<?php echo htmlspecialchars($networkSlug); ?>">
                                        <td data-label="#"><?php echo $index + 1; ?></td>
                                        <td class="url-cell" data-label="<?php echo __('Ссылка'); ?>">
                                            <div class="small text-muted host-muted"><i class="bi bi-globe2 me-1"></i><?php echo htmlspecialchars($hostDisp); ?></div>
                                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="view-url text-truncate-path" title="<?php echo htmlspecialchars($url); ?>" data-bs-toggle="tooltip"><?php echo htmlspecialchars($pathDisp); ?></a>
                                            <input type="url" class="form-control d-none edit-url" name="edited_links[<?php echo (int)$linkId; ?>][url]" value="<?php echo htmlspecialchars($url); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                        </td>
                                        <td class="anchor-cell" data-label="<?php echo __('Анкор'); ?>">
                                            <span class="view-anchor text-truncate-anchor" title="<?php echo htmlspecialchars($anchor); ?>" data-bs-toggle="tooltip"><?php echo htmlspecialchars($anchor); ?></span>
                                            <input type="text" class="form-control d-none edit-anchor" name="edited_links[<?php echo (int)$linkId; ?>][anchor]" value="<?php echo htmlspecialchars($anchor); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> />
                                        </td>
                                        <td class="language-cell" data-label="<?php echo __('Язык'); ?>">
                                            <span class="badge bg-secondary-subtle text-light-emphasis view-language text-uppercase"><?php echo htmlspecialchars($lang); ?></span>
                                            <select class="form-select form-select-sm d-none edit-language" name="edited_links[<?php echo (int)$linkId; ?>][language]" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                                <?php foreach (array_merge(['auto'], $pp_lang_codes) as $lv): ?>
                                                    <option value="<?php echo htmlspecialchars($lv); ?>" <?php echo $lv===$lang?'selected':''; ?>><?php echo strtoupper($lv); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="wish-cell" data-label="<?php echo __('Пожелание'); ?>">
                                            <?php $fullWish = (string)($item['wish'] ?? ''); ?>
                                            <button type="button" class="icon-btn action-show-wish" data-wish="<?php echo htmlspecialchars($fullWish); ?>" title="<?php echo __('Показать пожелание'); ?>" data-bs-toggle="tooltip"><i class="bi bi-journal-text"></i></button>
                                            <div class="view-wish d-none"><?php echo htmlspecialchars($fullWish); ?></div>
                                            <textarea class="form-control d-none edit-wish" rows="2" name="edited_links[<?php echo (int)$linkId; ?>][wish]" <?php echo $canEdit ? '' : 'disabled'; ?>><?php echo htmlspecialchars($fullWish); ?></textarea>
                                        </td>
                                        <td data-label="<?php echo __('Статус'); ?>" class="status-cell">
                                            <?php if ($status === 'published'): ?>
                                                <span class="badge badge-success"><?php echo __('Опубликована'); ?></span>
                                                <?php if (!empty($postUrl)): ?>
                                                    <div class="small mt-1"><a href="<?php echo htmlspecialchars($postUrl); ?>" target="_blank" rel="noopener"><?php echo __('Открыть материал'); ?></a></div>
                                                <?php endif; ?>
                                                <?php if (!empty($networkSlug)): ?>
                                                    <div class="text-muted small"><?php echo __('Сеть'); ?>: <?php echo htmlspecialchars($networkSlug); ?></div>
                                                <?php endif; ?>
                                            <?php elseif ($status === 'pending'): ?>
                                                <span class="badge badge-warning"><?php echo __('В ожидании'); ?></span>
                                                <?php if (!empty($networkSlug)): ?>
                                                    <div class="text-muted small"><?php echo __('Сеть'); ?>: <?php echo htmlspecialchars($networkSlug); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end" data-label="<?php echo __('Действия'); ?>">
                                            <button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                                            <?php if ($status === 'pending'): ?>
                                                <button type="button" class="btn btn-outline-warning btn-sm me-1 action-cancel" data-url="<?php echo htmlspecialchars($url); ?>" data-id="<?php echo (int)$linkId; ?>" title="<?php echo __('Отменить публикацию'); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отменить'); ?></span></button>
                                            <?php elseif ($status === 'not_published'): ?>
                                                <button type="button" class="btn btn-sm btn-publish me-1 action-publish" data-url="<?php echo htmlspecialchars($url); ?>" data-id="<?php echo (int)$linkId; ?>">
                                                    <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span>
                                                </button>
                                            <?php else: ?>
                                                <?php if (!empty($postUrl)): ?>
                                                    <a href="<?php echo htmlspecialchars($postUrl); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-box-arrow-up-right me-1"></i><span class="d-none d-lg-inline"><?php echo __('Открыть'); ?></span></a>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm me-1" disabled><i class="bi bi-rocket-takeoff me-1"></i><span class="d-none d-lg-inline"><?php echo __('Опубликована'); ?></span></button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($canEdit): ?>
                                                <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="icon-btn action-remove" data-id="<?php echo (int)$linkId; ?>" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
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

<!-- Analyze Modal -->
<div class="modal fade modal-fixed-center" id="analyzeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-search me-2"></i><?php echo __('Анализ страницы'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="analyze-loading" class="text-center py-3 d-none">
          <div class="spinner-border" role="status"></div>
          <div class="mt-2 small text-muted"><?php echo __('Идет анализ...'); ?></div>
        </div>
        <div id="analyze-result" class="d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
      </div>
    </div>
  </div>
</div>

<!-- Wish Modal (показ полного текста пожелания) -->
<div class="modal fade modal-fixed-center" id="wishModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-journal-text me-2"></i><?php echo __('Пожелание'); ?></h5>
        <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="wishCopyBtn"><i class="bi bi-clipboard"></i></button>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="wishContent" class="small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
      </div>
    </div>
  </div>
</div>

<script>
// Initialize Bootstrap tooltips
(function(){
    if (window.bootstrap) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) { try { new bootstrap.Tooltip(tooltipTriggerEl); } catch(e){} });
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    // Move modals to body
    const projectInfoModalEl = document.getElementById('projectInfoModal');
    if (projectInfoModalEl && projectInfoModalEl.parentElement !== document.body) { document.body.appendChild(projectInfoModalEl); }
    const analyzeModalEl = document.getElementById('analyzeModal');
    if (analyzeModalEl && analyzeModalEl.parentElement !== document.body) { document.body.appendChild(analyzeModalEl); }
    // Ensure wish modal is attached to body
    const wishModalEl = document.getElementById('wishModal');
    if (wishModalEl && wishModalEl.parentElement !== document.body) { document.body.appendChild(wishModalEl); }

    const form = document.getElementById('project-form');
    const addLinkBtn = document.getElementById('add-link');
    const addedHidden = document.getElementById('added-hidden');
    const newLinkInput = form.querySelector('input[name="new_link"]');
    const newAnchorInput = form.querySelector('input[name="new_anchor"]');
    const newLangSelect = form.querySelector('select[name="new_language"]');
    const newWish = form.querySelector('#new_wish');
    const globalWish = document.querySelector('#global_wishes');
    const useGlobal = form.querySelector('#use_global_wish');
    const projectInfoForm = document.getElementById('project-info-form');
    let addIndex = 0;

    const PROJECT_ID = <?php echo (int)$project['id']; ?>;
    const PROJECT_HOST = '<?php echo htmlspecialchars($pp_normalize_host($project['domain_host'] ?? '')); ?>';
    let CURRENT_PROJECT_HOST = PROJECT_HOST;

    // New: references for links table (may not exist initially)
    let linksTable = document.querySelector('.table-links');
    let linksTbody = linksTable ? linksTable.querySelector('tbody') : null;

    // Expose server language codes list to JS
    const LANG_CODES = <?php echo json_encode(array_values($pp_lang_codes)); ?>;

    // Helper: apply project host coming from server and update UI hints/placeholders
    function applyProjectHost(host) {
        const normalized = String(host || '').toLowerCase().replace(/^www\./,'');
        CURRENT_PROJECT_HOST = normalized;
        const hint = document.getElementById('domain-hint');
        const hostCode = document.getElementById('domain-host-code');
        if (hostCode) hostCode.textContent = normalized;
        if (hint) hint.style.display = normalized ? '' : 'none';
        if (newLinkInput && normalized) newLinkInput.setAttribute('placeholder', 'https://' + normalized + '/...');
    }

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
        const empty = document.querySelector('#links-card .card-body .empty-state');
        const cardBody = document.querySelector('#links-card .card-body');
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
                        <th class="text-end" style="width:200px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>`;
        if (empty) empty.replaceWith(wrapper); else cardBody.prepend(wrapper);
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

    // Update helper: reflect edited values into the row UI
    function updateRowView(tr, url, anchor, lang, wish) {
        const urlCell = tr.querySelector('.url-cell');
        const anchorCell = tr.querySelector('.anchor-cell');
        const langCell = tr.querySelector('.language-cell');
        const wishCell = tr.querySelector('.wish-cell');
        // URL
        if (urlCell) {
            const a = urlCell.querySelector('.view-url');
            const hostMuted = urlCell.querySelector('.host-muted');
            const editUrl = urlCell.querySelector('.edit-url');
            if (a) {
                a.setAttribute('href', url);
                a.setAttribute('title', url);
                a.textContent = pathFromUrl(url);
            }
            if (hostMuted) hostMuted.innerHTML = '<i class="bi bi-globe2 me-1"></i>' + escapeHtml(hostFromUrl(url));
            if (editUrl) editUrl.value = url;
            const pubBtn = tr.querySelector('.action-publish');
            if (pubBtn) { pubBtn.setAttribute('data-url', url); }
        }
        // Anchor
        if (anchorCell) {
            const viewAnchor = anchorCell.querySelector('.view-anchor');
            const editAnchor = anchorCell.querySelector('.edit-anchor');
            if (viewAnchor) { viewAnchor.textContent = anchor; viewAnchor.setAttribute('title', anchor); }
            if (editAnchor) editAnchor.value = anchor;
        }
        // Language
        if (langCell) {
            const viewLang = langCell.querySelector('.view-language');
            const editLang = langCell.querySelector('.edit-language');
            if (viewLang) viewLang.textContent = (lang || '').toUpperCase();
            if (editLang) editLang.value = lang || '';
        }
        // Wish
        if (wishCell) {
            const viewWish = wishCell.querySelector('.view-wish');
            const editWish = wishCell.querySelector('.edit-wish');
            const showBtn = wishCell.querySelector('.action-show-wish');
            if (viewWish) viewWish.textContent = wish || '';
            if (editWish) editWish.value = wish || '';
            if (showBtn) showBtn.setAttribute('data-wish', wish || '');
        }
        initTooltips(tr);
    }

    async function saveRowEdit(tr, btn) {
        const id = parseInt(tr.getAttribute('data-id'), 10);
        if (Number.isNaN(id) || id <= 0) { alert('<?php echo __('Невозможно сохранить: идентификатор ссылки не определен. Обновите страницу.'); ?>'); return false; }
        const url = tr.querySelector('.url-cell .edit-url')?.value?.trim() || '';
        const anchor = tr.querySelector('.anchor-cell .edit-anchor')?.value?.trim() || '';
        const lang = tr.querySelector('.language-cell .edit-language')?.value?.trim() || '';
        const wish = tr.querySelector('.wish-cell .edit-wish')?.value?.trim() || '';
        if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return false; }
        // Enforce domain on client
        try { const h = new URL(url).hostname.toLowerCase().replace(/^www\./,''); if (CURRENT_PROJECT_HOST && h !== CURRENT_PROJECT_HOST) { alert('<?php echo __('Ссылка должна быть в рамках домена проекта'); ?>: ' + CURRENT_PROJECT_HOST); return false; } } catch(e){}
        setButtonLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('update_project', '1');
            fd.append('ajax', '1');
            fd.append('wishes', globalWish.value || '');
            fd.append(`edited_links[${id}][url]`, url);
            fd.append(`edited_links[${id}][anchor]`, anchor);
            fd.append(`edited_links[${id}][language]`, lang);
            fd.append(`edited_links[${id}][wish]`, wish);
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.ok) { alert('<?php echo __('Ошибка'); ?>: ' + (data && data.message ? data.message : 'ERROR')); return false; }
            // If server fixed project domain on first edit, reflect it in UI
            if (data.domain_host) { applyProjectHost(data.domain_host); }
            if (data.domain_errors && Number(data.domain_errors) > 0) {
                alert('<?php echo __('Отклонено ссылок с другим доменом'); ?>: ' + data.domain_errors);
            }
            updateRowView(tr, url, anchor, lang, wish);
            return true;
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
            return false;
        } finally {
            setButtonLoading(btn, false);
        }
    }

    function toggleRowEdit(tr, editing) {
        const urlCell = tr.querySelector('.url-cell');
        const anchorCell = tr.querySelector('.anchor-cell');
        const langCell = tr.querySelector('.language-cell');
        const wishCell = tr.querySelector('.wish-cell');
        const viewUrl = urlCell?.querySelector('.view-url');
        const viewAnchor = anchorCell?.querySelector('.view-anchor');
        const editUrl = urlCell?.querySelector('.edit-url');
        const editAnchor = anchorCell?.querySelector('.edit-anchor');
        const viewLang = langCell?.querySelector('.view-language');
        const editLang = langCell?.querySelector('.edit-language');
        const viewWish = wishCell?.querySelector('.view-wish');
        const editWish = wishCell?.querySelector('.edit-wish');
        if (!editUrl || !editAnchor) return;
        if (editing) {
            editUrl.classList.remove('d-none');
            editAnchor.classList.remove('d-none');
            viewUrl?.classList.add('d-none');
            viewAnchor?.classList.add('d-none');
            if (editLang) { editLang.classList.remove('d-none'); viewLang?.classList.add('d-none'); }
            if (editWish) { editWish.classList.remove('d-none'); viewWish?.classList.add('d-none'); }
        } else {
            editUrl.classList.add('d-none');
            editAnchor.classList.add('d-none');
            viewUrl?.classList.remove('d-none');
            viewAnchor?.classList.remove('d-none');
            if (editLang) { editLang.classList.add('d-none'); viewLang?.classList.remove('d-none'); }
            if (editWish) { editWish.classList.add('d-none'); viewWish?.classList.remove('d-none'); }
        }
    }

    async function handleEditButton(btn) {
        const tr = btn.closest('tr');
        if (!tr) return;
        const isEditing = !tr.querySelector('.url-cell .edit-url')?.classList.contains('d-none');
        if (!isEditing) {
            toggleRowEdit(tr, true);
            // Switch to check icon while editing, keep icon-only button
            btn.dataset.originalIcon = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i>';
            btn.setAttribute('title', '<?php echo __('Готово'); ?>');
        } else {
            const ok = await saveRowEdit(tr, btn);
            if (ok) {
                toggleRowEdit(tr, false);
                btn.innerHTML = btn.dataset.originalIcon || '<i class="bi bi-pencil"></i>';
                btn.setAttribute('title', '<?php echo __('Редактировать'); ?>');
            }
        }
    }

    async function handleRemoveButton(btn) {
        const tr = btn.closest('tr');
        if (!tr) return;
        const id = parseInt(btn.getAttribute('data-id') || tr.getAttribute('data-id') || '', 10);
        if (Number.isNaN(id) || id <= 0) { alert('<?php echo __('Невозможно удалить: идентификатор ссылки не определен. Обновите страницу.'); ?>'); return; }
        if (!confirm('<?php echo __('Удалить ссылку?'); ?>')) return;
        setButtonLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('update_project', '1');
            fd.append('ajax', '1');
            fd.append('wishes', globalWish.value || '');
            fd.append('remove_links[]', String(id));
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'Accept':'application/json' }, credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.ok) { alert('<?php echo __('Ошибка'); ?>: ' + (data && data.message ? data.message : 'ERROR')); return; }
            // Remove row and renumber
            tr.remove();
            refreshRowNumbers();
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    // Enhance existing bindings to use new handlers (and avoid double-binding)
    document.querySelectorAll('.action-edit').forEach(btn => {
        if (btn.dataset.bound === '1') return; btn.dataset.bound = '1';
        btn.addEventListener('click', () => handleEditButton(btn));
    });
    document.querySelectorAll('.action-remove').forEach(btn => {
        if (btn.dataset.bound === '1') return; btn.dataset.bound = '1';
        btn.addEventListener('click', () => handleRemoveButton(btn));
    });

    // Заглушка для кнопок публикации новых строк больше не нужна (мы сохраняем сразу)

    if (projectInfoForm) {
        projectInfoForm.addEventListener('submit', () => {
            // При submit модалки значение hidden синхронизируется после перезагрузки страницы сервером
        });
    }
    if (useGlobal) {
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
    }
    if (globalWish && useGlobal) {
        globalWish.addEventListener('input', () => { if (useGlobal.checked) { newWish.value = globalWish.value; } });
    }

    function isValidUrl(string) { try { new URL(string); return true; } catch (_) { return false; } }

    // escaping helpers for safe HTML/attribute insertion
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c] || c)); }
    function escapeAttribute(s){ return String(s).replace(/["']/g, c => ({'"':'&quot;','\'':'&#39;'}[c])); }

    function pathFromUrl(u){
        try { const {pathname, search, hash} = new URL(u); return (pathname || '/') + (search || '') + (hash || ''); } catch(e){ return u; }
    }
    function hostFromUrl(u){ try { const h = new URL(u).hostname.toLowerCase(); return h.replace(/^www\./,''); } catch(e){ return ''; } }

    // CSRF helpers
    function getCsrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        if (input && input.value) return input.value;
        if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        return '';
    }

    async function sendPublishAction(btn, url, action) {
        const csrf = getCsrfToken();
        if (!csrf) { alert('CSRF missing'); return; }
        if (!url) { alert('<?php echo __('Сначала сохраните проект чтобы опубликовать новую ссылку.'); ?>'); return; }
        setButtonLoading(btn, true);
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutes timeout
        try {
            const formData = new FormData();
            formData.append('csrf_token', csrf);
            formData.append('project_id', PROJECT_ID);
            formData.append('url', url);
            formData.append('action', action);
            const res = await fetch('<?php echo pp_url('public/publish_link.php'); ?>', { method: 'POST', body: formData, credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);
            const data = await res.json().catch(()=>({ok:false,error:'BAD_JSON'}));
            if (!data.ok) {
                let msg = data.error || 'ERROR';
                const map = {
                    'ALREADY_PUBLISHED':'<?php echo __('Уже опубликована'); ?>',
                    'DB_ERROR':'<?php echo __('Ошибка базы данных'); ?>',
                    'PROJECT_NOT_FOUND':'<?php echo __('Проект не найден'); ?>',
                    'FORBIDDEN':'<?php echo __('Нет прав'); ?>',
                    'NOT_PENDING':'<?php echo __('Не в ожидании'); ?>',
                    'BAD_ACTION':'<?php echo __('Неверное действие'); ?>',
                    'NO_ENABLED_NETWORKS':'<?php echo __('Нет доступных сетей публикации. Проверьте настройки.'); ?>',
                    'MISSING_OPENAI_KEY':'<?php echo __('Укажите OpenAI API Key в настройках.'); ?>',
                    'NETWORK_ERROR':'<?php echo __('Ошибка при публикации через сеть'); ?>',
                    'URL_NOT_IN_PROJECT':'<?php echo __('Ссылка отсутствует в проекте'); ?>'
                };
                if (map[msg]) msg = map[msg];
                if (data.error === 'NETWORK_ERROR' && data.details) {
                    msg += ' (' + data.details + ')';
                }
                alert('<?php echo __('Ошибка'); ?>: ' + msg);
                return;
            }
            updateRowUI(url, data.status, data);
        } catch (e) {
            if (e.name === 'AbortError') {
                alert('<?php echo __('Таймаут запроса'); ?>');
            } else {
                alert('<?php echo __('Сетевая ошибка'); ?>');
            }
        } finally {
            clearTimeout(timeoutId);
            setButtonLoading(btn, false);
        }
    }

    function updateRowUI(url, status, payload = {}) {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        rows.forEach(tr => {
            const linkEl = tr.querySelector('.url-cell .view-url');
            if (!linkEl) return;
            if (linkEl.getAttribute('href') === url) {
                const statusCell = tr.querySelector('.status-cell') || tr.querySelector('td:nth-child(6)');
                const actionsCell = tr.querySelector('td.text-end');
                if (status === 'published') {
                    const postUrl = payload.post_url || '';
                    const networkLabel = payload.network_title || payload.network || '';
                    if (tr) {
                        tr.dataset.postUrl = postUrl;
                        tr.dataset.network = networkLabel;
                    }
                    if (statusCell) {
                        let html = '<span class="badge badge-success"><?php echo __('Опубликована'); ?></span>';
                        if (postUrl) {
                            html += '<div class="small mt-1"><a href="'+escapeHtml(postUrl)+'" target="_blank" rel="noopener"><?php echo __('Открыть материал'); ?></a></div>';
                        }
                        if (networkLabel) {
                            html += '<div class="text-muted small"><?php echo __('Сеть'); ?>: '+escapeHtml(networkLabel)+'</div>';
                        }
                        statusCell.innerHTML = html;
                    }
                    if (actionsCell) {
                        let html = '';
                        if (postUrl) {
                            html += '<a href="'+escapeAttribute(postUrl)+'" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-box-arrow-up-right me-1"></i><span class="d-none d-lg-inline"><?php echo __('Открыть'); ?></span></a>';
                        }
                        html += '<button type="button" class="btn btn-outline-secondary btn-sm me-1" disabled><i class="bi bi-rocket-takeoff me-1"></i><span class="d-none d-lg-inline"><?php echo __('Опубликована'); ?></span></button>';
                        actionsCell.innerHTML = html;
                    }
                    const editBtns = tr.querySelectorAll('.action-edit, .action-remove');
                    editBtns.forEach(btn => {
                        btn.classList.add('disabled');
                        btn.setAttribute('disabled', 'disabled');
                    });
                    bindDynamicPublishButtons();
                    return;
                }
                if (statusCell) {
                    if (status === 'pending') {
                        statusCell.innerHTML = '<span class="badge badge-warning"><?php echo __('В ожидании'); ?></span>';
                        if (tr) { tr.dataset.network = payload.network || ''; }
                    } else if (status === 'not_published') {
                        statusCell.innerHTML = '<span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>';
                        if (tr) { tr.dataset.postUrl = ''; tr.dataset.network = ''; }
                    }
                }
                if (actionsCell) {
                    if (status === 'pending') {
                        actionsCell.querySelectorAll('.action-edit,.action-remove').forEach(b=>{ b.disabled = true; b.classList.add('disabled'); });
                        const pubBtn = actionsCell.querySelector('.action-publish');
                        if (pubBtn) {
                            // Replace publish button with cancel button only (no duplication)
                            pubBtn.outerHTML = '<button type="button" class="btn btn-outline-warning btn-sm me-1 action-cancel" data-url="'+escapeHtml(url)+'" title="<?php echo __('Отменить публикацию'); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отменить'); ?></span></button>';
                        }
                    } else if (status === 'not_published') {
                        const cancelBtn = actionsCell.querySelector('.action-cancel');
                        if (cancelBtn) {
                            cancelBtn.outerHTML = '<button type="button" class="btn btn-sm btn-publish me-1 action-publish" data-url="'+escapeHtml(url)+'"><i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span></button>';
                        }
                        actionsCell.querySelectorAll('.action-edit,.action-remove').forEach(b=>{ b.disabled = false; b.classList.remove('disabled'); });
                    }
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
        // Bind analyze buttons
        document.querySelectorAll('.action-analyze').forEach(btn => {
            if (btn.dataset.bound==='1') return;
            btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                const linkEl = tr?.querySelector('.url-cell .view-url');
                const url = linkEl ? linkEl.getAttribute('href') : '';
                if (url) openAnalyzeModal(url);
            });
        });
        // Bind show wish buttons
        document.querySelectorAll('.action-show-wish').forEach(btn => {
            if (btn.dataset.bound==='1') return;
            btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const wish = btn.getAttribute('data-wish') || btn.closest('tr')?.querySelector('.view-wish')?.textContent || '';
                openWishModal(wish);
            });
        });
    }

    // Initial bind
    bindDynamicPublishButtons();

    // Add link button handler
    addLinkBtn.addEventListener('click', async function() {
        const url = newLinkInput.value.trim();
        const anchor = newAnchorInput.value.trim();
        const lang = (newLangSelect ? newLangSelect.value.trim() : 'auto');
        const wish = newWish.value.trim();
        if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
        // Domain restriction (client-side)
        try {
            const u = new URL(url);
            const host = (u.hostname || '').toLowerCase().replace(/^www\./,'');
            if (CURRENT_PROJECT_HOST && host !== CURRENT_PROJECT_HOST) {
                alert('<?php echo __('Ссылка должна быть в рамках домена проекта'); ?>: ' + CURRENT_PROJECT_HOST);
                return;
            }
        } catch (e) {}

        setButtonLoading(addLinkBtn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('update_project', '1');
            fd.append('ajax', '1');
            fd.append('wishes', globalWish.value || '');
            fd.append('added_links[0][url]', url);
            fd.append('added_links[0][anchor]', anchor);
            fd.append('added_links[0][language]', lang || 'auto');
            fd.append('added_links[0][wish]', wish);

            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'Accept':'application/json' }, credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.ok) {
                alert('<?php echo __('Ошибка'); ?>: ' + (data && data.message ? data.message : 'ERROR'));
                return;
            }
            // обновим хост проекта, если он установлен сервером при первой ссылке
            if (data.domain_host) {
                applyProjectHost(data.domain_host);
            }
            if (data.domain_errors && Number(data.domain_errors) > 0) {
                alert('<?php echo __('Отклонено ссылок с другим доменом'); ?>: ' + data.domain_errors);
            }
            const payload = data.new_link || { id: 0, url, anchor, language: lang, wish: wish };
            // Добавляем строку в таблицу (сразу обычное состояние, т.к. уже сохранено)
            const tbody = ensureLinksTable();
            if (tbody) {
                const tr = document.createElement('tr');
                const newId = parseInt(payload.id || '0', 10) || 0;
                const newIndex = (data.links_count && data.links_count > 0) ? (data.links_count - 1) : 0;
                tr.setAttribute('data-id', String(newId));
                tr.setAttribute('data-index', String(newIndex));
                tr.dataset.postUrl = '';
                tr.dataset.network = '';
                const pathDisp = pathFromUrl(url);
                const hostDisp = hostFromUrl(url);
                tr.innerHTML = `
                    <td></td>
                    <td class="url-cell">
                        <div class="small text-muted host-muted"><i class="bi bi-globe2 me-1"></i>${escapeHtml(hostDisp)}</div>
                        <a href="${escapeHtml(url)}" target="_blank" class="view-url text-truncate-path" title="${escapeHtml(url)}" data-bs-toggle="tooltip">${escapeHtml(pathDisp)}</a>
                        <input type="url" class="form-control d-none edit-url" value="${escapeAttribute(url)}" />
                    </td>
                    <td class="anchor-cell">
                        <span class="view-anchor text-truncate-anchor" title="${escapeHtml(anchor)}" data-bs-toggle="tooltip">${escapeHtml(anchor)}</span>
                        <input type="text" class="form-control d-none edit-anchor" value="${escapeAttribute(anchor)}" />
                    </td>
                    <td class="language-cell">
                        <span class="badge bg-secondary-subtle text-light-emphasis view-language text-uppercase">${(payload.language || lang).toUpperCase()}</span>
                        <select class="form-select form-select-sm d-none edit-language">
                            ${LANG_CODES.map(l=>`<option value="${l}" ${l===(payload.language||lang)?'selected':''}>${l.toUpperCase()}</option>`).join('')}
                        </select>
                    </td>
                    <td class="wish-cell">
                        <button type="button" class="icon-btn action-show-wish" data-wish="${escapeHtml(payload.wish || wish)}" title="<?php echo __('Показать пожелание'); ?>" data-bs-toggle="tooltip"><i class="bi bi-journal-text"></i></button>
                        <div class="view-wish d-none">${escapeHtml(payload.wish || wish)}</div>
                        <textarea class="form-control d-none edit-wish" rows="2">${escapeHtml(payload.wish || wish)}</textarea>
                    </td>
                    <td class="status-cell">
                        <span class="badge badge-secondary"><?php echo __('Не опубликована'); ?></span>
                    </td>
                    <td class="text-end">
                        <button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                        <button type="button" class="btn btn-sm btn-publish me-1 action-publish" data-url="${escapeHtml(url)}"><i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Опубликовать'); ?></span></button>
                        <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="icon-btn action-remove" data-id="${String(newId)}" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                    </td>`;
                tbody.appendChild(tr);
                refreshRowNumbers();
                bindDynamicRowActions();
                initTooltips(tr);
            }

            // Очистим поля
            newLinkInput.value = '';
            newAnchorInput.value = '';
            newWish.value = '';
            if (newLangSelect) newLangSelect.value = newLangSelect.querySelector('option')?.value || newLangSelect.value;
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(addLinkBtn, false);
        }
    });

    // Extend binder to include edit/remove in addition to publish/cancel/analyze/wish
    function bindDynamicRowActions() {
        // publish
        document.querySelectorAll('.action-publish').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                sendPublishAction(btn, url, 'publish');
            });
        });
        // cancel
        document.querySelectorAll('.action-cancel').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                if (!confirm('<?php echo __('Отменить публикацию ссылки?'); ?>')) return;
                sendPublishAction(btn, url, 'cancel');
            });
        });
        // analyze
        document.querySelectorAll('.action-analyze').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                const linkEl = tr?.querySelector('.url-cell .view-url');
                const url = linkEl ? linkEl.getAttribute('href') : '';
                if (url) openAnalyzeModal(url);
            });
        });
        // show wish
        document.querySelectorAll('.action-show-wish').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const wish = btn.getAttribute('data-wish') || btn.closest('tr')?.querySelector('.view-wish')?.textContent || '';
                openWishModal(wish);
            });
        });
        // edit
        document.querySelectorAll('.action-edit').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => handleEditButton(btn));
        });
        // remove
        document.querySelectorAll('.action-remove').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => handleRemoveButton(btn));
        });
    }

    // Initial bind
    bindDynamicRowActions();

    // Background polling for pending statuses so user can navigate and return
    async function pollStatusesOnce() {
        try {
            const rows = document.querySelectorAll('table.table-links tbody tr');
            for (const tr of rows) {
                const statusCell = tr.querySelector('.status-cell');
                const linkEl = tr.querySelector('.url-cell .view-url');
                if (!statusCell || !linkEl) continue;
                if (statusCell.textContent.includes('<?php echo __('В ожидании'); ?>') || statusCell.textContent.includes('queued') || statusCell.textContent.includes('pending')) {
                    const url = linkEl.getAttribute('href');
                    const fd = new URLSearchParams();
                    fd.set('project_id', String(PROJECT_ID));
                    fd.set('url', url);
                    const res = await fetch('<?php echo pp_url('public/publication_status.php'); ?>?' + fd.toString(), { credentials:'same-origin' });
                    const data = await res.json().catch(()=>null);
                    if (!data || !data.ok) continue;
                    if (data.status === 'published') {
                        updateRowUI(url, 'published', data);
                    } else if (data.status === 'failed') {
                        // Reset to not_published to allow retry; optionally show error via tooltip
                        updateRowUI(url, 'not_published', {});
                    }
                }
            }
        } catch (_e) { /* ignore */ }
    }
    // Poll periodically while page is visible
    let pollTimer = null;
    function startPolling(){ if (pollTimer) return; pollTimer = setInterval(pollStatusesOnce, 4000); }
    function stopPolling(){ if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPolling(); else startPolling(); });
    startPolling();

    function openAnalyzeModal(url){
        const modalEl = document.getElementById('analyzeModal');
        const resEl = document.getElementById('analyze-result');
        const loadEl = document.getElementById('analyze-loading');
        if (!modalEl) return;
        const m = new bootstrap.Modal(modalEl);
        resEl.classList.add('d-none');
        resEl.innerHTML = '';
        loadEl.classList.remove('d-none');
        m.show();
        (async () => {
            const csrf = getCsrfToken();
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('project_id', String(PROJECT_ID));
            fd.append('url', url);
            try {
                const resp = await fetch('<?php echo pp_url('public/analyze_url.php'); ?>', { method:'POST', body: fd, credentials:'same-origin' });
                const data = await resp.json();
                loadEl.classList.add('d-none');
                if (!data || !data.ok) {
                    resEl.innerHTML = '<div class="alert alert-danger">' + (escapeHtml(data?.error || 'ERROR')) + '</div>';
                    resEl.classList.remove('d-none');
                    return;
                }
                const d = data.data || {};
                const rows = [];
                const addRow = (k, v) => { if (v && String(v).trim() !== '') rows.push(`<tr><th>${escapeHtml(k)}</th><td>${escapeHtml(String(v))}</td></tr>`); };
                addRow('<?php echo __('URL'); ?>', d.final_url || url);
                addRow('<?php echo __('Язык'); ?>', d.lang || '');
                addRow('<?php echo __('Регион'); ?>', d.region || '');
                addRow('<?php echo __('Заголовок'); ?>', d.title || '');
                addRow('<?php echo __('Описание'); ?>', d.description || '');
                addRow('<?php echo __('Canonical'); ?>', d.canonical || '');
                addRow('<?php echo __('Дата публикации'); ?>', d.published_time || '');
                addRow('<?php echo __('Дата обновления'); ?>', d.modified_time || '');
                const extended = d.hreflang && Array.isArray(d.hreflang) && d.hreflang.length ? `<details class="mt-3"><summary class="fw-semibold"><?php echo __('Альтернативы hreflang'); ?></summary><div class="mt-2 small">${d.hreflang.map(h=>escapeHtml(`${h.hreflang || ''} → ${h.href || ''}`)).join('<br>')}</div></details>` : '';
                resEl.innerHTML = `
                    <div class="table-responsive">
                      <table class="table table-sm">
                        <tbody>${rows.join('')}</tbody>
                      </table>
                    </div>
                    ${extended}
                `;
                resEl.classList.remove('d-none');
            } catch (e) {
                loadEl.classList.add('d-none');
                resEl.innerHTML = '<div class="alert alert-danger"><?php echo __('Сетевая ошибка'); ?></div>';
                resEl.classList.remove('d-none');
            }
        })();
    }

    function openWishModal(text){
        const el = document.getElementById('wishModal');
        const body = document.getElementById('wishContent');
        const copyBtn = document.getElementById('wishCopyBtn');
        if (!el || !body) return;
        body.textContent = text || '<?php echo __('Пусто'); ?>';
        const modal = new bootstrap.Modal(el);
        modal.show();
        if (copyBtn) {
            copyBtn.onclick = async () => {
                try { await navigator.clipboard.writeText(text || ''); copyBtn.classList.add('btn-success'); setTimeout(()=>copyBtn.classList.remove('btn-success'), 1000); } catch(e) {}
            };
        }
    }

    // Helper: (re)initialize Bootstrap tooltips in a given container (or document)
    function initTooltips(root) {
        try {
            if (!window.bootstrap || !bootstrap.Tooltip) return;
            const scope = root || document;
            scope.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                try { bootstrap.Tooltip.getOrCreateInstance(el); } catch (e) {}
            });
        } catch (e) {}
    }

    // After initial DOM is ready, ensure tooltips are active
    initTooltips(document);
});
</script>

<?php include '../includes/footer.php'; ?>
