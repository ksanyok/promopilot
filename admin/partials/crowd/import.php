<?php
/** @var string $exportUrl */
?>
<div class="mb-4">
    <div class="card crowd-panel crowd-panel--upload h-100">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="card-title mb-0"><?php echo __('Импорт ссылок'); ?></h5>
                    <p class="text-muted small mb-0"><?php echo __('Загрузите TXT файлы, по одной ссылке в строке.'); ?></p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="<?php echo htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-download me-1"></i><?php echo __('Экспорт CSV'); ?>
                    </a>
                    <i class="bi bi-upload text-primary fs-4"></i>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="crowd_import" value="1">
                <div class="mb-3">
                    <label for="crowdFiles" class="form-label"><?php echo __('Файлы TXT'); ?></label>
                    <input type="file" name="crowd_files[]" id="crowdFiles" class="form-control" accept=".txt,text/plain" multiple required>
                    <div class="form-text"><?php echo __('Максимум 10 МБ на файл. Дубликаты будут автоматически отфильтрованы.'); ?></div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-file-earmark-plus me-1"></i><?php echo __('Импортировать'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
