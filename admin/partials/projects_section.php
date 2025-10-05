<?php
$projectsSummary = $projectsSummary ?? [];
$projectsData = $projectsData ?? [];
$focusUserAttr = isset($focusUserId) && $focusUserId > 0 ? (string)(int)$focusUserId : '';
$totalProjects = (int)($projectsSummary['total'] ?? 0);
$totalLinks = (int)($projectsSummary['links_total'] ?? 0);
$publishedTotal = (int)($projectsSummary['published_total'] ?? 0);
$activeRunsTotal = (int)($projectsSummary['active_runs'] ?? 0);
$completedRunsTotal = (int)($projectsSummary['completed_runs'] ?? 0);
$activeProjects = (int)($projectsSummary['active_projects'] ?? 0);
$avgLinks = (float)($projectsSummary['avg_links'] ?? 0);
$publishedPctTotal = (int)($projectsSummary['published_pct'] ?? 0);
$lastActivityDisplay = (string)($projectsSummary['last_activity_display'] ?? '—');
$formatNum = static function ($value) {
    if (is_float($value)) {
        return number_format($value, 1, '.', ' ');
    }
    return number_format((float)$value, 0, '.', ' ');
};
?>
<div id="projects-section" data-focus-user="<?php echo htmlspecialchars($focusUserAttr, ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
    <h3><?php echo __('Проекты'); ?></h3>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted text-uppercase small fw-semibold mb-1"><?php echo __('Всего проектов'); ?></div>
                    <div class="fs-4 fw-semibold mb-2"><?php echo $formatNum($totalProjects); ?></div>
                    <div class="text-muted small"><i class="bi bi-lightning-charge me-1"></i><?php echo sprintf(__('Активных проектов: %s'), $formatNum($activeProjects)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted text-uppercase small fw-semibold mb-1"><?php echo __('Ссылок в проектах'); ?></div>
                    <div class="fs-4 fw-semibold mb-2"><?php echo $formatNum($totalLinks); ?></div>
                    <div class="text-muted small"><i class="bi bi-diagram-3 me-1"></i><?php echo sprintf(__('В среднем на проект: %s'), $totalProjects > 0 ? $formatNum($avgLinks) : '0'); ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted text-uppercase small fw-semibold mb-1"><?php echo __('Опубликовано ссылок'); ?></div>
                    <div class="fs-4 fw-semibold mb-2"><?php echo $formatNum($publishedTotal); ?></div>
                    <div class="text-muted small"><i class="bi bi-graph-up me-1"></i><?php echo sprintf(__('%d%% от общего числа ссылок'), $publishedPctTotal); ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted text-uppercase small fw-semibold mb-1"><?php echo __('Запуски кампаний'); ?></div>
                    <div class="fs-4 fw-semibold mb-2"><?php echo $formatNum($activeRunsTotal); ?></div>
                    <div class="text-muted small"><i class="bi bi-patch-check-fill me-1"></i><?php echo sprintf(__('Завершено: %s'), $formatNum($completedRunsTotal)); ?></div>
                    <div class="text-muted small mt-1"><i class="bi bi-clock-history me-1"></i><?php echo __('Последняя активность'); ?>: <span><?php echo htmlspecialchars($lastActivityDisplay, ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th class="text-nowrap">ID</th>
                    <th style="min-width:260px;"><?php echo __('Название'); ?></th>
                    <th class="text-nowrap"><?php echo __('Владелец'); ?></th>
                    <th class="text-center"><?php echo __('Ссылки'); ?></th>
                    <th style="min-width:220px;"><?php echo __('Публикации'); ?></th>
                    <th class="text-nowrap text-center"><?php echo __('Запуски'); ?></th>
                    <th class="text-nowrap"><?php echo __('Активность'); ?></th>
                    <th class="text-nowrap d-none d-lg-table-cell"><?php echo __('Создан'); ?></th>
                    <th class="text-end" style="min-width:200px;"><?php echo __('Действия'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($projectsData)): ?>
                    <?php foreach ($projectsData as $project): ?>
                        <?php
                            $projectId = (int)($project['id'] ?? 0);
                            $linksCount = (int)($project['links_count'] ?? 0);
                            $publishedLinks = (int)($project['published_links'] ?? 0);
                            $progressPct = (int)($project['progress_pct'] ?? 0);
                            $activeRuns = (int)($project['active_runs'] ?? 0);
                            $completedRuns = (int)($project['completed_runs'] ?? 0);
                            $ownerId = (int)($project['user_id'] ?? 0);
                            $focusUrl = pp_url('admin/admin.php?focus_user=' . $ownerId) . '#users-section';
                            $loginToken = action_token('login_as', (string)$ownerId);
                            $loginBase = 'admin/admin_login_as.php?user_id=' . $ownerId . '&t=' . urlencode($loginToken);
                            $openProjectUrl = pp_url($loginBase . '&r=' . urlencode('client/project.php?id=' . $projectId));
                            $openHistoryUrl = pp_url($loginBase . '&r=' . urlencode('client/history.php?id=' . $projectId));
                            $loginAsUrl = pp_url($loginBase);
                            $primaryUrl = (string)($project['primary_url'] ?? '');
                            $primaryHost = (string)($project['primary_host'] ?? '');
                            $topic = trim((string)($project['topic'] ?? ''));
                            $language = strtoupper(trim((string)($project['language'] ?? '')));
                            $region = trim((string)($project['region'] ?? ''));
                            $progressPct = max(0, min(100, $progressPct));
                        ?>
                        <tr data-owner-id="<?php echo $ownerId; ?>">
                            <td class="text-muted">#<?php echo $projectId; ?></td>
                            <td>
                                <div class="fw-semibold mb-1"><?php echo htmlspecialchars((string)$project['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($primaryHost !== '' || $primaryUrl !== ''): ?>
                                    <div class="small mb-1">
                                        <a href="<?php echo htmlspecialchars($primaryUrl !== '' ? $primaryUrl : ('https://' . $primaryHost), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="link-secondary text-decoration-none">
                                            <i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($primaryHost !== '' ? $primaryHost : $primaryUrl, ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2 text-muted small">
                                    <?php if ($topic !== ''): ?><span class="badge bg-light text-dark"><i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($topic, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if ($language !== ''): ?><span class="badge bg-secondary text-uppercase"><i class="bi bi-translate me-1"></i><?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if ($region !== ''): ?><span class="badge bg-light text-dark"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($project['description'])): ?>
                                    <div class="text-muted small mt-2 d-none d-xxl-block"><?php echo htmlspecialchars((string)$project['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a href="<?php echo htmlspecialchars($focusUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fw-semibold text-decoration-none">
                                    <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars((string)$project['owner_username'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if (!empty($project['owner_email'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars((string)$project['owner_email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="fw-semibold"><?php echo $formatNum($linksCount); ?></div>
                                <div class="text-muted small"><?php echo sprintf(__('Опубликовано: %s'), $formatNum($publishedLinks)); ?></div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 10px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?php echo $progressPct; ?>%;" aria-valuenow="<?php echo $progressPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="text-nowrap small text-muted"><?php echo $formatNum($publishedLinks); ?>/<?php echo $formatNum($linksCount); ?> (<?php echo $progressPct; ?>%)</div>
                                </div>
                            </td>
                            <td class="text-center">
                                <div>
                                    <span class="badge <?php echo $activeRuns > 0 ? 'bg-primary' : 'bg-secondary'; ?>"><?php echo sprintf(__('Активные: %s'), $formatNum($activeRuns)); ?></span>
                                </div>
                                <div class="small text-muted mt-1"><?php echo sprintf(__('Завершено: %s'), $formatNum($completedRuns)); ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold small"><?php echo htmlspecialchars((string)$project['last_activity_display'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($project['created_display'])): ?>
                                    <div class="text-muted small"><?php echo __('Создан'); ?>: <?php echo htmlspecialchars((string)$project['created_display'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted d-none d-lg-table-cell">
                                <?php echo htmlspecialchars((string)$project['created_display'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group" role="group">
                                    <a href="<?php echo htmlspecialchars($openProjectUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Открыть проект'); ?></a>
                                    <a href="<?php echo htmlspecialchars($openHistoryUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-clock-history me-1"></i><?php echo __('История'); ?></a>
                                    <a href="<?php echo htmlspecialchars($loginAsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning btn-sm"><i class="bi bi-person-check me-1"></i><?php echo __('Войти как'); ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4"><?php echo __('Проектов пока нет.'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const projectsSection = document.getElementById('projects-section');
        if (!projectsSection) { return; }
        const focusUser = projectsSection.getAttribute('data-focus-user');
        if (!focusUser) { return; }
        const rows = projectsSection.querySelectorAll('[data-owner-id="' + focusUser + '"]');
        if (!rows.length) { return; }
        rows.forEach(function(row){ row.classList.add('table-warning'); });
        rows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function(){ rows.forEach(function(row){ row.classList.remove('table-warning'); }); }, 4000);
    });
    </script>
</div>
