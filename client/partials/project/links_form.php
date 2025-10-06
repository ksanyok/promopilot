<?php /* Project links form and table extracted from client/project.php */ ?>
<form method="post" id="project-form" class="mb-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="update_project" value="1" />
    <input type="hidden" id="global_wishes" name="wishes" value="<?php echo htmlspecialchars($project['wishes'] ?? ''); ?>" />

    <div class="modal fade modal-glass" id="addLinkModal" tabindex="-1" aria-labelledby="addLinkModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content modal-content--glass">
                <div class="modal-header modal-header--glass">
                    <h5 class="modal-title" id="addLinkModalLabel"><i class="bi bi-link-45deg me-2"></i><?php echo __('Добавить ссылку'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('Закрыть'); ?>"></button>
                </div>
                <div class="modal-body modal-body--glass">
                    <div class="modal-intro d-flex align-items-start gap-3 mb-4">
                        <div class="modal-intro__icon"><i class="bi bi-stars"></i></div>
                        <div>
                            <div class="fw-semibold"><?php echo __('Укажите страницу внутри проекта'); ?></div>
                            <p class="text-muted small mb-0"><?php echo __('Мы проверим домен, сохраним анкор и сформируем индивидуальное пожелание для команды.'); ?></p>
                        </div>
                    </div>
                    <div class="row g-3 modal-field-grid align-items-stretch mb-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label visually-hidden" for="new_link_input"><?php echo __('URL'); ?></label>
                            <input type="url" name="new_link" id="new_link_input" class="form-control" placeholder="<?php echo !empty($project['domain_host']) ? htmlspecialchars('https://' . $project['domain_host'] . '/...') : __('URL'); ?> *">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label visually-hidden" for="new_anchor_input"><?php echo __('Анкор'); ?></label>
                            <input type="text" name="new_anchor" id="new_anchor_input" class="form-control" placeholder="<?php echo __('Анкор'); ?>">
                        </div>
                        <div class="col-12 col-lg-2">
                            <label class="form-label visually-hidden" for="new_language_select"><?php echo __('Язык'); ?></label>
                            <select name="new_language" id="new_language_select" class="form-select" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo __('AUTO попытается определить язык автоматически.'); ?>">
                                <?php $opts = array_merge(['auto'], $pp_lang_codes); $def = 'auto'; foreach ($opts as $l): ?>
                                    <option value="<?php echo htmlspecialchars($l); ?>" <?php echo ($def===$l?'selected':''); ?>><?php echo strtoupper($l); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if (!empty($project['domain_host'])): ?>
                    <div class="modal-domain note note--warning mb-3" id="domain-hint"><i class="bi bi-shield-lock me-2"></i><span><?php echo __('Добавлять ссылки можно только в рамках домена проекта'); ?>:</span> <code id="domain-host-code"><?php echo htmlspecialchars($project['domain_host']); ?></code></div>
                    <?php else: ?>
                    <div class="modal-domain note note--warning mb-3" id="domain-hint" style="display:none"><i class="bi bi-shield-lock me-2"></i><span><?php echo __('Добавлять ссылки можно только в рамках домена проекта'); ?>:</span> <code id="domain-host-code"></code></div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label mb-1" for="new_wish"><?php echo __('Пожелание для этой ссылки'); ?></label>
                        <textarea name="new_wish" id="new_wish" rows="3" class="form-control" placeholder="<?php echo __('Если нужно индивидуальное ТЗ (иначе можно использовать глобальное)'); ?>"></textarea>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="use_global_wish">
                            <label class="form-check-label" for="use_global_wish"><?php echo __('Использовать глобальное пожелание проекта'); ?></label>
                        </div>
                        <div class="form-helper small text-muted mt-2"><i class="bi bi-pencil"></i> <?php echo __('Опишите тональность, ключевые мысли или ограничения для этой публикации.'); ?></div>
                    </div>
                    <div id="added-hidden"></div>
                </div>
                <div class="modal-footer modal-footer--glass justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-2 small text-muted">
                        <i class="bi bi-clock-history"></i>
                        <a href="<?php echo pp_url('client/history.php?id=' . (int)$project['id']); ?>" class="text-decoration-none fw-semibold"><?php echo __('История'); ?></a>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Закрыть'); ?></button>
                        <button type="button"
                                id="add-link"
                                class="btn btn-primary"
                                data-loading-label-default="<?php echo __('Добавить'); ?>"
                                data-loading-label-loading="<?php echo __('Добавляем...'); ?>">
                            <span class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true" data-loading-spinner></span>
                            <span data-loading-label><i class="bi bi-plus-lg me-1"></i><?php echo __('Добавить'); ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card section mb-3" id="links-card">
        <div class="section-header">
            <div class="label"><i class="bi bi-diagram-3"></i><span><?php echo __('Ссылки проекта'); ?></span></div>
            <div class="toolbar status-toolbar d-flex flex-wrap align-items-center gap-3">
                <div class="status-legend small text-muted" data-bs-toggle="tooltip" title="<?php echo __('Статусы продвижения'); ?>">
                    <span><span class="legend-dot legend-dot-idle"></span><?php echo __('Продвижение не запускалось'); ?></span>
                    <span><span class="legend-dot legend-dot-running"></span><?php echo __('Выполняется'); ?></span>
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
                            $promotionActive = in_array($promotionStatus, ['queued','running','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready'], true);
                            $promotionTotal = (int)($promotionProgress['total'] ?? 0);
                            $promotionTarget = (int)($promotionProgress['target'] ?? 0);
                            if ($promotionTarget <= 0) { $promotionTarget = $promotionTotal; }
                            $promotionDone = (int)($promotionProgress['done'] ?? 0);
                            $promotionLevels = (is_array($promotionInfo) && isset($promotionInfo['levels']) && is_array($promotionInfo['levels'])) ? $promotionInfo['levels'] : [];
                            $level1Data = $promotionLevels[1] ?? [];
                            $level2Data = $promotionLevels[2] ?? [];
                            $level3Data = $promotionLevels[3] ?? [];
                            $level1Total = (int)($level1Data['total'] ?? 0);
                            $level1Success = (int)($level1Data['success'] ?? 0);
                            $level1Required = (int)($level1Data['required'] ?? ($promotionTarget ?: 0));
                            $level2Total = (int)($level2Data['total'] ?? 0);
                            $level2Success = (int)($level2Data['success'] ?? 0);
                            $level2Required = (int)($level2Data['required'] ?? 0);
                            $level3Total = (int)($level3Data['total'] ?? 0);
                            $level3Success = (int)($level3Data['success'] ?? 0);
                            $level3Required = (int)($level3Data['required'] ?? 0);
                            $crowdData = (is_array($promotionInfo) && isset($promotionInfo['crowd']) && is_array($promotionInfo['crowd'])) ? $promotionInfo['crowd'] : [];
                            $crowdPlanned = (int)($crowdData['planned'] ?? 0);
                            $crowdTotal = (int)($crowdData['total'] ?? 0);
                            $crowdCompleted = (int)($crowdData['completed'] ?? 0);
                            $crowdRunning = (int)($crowdData['running'] ?? 0);
                            $crowdQueued = (int)($crowdData['queued'] ?? 0);
                            $crowdFailed = (int)($crowdData['failed'] ?? 0);
                            $crowdManual = (int)($crowdData['manual_fallback'] ?? 0);
                            $crowdTarget = max($crowdTotal, $crowdPlanned);
                            if ($crowdTarget === 0 && $crowdCompleted > 0) { $crowdTarget = $crowdCompleted; }
                            $promotionDetails = [];
                            if ($level1Success > 0 || $level1Required > 0) {
                                $promotionDetails[] = sprintf('%s: %d%s', __('Уровень 1'), $level1Success, $level1Required > 0 ? ' / ' . $level1Required : '');
                            }
                            if ($level2Success > 0 || $level2Required > 0) {
                                $promotionDetails[] = sprintf('%s: %d%s', __('Уровень 2'), $level2Success, $level2Required > 0 ? ' / ' . $level2Required : '');
                            }
                            if ($level3Success > 0 || $level3Required > 0) {
                                $promotionDetails[] = sprintf('%s: %d%s', __('Уровень 3'), $level3Success, $level3Required > 0 ? ' / ' . $level3Required : '');
                            }
                            if ($crowdTarget > 0 || $crowdCompleted > 0) {
                                $crowdDetail = sprintf(__('Крауд: %1$d / %2$d'), $crowdCompleted, $crowdTarget);
                                $crowdExtras = [];
                                $crowdInProgress = $crowdRunning + $crowdQueued;
                                if ($crowdInProgress > 0) {
                                    $crowdExtras[] = sprintf(__('В работе: %d'), $crowdInProgress);
                                }
                                if ($crowdFailed > 0) {
                                    $crowdExtras[] = sprintf(__('Ошибок: %d'), $crowdFailed);
                                }
                                if ($crowdManual > 0) {
                                    $crowdExtras[] = sprintf(__('Задачи для ручного размещения: %d'), $crowdManual);
                                }
                                if (!empty($crowdExtras)) {
                                    $crowdDetail .= ' (' . implode(', ', $crowdExtras) . ')';
                                }
                                $promotionDetails[] = $crowdDetail;
                            } elseif ($crowdPlanned > 0) {
                                $promotionDetails[] = sprintf(__('Крауд задач запланировано: %d'), $crowdPlanned);
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
                                case 'pending_level3':
                                    $promotionStatusLabel = __('Ожидание уровня 3');
                                    break;
                                case 'level3_active':
                                    $promotionStatusLabel = __('Уровень 3 выполняется');
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
                                    $promotionStatusLabel = ($promotionStatus === 'idle') ? __('Продвижение не запускалось') : ucfirst($promotionStatus);
                                    break;
                            }
                            $canEdit = ($promotionStatus === 'idle');
                            $pu = @parse_url($url);
                            $hostDisp = pp_normalize_host($pu['host'] ?? '');
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
                            data-level3-total="<?php echo $level3Total; ?>"
                            data-level3-success="<?php echo $level3Success; ?>"
                            data-level3-required="<?php echo $level3Required; ?>"
                            data-crowd-planned="<?php echo $crowdPlanned; ?>"
                            data-crowd-total="<?php echo $crowdTotal; ?>"
                            data-crowd-completed="<?php echo $crowdCompleted; ?>"
                            data-crowd-running="<?php echo $crowdRunning; ?>"
                            data-crowd-queued="<?php echo $crowdQueued; ?>"
                            data-crowd-failed="<?php echo $crowdFailed; ?>"
                            data-crowd-manual="<?php echo $crowdManual; ?>">
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
                                     data-level3-total="<?php echo $level3Total; ?>"
                                     data-level3-success="<?php echo $level3Success; ?>"
                                     data-level3-required="<?php echo $level3Required; ?>"
                                     data-crowd-planned="<?php echo $crowdPlanned; ?>"
                                     data-crowd-total="<?php echo $crowdTotal; ?>"
                                     data-crowd-completed="<?php echo $crowdCompleted; ?>"
                                     data-crowd-running="<?php echo $crowdRunning; ?>"
                                     data-crowd-queued="<?php echo $crowdQueued; ?>"
                                     data-crowd-failed="<?php echo $crowdFailed; ?>">
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
                                        <div class="promotion-progress-level promotion-progress-level3 d-none" data-level="3">
                                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                                <span><?php echo __('Уровень 3'); ?></span>
                                                <span class="promotion-progress-value">0 / 0</span>
                                            </div>
                                            <div class="progress progress-thin">
                                                <div class="progress-bar promotion-progress-bar bg-warning" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                            </div>
                                        </div>
                                        <div class="promotion-progress-level promotion-progress-crowd d-none" data-level="crowd">
                                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                                <span><?php echo __('Крауд'); ?></span>
                                                <span class="promotion-progress-value">0 / 0</span>
                                            </div>
                                            <div class="progress progress-thin">
                                                <div class="progress-bar promotion-progress-bar bg-success" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
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
                                <div class="link-actions d-flex flex-wrap justify-content-end gap-2">
                                    <button type="button" class="icon-btn action-analyze" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                                    <?php if ($promotionStatus === 'completed'): ?>
                                    <?php elseif ($promotionActive): ?>
                                        <div class="link-actions-progress d-flex flex-column align-items-stretch gap-2">
                                            <button type="button" class="btn btn-sm btn-publish btn-progress-running w-100" disabled data-loading="1">
                                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                                <span class="label d-none d-md-inline"><?php echo __('В процессе'); ?></span>
                                            </button>
                                            <?php if ($promotionRunId > 0): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm action-promotion-progress link-actions-progress-btn w-100" data-run-id="<?php echo $promotionRunId; ?>" data-url="<?php echo htmlspecialchars($url); ?>" title="<?php echo __('Промежуточный отчет'); ?>">
                                                    <i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <button type="button"
                                                class="btn btn-sm btn-publish action-promote"
                                                data-url="<?php echo htmlspecialchars($url); ?>"
                                                data-id="<?php echo (int)$linkId; ?>"
                                                data-charge-amount="<?php echo htmlspecialchars($promotionChargeAmountAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-charge-formatted="<?php echo htmlspecialchars($promotionChargeFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-charge-base="<?php echo htmlspecialchars($promotionChargeBaseAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-charge-base-formatted="<?php echo htmlspecialchars($promotionBaseFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-charge-savings="<?php echo htmlspecialchars($promotionChargeSavingsAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-charge-savings-formatted="<?php echo htmlspecialchars($promotionChargeSavingsFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                data-discount-percent="<?php echo htmlspecialchars($promotionDiscountPercentAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($promotionReportReady && $promotionRunId > 0): ?>
                                        <button type="button" class="btn btn-outline-success btn-sm action-promotion-report" data-run-id="<?php echo $promotionRunId; ?>" data-url="<?php echo htmlspecialchars($url); ?>" title="<?php echo __('Скачать отчет'); ?>"><i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span></button>
                                    <?php endif; ?>

                                    <?php if ($canEdit): ?>
                                        <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                                        <button type="button" class="icon-btn action-remove" data-id="<?php echo (int)$linkId; ?>" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="icon-btn disabled" disabled title="<?php echo __('Редактирование доступно до запуска продвижения.'); ?>"><i class="bi bi-lock"></i></button>
                                        <button type="button" class="icon-btn disabled" disabled title="<?php echo __('Удаление доступно до запуска продвижения.'); ?>"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </div>
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
