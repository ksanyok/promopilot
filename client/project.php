<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/project_helpers.php';

if (!is_logged_in()) {
    redirect('auth/login.php');
}

$id = (int)($_GET['id'] ?? 0);
$user_id = (int)($_SESSION['user_id']);

$project = pp_project_fetch_with_user($id);
if (!$project) {
    include '../includes/header.php';
    echo '<div class="alert alert-warning">' . __('Проект не найден.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

$promotionSettings = function_exists('pp_promotion_settings') ? pp_promotion_settings() : [];
$promotionBasePrice = max(0.0, (float)($promotionSettings['price_per_link'] ?? 0));
$userPromotionDiscount = max(0.0, min(100.0, (float)($project['promotion_discount'] ?? 0)));
$promotionChargeAmount = max(0.0, round($promotionBasePrice * (1 - $userPromotionDiscount / 100), 2));
$promotionChargeFormatted = format_currency($promotionChargeAmount);
$promotionBaseFormatted = format_currency($promotionBasePrice);
$promotionChargeSavings = max(0.0, round($promotionBasePrice - $promotionChargeAmount, 2));
$promotionChargeSavingsFormatted = format_currency($promotionChargeSavings);
$promotionChargeAmountAttr = number_format($promotionChargeAmount, 2, '.', '');
$promotionChargeBaseAttr = number_format($promotionBasePrice, 2, '.', '');
$promotionChargeSavingsAttr = number_format($promotionChargeSavings, 2, '.', '');
$promotionDiscountPercentAttr = rtrim(rtrim(number_format($userPromotionDiscount, 4, '.', ''), '0'), '.');
if ($promotionDiscountPercentAttr === '') { $promotionDiscountPercentAttr = '0'; }
$currentUserBalance = (float)($project['balance'] ?? 0);
$currentUserBalanceFormatted = format_currency($currentUserBalance);

$taxonomy = pp_get_network_taxonomy(true);
$availableRegions = $taxonomy['regions'] ?? [];
$availableTopics  = $taxonomy['topics'] ?? [];
if (empty($availableRegions)) { $availableRegions = ['Global']; }
if (empty($availableTopics))  { $availableTopics  = ['General']; }

$pp_lang_codes = [
    'ru', 'en', 'uk', 'de', 'fr', 'es', 'it', 'pt', 'pt-br', 'pl', 'tr', 'nl', 'cs', 'sk', 'bg', 'ro', 'el', 'hu', 'sv', 'da',
    'no', 'fi', 'et', 'lv', 'lt', 'ka', 'az', 'kk', 'uz', 'sr', 'sl', 'hr', 'he', 'ar', 'fa', 'hi', 'id', 'ms', 'vi', 'th',
    'zh', 'zh-cn', 'zh-tw', 'ja', 'ko',
];

$links = pp_project_fetch_links($id, $project['language'] ?? 'ru');

$snapshot = pp_project_promotion_snapshot((int)$project['id'], $links);
$promotionSummary = $snapshot['summary'];
$promotionStatusByUrl = $snapshot['status_by_url'];
$canDeleteProject = $snapshot['can_delete'];

if (!is_admin() && (int)$project['user_id'] !== $user_id) {
    include '../includes/header.php';
    echo '<div class="alert alert-danger">' . __('Доступ запрещен.') . '</div>';
    echo '<a class="btn btn-secondary" href="' . pp_url('client/client.php') . '">' . __('Вернуться') . '</a>';
    include '../includes/footer.php';
    exit;
}

$pp_is_ajax = (
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
    (isset($_POST['ajax']) && $_POST['ajax'] == '1') ||
    (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_project'])) {
        if (!verify_csrf()) {
            $message = __('Ошибка удаления проекта.') . ' (CSRF)';
        } elseif (!$canDeleteProject) {
            $message = __('Удаление проекта недоступно: есть активные или выполненные ссылки.');
        } else {
            $previewDescriptor = function_exists('pp_project_preview_descriptor') ? pp_project_preview_descriptor($project) : null;
            $previewPath = (is_array($previewDescriptor) && !empty($previewDescriptor['exists']) && !empty($previewDescriptor['path'])) ? (string)$previewDescriptor['path'] : null;
            $deleteResult = pp_project_delete_with_relations($id, $user_id, is_admin());
            if ($deleteResult['ok']) {
                if ($previewPath && @is_file($previewPath)) {
                    @unlink($previewPath);
                }
                $_SESSION['pp_client_flash'] = ['type' => 'success', 'text' => __('Проект удален.')];
                redirect('client/client.php');
                exit;
            }
            $message = __('Не удалось удалить проект.');
        }
    } elseif (isset($_POST['update_project_info'])) {
        if (!verify_csrf()) {
            $message = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $updateInfo = pp_project_update_main_info($id, $project, $_POST, [
                'allowed_languages' => ['ru','en','es','fr','de'],
                'available_regions' => $availableRegions,
                'available_topics' => $availableTopics,
            ]);
            $message = $updateInfo['message'];
        }
    } elseif (isset($_POST['update_project'])) {
        if (!verify_csrf()) {
            $message = __('Ошибка обновления.') . ' (CSRF)';
        } else {
            $updateResult = pp_project_handle_links_update($id, $project, $_POST);
            if ($pp_is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($updateResult, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $message = $updateResult['message'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pp_is_ajax) {
    $links = pp_project_fetch_links($id, $project['language'] ?? 'ru');
    $snapshot = pp_project_promotion_snapshot((int)$project['id'], $links);
    $promotionSummary = $snapshot['summary'];
    $promotionStatusByUrl = $snapshot['status_by_url'];
    $canDeleteProject = $snapshot['can_delete'];
}

$pubStatusByUrl = pp_project_publication_statuses($id);

if (empty($project['primary_url'])) {
    $project['primary_url'] = $links[0]['url'] ?? null;
}
$projectPrimaryUrl = pp_project_primary_url($project, $project['primary_url'] ?? null);
$projectPreviewDescriptor = pp_project_preview_descriptor($project);
$projectPreviewUrl = pp_project_preview_url($project, $projectPrimaryUrl, ['cache_bust' => true]);
$projectPreviewExists = !empty($projectPreviewDescriptor['exists']);
$projectPreviewHasUrl = !empty($projectPreviewUrl);
$projectPreviewUpdatedAt = $projectPreviewExists ? (int)($projectPreviewDescriptor['modified_at'] ?? 0) : 0;
$projectPreviewUpdatedHuman = $projectPreviewUpdatedAt ? date('d.m.Y H:i', $projectPreviewUpdatedAt) : null;
$projectPreviewStale = pp_project_preview_is_stale($projectPreviewDescriptor, 259200);
$projectPreviewShouldAuto = !$projectPreviewHasUrl;
$projectPreviewStatusKey = $projectPreviewHasUrl ? 'ok' : 'pending';
if (!$projectPreviewHasUrl) {
    $projectPreviewStatusText = __('Скрин еще не готов');
} else {
    $projectPreviewStatusText = '';
}
$projectPreviewStatusIcon = $projectPreviewStatusKey === 'ok' ? 'bi-check-circle' : ($projectPreviewStatusKey === 'warning' ? 'bi-exclamation-triangle' : 'bi-camera');
$projectPrimaryHost = trim((string)($project['domain_host'] ?? ''));
if ($projectPrimaryHost === '' && $projectPrimaryUrl) {
    $parsedHost = parse_url($projectPrimaryUrl, PHP_URL_HOST);
    if (!empty($parsedHost)) { $projectPrimaryHost = $parsedHost; }
}
if (function_exists('mb_substr')) {
    $projectInitial = mb_strtoupper(mb_substr($project['name'] ?? '', 0, 1, 'UTF-8'), 'UTF-8');
} else {
    $projectInitial = strtoupper(substr((string)($project['name'] ?? ''), 0, 1));
}
if ($projectInitial === '') { $projectInitial = '∎'; }

// Make this page full-width (no Bootstrap container wrapper from header)
$pp_container = false;
$pp_container_class = '';
// Provide current project context for sidebar highlighting (optional)
$pp_current_project = ['id' => (int)$project['id'], 'name' => (string)$project['name']];
$GLOBALS['pp_layout_has_sidebar'] = true;

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
                            <div class="project-hero__heading">
                                <div class="project-hero__heading-left">
                                    <div class="title d-flex align-items-center gap-2 flex-wrap">
                                        <span class="project-hero__title-text" title="<?php echo htmlspecialchars($project['name']); ?>"><?php echo htmlspecialchars($project['name']); ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#projectInfoModal" title="<?php echo __('Редактировать основную информацию'); ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <i class="bi bi-info-circle ms-1 text-primary" data-bs-toggle="tooltip" title="<?php echo __('Страница проекта: управляйте ссылками и пожеланиями.'); ?>"></i>
                                    </div>
                                    <div class="subtitle">@<?php echo htmlspecialchars($project['username']); ?></div>
                                </div>
                                <div class="project-hero__heading-right">
                                    <div class="project-hero__actions">
                                        <button type="button" class="btn btn-primary project-hero__action-add" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                                            <i class="bi bi-plus-lg"></i><span><?php echo __('Добавить ссылку'); ?></span>
                                        </button>
                                        <?php if ($canDeleteProject): ?>
                                        <button type="button" class="btn btn-outline-danger project-hero__action-delete" data-bs-toggle="modal" data-bs-target="#deleteProjectModal">
                                            <i class="bi bi-trash"></i><span><?php echo __('Удалить проект'); ?></span>
                                        </button>
                                        <?php endif; ?>
                                        <a href="<?php echo pp_url('client/history.php?id=' . (int)$project['id']); ?>" class="btn btn-outline-light project-hero__action-history" data-bs-toggle="tooltip" title="<?php echo __('История'); ?>">
                                            <i class="bi bi-clock-history"></i>
                                        </a>
                                        <span class="chip" data-bs-toggle="tooltip" title="<?php echo __('Внутренний идентификатор проекта'); ?>"><i class="bi bi-folder2-open"></i>ID <?php echo (int)$project['id']; ?></span>
                                    </div>
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
                             data-has-preview-url="<?php echo $projectPreviewHasUrl ? '1' : '0'; ?>"
                             data-preview-source="<?php echo $projectPreviewExists ? 'local' : 'external'; ?>"
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
                                <button type="button" class="project-hero__refresh project-hero__refresh--overlay<?php echo $projectPreviewHasUrl ? '' : ' d-none'; ?>" data-action="refresh-preview" title="<?php echo __('Обновить превью'); ?>" aria-label="<?php echo __('Обновить превью'); ?>">
                                    <span class="label-default"><i class="bi bi-arrow-repeat"></i></span>
                                    <span class="label-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></span>
                                    <span class="visually-hidden"><?php echo __('Обновить превью'); ?></span>
                                </button>
                                <span class="project-hero__preview-glow"></span>
                            </div>
                            <?php if (!$projectPreviewHasUrl): ?>
                                <div class="project-hero__preview-actions d-flex flex-wrap align-items-center gap-2">
                                    <button type="button" class="project-hero__refresh" data-action="refresh-preview">
                                        <span class="label-default"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить превью'); ?></span>
                                        <span class="label-loading">
                                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                            <?php echo __('Обновление...'); ?>
                                        </span>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <div class="project-hero__preview-status small<?php echo $projectPreviewStatusText === '' ? ' d-none' : ''; ?>" data-preview-status data-status="<?php echo htmlspecialchars($projectPreviewStatusKey); ?>">
                                <i class="bi <?php echo htmlspecialchars($projectPreviewStatusIcon); ?>"></i>
                                <span data-preview-status-text><?php echo htmlspecialchars($projectPreviewStatusText); ?></span>
                            </div>
                            <?php if ($projectPrimaryHost !== ''): ?>
                                <div class="project-hero__domain small text-muted fw-semibold">
                                    <?php if (!empty($projectPrimaryUrl)): ?>
                                        <a href="<?php echo htmlspecialchars($projectPrimaryUrl); ?>" target="_blank" rel="noopener" class="text-decoration-none text-reset"><?php echo htmlspecialchars($projectPrimaryHost); ?></a>
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

<?php include __DIR__ . '/partials/project/links_form.php'; ?>
<?php include __DIR__ . '/partials/project/modals.php'; ?>
<?php include __DIR__ . '/partials/project/scripts.php'; ?>
<?php include '../includes/footer.php'; ?>
