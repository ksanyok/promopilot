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

if (empty($project['primary_url'])) {
    $project['primary_url'] = $links[0]['url'] ?? null;
}
$projectPrimaryUrl = pp_project_primary_url($project, $project['primary_url'] ?? null);
$projectPreviewDescriptor = pp_project_preview_descriptor($project);
$projectPreviewUrl = pp_project_preview_url($project, $projectPrimaryUrl, ['cache_bust' => true]);
$projectPreviewExists = !empty($projectPreviewDescriptor['exists']);
$projectPreviewUpdatedAt = $projectPreviewExists ? (int)($projectPreviewDescriptor['modified_at'] ?? 0) : 0;
$projectPreviewUpdatedHuman = $projectPreviewUpdatedAt ? date('d.m.Y H:i', $projectPreviewUpdatedAt) : null;
$projectPreviewStale = pp_project_preview_is_stale($projectPreviewDescriptor, 259200);
$projectPreviewShouldAuto = !$projectPreviewExists || $projectPreviewStale;
$projectPreviewStatusKey = 'pending';
if (!$projectPreviewExists) {
    $projectPreviewStatusKey = 'pending';
    $projectPreviewStatusText = __('Скрин еще не готов');
} elseif ($projectPreviewStale) {
    $projectPreviewStatusKey = 'warning';
    $projectPreviewStatusText = $projectPreviewUpdatedHuman ? sprintf(__('Скрин обновлен давно: %s'), $projectPreviewUpdatedHuman) : __('Скрин обновлен давно');
} else {
    $projectPreviewStatusKey = 'ok';
    $projectPreviewStatusText = $projectPreviewUpdatedHuman ? sprintf(__('Скрин обновлен %s'), $projectPreviewUpdatedHuman) : __('Скрин обновлен');
}
$projectPreviewStatusIcon = $projectPreviewStatusKey === 'ok' ? 'bi-check-circle' : ($projectPreviewStatusKey === 'warning' ? 'bi-exclamation-triangle' : 'bi-camera');
$projectPrimaryHost = trim((string)($project['domain_host'] ?? ''));
if ($projectPrimaryHost === '' && $projectPrimaryUrl) {
    $parsedHost = parse_url($projectPrimaryUrl, PHP_URL_HOST);
    if (!empty($parsedHost)) { $projectPrimaryHost = $parsedHost; }
}
$projectFaviconUrl = $projectPrimaryHost !== '' ? ('https://www.google.com/s2/favicons?sz=128&domain=' . rawurlencode($projectPrimaryHost)) : null;
if (function_exists('mb_substr')) {
    $projectInitial = mb_strtoupper(mb_substr($project['name'] ?? '', 0, 1, 'UTF-8'), 'UTF-8');
} else {
    $projectInitial = strtoupper(substr((string)($project['name'] ?? ''), 0, 1));
}
if ($projectInitial === '') { $projectInitial = '∎'; }

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
                if ($st === 'partial') {
                    $status = 'manual_review';
                } elseif ($postUrl !== '' || $st === 'success') {
                    $status = 'published';
                } elseif ($st === 'failed' || $st === 'cancelled') {
                    $status = 'not_published';
                } elseif ($st === 'queued' || $st === 'running') {
                    $status = 'pending';
                }
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

$promotionStatusByUrl = [];
if (function_exists('pp_promotion_get_status')) {
    foreach ($links as $item) {
        $linkUrl = (string)($item['url'] ?? '');
        if ($linkUrl === '') { continue; }
        $stat = pp_promotion_get_status((int)$project['id'], $linkUrl);
        if (is_array($stat) && !empty($stat['ok'])) {
            $promotionStatusByUrl[$linkUrl] = $stat;
        }
    }
}

$promotionSummary = [
    'total' => count($links),
    'active' => 0,
    'completed' => 0,
    'idle' => 0,
    'issues' => 0,
];
$promotionActiveStates = ['queued','running','level1_active','pending_level2','level2_active','pending_crowd','crowd_ready','report_ready'];
$promotionIssueStates = ['failed','cancelled'];
foreach ($links as $item) {
    $linkUrl = (string)($item['url'] ?? '');
    $status = 'idle';
    if (isset($promotionStatusByUrl[$linkUrl]) && is_array($promotionStatusByUrl[$linkUrl])) {
        $status = (string)($promotionStatusByUrl[$linkUrl]['status'] ?? 'idle');
    }
    if (in_array($status, $promotionActiveStates, true)) {
        $promotionSummary['active']++;
    } elseif ($status === 'completed') {
        $promotionSummary['completed']++;
    } elseif (in_array($status, $promotionIssueStates, true)) {
        $promotionSummary['issues']++;
    } else {
        $promotionSummary['idle']++;
    }
}

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
                    <div class="project-hero__layout">
                        <div class="project-hero__main">
                            <div class="project-hero__heading d-flex align-items-start justify-content-between gap-3 flex-wrap">
                                <div>
                                    <div class="title d-flex align-items-center gap-2">
                                        <span><?php echo htmlspecialchars($project['name']); ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#projectInfoModal" title="<?php echo __('Редактировать основную информацию'); ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <i class="bi bi-info-circle ms-1 text-primary" data-bs-toggle="tooltip" title="<?php echo __('Страница проекта: управляйте ссылками и пожеланиями.'); ?>"></i>
                                    </div>
                                    <div class="subtitle">@<?php echo htmlspecialchars($project['username']); ?></div>
                                </div>
                                <div class="project-hero__id text-end">
                                    <span class="chip" data-bs-toggle="tooltip" title="<?php echo __('Внутренний идентификатор проекта'); ?>"><i class="bi bi-folder2-open"></i>ID <?php echo (int)$project['id']; ?></span>
                                </div>
                            </div>
                            <div class="meta-list">
                                <div class="meta-item"><i class="bi bi-calendar3"></i><span><?php echo __('Дата создания'); ?>: <?php echo htmlspecialchars($project['created_at']); ?></span></div>
                                <div class="meta-item"><i class="bi bi-translate"></i><span><?php echo __('Язык страницы'); ?>: <?php echo htmlspecialchars($project['language'] ?? 'ru'); ?></span></div>
                                <?php if (!empty($project['region'])): ?>
                                  <div class="meta-item"><i class="bi bi-geo-alt"></i><span><?php echo __('Регион'); ?>: <?php echo htmlspecialchars($project['region']); ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($project['topic'])): ?>
                                  <div class="meta-item"><i class="bi bi-tags"></i><span><?php echo __('Тематика'); ?>: <?php echo htmlspecialchars($project['topic']); ?></span></div>
                                <?php endif; ?>
                                <?php if ($projectPrimaryHost !== ''): ?>
                                <div class="meta-item"><i class="bi bi-globe2"></i><span><?php echo __('Домен'); ?>: <?php echo htmlspecialchars($projectPrimaryHost); ?></span></div>
                                <?php else: ?>
                                <div class="meta-item"><i class="bi bi-globe2"></i><span class="text-warning"><?php echo __('Домен будет зафиксирован по первой добавленной ссылке.'); ?></span></div>
                                <?php endif; ?>
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
                    <div class="project-hero__preview" data-project-preview
                             data-project-id="<?php echo (int)$project['id']; ?>"
                             data-endpoint="<?php echo htmlspecialchars(pp_url('public/project_preview.php')); ?>"
                             data-csrf="<?php echo htmlspecialchars(get_csrf_token()); ?>"
                             data-auto-refresh="<?php echo $projectPreviewShouldAuto ? '1' : '0'; ?>"
                             data-preview-updated-at="<?php echo $projectPreviewUpdatedAt; ?>"
                        data-preview-updated-human="<?php echo htmlspecialchars($projectPreviewUpdatedHuman ?? ''); ?>"
                        data-has-preview="<?php echo $projectPreviewExists ? '1' : '0'; ?>"
                             data-preview-alt="<?php echo htmlspecialchars($project['name']); ?>"
                        data-text-success="<?php echo htmlspecialchars(__('Скрин обновлен %s')); ?>"
                        data-text-warning="<?php echo htmlspecialchars(__('Скрин обновлен давно: %s')); ?>"
                        data-text-pending="<?php echo htmlspecialchars(__('Скрин еще не готов')); ?>"
                        data-text-error="<?php echo htmlspecialchars(__('Не удалось обновить скрин')); ?>"
                        data-text-processing="<?php echo htmlspecialchars(__('Обновляем превью...')); ?>">
                            <div class="project-hero__preview-frame">
                                <?php if (!empty($projectPreviewUrl)): ?>
                                    <img src="<?php echo htmlspecialchars($projectPreviewUrl); ?>" alt="<?php echo htmlspecialchars($project['name']); ?>" class="project-hero__screenshot" loading="lazy" decoding="async" data-preview-image>
                                <?php else: ?>
                                    <div class="project-hero__screenshot project-hero__screenshot--placeholder" data-preview-placeholder><span><?php echo htmlspecialchars($projectInitial); ?></span></div>
                                <?php endif; ?>
                                <span class="project-hero__preview-glow"></span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="button" class="project-hero__refresh btn btn-sm" data-action="refresh-preview">
                                    <span class="label-default"><i class="bi bi-arrow-repeat"></i><?php echo __('Обновить превью'); ?></span>
                                    <span class="label-loading">
                                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                        <?php echo __('Обновление...'); ?>
                                    </span>
                                </button>
                                <div class="project-hero__preview-status small" data-preview-status data-status="<?php echo htmlspecialchars($projectPreviewStatusKey); ?>">
                                    <i class="bi <?php echo htmlspecialchars($projectPreviewStatusIcon); ?>"></i>
                                    <span data-preview-status-text><?php echo htmlspecialchars($projectPreviewStatusText); ?></span>
                                </div>
                            </div>
                            <?php if ($projectPrimaryHost !== ''): ?>
                                <div class="project-hero__domain small text-muted">
                                    <?php if (!empty($projectFaviconUrl)): ?><img src="<?php echo htmlspecialchars($projectFaviconUrl); ?>" alt="favicon" loading="lazy"><?php endif; ?>
                                    <?php if (!empty($projectPrimaryUrl)): ?>
                                        <a href="<?php echo htmlspecialchars($projectPrimaryUrl); ?>" target="_blank" rel="noopener" class="text-decoration-none text-reset fw-semibold"><?php echo htmlspecialchars($projectPrimaryHost); ?></a>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($projectPrimaryHost); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Project Info -->
            <div class="row g-3 mb-4 promotion-stats-row">
                <div class="col-sm-6 col-lg-3">
                    <div class="card promotion-stat-card promotion-stat-card--total h-100 bounce-in fade-in">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div class="promotion-stat-card__label text-uppercase small fw-semibold text-muted"><?php echo __('Всего ссылок'); ?></div>
                            <div class="promotion-stat-card__value" data-stat-total><?php echo number_format($promotionSummary['total'], 0, '.', ' '); ?></div>
                            <div class="promotion-stat-card__meta text-muted" data-stat-idle-wrapper>
                                <i class="bi bi-hourglass-split me-1"></i><span><?php echo __('Ожидают запуска'); ?>:</span>
                                <span class="promotion-stat-card__meta-value" data-stat-idle><?php echo number_format($promotionSummary['idle'], 0, '.', ' '); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card promotion-stat-card promotion-stat-card--active h-100 bounce-in fade-in" style="animation-delay:.06s;">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div class="promotion-stat-card__label text-uppercase small fw-semibold text-muted"><?php echo __('В работе'); ?></div>
                            <div class="promotion-stat-card__value" data-stat-active><?php echo number_format($promotionSummary['active'], 0, '.', ' '); ?></div>
                            <div class="promotion-stat-card__meta text-muted small">
                                <i class="bi bi-lightning-charge me-1"></i><?php echo __('Активных сценариев продвижения сейчас'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card promotion-stat-card promotion-stat-card--done h-100 bounce-in fade-in" style="animation-delay:.12s;">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div class="promotion-stat-card__label text-uppercase small fw-semibold text-muted"><?php echo __('Завершено'); ?></div>
                            <div class="promotion-stat-card__value" data-stat-completed><?php echo number_format($promotionSummary['completed'], 0, '.', ' '); ?></div>
                            <div class="promotion-stat-card__meta text-muted small">
                                <i class="bi bi-patch-check-fill me-1"></i><?php echo __('Достигли планового охвата'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card promotion-stat-card promotion-stat-card--issues h-100 bounce-in fade-in" style="animation-delay:.18s;">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div class="promotion-stat-card__label text-uppercase small fw-semibold text-muted"><?php echo __('Требуют внимания'); ?></div>
                            <div class="promotion-stat-card__value" data-stat-issues><?php echo number_format($promotionSummary['issues'], 0, '.', ' '); ?></div>
                            <div class="promotion-stat-card__meta text-muted small">
                                <i class="bi bi-exclamation-triangle me-1"></i><?php echo __('Отменено или ошибка'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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
                        <div class="toolbar status-toolbar d-flex flex-wrap align-items-center gap-3">
                            <div class="status-legend small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Статусы продвижения'); ?>">
                                <span><span class="legend-dot legend-dot-idle"></span><?php echo __('Продвижение не запускалось'); ?></span>
                                <span><span class="legend-dot legend-dot-running"></span><?php echo __('Продвижение выполняется'); ?></span>
                                <span><span class="legend-dot legend-dot-done"></span><?php echo __('Продвижение завершено'); ?></span>
                                <span><span class="legend-dot legend-dot-cancelled"></span><?php echo __('Продвижение отменено'); ?></span>
                            </div>
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
                                        $promotionInfo = $promotionStatusByUrl[$url] ?? null;
                                        $promotionStatus = is_array($promotionInfo) ? (string)($promotionInfo['status'] ?? 'idle') : 'idle';
                                        $promotionStage = is_array($promotionInfo) ? (string)($promotionInfo['stage'] ?? '') : '';
                                        $promotionProgress = is_array($promotionInfo) ? ($promotionInfo['progress'] ?? ['done' => 0, 'total' => 0]) : ['done' => 0, 'total' => 0];
                                        $promotionRunId = is_array($promotionInfo) ? (int)($promotionInfo['run_id'] ?? 0) : 0;
                                        $promotionReportReady = !empty($promotionInfo['report_ready']);
                                        $promotionActive = in_array($promotionStatus, ['queued','running','level1_active','pending_level2','level2_active','pending_crowd','crowd_ready','report_ready'], true);
                                        $promotionTotal = (int)($promotionProgress['total'] ?? 0);
                                        $promotionTarget = (int)($promotionProgress['target'] ?? 0);
                                        if ($promotionTarget <= 0) { $promotionTarget = $promotionTotal; }
                                        $promotionDone = (int)($promotionProgress['done'] ?? 0);
                                        $promotionLevels = (is_array($promotionInfo) && isset($promotionInfo['levels']) && is_array($promotionInfo['levels'])) ? $promotionInfo['levels'] : [];
                                        $level1Data = isset($promotionLevels[1]) && is_array($promotionLevels[1]) ? $promotionLevels[1] : [];
                                        $level2Data = isset($promotionLevels[2]) && is_array($promotionLevels[2]) ? $promotionLevels[2] : [];
                                        $level1Total = (int)($level1Data['total'] ?? 0);
                                        $level1Success = (int)($level1Data['success'] ?? 0);
                                        $level1Required = (int)($level1Data['required'] ?? ($promotionTarget ?: 0));
                                        $level2Total = (int)($level2Data['total'] ?? 0);
                                        $level2Success = (int)($level2Data['success'] ?? 0);
                                        $level2Required = (int)($level2Data['required'] ?? 0);
                                        $crowdData = (is_array($promotionInfo) && isset($promotionInfo['crowd']) && is_array($promotionInfo['crowd'])) ? $promotionInfo['crowd'] : [];
                                        $crowdPlanned = (int)($crowdData['planned'] ?? 0);
                                        $promotionDetails = [];
                                        if ($level1Success > 0 || $level1Required > 0) {
                                            $promotionDetails[] = sprintf('%s: %d%s', __('Уровень 1'), $level1Success, $level1Required > 0 ? ' / ' . $level1Required : '');
                                        }
                                        if ($level2Success > 0 || $level2Required > 0) {
                                            $promotionDetails[] = sprintf('%s: %d%s', __('Уровень 2'), $level2Success, $level2Required > 0 ? ' / ' . $level2Required : '');
                                        }
                                        if ($crowdPlanned > 0) {
                                            $promotionDetails[] = sprintf('%s: %d', __('Крауд'), $crowdPlanned);
                                        }
                                        $promotionStatusLabel = '';
                                        switch ($promotionStatus) {
                                            case 'queued':
                                            case 'running':
                                            case 'level1_active':
                                                $promotionStatusLabel = __('Уровень 1 выполняется');
                                                break;
                                            case 'pending_level2':
                                                $promotionStatusLabel = __('Ожидание уровня 2');
                                                break;
                                            case 'level2_active':
                                                $promotionStatusLabel = __('Уровень 2 выполняется');
                                                break;
                                            case 'pending_crowd':
                                                $promotionStatusLabel = __('Подготовка крауда');
                                                break;
                                            case 'crowd_ready':
                                                $promotionStatusLabel = __('Крауд готов');
                                                break;
                                            case 'report_ready':
                                                $promotionStatusLabel = __('Формируется отчет');
                                                break;
                                            case 'completed':
                                                $promotionStatusLabel = __('Завершено');
                                                break;
                                            case 'failed':
                                                $promotionStatusLabel = __('Ошибка продвижения');
                                                break;
                                            case 'cancelled':
                                                $promotionStatusLabel = __('Отменено');
                                                break;
                                            default:
                                                if ($promotionStatus === 'idle') {
                                                    $promotionStatusLabel = __('Продвижение не запускалось');
                                                } else {
                                                    $promotionStatusLabel = ucfirst($promotionStatus);
                                                }
                                                break;
                                        }
                                        $canEdit = ($promotionStatus === 'idle');
                                        $pu = @parse_url($url);
                                        $hostDisp = $pp_normalize_host($pu['host'] ?? '');
                                        $pathDisp = (string)($pu['path'] ?? '/');
                                        if (!empty($pu['query'])) { $pathDisp .= '?' . $pu['query']; }
                                        if (!empty($pu['fragment'])) { $pathDisp .= '#' . $pu['fragment']; }
                                        if ($pathDisp === '') { $pathDisp = '/'; }
                                    ?>
                                    <tr data-id="<?php echo (int)$linkId; ?>"
                                        data-index="<?php echo (int)$index; ?>"
                                        data-post-url="<?php echo htmlspecialchars($postUrl); ?>"
                                        data-network="<?php echo htmlspecialchars($networkSlug); ?>"
                                        data-publication-status="<?php echo htmlspecialchars($status); ?>"
                                        data-promotion-status="<?php echo htmlspecialchars($promotionStatus); ?>"
                                        data-promotion-stage="<?php echo htmlspecialchars($promotionStage); ?>"
                                        data-promotion-run-id="<?php echo $promotionRunId ?: ''; ?>"
                                        data-promotion-report-ready="<?php echo $promotionReportReady ? '1' : '0'; ?>"
                                        data-promotion-total="<?php echo $promotionTarget; ?>"
                                        data-promotion-done="<?php echo $promotionDone; ?>"
                                        data-promotion-target="<?php echo $promotionTarget; ?>"
                                        data-promotion-attempted="<?php echo $promotionTotal; ?>"
                                        data-level1-total="<?php echo $level1Total; ?>"
                                        data-level1-success="<?php echo $level1Success; ?>"
                                        data-level1-required="<?php echo $level1Required; ?>"
                                        data-level2-total="<?php echo $level2Total; ?>"
                                        data-level2-success="<?php echo $level2Success; ?>"
                                        data-level2-required="<?php echo $level2Required; ?>"
                                        data-crowd-planned="<?php echo $crowdPlanned; ?>">
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
                                            <div class="promotion-status-block small mt-2 <?php echo $promotionStatus === 'idle' ? 'text-muted' : 'text-primary'; ?>"
                                                 data-run-id="<?php echo $promotionRunId ?: ''; ?>"
                                                 data-status="<?php echo htmlspecialchars($promotionStatus); ?>"
                                                 data-stage="<?php echo htmlspecialchars($promotionStage); ?>"
                                                 data-total="<?php echo $promotionTarget; ?>"
                                                 data-done="<?php echo $promotionDone; ?>"
                                                 data-report-ready="<?php echo $promotionReportReady ? '1' : '0'; ?>"
                                                 data-level1-total="<?php echo $level1Total; ?>"
                                                 data-level1-success="<?php echo $level1Success; ?>"
                                                 data-level1-required="<?php echo $level1Required; ?>"
                                                 data-level2-total="<?php echo $level2Total; ?>"
                                                 data-level2-success="<?php echo $level2Success; ?>"
                                                 data-level2-required="<?php echo $level2Required; ?>"
                                                 data-crowd-planned="<?php echo $crowdPlanned; ?>">
                                                <div class="promotion-status-top">
                                                    <span class="promotion-status-heading"><?php echo __('Продвижение'); ?>:</span>
                                                    <span class="promotion-status-label ms-1"><?php echo htmlspecialchars($promotionStatusLabel); ?></span>
                                                    <span class="promotion-progress-count ms-1 <?php echo $promotionTarget > 0 ? '' : 'd-none'; ?>"><?php echo $promotionTarget > 0 ? '(' . $promotionDone . ' / ' . $promotionTarget . ')' : ''; ?></span>
                                                </div>
                                                <div class="promotion-progress-visual mt-2 <?php echo $promotionActive ? '' : 'd-none'; ?>">
                                                    <div class="promotion-progress-level promotion-progress-level1 d-none" data-level="1">
                                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                                            <span><?php echo __('Уровень 1'); ?></span>
                                                            <span class="promotion-progress-value">0 / 0</span>
                                                        </div>
                                                        <div class="progress progress-thin">
                                                            <div class="progress-bar promotion-progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                                        </div>
                                                    </div>
                                                    <div class="promotion-progress-level promotion-progress-level2 d-none" data-level="2">
                                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                                            <span><?php echo __('Уровень 2'); ?></span>
                                                            <span class="promotion-progress-value">0 / 0</span>
                                                        </div>
                                                        <div class="progress progress-thin">
                                                            <div class="progress-bar promotion-progress-bar bg-info" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="promotion-progress-details text-muted <?php echo ($promotionStatus === 'completed' || empty($promotionDetails)) ? 'd-none' : ''; ?>">
                                                    <?php foreach ($promotionDetails as $detail): ?>
                                                        <div><?php echo htmlspecialchars($detail); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="promotion-status-complete mt-2 <?php echo $promotionStatus === 'completed' ? '' : 'd-none'; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo __('Передача ссылочного веса займет 2-3 месяца, мы продолжаем мониторинг.'); ?>">
                                                    <i class="bi bi-patch-check-fill text-success"></i>
                                                    <span class="promotion-status-complete-text"><?php echo __('Продвижение завершено'); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end" data-label="<?php echo __('Действия'); ?>">
                                            <button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                                            <?php if ($promotionStatus === 'completed'): ?>
                                                <!-- Promotion finished: no further actions -->
                                            <?php elseif ($promotionActive): ?>
                                                <button type="button" class="btn btn-sm btn-publish me-1 action-promote disabled" disabled data-loading="1">
                                                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                                    <span class="label d-none d-md-inline"><?php echo __('Продвижение выполняется'); ?></span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-publish me-1 action-promote" data-url="<?php echo htmlspecialchars($url); ?>" data-id="<?php echo (int)$linkId; ?>">
                                                    <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($promotionRunId > 0 && $promotionActive): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm me-1 action-promotion-progress" data-run-id="<?php echo $promotionRunId; ?>" data-url="<?php echo htmlspecialchars($url); ?>" title="<?php echo __('Промежуточный отчет'); ?>">
                                                    <i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($promotionReportReady && $promotionRunId > 0): ?>
                                                <button type="button" class="btn btn-outline-success btn-sm me-1 action-promotion-report" data-run-id="<?php echo $promotionRunId; ?>" data-url="<?php echo htmlspecialchars($url); ?>" title="<?php echo __('Скачать отчет'); ?>"><i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span></button>
                                            <?php endif; ?>

                                            <?php if ($canEdit): ?>
                                                <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="icon-btn action-remove" data-id="<?php echo (int)$linkId; ?>" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                                            <?php else: ?>
                                                <button type="button" class="icon-btn disabled" disabled title="<?php echo __('Редактирование доступно до запуска продвижения.'); ?>"><i class="bi bi-lock"></i></button>
                                                <button type="button" class="icon-btn disabled" disabled title="<?php echo __('Удаление доступно до запуска продвижения.'); ?>"><i class="bi bi-trash"></i></button>
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

<!-- Promotion Report Modal -->
<div class="modal fade" id="promotionReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-graph-up-arrow me-2"></i><?php echo __('Отчет по продвижению'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="promotionReportContent"></div>
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
    const promotionReportModalEl = document.getElementById('promotionReportModal');
    if (promotionReportModalEl && promotionReportModalEl.parentElement !== document.body) { document.body.appendChild(promotionReportModalEl); }

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

    // Project preview automation
    const previewWrapper = document.querySelector('[data-project-preview]');
    if (previewWrapper) {
        const previewEndpoint = previewWrapper.dataset.endpoint || '';
        const previewCsrf = previewWrapper.dataset.csrf || '';
        const previewAuto = previewWrapper.dataset.autoRefresh === '1';
        let previewImage = previewWrapper.querySelector('[data-preview-image]');
        const previewPlaceholder = previewWrapper.querySelector('[data-preview-placeholder]');
        const previewButton = previewWrapper.querySelector('[data-action="refresh-preview"]');
        const previewStatus = previewWrapper.querySelector('[data-preview-status]');
        const previewStatusText = previewStatus ? previewStatus.querySelector('[data-preview-status-text]') : null;
        const previewStatusIcon = previewStatus ? previewStatus.querySelector('i') : null;
        let previewBusy = false;

        const PREVIEW_LOCALE = document.documentElement.getAttribute('lang') || navigator.language || 'ru-RU';

        const STATUS_ICONS = {
            ok: 'bi-check-circle',
            warning: 'bi-exclamation-triangle',
            pending: 'bi-camera',
            error: 'bi-exclamation-triangle',
            processing: 'bi-hourglass-split'
        };

        const TEXT_TEMPLATES = {
            success: previewWrapper.dataset.textSuccess || '',
            warning: previewWrapper.dataset.textWarning || '',
            pending: previewWrapper.dataset.textPending || '',
            error: previewWrapper.dataset.textError || '',
            processing: previewWrapper.dataset.textProcessing || ''
        };

        const applyTemplate = (template, value) => {
            if (!template) return value || '';
            if (template.includes('%s')) {
                return template.replace('%s', value || '');
            }
            return template;
        };

        const formatHuman = (unixSeconds) => {
            const ts = Number(unixSeconds || 0);
            if (!ts) return '';
            try {
                const dt = new Date(ts * 1000);
                return new Intl.DateTimeFormat(PREVIEW_LOCALE, {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                }).format(dt);
            } catch (_) {
                return '';
            }
        };

        const updateStatus = (statusKey, options = {}) => {
            if (!previewStatus) return;
            const key = statusKey || 'pending';
            const iconClass = STATUS_ICONS[key] || STATUS_ICONS.pending;
            if (previewStatusIcon) {
                previewStatusIcon.className = 'bi ' + iconClass;
            }
            const text = options.text || TEXT_TEMPLATES.pending;
            if (previewStatusText) {
                previewStatusText.textContent = text;
            }
            previewStatus.dataset.status = key;
        };

        const togglePreviewLoading = (state) => {
            if (!previewButton) return;
            previewButton.classList.toggle('is-loading', !!state);
            previewButton.disabled = !!state;
        };

        const handleSuccess = (data) => {
            if (data.preview_url) {
                if (previewImage) {
                    previewImage.src = data.preview_url;
                } else {
                    const img = document.createElement('img');
                    img.src = data.preview_url;
                    img.alt = previewWrapper.dataset.previewAlt || '';
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    img.className = 'project-hero__screenshot';
                    img.setAttribute('data-preview-image', '1');
                    const frame = previewWrapper.querySelector('.project-hero__preview-frame');
                    if (frame) {
                        if (previewPlaceholder) {
                            previewPlaceholder.replaceWith(img);
                        } else {
                            frame.prepend(img);
                        }
                    }
                    previewImage = img;
                }
            }

            previewWrapper.dataset.hasPreview = '1';
            if (typeof data.modified_at !== 'undefined') {
                previewWrapper.dataset.previewUpdatedAt = String(data.modified_at || '');
            }
            if (typeof data.modified_human !== 'undefined') {
                previewWrapper.dataset.previewUpdatedHuman = String(data.modified_human || '');
            }
            previewWrapper.dataset.autoRefresh = '0';

            const human = (data.modified_human || '').toString() || formatHuman(data.modified_at) || previewWrapper.dataset.previewUpdatedHuman || '';
            const text = human ? applyTemplate(TEXT_TEMPLATES.success, human) : TEXT_TEMPLATES.success || TEXT_TEMPLATES.pending;
            updateStatus('ok', { text });
        };

        const handleError = (errorMessage) => {
            const human = previewWrapper.dataset.previewUpdatedHuman || '';
            let text = TEXT_TEMPLATES.error || errorMessage || 'Error';
            if (human && TEXT_TEMPLATES.warning) {
                text = applyTemplate(TEXT_TEMPLATES.warning, human);
            }
            updateStatus('error', { text });
        };

        const refreshPreview = async (force) => {
            if (!previewEndpoint || previewBusy) return;
            previewBusy = true;
            togglePreviewLoading(true);
            const processingText = TEXT_TEMPLATES.processing || TEXT_TEMPLATES.pending;
            updateStatus('processing', { text: processingText });

            try {
                const formData = new FormData();
                formData.append('project_id', String(previewWrapper.dataset.projectId || PROJECT_ID));
                if (previewCsrf) {
                    formData.append('csrf_token', previewCsrf);
                }
                if (force) {
                    formData.append('force', '1');
                }
                const response = await fetch(previewEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload || !payload.ok) {
                    const message = payload && payload.error ? payload.error : 'REQUEST_FAILED';
                    handleError(message);
                    return;
                }
                handleSuccess(payload);
            } catch (err) {
                handleError(String(err && err.message ? err.message : err));
            } finally {
                togglePreviewLoading(false);
                previewBusy = false;
            }
        };

        if (previewButton) {
            previewButton.addEventListener('click', () => refreshPreview(true));
        }
        if (previewAuto) {
            window.setTimeout(() => refreshPreview(false), 400);
        }
    }

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

    const PROMOTION_ACTIVE_STATUSES = ['queued','running','level1_active','pending_level2','level2_active','pending_crowd','crowd_ready','report_ready'];
    const PROMOTION_STATUS_LABELS = {
        'queued': '<?php echo __('Уровень 1 выполняется'); ?>',
        'running': '<?php echo __('Уровень 1 выполняется'); ?>',
        'level1_active': '<?php echo __('Уровень 1 выполняется'); ?>',
        'pending_level2': '<?php echo __('Ожидание уровня 2'); ?>',
        'level2_active': '<?php echo __('Уровень 2 выполняется'); ?>',
        'pending_crowd': '<?php echo __('Подготовка крауда'); ?>',
        'crowd_ready': '<?php echo __('Крауд готов'); ?>',
        'report_ready': '<?php echo __('Формируется отчет'); ?>',
        'completed': '<?php echo __('Завершено'); ?>',
        'failed': '<?php echo __('Ошибка продвижения'); ?>',
        'cancelled': '<?php echo __('Отменено'); ?>',
        'idle': '<?php echo __('Продвижение не запускалось'); ?>'
    };
    const PROMOTION_DETAIL_LABELS = {
        level1: <?php echo json_encode(__('Уровень 1')); ?>,
        level2: <?php echo json_encode(__('Уровень 2')); ?>,
        crowd: <?php echo json_encode(__('Крауд')); ?>
    };

    const PROMOTION_COMPLETE_TEXT = <?php echo json_encode(__('Продвижение завершено')); ?>;
    const PROMOTION_COMPLETE_TOOLTIP = <?php echo json_encode(__('Передача ссылочного веса займет 2-3 месяца, мы продолжаем мониторинг.')); ?>;

    const PROMOTION_REPORT_STRINGS = {
        root: <?php echo json_encode(__('Целевая страница')); ?>,
        level1: <?php echo json_encode(__('Уровень 1')); ?>,
        level2: <?php echo json_encode(__('Уровень 2')); ?>,
        crowd: <?php echo json_encode(__('Крауд')); ?>,
        level1Count: <?php echo json_encode(__('Успешных публикаций уровня 1')); ?>,
        level2Count: <?php echo json_encode(__('Успешных публикаций уровня 2')); ?>,
        uniqueNetworks: <?php echo json_encode(__('Уникальные сети')); ?>,
    diagramTitle: <?php echo json_encode(__('Карта публикаций по уровням')); ?>,
    diagramHelper: <?php echo json_encode(__('Выберите публикацию первого уровня, чтобы увидеть связанные материалы.')); ?>,
        exportJson: <?php echo json_encode(__('Экспорт JSON')); ?>,
        exportCsv: <?php echo json_encode(__('Экспорт CSV')); ?>,
        exportTooltip: <?php echo json_encode(__('Сохраните отчет, чтобы отправить клиенту или сохранить для аудита.')); ?>,
        tableSource: <?php echo json_encode(__('Источник')); ?>,
        tableUrl: <?php echo json_encode(__('Ссылка')); ?>,
        tableAnchor: <?php echo json_encode(__('Анкор')); ?>,
        tableStatus: <?php echo json_encode(__('Статус')); ?>,
        tableParent: <?php echo json_encode(__('Родитель')); ?>,
        noData: <?php echo json_encode(__('Нет данных отчета.')); ?>,
        summary: <?php echo json_encode(__('Сводка')); ?>,
        totalLabel: <?php echo json_encode(__('Всего')); ?>,
        targetLabel: <?php echo json_encode(__('Целевой URL')); ?>,
        statusLabel: <?php echo json_encode(__('Статус')); ?>,
        crowdLinks: <?php echo json_encode(__('Крауд ссылки')); ?>,
        filenamePrefix: <?php echo json_encode('promotion-report'); ?>
    };

    let promotionReportContext = null;

    const PROMOTION_STATS_ELEMENTS = {
        total: document.querySelector('[data-stat-total]'),
        active: document.querySelector('[data-stat-active]'),
        completed: document.querySelector('[data-stat-completed]'),
        issues: document.querySelector('[data-stat-issues]'),
        idle: document.querySelector('[data-stat-idle]'),
        idleWrapper: document.querySelector('[data-stat-idle-wrapper]')
    };

    function formatStatValue(value) {
        if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
            try { return new Intl.NumberFormat('ru-RU').format(Number(value) || 0); } catch (_) {}
        }
        return String(Number(value) || 0);
    }

    function recalcPromotionStats() {
        const tbody = linksTbody || document.querySelector('.table-links tbody');
        const rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
        let total = rows.length;
        let active = 0;
        let completed = 0;
        let idle = 0;
        let issues = 0;
        rows.forEach(tr => {
            const datasetStatus = tr.dataset.promotionStatus || '';
            const blockStatus = tr.querySelector('.promotion-status-block')?.dataset.status || '';
            const status = datasetStatus || blockStatus || 'idle';
            if (PROMOTION_ACTIVE_STATUSES.includes(status)) {
                active++;
            } else if (status === 'completed') {
                completed++;
            } else if (status === 'failed' || status === 'cancelled') {
                issues++;
            } else {
                idle++;
            }
        });

        if (PROMOTION_STATS_ELEMENTS.total) PROMOTION_STATS_ELEMENTS.total.textContent = formatStatValue(total);
        if (PROMOTION_STATS_ELEMENTS.active) PROMOTION_STATS_ELEMENTS.active.textContent = formatStatValue(active);
        if (PROMOTION_STATS_ELEMENTS.completed) PROMOTION_STATS_ELEMENTS.completed.textContent = formatStatValue(completed);
        if (PROMOTION_STATS_ELEMENTS.issues) PROMOTION_STATS_ELEMENTS.issues.textContent = formatStatValue(issues);
        if (PROMOTION_STATS_ELEMENTS.idle) PROMOTION_STATS_ELEMENTS.idle.textContent = formatStatValue(idle);
        if (PROMOTION_STATS_ELEMENTS.idleWrapper) {
            PROMOTION_STATS_ELEMENTS.idleWrapper.classList.toggle('opacity-50', total === 0);
        }
    }

    function isPromotionActiveStatus(status) {
        return PROMOTION_ACTIVE_STATUSES.includes(status);
    }

    function getPromotionStatusLabel(status) {
        if (!status) { return PROMOTION_STATUS_LABELS.idle; }
        return PROMOTION_STATUS_LABELS[status] || (status === 'idle' ? PROMOTION_STATUS_LABELS.idle : status);
    }

    function updatePromotionBlock(tr, promotion) {
        const block = tr.querySelector('.promotion-status-block');
        if (!block) return;
        const status = promotion?.status || tr.dataset.promotionStatus || 'idle';
        const stage = promotion?.stage || tr.dataset.promotionStage || '';
        const progress = promotion?.progress || {};
        const runId = promotion?.run_id || tr.dataset.promotionRunId || '';
    const levelDataRaw = promotion?.levels || {};
        const level1Data = levelDataRaw['1'] || levelDataRaw[1] || {};
        const level2Data = levelDataRaw['2'] || levelDataRaw[2] || {};
    const progressWrapper = block.querySelector('.promotion-progress-visual');
    const completeEl = block.querySelector('.promotion-status-complete');
    const completeTextEl = completeEl?.querySelector('.promotion-status-complete-text');
    const isActive = isPromotionActiveStatus(status);
    const level1Success = Number(level1Data.success ?? block.dataset.level1Success ?? tr.dataset.level1Success ?? 0) || 0;
    const level1Required = Number(level1Data.required ?? block.dataset.level1Required ?? tr.dataset.level1Required ?? progress?.target ?? progress?.total ?? 0) || 0;
    const level2Success = Number(level2Data.success ?? block.dataset.level2Success ?? tr.dataset.level2Success ?? 0) || 0;
    const level2Required = Number(level2Data.required ?? block.dataset.level2Required ?? tr.dataset.level2Required ?? 0) || 0;
    const done = Number(progress.done ?? level1Success) || 0;
    const targetRaw = progress.target ?? level1Required;
    const target = Number((targetRaw !== undefined && targetRaw !== null) ? targetRaw : 0);
    const total = target > 0 ? target : (Number(progress.total ?? level1Required ?? 0) || 0);
        const reportReady = Boolean(promotion?.report_ready || (status === 'completed') || tr.dataset.promotionReportReady === '1');

        block.dataset.status = status;
        block.dataset.stage = stage;
        block.dataset.runId = runId ? String(runId) : '';
        block.dataset.done = String(done);
        block.dataset.total = String(total);
        block.dataset.reportReady = reportReady ? '1' : '0';

        const labelEl = block.querySelector('.promotion-status-label');
        if (labelEl) {
            labelEl.textContent = getPromotionStatusLabel(status);
        }
        const countEl = block.querySelector('.promotion-progress-count');
        if (countEl) {
            if (target > 0 && isActive) {
                countEl.textContent = `(${done} / ${target})`;
                countEl.classList.remove('d-none');
            } else {
                countEl.textContent = '';
                countEl.classList.add('d-none');
            }
        }
        block.classList.remove('text-muted','text-primary','text-success','text-danger');
        if (status === 'idle') {
            block.classList.add('text-muted');
        } else if (status === 'completed') {
            block.classList.add('text-success');
        } else if (status === 'failed') {
            block.classList.add('text-danger');
        } else {
            block.classList.add('text-primary');
        }

        if (progressWrapper) {
            if (isActive) {
                progressWrapper.classList.remove('d-none');
            } else {
                progressWrapper.classList.add('d-none');
            }
        }

        if (completeEl) {
            if (status === 'completed') {
                completeEl.classList.remove('d-none');
                if (completeTextEl) { completeTextEl.textContent = PROMOTION_COMPLETE_TEXT; }
                completeEl.setAttribute('title', PROMOTION_COMPLETE_TOOLTIP);
                if (window.bootstrap && bootstrap.Tooltip) {
                    try { bootstrap.Tooltip.getOrCreateInstance(completeEl); } catch (_) {}
                }
            } else {
                completeEl.classList.add('d-none');
            }
        }

        const level1Total = level1Success;
        const level2Total = level2Success;
        const crowdData = promotion?.crowd || {};
        const crowdPlanned = Number(crowdData.planned ?? block.dataset.crowdPlanned ?? tr.dataset.crowdPlanned ?? 0);

        block.dataset.level1Total = String(level1Total);
        block.dataset.level1Success = String(level1Success);
        block.dataset.level2Total = String(level2Total);
        block.dataset.level2Success = String(level2Success);
        block.dataset.level1Required = String(level1Required);
        block.dataset.level2Required = String(level2Required);
        block.dataset.crowdPlanned = String(crowdPlanned);

    const level1El = block.querySelector('.promotion-progress-level1');
    const level2El = block.querySelector('.promotion-progress-level2');

        if (level1El) {
            const valueEl = level1El.querySelector('.promotion-progress-value');
            const barEl = level1El.querySelector('.promotion-progress-bar');
            const required = level1Required > 0 ? level1Required : level1Total;
            if (required > 0 || level1Total > 0) {
                level1El.classList.remove('d-none');
                if (valueEl) { valueEl.textContent = required > 0 ? `${level1Success} / ${required}` : String(level1Success); }
                if (barEl) {
                    const pct = required > 0 ? Math.min(100, Math.round((level1Success / required) * 100)) : 100;
                    barEl.style.width = `${pct}%`;
                    barEl.setAttribute('aria-valuenow', String(pct));
                }
            } else {
                level1El.classList.add('d-none');
            }
        }

        if (level2El) {
            const valueEl = level2El.querySelector('.promotion-progress-value');
            const barEl = level2El.querySelector('.promotion-progress-bar');
            if ((level2Required > 0 || level2Success > 0) && !Number.isNaN(level2Success)) {
                level2El.classList.remove('d-none');
                const required = level2Required > 0 ? level2Required : level2Success;
                if (valueEl) { valueEl.textContent = required > 0 ? `${level2Success} / ${required}` : String(level2Success); }
                if (barEl) {
                    const pct = required > 0 ? Math.min(100, Math.round((level2Success / required) * 100)) : 100;
                    barEl.style.width = `${pct}%`;
                    barEl.setAttribute('aria-valuenow', String(pct));
                }
            } else {
                level2El.classList.add('d-none');
            }
        }

        const detailsEl = block.querySelector('.promotion-progress-details');
        if (detailsEl) {
            if (status === 'completed') {
                detailsEl.innerHTML = '';
                detailsEl.classList.add('d-none');
            } else {
                const details = [];
                if (level1Success > 0 || level1Required > 0) {
                    details.push(`${PROMOTION_DETAIL_LABELS.level1}: ${level1Success}${level1Required > 0 ? ' / ' + level1Required : ''}`);
                }
                if (level2Success > 0 || level2Required > 0) {
                    details.push(`${PROMOTION_DETAIL_LABELS.level2}: ${level2Success}${level2Required > 0 ? ' / ' + level2Required : ''}`);
                }
                if (crowdPlanned > 0) {
                    details.push(`${PROMOTION_DETAIL_LABELS.crowd}: ${crowdPlanned}`);
                }
                if (details.length) {
                    detailsEl.innerHTML = details.map(text => `<div>${escapeHtml(text)}</div>`).join('');
                    detailsEl.classList.remove('d-none');
                } else {
                    detailsEl.innerHTML = '';
                    detailsEl.classList.add('d-none');
                }
            }
        }

        tr.dataset.promotionStatus = status;
        tr.dataset.promotionStage = stage || '';
        tr.dataset.promotionRunId = runId ? String(runId) : '';
        tr.dataset.promotionReportReady = reportReady ? '1' : '0';
        tr.dataset.promotionDone = String(done);
        tr.dataset.promotionTotal = String(total);
        tr.dataset.level1Total = String(level1Total);
        tr.dataset.level1Success = String(level1Success);
        tr.dataset.level2Total = String(level2Total);
        tr.dataset.level2Success = String(level2Success);
        tr.dataset.level1Required = String(level1Required);
        tr.dataset.level2Required = String(level2Required);
        tr.dataset.crowdPlanned = String(crowdPlanned);

        if (typeof initTooltips === 'function') {
            initTooltips(block);
        }

        recalcPromotionStats();
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

    function refreshStatusCell(tr, status, payload = {}) {
        const statusCell = tr.querySelector('.status-cell');
        if (!statusCell) return;
        const promotionBlock = statusCell.querySelector('.promotion-status-block');
        const postUrl = payload.post_url || tr.dataset.postUrl || '';
        const networkLabel = payload.network_title || payload.network || tr.dataset.network || '';
        statusCell.innerHTML = '';
        if (promotionBlock) {
            statusCell.appendChild(promotionBlock);
        }
        tr.dataset.postUrl = postUrl || '';
        tr.dataset.network = networkLabel || '';
        tr.dataset.publicationStatus = status || tr.dataset.publicationStatus || 'not_published';
    }

    function refreshActionsCell(tr) {
        const actionsCell = tr.querySelector('td.text-end');
        if (!actionsCell) return;
        const promotionStatus = tr.dataset.promotionStatus || 'idle';
        const promotionRunId = tr.dataset.promotionRunId || '';
        const promotionReportReady = tr.dataset.promotionReportReady === '1';
        const promotionActive = isPromotionActiveStatus(promotionStatus);
        const promotionFinished = promotionStatus === 'completed';
        const postUrl = tr.dataset.postUrl || '';
        const url = tr.querySelector('.url-cell .view-url')?.getAttribute('href') || '';
        const linkId = tr.getAttribute('data-id') || '';
        let html = '<button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>';

        if (postUrl) {
            html += '<a href="' + escapeAttribute(postUrl) + '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-box-arrow-up-right me-1"></i><span class="d-none d-lg-inline"><?php echo __('Открыть'); ?></span></a>';
        }

        if (promotionFinished) {
            // no promote button when completed
        } else if (promotionActive) {
            html += '<button type="button" class="btn btn-sm btn-publish me-1" disabled data-loading="1">'
                + '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                + '<span class="label d-none d-md-inline"><?php echo __('Продвижение выполняется'); ?></span>'
                + '</button>';
        } else {
            html += '<button type="button" class="btn btn-sm btn-publish me-1 action-promote" data-url="' + escapeAttribute(url) + '" data-id="' + escapeAttribute(linkId) + '"><i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span></button>';
        }

        if (promotionRunId && promotionActive) {
            html += '<button type="button" class="btn btn-outline-info btn-sm me-1 action-promotion-progress" data-run-id="' + escapeAttribute(promotionRunId) + '" data-url="' + escapeAttribute(url) + '" title="<?php echo __('Промежуточный отчет'); ?>"><i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span></button>';
        }

        if (promotionReportReady && promotionRunId) {
            html += '<button type="button" class="btn btn-outline-success btn-sm me-1 action-promotion-report" data-run-id="' + escapeAttribute(promotionRunId) + '" data-url="' + escapeAttribute(url) + '" title="<?php echo __('Скачать отчет'); ?>"><i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span></button>';
        }

    const canEdit = (promotionStatus === 'idle');
        if (canEdit) {
            html += '<button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>';
            html += '<button type="button" class="icon-btn action-remove" data-id="' + escapeAttribute(linkId) + '" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>';
        } else {
            html += '<button type="button" class="icon-btn disabled" disabled title="<?php echo __('Редактирование доступно до запуска продвижения.'); ?>"><i class="bi bi-lock"></i></button>';
            html += '<button type="button" class="icon-btn disabled" disabled title="<?php echo __('Удаление доступно до запуска продвижения.'); ?>"><i class="bi bi-trash"></i></button>';
        }

        actionsCell.innerHTML = html;
        bindDynamicRowActions();
        recalcPromotionStats();
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
        const status = tr.dataset.promotionStatus || 'idle';
        if (status !== 'idle') {
            alert('<?php echo __('Редактирование доступно до запуска продвижения.'); ?>');
            return;
        }
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
        const status = tr.dataset.promotionStatus || 'idle';
        if (status !== 'idle') {
            alert('<?php echo __('Удаление доступно до запуска продвижения.'); ?>');
            return;
        }
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
            recalcPromotionStats();
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

    // Avoid alerts if user navigates away while request is in-flight
    let PP_PAGE_UNLOADING = false;
    window.addEventListener('beforeunload', () => { PP_PAGE_UNLOADING = true; });

    async function startPromotion(btn, url) {
        const csrf = getCsrfToken();
        if (!csrf) { alert('CSRF missing'); return; }
        if (!url) { alert('<?php echo __('Сначала сохраните ссылку перед запуском продвижения.'); ?>'); return; }
        const tr = btn.closest('tr');
        setButtonLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('project_id', PROJECT_ID);
            fd.append('url', url);
            const res = await fetch('<?php echo pp_url('public/promote_link.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json().catch(()=>null);
            if (!data || !data.ok) {
                let msg = data?.error || 'ERROR';
                const map = {
                    'LEVEL1_DISABLED': '<?php echo __('Уровень 1 отключен в настройках.'); ?>',
                    'URL_NOT_IN_PROJECT': '<?php echo __('Ссылка не принадлежит проекту.'); ?>',
                    'DB': '<?php echo __('Ошибка базы данных'); ?>',
                    'FORBIDDEN': '<?php echo __('Нет прав'); ?>',
                    'INSUFFICIENT_FUNDS': '<?php echo __('Недостаточно средств на балансе.'); ?>'
                };
                if (map[msg]) { msg = map[msg]; }
                alert('<?php echo __('Ошибка запуска продвижения'); ?>: ' + msg);
                return;
            }
            if (tr) {
                updatePromotionBlock(tr, data.promotion || data);
                refreshActionsCell(tr);
            }
            await pollPromotionStatusesOnce();
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    async function cancelPromotion(btn, url) {
        if (!confirm('<?php echo __('Остановить продвижение для этой ссылки?'); ?>')) return;
        const csrf = getCsrfToken();
        if (!csrf) { alert('CSRF missing'); return; }
        const tr = btn.closest('tr');
        setButtonLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('project_id', PROJECT_ID);
            fd.append('url', url);
            const res = await fetch('<?php echo pp_url('public/promotion_cancel.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json().catch(()=>null);
            if (!data || !data.ok) {
                let msg = data?.error || 'ERROR';
                const map = {
                    'NOT_FOUND': '<?php echo __('Активное продвижение не найдено.'); ?>',
                    'DB': '<?php echo __('Ошибка базы данных'); ?>',
                    'FORBIDDEN': '<?php echo __('Нет прав'); ?>'
                };
                if (map[msg]) { msg = map[msg]; }
                alert('<?php echo __('Ошибка остановки продвижения'); ?>: ' + msg);
                return;
            }
            if (tr) {
                updatePromotionBlock(tr, data.promotion || { status: 'cancelled' });
                refreshActionsCell(tr);
            }
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    function renderPromotionReport(report, meta = {}) {
        const normalized = normalizePromotionReport(report || {});
        if (!normalized.hasData) {
            promotionReportContext = null;
            return `<div class="text-muted">${escapeHtml(PROMOTION_REPORT_STRINGS.noData)}</div>`;
        }
        promotionReportContext = { report: report || {}, normalized, meta, activeParentId: null };
        const summaryItems = [
            { label: PROMOTION_REPORT_STRINGS.level1Count, value: normalized.level1.length },
            { label: PROMOTION_REPORT_STRINGS.level2Count, value: normalized.level2.length },
            { label: PROMOTION_REPORT_STRINGS.uniqueNetworks, value: normalized.uniqueNetworks }
        ];
        if (normalized.crowd.length) {
            summaryItems.push({ label: PROMOTION_REPORT_STRINGS.crowdLinks, value: normalized.crowd.length });
        }
        const summaryHtml = summaryItems.map(item => `
            <li class="promotion-report-summary-item">
                <span class="label">${escapeHtml(item.label)}</span>
                <span class="value">${escapeHtml(String(item.value))}</span>
            </li>
        `).join('');
        const targetUrl = meta?.target || '';
        const targetLink = targetUrl ? `<a href="${escapeAttribute(targetUrl)}" target="_blank" rel="noopener">${escapeHtml(targetUrl)}</a>` : '—';
        const statusText = meta?.status ? escapeHtml(getPromotionStatusLabel(meta.status)) : '—';
        const flowHtml = buildPromotionReportFlow(normalized, meta);
        const tablesHtml = buildPromotionReportTables(normalized, meta);
        const totalNodes = normalized.level1.length + normalized.level2.length;
        return `
            <div class="promotion-report-wrapper" data-report-root>
                <div class="promotion-report-toolbar d-flex flex-column flex-lg-row gap-2 align-items-lg-center justify-content-between mb-3">
                    <div class="text-muted small">${escapeHtml(PROMOTION_REPORT_STRINGS.exportTooltip)}</div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-light promotion-report-export" data-format="json"><i class="bi bi-filetype-json me-1"></i>${escapeHtml(PROMOTION_REPORT_STRINGS.exportJson)}</button>
                        <button type="button" class="btn btn-outline-light promotion-report-export" data-format="csv"><i class="bi bi-table me-1"></i>${escapeHtml(PROMOTION_REPORT_STRINGS.exportCsv)}</button>
                    </div>
                </div>
                <div class="promotion-report-grid mb-4">
                    <div class="promotion-report-visual promotion-report-panel">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="fw-semibold mb-0">${escapeHtml(PROMOTION_REPORT_STRINGS.diagramTitle)}</h6>
                            <span class="badge bg-primary-subtle text-light-emphasis">${escapeHtml(PROMOTION_REPORT_STRINGS.totalLabel)}: ${totalNodes}</span>
                        </div>
                        <div class="promotion-report-flow" data-report-flow>
                            ${flowHtml}
                        </div>
                        <p class="small text-muted mt-2 mb-0">${escapeHtml(PROMOTION_REPORT_STRINGS.diagramHelper)}</p>
                    </div>
                    <div class="promotion-report-summary promotion-report-panel">
                        <h6 class="fw-semibold mb-3">${escapeHtml(PROMOTION_REPORT_STRINGS.summary)}</h6>
                        <ul class="promotion-report-summary-list list-unstyled mb-3">
                            ${summaryHtml}
                        </ul>
                        <div class="promotion-report-meta small text-muted">
                            <div><strong>${escapeHtml(PROMOTION_REPORT_STRINGS.targetLabel)}:</strong> ${targetLink}</div>
                            <div><strong>${escapeHtml(PROMOTION_REPORT_STRINGS.statusLabel)}:</strong> ${statusText}</div>
                        </div>
                    </div>
                </div>
                ${tablesHtml}
            </div>
        `;
    }

    function normalizePromotionReport(report) {
        const level1Raw = Array.isArray(report?.level1) ? report.level1 : [];
        const level2Raw = Array.isArray(report?.level2) ? report.level2 : [];
        const crowdRaw = Array.isArray(report?.crowd) ? report.crowd : [];
        const level1 = level1Raw
            .filter(item => item && (item.url || item.target_url))
            .map(item => ({ ...item }));
        const parentMap = new Map();
        level1.forEach(node => {
            const nodeId = getReportNodeId(node);
            node._id = nodeId;
            parentMap.set(nodeId, node);
        });
        const childrenMap = new Map();
        const level2 = level2Raw
            .filter(item => item && (item.url || item.target_url))
            .map(item => {
                const clone = { ...item };
                const parentId = getReportParentId(clone);
                const nodeId = getReportNodeId(clone);
                clone._id = nodeId;
                clone.parent_id = parentId;
                if (!childrenMap.has(parentId)) { childrenMap.set(parentId, []); }
                childrenMap.get(parentId).push(clone);
                return clone;
            });
        const crowd = crowdRaw
            .filter(item => item && item.target_url)
            .map(item => ({ ...item }));
        const uniqueNetworks = new Set();
        level1.forEach(node => { if (node.network) uniqueNetworks.add(node.network); });
        level2.forEach(node => { if (node.network) uniqueNetworks.add(node.network); });
        return {
            hasData: level1.length > 0 || level2.length > 0 || crowd.length > 0,
            level1,
            level2,
            crowd,
            childrenMap,
            parentMap,
            uniqueNetworks: uniqueNetworks.size,
        };
    }

    function buildPromotionReportFlow(normalized, meta) {
        const columns = [];
        columns.push(buildPromotionFlowRoot(meta));
        columns.push(buildPromotionFlowLevelColumn(normalized.level1, 1));
        columns.push(buildPromotionFlowLevel2Column(normalized));
        if (normalized.crowd.length) {
            columns.push(buildPromotionFlowCrowdColumn(normalized.crowd));
        }
        return columns.join('');
    }

    function buildPromotionFlowRoot(meta) {
        const targetUrl = meta?.target || '';
        const host = getHostname(targetUrl);
        const hostDisplay = truncateMiddle(host, 44);
        const resetCard = `
            <div class="promotion-flow-card is-root" role="button" tabindex="0" data-flow-reset="1">
                <div class="card-title d-flex align-items-center gap-2">
                    <span class="badge bg-primary-subtle text-light-emphasis">ROOT</span>
                    <span class="text-truncate">${escapeHtml(PROMOTION_REPORT_STRINGS.root)}</span>
                </div>
                ${host ? `<div class="card-meta small text-muted" title="${escapeAttribute(host)}">${escapeHtml(hostDisplay)}</div>` : ''}
            </div>`;
        const linkHtml = targetUrl ? `<a href="${escapeAttribute(targetUrl)}" class="small text-muted text-decoration-none" target="_blank" rel="noopener" title="${escapeAttribute(targetUrl)}">${escapeHtml(truncateMiddle(targetUrl, 56))}</a>` : `<span class="small text-muted">—</span>`;
        return `
            <div class="promotion-flow-column" data-flow-column="root">
                <div class="promotion-flow-header">
                    <div class="title">${escapeHtml(PROMOTION_REPORT_STRINGS.root)}</div>
                    ${linkHtml}
                </div>
                <div class="promotion-flow-body">
                    ${resetCard}
                </div>
            </div>
        `;
    }

    function buildPromotionFlowLevelColumn(items, level) {
        const label = level === 1 ? PROMOTION_REPORT_STRINGS.level1 : PROMOTION_REPORT_STRINGS.level2;
        const clickable = level === 1;
        let cards = items.map(node => buildPromotionFlowCard(node, level, { clickable })).join('');
        if (!cards) {
            cards = `<div class="promotion-flow-empty text-muted">${escapeHtml(PROMOTION_REPORT_STRINGS.noData)}</div>`;
        }
        return `
            <div class="promotion-flow-column" data-flow-column="level${level}">
                <div class="promotion-flow-header d-flex align-items-center justify-content-between">
                    <div class="title">${escapeHtml(label)}</div>
                    <span class="badge bg-secondary-subtle text-light-emphasis">${items.length}</span>
                </div>
                <div class="promotion-flow-body">
                    ${cards}
                </div>
            </div>
        `;
    }

    function buildPromotionFlowLevel2Column(normalized) {
        const groups = [];
        const processed = new Set();
        const renderGroup = (parentId, title, children) => {
            const groupCards = children.map(child => buildPromotionFlowCard(child, 2, { clickable: false })).join('');
            return `
                <div class="promotion-flow-subgroup" data-parent-id="${escapeAttribute(parentId || '')}">
                    <div class="subgroup-title small text-muted">${escapeHtml(title)}</div>
                    ${groupCards}
                </div>
            `;
        };
        normalized.level1.forEach(parent => {
            const parentId = getReportNodeId(parent);
            const children = normalized.childrenMap.get(parentId) || [];
            if (!children.length) { return; }
            processed.add(parentId);
            groups.push(renderGroup(parentId, parent.network || PROMOTION_REPORT_STRINGS.level1, children));
        });
        normalized.childrenMap.forEach((children, parentId) => {
            if (processed.has(parentId)) { return; }
            if (!children || !children.length) { return; }
            const parentNode = normalized.parentMap.get(parentId);
            let title = parentNode ? (parentNode.network || PROMOTION_REPORT_STRINGS.level1) : PROMOTION_REPORT_STRINGS.root;
            if (!parentNode && parentId && parentId !== '0') {
                title = `${PROMOTION_REPORT_STRINGS.level1} #${parentId}`;
            }
            groups.push(renderGroup(parentId, title, children));
        });
        const body = groups.length ? groups.join('') : `<div class="promotion-flow-empty text-muted">${escapeHtml(PROMOTION_REPORT_STRINGS.noData)}</div>`;
        return `
            <div class="promotion-flow-column" data-flow-column="level2">
                <div class="promotion-flow-header d-flex align-items-center justify-content-between">
                    <div class="title">${escapeHtml(PROMOTION_REPORT_STRINGS.level2)}</div>
                    <span class="badge bg-secondary-subtle text-light-emphasis">${normalized.level2.length}</span>
                </div>
                <div class="promotion-flow-body">
                    ${body}
                </div>
            </div>
        `;
    }

    function buildPromotionFlowCrowdColumn(items) {
        const list = items.map(item => {
            const url = item.target_url || '';
            const label = formatFlowUrl(url);
            const idLabel = item.crowd_link_id ? `#${escapeHtml(String(item.crowd_link_id))}` : '';
            return `
                <div class="promotion-flow-card level-crowd" data-level="crowd">
                    <div class="card-title d-flex align-items-center justify-content-between">
                        <span class="text-truncate">${escapeHtml(PROMOTION_REPORT_STRINGS.crowd)}</span>
                        ${idLabel ? `<span class="badge bg-info-subtle text-light-emphasis">${idLabel}</span>` : ''}
                    </div>
                    ${url ? `<a href="${escapeAttribute(url)}" target="_blank" rel="noopener" class="card-link text-truncate" title="${escapeAttribute(url)}">${escapeHtml(label)}</a>` : `<span class="card-link text-muted">—</span>`}
                </div>
            `;
        }).join('');
        return `
            <div class="promotion-flow-column" data-flow-column="crowd">
                <div class="promotion-flow-header d-flex align-items-center justify-content-between">
                    <div class="title">${escapeHtml(PROMOTION_REPORT_STRINGS.crowd)}</div>
                    <span class="badge bg-secondary-subtle text-light-emphasis">${items.length}</span>
                </div>
                <div class="promotion-flow-body">
                    ${list}
                </div>
            </div>
        `;
    }

    function buildPromotionFlowCard(node, level, options = {}) {
        const clickable = options.clickable !== false;
        const classes = ['promotion-flow-card', `level-${level}`];
        if (clickable) { classes.push('is-clickable'); }
        if (options.extraClass) { classes.push(options.extraClass); }
        const nodeId = getReportNodeId(node);
        const parentId = getReportParentId(node);
        const url = node.url || node.target_url || '';
        const label = formatFlowUrl(url);
        const anchor = node.anchor ? String(node.anchor) : '';
        const host = getHostname(url);
        const attrs = [
            `class="${classes.join(' ')}"`,
            `data-node-id="${escapeAttribute(nodeId)}"`,
            `data-level="${escapeAttribute(String(level))}"`
        ];
        if (clickable) {
            attrs.push('role="button"');
            attrs.push('tabindex="0"');
        }
        if (parentId) {
            attrs.push(`data-parent-id="${escapeAttribute(parentId)}"`);
        }
        if (url) {
            attrs.push(`data-url="${escapeAttribute(url)}"`);
        }
        if (node.network) {
            attrs.push(`data-network="${escapeAttribute(String(node.network))}"`);
        }
        return `
            <div ${attrs.join(' ')}>
                <div class="card-title d-flex align-items-center justify-content-between gap-2">
                    <span class="text-truncate">${escapeHtml(node.network || (level === 1 ? PROMOTION_REPORT_STRINGS.level1 : PROMOTION_REPORT_STRINGS.level2))}</span>
                    <span class="badge bg-primary-subtle text-light-emphasis">L${level}</span>
                </div>
                ${url ? `<a href="${escapeAttribute(url)}" target="_blank" rel="noopener" class="card-link text-truncate" title="${escapeAttribute(url)}">${escapeHtml(label)}</a>` : `<span class="card-link text-muted">—</span>`}
                ${anchor ? `<div class="card-meta small text-muted" title="${escapeAttribute(anchor)}">${escapeHtml(truncateMiddle(anchor, 44))}</div>` : ''}
                ${host ? `<div class="card-meta small text-muted" title="${escapeAttribute(host)}">${escapeHtml(host)}</div>` : ''}
            </div>
        `;
    }

    function getReportNodeId(node) {
        if (!node) { return ''; }
        const raw = node.node_id ?? node.id ?? node.nodeId ?? '';
        return raw !== undefined && raw !== null ? String(raw) : '';
    }

    function getReportParentId(node) {
        if (!node) { return ''; }
        const raw = node.parent_id ?? node.parentId ?? '';
        return raw !== undefined && raw !== null ? String(raw) : '';
    }

    function truncateMiddle(text, maxLen = 40) {
        const str = String(text ?? '');
        if (str.length <= maxLen) { return str; }
        const ellipsis = '…';
        const keep = maxLen - ellipsis.length;
        const head = Math.ceil(keep / 2);
        const tail = Math.floor(keep / 2);
        return str.slice(0, head) + ellipsis + str.slice(str.length - tail);
    }

    function formatFlowUrl(url, maxLen = 36) {
        if (!url) { return ''; }
        try {
            const parsed = new URL(url);
            const host = (parsed.hostname || '').replace(/^www\./i, '');
            const path = parsed.pathname || '/';
            const search = parsed.search || '';
            const hash = parsed.hash || '';
            const combined = `${host}${path}${search}${hash}`;
            return truncateMiddle(combined, maxLen);
        } catch (_e) {
            return truncateMiddle(url, maxLen);
        }
    }

    function buildPromotionReportTables(normalized, meta) {
        const sections = [];
        if (normalized.level1.length) {
            sections.push(buildPromotionReportLevelSection(normalized.level1, PROMOTION_REPORT_STRINGS.level1));
        }
        if (normalized.level2.length) {
            sections.push(buildPromotionReportLevelSection(normalized.level2, PROMOTION_REPORT_STRINGS.level2, normalized.parentMap));
        }
        if (normalized.crowd.length) {
            sections.push(buildPromotionReportCrowdSection(normalized.crowd));
        }
        return sections.join('');
    }

    function buildPromotionReportLevelSection(items, title, parentMap = null) {
        const rows = items.map(item => {
            const network = escapeHtml(item.network || '—');
            const url = item.url || item.target_url || '';
            const displayUrl = url ? `<a href="${escapeAttribute(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>` : '—';
            const anchor = escapeHtml(item.anchor || '');
            const status = escapeHtml(item.status || 'success');
            let parent = '—';
            if (parentMap) {
                const parentId = getReportParentId(item);
                const parentNode = parentMap.get(parentId);
                if (parentNode) {
                    const parentUrl = parentNode.url || parentNode.target_url || '';
                    parent = parentUrl ? `<a href="${escapeAttribute(parentUrl)}" target="_blank" rel="noopener">${escapeHtml(parentUrl)}</a>` : '—';
                }
            }
            return `
                <tr>
                    <td class="text-nowrap"><span class="badge bg-primary-subtle text-light-emphasis">${network}</span></td>
                    <td>${displayUrl}</td>
                    <td>${anchor || '—'}</td>
                    ${parentMap ? `<td>${parent}</td>` : ''}
                    <td class="text-nowrap"><span class="badge bg-success-subtle text-light-emphasis">${status}</span></td>
                </tr>
            `;
        }).join('');
        const headerCols = `
            <th>${escapeHtml(PROMOTION_REPORT_STRINGS.tableSource)}</th>
            <th>${escapeHtml(PROMOTION_REPORT_STRINGS.tableUrl)}</th>
            <th>${escapeHtml(PROMOTION_REPORT_STRINGS.tableAnchor)}</th>
            ${parentMap ? `<th>${escapeHtml(PROMOTION_REPORT_STRINGS.tableParent)}</th>` : ''}
            <th>${escapeHtml(PROMOTION_REPORT_STRINGS.tableStatus)}</th>`;
        return `
            <div class="promotion-report-section mb-4">
                <h6 class="fw-semibold mb-2">${escapeHtml(title)} (${items.length})</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped promotion-report-table align-middle">
                        <thead><tr>${headerCols}</tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        `;
    }

    function buildPromotionReportCrowdSection(items) {
        const rows = items.map(item => {
            const target = item.target_url || '';
            return `<li>${escapeHtml(String(item.crowd_link_id || ''))} → ${target ? `<a href="${escapeAttribute(target)}" target="_blank" rel="noopener">${escapeHtml(target)}</a>` : '—'}</li>`;
        }).join('');
        return `
            <div class="promotion-report-section">
                <h6 class="fw-semibold mb-2">${escapeHtml(PROMOTION_REPORT_STRINGS.crowdLinks)} (${items.length})</h6>
                <ul class="promotion-report-crowd list-unstyled mb-0">
                    ${rows}
                </ul>
            </div>
        `;
    }

    function getHostname(url) {
        if (!url) return '';
        try {
            const host = new URL(url).hostname || '';
            return host.replace(/^www\./i, '');
        } catch (_e) {
            return '';
        }
    }


    function buildReportFilename(ext) {
        const stamp = new Date().toISOString().replace(/[:T]/g, '-').split('.')[0];
        return `${PROMOTION_REPORT_STRINGS.filenamePrefix}-${stamp}.${ext}`;
    }

    function downloadPromotionReportFile(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(url), 750);
    }

    function exportPromotionReportJSON(context) {
        if (!context) { return; }
        const payload = {
            generated_at: new Date().toISOString(),
            meta: {
                target: context.meta?.target || '',
                status: context.meta?.status || '',
                level1_count: context.normalized.level1.length,
                level2_count: context.normalized.level2.length,
                crowd_count: context.normalized.crowd.length,
            },
            report: context.report || {}
        };
        const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        downloadPromotionReportFile(blob, buildReportFilename('json'));
    }

    function csvEscape(value) {
        const str = String(value ?? '');
        if (/[";\n]/.test(str)) {
            return '"' + str.replace(/"/g, '""') + '"';
        }
        return str;
    }

    function exportPromotionReportCSV(context) {
        if (!context) { return; }
        const rows = [['level', 'network', 'url', 'anchor', 'parent_url']];
        const target = context.meta?.target || '';
        context.normalized.level1.forEach(node => {
            rows.push([
                'level1',
                node.network || '',
                node.url || node.target_url || '',
                node.anchor || '',
                target
            ]);
        });
        context.normalized.level2.forEach(node => {
            const parentId = getReportParentId(node);
            const parentNode = context.normalized.parentMap.get(parentId);
            const parentUrl = parentNode ? (parentNode.url || parentNode.target_url || '') : '';
            rows.push([
                'level2',
                node.network || '',
                node.url || node.target_url || '',
                node.anchor || '',
                parentUrl
            ]);
        });
        const csv = rows.map(row => row.map(csvEscape).join(';')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        downloadPromotionReportFile(blob, buildReportFilename('csv'));
    }

    function initPromotionReportInteractive(container) {
        if (!promotionReportContext || !container) { return; }
        const flow = container.querySelector('[data-report-flow]');
        const level1Cards = flow ? Array.from(flow.querySelectorAll('.promotion-flow-card.level-1')) : [];
        const rootCard = flow ? flow.querySelector('.promotion-flow-card[data-flow-reset="1"]') : null;
        const level2Groups = flow ? Array.from(flow.querySelectorAll('.promotion-flow-subgroup')) : [];
        const level2Cards = flow ? Array.from(flow.querySelectorAll('.promotion-flow-card.level-2')) : [];

        const setActiveParent = parentId => {
            const active = parentId || '';
            promotionReportContext.activeParentId = active || null;
            level1Cards.forEach(card => {
                const matches = active && card.dataset.nodeId === active;
                card.classList.toggle('is-active', matches);
            });
            level2Groups.forEach(group => {
                const matches = !active || group.dataset.parentId === active;
                group.classList.toggle('is-hidden', !matches);
            });
            level2Cards.forEach(card => {
                const matches = !active || card.dataset.parentId === active;
                card.classList.toggle('is-dimmed', !matches);
            });
            if (flow) {
                flow.classList.toggle('has-filter', Boolean(active));
            }
        };

        const bindCardToggle = card => {
            if (!card) { return; }
            const nodeId = card.dataset.nodeId || '';
            const toggle = () => {
                if (!nodeId) { return; }
                setActiveParent(promotionReportContext.activeParentId === nodeId ? null : nodeId);
            };
            card.addEventListener('click', toggle);
            card.addEventListener('keydown', evt => {
                if (evt.key === 'Enter' || evt.key === ' ') {
                    evt.preventDefault();
                    toggle();
                }
            });
        };

        if (rootCard) {
            const reset = () => setActiveParent(null);
            rootCard.addEventListener('click', reset);
            rootCard.addEventListener('keydown', evt => {
                if (evt.key === 'Enter' || evt.key === ' ') {
                    evt.preventDefault();
                    reset();
                }
            });
        }

        level1Cards.forEach(bindCardToggle);
        setActiveParent(null);

        container.querySelectorAll('.promotion-report-export').forEach(btn => {
            if (btn.dataset.bound === '1') { return; }
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => {
                const format = btn.getAttribute('data-format');
                if (format === 'json') { exportPromotionReportJSON(promotionReportContext); }
                else if (format === 'csv') { exportPromotionReportCSV(promotionReportContext); }
            });
        });
    }

    async function openPromotionReport(btn) {
        const runId = btn.getAttribute('data-run-id');
        if (!runId) return;
        setButtonLoading(btn, true);
        try {
            const params = new URLSearchParams({ run_id: runId });
            const res = await fetch('<?php echo pp_url('public/promotion_report.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
            const data = await res.json().catch(()=>null);
            if (!data || !data.ok) {
                let msg = data?.error || 'ERROR';
                const map = {
                    'FORBIDDEN': '<?php echo __('Нет прав'); ?>',
                    'NOT_FOUND': '<?php echo __('Отчет не найден'); ?>'
                };
                if (map[msg]) { msg = map[msg]; }
                alert('<?php echo __('Ошибка получения отчета'); ?>: ' + msg);
                return;
            }
            const modalEl = document.getElementById('promotionReportModal');
            const bodyEl = document.getElementById('promotionReportContent');
            if (!modalEl || !bodyEl) return;
            bodyEl.innerHTML = renderPromotionReport(data.report || {}, { target: data.target_url || '', status: data.status || '' });
            initPromotionReportInteractive(bodyEl);
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    function updateRowUI(url, status, payload = {}) {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        rows.forEach(tr => {
            const linkEl = tr.querySelector('.url-cell .view-url');
            if (!linkEl) return;
            if (linkEl.getAttribute('href') !== url) return;

            tr.dataset.publicationStatus = status;

            const promotionData = payload.promotion || null;
            refreshStatusCell(tr, status, payload);
            if (promotionData) { updatePromotionBlock(tr, promotionData); }
            refreshActionsCell(tr);
        });
    }

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
                tr.dataset.publicationStatus = 'not_published';
                tr.dataset.promotionStatus = 'idle';
                tr.dataset.promotionStage = '';
                tr.dataset.promotionRunId = '';
                tr.dataset.promotionReportReady = '0';
                tr.dataset.promotionTotal = '0';
                tr.dataset.promotionDone = '0';
                tr.dataset.promotionTarget = '0';
                tr.dataset.promotionAttempted = '0';
                tr.dataset.level1Total = '0';
                tr.dataset.level1Success = '0';
                tr.dataset.level1Required = '0';
                tr.dataset.level2Total = '0';
                tr.dataset.level2Success = '0';
                tr.dataset.level2Required = '0';
                tr.dataset.crowdPlanned = '0';
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
                        <div class="promotion-status-block small mt-2 text-muted"
                             data-run-id=""
                             data-status="idle"
                             data-stage=""
                        data-total="0"
                             data-done="0"
                             data-report-ready="0"
                             data-level1-total="0"
                             data-level1-success="0"
                        data-level1-required="0"
                             data-level2-total="0"
                             data-level2-success="0"
                        data-level2-required="0"
                             data-crowd-planned="0">
                            <div class="promotion-status-top">
                                <span class="promotion-status-heading"><?php echo __('Продвижение'); ?>:</span>
                                <span class="promotion-status-label ms-1"><?php echo __('Продвижение не запускалось'); ?></span>
                                <span class="promotion-progress-count ms-1 d-none"></span>
                            </div>
                            <div class="promotion-progress-visual mt-2 d-none">
                                <div class="promotion-progress-level promotion-progress-level1 d-none" data-level="1">
                                    <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                        <span><?php echo __('Уровень 1'); ?></span>
                                        <span class="promotion-progress-value">0 / 0</span>
                                    </div>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar promotion-progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                    </div>
                                </div>
                                <div class="promotion-progress-level promotion-progress-level2 d-none" data-level="2">
                                    <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                        <span><?php echo __('Уровень 2'); ?></span>
                                        <span class="promotion-progress-value">0 / 0</span>
                                    </div>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar promotion-progress-bar bg-info" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="promotion-progress-details text-muted d-none"></div>
                            <div class="promotion-status-complete mt-2 d-none" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo __('Передача ссылочного веса займет 2-3 месяца, мы продолжаем мониторинг.'); ?>">
                                <i class="bi bi-patch-check-fill text-success"></i>
                                <span class="promotion-status-complete-text"><?php echo __('Продвижение завершено'); ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="text-end">
                        <button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                        <button type="button" class="btn btn-sm btn-publish me-1 action-promote" data-url="${escapeHtml(url)}" data-id="${String(newId)}">
                            <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span>
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm me-1 action-promotion-progress d-none" data-run-id="0" data-url="${escapeHtml(url)}">
                            <i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span>
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm me-1 action-promotion-report d-none" data-run-id="0" data-url="${escapeHtml(url)}">
                            <i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span>
                        </button>
                        <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="icon-btn action-remove" data-id="${String(newId)}" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                    </td>`;
                tbody.appendChild(tr);
                refreshRowNumbers();
                bindDynamicRowActions();
                initTooltips(tr);
                recalcPromotionStats();
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

    // Extend binder to include promote/cancel/report/analyze/wish/edit/remove
    function bindDynamicRowActions() {
        document.querySelectorAll('.action-promote').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                startPromotion(btn, url);
            });
        });
        document.querySelectorAll('.action-promotion-progress').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                openPromotionReport(btn);
            });
        });
        document.querySelectorAll('.action-promotion-report').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                openPromotionReport(btn);
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
    recalcPromotionStats();

    // Background polling for pending statuses so user can navigate and return
    async function pollStatusesOnce() {
        try {
            const rows = document.querySelectorAll('table.table-links tbody tr');
            for (const tr of rows) {
                const linkEl = tr.querySelector('.url-cell .view-url');
                if (!linkEl) continue;
                const currentStatus = tr.dataset.publicationStatus || 'not_published';
                if (currentStatus !== 'pending') continue;
                const url = linkEl.getAttribute('href');
                const fd = new URLSearchParams();
                fd.set('project_id', String(PROJECT_ID));
                fd.set('url', url);
                const res = await fetch('<?php echo pp_url('public/publication_status.php'); ?>?' + fd.toString(), { credentials:'same-origin' });
                const data = await res.json().catch(()=>null);
                if (!data || !data.ok) continue;
                if (data.status === 'published') {
                    updateRowUI(url, 'published', data);
                } else if (data.status === 'manual_review') {
                    updateRowUI(url, 'manual_review', data);
                } else if (data.status === 'failed') {
                    // Reset to not_published to allow retry; optionally show error via tooltip
                    updateRowUI(url, 'not_published', {});
                }
            }
        } catch (_e) { /* ignore */ }
        await pollPromotionStatusesOnce();
    }

    async function pollPromotionStatusesOnce() {
        try {
            const rows = document.querySelectorAll('table.table-links tbody tr');
            for (const tr of rows) {
                const promotionStatus = tr.dataset.promotionStatus || 'idle';
                if (!isPromotionActiveStatus(promotionStatus) && promotionStatus !== 'report_ready') { continue; }
                const linkEl = tr.querySelector('.url-cell .view-url');
                if (!linkEl) continue;
                const url = linkEl.getAttribute('href');
                if (!url) continue;
                const params = new URLSearchParams();
                params.set('project_id', String(PROJECT_ID));
                params.set('url', url);
                const runId = tr.dataset.promotionRunId || '';
                if (runId) { params.set('run_id', runId); }
                const res = await fetch('<?php echo pp_url('public/promotion_status.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
                const data = await res.json().catch(()=>null);
                if (!data || !data.ok) continue;
                updatePromotionBlock(tr, data);
                refreshActionsCell(tr);
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
