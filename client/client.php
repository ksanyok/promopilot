<?php
require_once __DIR__ . '/../includes/init.php';

if (!is_logged_in() || is_admin()) {
    redirect('auth/login.php');
}

$user_id = $_SESSION['user_id'];
$conn = connect_db();

// Получить баланс
$stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$balance = $user['balance'];

// Получить проекты с агрегированными показателями
$sql = "SELECT 
            p.id,
            p.name,
            p.description,
            p.created_at,
            p.language,
            p.region,
            p.topic,
            p.domain_host,
            (SELECT COUNT(*) FROM project_links pl WHERE pl.project_id = p.id) AS links_count,
            (SELECT COUNT(*) FROM promotion_runs pr WHERE pr.project_id = p.id AND pr.status IN ('queued','running','level1_active','pending_level2','level2_active','pending_crowd','crowd_ready','report_ready')) AS active_runs,
            (SELECT COUNT(*) FROM promotion_runs pr WHERE pr.project_id = p.id AND pr.status = 'completed') AS completed_runs,
            (SELECT MAX(pr.updated_at) FROM promotion_runs pr WHERE pr.project_id = p.id) AS last_promotion_at,
            (SELECT COUNT(*) FROM publications pub WHERE pub.project_id = p.id AND (pub.status = 'success' OR pub.post_url <> '')) AS published_links,
            (SELECT url FROM project_links pl WHERE pl.project_id = p.id ORDER BY pl.id ASC LIMIT 1) AS primary_url
        FROM projects p
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$projectsResult = $stmt->get_result();

$projectsData = [];
$dashboardSummary = [
    'projects' => 0,
    'links' => 0,
    'active_runs' => 0,
    'completed_runs' => 0,
    'published_links' => 0,
    'last_activity' => null
];

while ($row = $projectsResult->fetch_assoc()) {
    $row['links_count'] = (int)($row['links_count'] ?? 0);
    $row['active_runs'] = (int)($row['active_runs'] ?? 0);
    $row['completed_runs'] = (int)($row['completed_runs'] ?? 0);
    $row['published_links'] = (int)($row['published_links'] ?? 0);
    $row['domain_host'] = trim((string)($row['domain_host'] ?? ''));
    $primaryRaw = trim((string)($row['primary_url'] ?? ''));
    $row['primary_url'] = $primaryRaw !== '' ? $primaryRaw : null;
    $resolvedPrimaryUrl = pp_project_primary_url($row, $primaryRaw !== '' ? $primaryRaw : null);
    $row['primary_url_resolved'] = $resolvedPrimaryUrl;
    if ($row['domain_host'] === '' && $resolvedPrimaryUrl) {
        $host = parse_url($resolvedPrimaryUrl, PHP_URL_HOST);
        if (!empty($host)) { $row['domain_host'] = $host; }
    }
    $row['preview_url'] = pp_project_preview_url($row, $primaryRaw !== '' ? $primaryRaw : null, ['cache_bust' => true]);
    $row['favicon_url'] = $row['domain_host'] !== '' ? ('https://www.google.com/s2/favicons?sz=128&domain=' . rawurlencode($row['domain_host'])) : null;
    $createdAt = $row['created_at'] ?? null;
    $lastPromotion = $row['last_promotion_at'] ?? null;
    $row['last_activity_at'] = $lastPromotion && $lastPromotion !== '0000-00-00 00:00:00' ? $lastPromotion : $createdAt;

    $projectsData[] = $row;

    $dashboardSummary['projects']++;
    $dashboardSummary['links'] += $row['links_count'];
    $dashboardSummary['active_runs'] += $row['active_runs'];
    $dashboardSummary['completed_runs'] += $row['completed_runs'];
    $dashboardSummary['published_links'] += $row['published_links'];
    if (!empty($row['last_activity_at']) && $row['last_activity_at'] !== '0000-00-00 00:00:00') {
        if ($dashboardSummary['last_activity'] === null || strtotime($row['last_activity_at']) > strtotime($dashboardSummary['last_activity'])) {
            $dashboardSummary['last_activity'] = $row['last_activity_at'];
        }
    } elseif (!empty($createdAt) && $createdAt !== '0000-00-00 00:00:00' && $dashboardSummary['last_activity'] === null) {
        $dashboardSummary['last_activity'] = $createdAt;
    }
}

$projectsResult->free();
$stmt->close();
$conn->close();

$formatActivity = static function (?string $timestamp) {
    if (empty($timestamp) || $timestamp === '0000-00-00 00:00:00') {
        return __('Еще нет активности');
    }
    $time = strtotime($timestamp);
    if (!$time) {
        return __('Еще нет активности');
    }
    return date('d.m.Y H:i', $time);
};

$firstProjectId = !empty($projectsData) ? (int)$projectsData[0]['id'] : null;
$pp_container = false;
$GLOBALS['pp_layout_has_sidebar'] = true;
?>

<?php include '../includes/header.php'; ?>

<?php include __DIR__ . '/../includes/client_sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="dashboard-hero-card card mb-4">
        <div class="card-body d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-4">
            <div class="dashboard-hero-card__intro">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h2 class="mb-0"><?php echo __('Клиентский дашборд'); ?></h2>
                    <i class="bi bi-info-circle info-help" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo __('Обзор ключевых показателей и быстрый доступ к проектам.'); ?>"></i>
                </div>
                <p class="text-muted mb-0 small"><?php echo __('Следите за балансом, активными кампаниями и переходите к нужному проекту в один клик.'); ?></p>
            </div>
            <div class="dashboard-hero-card__balance text-start text-md-end position-relative">
                <a href="<?php echo pp_url('client/balance.php'); ?>" class="stretched-link" aria-label="<?php echo __('Открыть финансовый дашборд'); ?>"></a>
                <div class="dashboard-balance-label text-uppercase small fw-semibold text-muted"><?php echo __('Ваш баланс'); ?></div>
                <div class="dashboard-balance-value"><?php echo htmlspecialchars(format_currency($balance)); ?></div>
                <div class="text-muted small"><i class="bi bi-lightning-charge me-1"></i><?php echo __('Баланс используется для запуска и масштабирования публикационных каскадов.'); ?></div>
            </div>
            <div class="dashboard-hero-card__actions">
                <a href="<?php echo pp_url('client/add_project.php'); ?>" class="btn btn-gradient"><i class="bi bi-plus-lg me-1"></i><?php echo __('Новый проект'); ?></a>
            </div>
        </div>
    </div>

    <div class="row g-3 dashboard-stat-row mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="dashboard-metric-card dashboard-metric-card--projects h-100">
                <div class="dashboard-metric-card__label"><?php echo __('Проектов'); ?></div>
                <div class="dashboard-metric-card__value"><?php echo number_format($dashboardSummary['projects'], 0, '.', ' '); ?></div>
                <div class="dashboard-metric-card__meta text-muted small"><i class="bi bi-clock-history me-1"></i><?php echo __('Последнее действие'); ?>: <span><?php echo htmlspecialchars($formatActivity($dashboardSummary['last_activity'])); ?></span></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="dashboard-metric-card dashboard-metric-card--links h-100">
                <div class="dashboard-metric-card__label"><?php echo __('Ссылок в проектах'); ?></div>
                <div class="dashboard-metric-card__value"><?php echo number_format($dashboardSummary['links'], 0, '.', ' '); ?></div>
                <div class="dashboard-metric-card__meta text-muted small"><i class="bi bi-diagram-3 me-1"></i><?php echo __('Опубликовано ссылок'); ?>: <span><?php echo number_format($dashboardSummary['published_links'], 0, '.', ' '); ?></span></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="dashboard-metric-card dashboard-metric-card--active h-100">
                <div class="dashboard-metric-card__label"><?php echo __('Активные запуски'); ?></div>
                <div class="dashboard-metric-card__value"><?php echo number_format($dashboardSummary['active_runs'], 0, '.', ' '); ?></div>
                <div class="dashboard-metric-card__meta text-muted small"><i class="bi bi-rocket-takeoff me-1"></i><?php echo __('Активных кампаний'); ?>: <span><?php echo number_format($dashboardSummary['active_runs'], 0, '.', ' '); ?></span></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="dashboard-metric-card dashboard-metric-card--completed h-100">
                <div class="dashboard-metric-card__label"><?php echo __('Завершенные запуски'); ?></div>
                <div class="dashboard-metric-card__value"><?php echo number_format($dashboardSummary['completed_runs'], 0, '.', ' '); ?></div>
                <div class="dashboard-metric-card__meta text-muted small"><i class="bi bi-patch-check-fill me-1"></i><?php echo __('Успешно завершено'); ?></div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 dashboard-projects-header">
        <div>
            <h3 class="mb-1"><?php echo __('Ваши проекты'); ?></h3>
            <div class="text-muted small"><i class="bi bi-question-circle me-1"></i><?php echo __('Каждый проект имеет собственный набор ссылок и историю публикаций.'); ?></div>
        </div>
        <?php if ($firstProjectId): ?>
        <a href="<?php echo pp_url('client/history.php?id=' . $firstProjectId); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i><?php echo __('История'); ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($projectsData)): ?>
        <div class="row g-3 dashboard-projects-grid">
            <?php foreach ($projectsData as $project): ?>
                <?php
                    $projectId = (int)$project['id'];
                    $projectName = htmlspecialchars($project['name']);
                    $projectDescription = trim((string)($project['description'] ?? ''));
                    if ($projectDescription === '') {
                        $projectDescription = __('Пока нет описания для этого проекта.');
                    }
                    $projectDescription = htmlspecialchars(mb_strlen($projectDescription) > 160 ? mb_substr($projectDescription, 0, 160) . '…' : $projectDescription);
                    $projectUrl = pp_url('client/project.php?id=' . $projectId);
                    $historyUrl = pp_url('client/history.php?id=' . $projectId);
                    $editUrl = $projectUrl . '#project-form';
                    $language = !empty($project['language']) ? strtoupper(htmlspecialchars($project['language'])) : null;
                    $region = !empty($project['region']) ? htmlspecialchars($project['region']) : null;
                    $topic = !empty($project['topic']) ? htmlspecialchars($project['topic']) : null;
                    $lastActivity = htmlspecialchars($formatActivity($project['last_activity_at']));
                    $projectPrimaryUrl = !empty($project['primary_url_resolved']) ? htmlspecialchars($project['primary_url_resolved']) : null;
                    $projectPreviewUrl = !empty($project['preview_url']) ? htmlspecialchars($project['preview_url']) : null;
                    $projectDomainHost = !empty($project['domain_host']) ? htmlspecialchars($project['domain_host']) : '';
                    $projectFaviconUrl = !empty($project['favicon_url']) ? htmlspecialchars($project['favicon_url']) : null;
                    if (function_exists('mb_substr')) {
                        $initialRaw = mb_substr($project['name'], 0, 1, 'UTF-8');
                        $projectInitial = mb_strtoupper($initialRaw, 'UTF-8');
                    } else {
                        $projectInitial = strtoupper(substr($project['name'], 0, 1));
                    }
                    if ($projectInitial === '') { $projectInitial = '∎'; }
                ?>
                <div class="col-xl-4 col-md-6">
                    <div class="dashboard-project-card h-100">
                        <div class="dashboard-project-card__media">
                            <div class="dashboard-project-card__media-inner">
                                <a href="<?php echo $projectUrl; ?>" class="dashboard-project-card__media-link" aria-label="<?php echo __('Открыть проект'); ?>">
                                    <?php if ($projectPreviewUrl): ?>
                                        <img src="<?php echo $projectPreviewUrl; ?>" alt="<?php echo $projectName; ?>" loading="lazy" decoding="async" class="dashboard-project-card__screenshot">
                                    <?php else: ?>
                                        <div class="dashboard-project-card__screenshot dashboard-project-card__screenshot--placeholder">
                                            <span><?php echo htmlspecialchars($projectInitial); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </a>
                                <span class="dashboard-project-card__media-glow"></span>
                                <a href="<?php echo $editUrl; ?>" class="dashboard-project-card__edit" title="<?php echo __('Редактировать'); ?>" aria-label="<?php echo __('Редактировать'); ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </div>
                            <?php if ($projectDomainHost !== ''): ?>
                                <div class="dashboard-project-card__domain">
                                    <?php if ($projectFaviconUrl): ?><img src="<?php echo $projectFaviconUrl; ?>" alt="favicon" class="dashboard-project-card__favicon" loading="lazy"><?php endif; ?>
                                    <?php if ($projectPrimaryUrl): ?>
                                        <a href="<?php echo $projectPrimaryUrl; ?>" target="_blank" rel="noopener" class="text-decoration-none dashboard-project-card__domain-link"><?php echo $projectDomainHost; ?></a>
                                    <?php else: ?>
                                        <span><?php echo $projectDomainHost; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-project-card__head">
                            <div>
                                <h4 class="dashboard-project-card__title mb-1"><?php echo $projectName; ?></h4>
                                <div class="dashboard-project-card__meta text-muted small">
                                    <i class="bi bi-calendar3 me-1"></i><?php echo __('Создан'); ?>: <?php echo htmlspecialchars(date('d.m.Y', strtotime($project['created_at']))); ?>
                                </div>
                            </div>
                            <div class="dashboard-project-card__tags">
                                <?php if ($language): ?><span class="badge bg-primary-subtle text-primary-emphasis"><?php echo $language; ?></span><?php endif; ?>
                                <?php if ($region): ?><span class="badge bg-secondary-subtle text-light-emphasis"><?php echo $region; ?></span><?php endif; ?>
                                <?php if ($topic): ?><span class="badge bg-info-subtle text-info-emphasis"><?php echo $topic; ?></span><?php endif; ?>
                            </div>
                        </div>
                        <p class="dashboard-project-card__description text-muted mb-3"><?php echo $projectDescription; ?></p>
                        <div class="dashboard-project-card__stats">
                            <div class="dashboard-project-card__stat">
                                <span class="label text-muted small"><?php echo __('Ссылок'); ?></span>
                                <span class="value"><?php echo number_format($project['links_count'], 0, '.', ' '); ?></span>
                            </div>
                            <div class="dashboard-project-card__stat">
                                <span class="label text-muted small"><?php echo __('Опубликовано ссылок'); ?></span>
                                <span class="value"><?php echo number_format($project['published_links'], 0, '.', ' '); ?></span>
                            </div>
                            <div class="dashboard-project-card__stat">
                                <span class="label text-muted small"><?php echo __('Активные запуски'); ?></span>
                                <span class="value"><?php echo number_format($project['active_runs'], 0, '.', ' '); ?></span>
                            </div>
                            <div class="dashboard-project-card__stat">
                                <span class="label text-muted small"><?php echo __('Завершенные запуски'); ?></span>
                                <span class="value"><?php echo number_format($project['completed_runs'], 0, '.', ' '); ?></span>
                            </div>
                        </div>
                        <div class="dashboard-project-card__footer d-flex align-items-center justify-content-between gap-2">
                            <span class="dashboard-project-card__activity text-muted small"><i class="bi bi-activity me-1"></i><?php echo $lastActivity; ?></span>
                            <div class="dashboard-project-card__actions d-flex gap-2 flex-wrap justify-content-end">
                                <a href="<?php echo $projectUrl; ?>" class="btn btn-sm btn-primary"><i class="bi bi-folder2-open me-1"></i><?php echo __('Открыть проект'); ?></a>
                                <a href="<?php echo $historyUrl; ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="<?php echo __('История'); ?>"><i class="bi bi-clock-history"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card empty-state-card text-center p-5">
            <div class="mb-3"><i class="bi bi-stars fs-1 text-primary"></i></div>
            <h4 class="mb-2"><?php echo __('У вас пока нет проектов.'); ?></h4>
            <p class="text-muted mb-3"><?php echo __('Создайте первый проект, чтобы добавить ссылки и запустить продвижение.'); ?></p>
            <a href="<?php echo pp_url('client/add_project.php'); ?>" class="btn btn-gradient"><i class="bi bi-plus-lg me-1"></i><?php echo __('Добавить проект'); ?></a>
        </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>