<?php
/** @var string $crowdMsg */
/** @var string|null $crowdStatusError */
/** @var array|null $crowdImportSummary */
?>
<?php if (!empty($crowdMsg)): ?>
    <div class="alert alert-info fade-in"><?php echo htmlspecialchars($crowdMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if (!empty($crowdStatusError)): ?>
    <div class="alert alert-warning fade-in"><?php echo __('Не удалось загрузить статус последнего запуска.'); ?> (<?php echo htmlspecialchars((string)$crowdStatusError, ENT_QUOTES, 'UTF-8'); ?>)</div>
<?php endif; ?>

<?php if (is_array($crowdImportSummary) && !empty($crowdImportSummary['errors'])): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1"><?php echo __('Ошибки импорта'); ?>:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($crowdImportSummary['errors'] as $err): ?>
                <li><?php echo htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
