<?php
/** @var string $headerKind */
/** @var string $headerPercentText */
/** @var int $headerProgress */
/** @var string $headerStatusLabel */
/** @var string $headerCountSummary */
?>
<div class="crowd-header mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
    <h3 class="crowd-title d-flex align-items-center gap-2 mb-0">
        <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill"><i class="bi bi-people-fill me-1"></i><?php echo __('Крауд маркетинг'); ?></span>
        <span><?php echo __('Управление крауд-ссылками'); ?></span>
    </h3>
    <div class="crowd-header-progress">
        <div class="crowd-header-progress__status">
            <span class="crowd-header-progress__label"><?php echo __('Прогресс проверки'); ?></span>
            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-2" data-crowd-header-kind><?php echo htmlspecialchars($headerKind, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="crowd-header-progress__percent" data-crowd-header-percent><?php echo htmlspecialchars($headerPercentText, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="crowd-header-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int)$headerProgress; ?>" data-crowd-progress-bar>
            <span class="crowd-header-progress__bar-fill" data-crowd-progress-header style="width: <?php echo (int)$headerProgress; ?>%;"></span>
        </div>
        <div class="crowd-header-progress__meta">
            <span class="crowd-header-progress__status-badge" data-crowd-header-status><?php echo htmlspecialchars($headerStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="crowd-header-progress__count" data-crowd-header-count><?php echo htmlspecialchars($headerCountSummary, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>
</div>
