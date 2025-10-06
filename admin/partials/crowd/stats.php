<?php
/** @var array $crowdStats */
/** @var int $deepStatsSuccess */
/** @var int $deepStatsPartial */
/** @var int $deepStatsFailed */
/** @var int $deepStatsSkipped */
?>
<div class="row g-3 mb-4 crowd-stats-row">
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--total h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Всего ссылок'); ?></div>
                <div class="crowd-stat-card__value"><?php echo number_format((int)($crowdStats['total'] ?? 0), 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--ok h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Проверено и активно'); ?></div>
                <div class="crowd-stat-card__value"><?php echo number_format((int)($crowdStats['ok'] ?? 0), 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--pending h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Ожидают проверки'); ?></div>
                <div class="crowd-stat-card__value"><?php echo number_format((int)($crowdStats['pending'] ?? 0), 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--errors h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Ошибки'); ?></div>
                <div class="crowd-stat-card__value"><?php echo number_format((int)($crowdStats['errors'] ?? 0), 0, '.', ' '); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4 crowd-deep-stats-row">
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--deep-success h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Глубокая проверка — успешно'); ?></div>
                <div class="crowd-stat-card__value"><span data-deep-stats-success><?php echo number_format($deepStatsSuccess, 0, '.', ' '); ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--deep-partial h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Глубокая проверка — вручную'); ?></div>
                <div class="crowd-stat-card__value"><span data-deep-stats-partial><?php echo number_format($deepStatsPartial, 0, '.', ' '); ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--deep-failed h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Глубокая проверка — ошибки'); ?></div>
                <div class="crowd-stat-card__value"><span data-deep-stats-failed><?php echo number_format($deepStatsFailed, 0, '.', ' '); ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card crowd-stat-card crowd-stat-card--deep-skipped h-100">
            <div class="card-body d-flex flex-column justify-content-between">
                <div class="crowd-stat-card__label"><?php echo __('Глубокая проверка — пропущено'); ?></div>
                <div class="crowd-stat-card__value"><span data-deep-stats-skipped><?php echo number_format($deepStatsSkipped, 0, '.', ' '); ?></span></div>
            </div>
        </div>
    </div>
</div>
