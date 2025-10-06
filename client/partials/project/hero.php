<?php /* Project hero section extracted from client/project.php */ ?>
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
          <div class="mb-3">
            <label class="form-label"><?php echo __('Язык страницы'); ?></label>
            <select name="project_language" class="form-select">
              <?php foreach (['ru'=>'RU','en'=>'EN','es'=>'ES','fr'=>'FR','de'=>'DE'] as $lv=>$lt): ?>
                <option value="<?php echo $lv; ?>" <?php echo ($project['language'] ?? 'ru')===$lv?'selected':''; ?>><?php echo $lt; ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text"><?php echo __('Влияет на язык по умолчанию для новых ссылок.'); ?></div>
          </div>
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
