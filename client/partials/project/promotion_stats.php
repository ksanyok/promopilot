<?php /* Promotion stats cards extracted from client/project.php */ ?>
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
