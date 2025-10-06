<?php
/** @var array $groupOptions */
/** @var array $statusOptions */
/** @var array $crowdFilters */
/** @var array $perPageOptions */
/** @var array $orderOptions */
/** @var array $crowdList */
/** @var array $items */
/** @var callable $crowdBuildUrl */
/** @var int $totalPages */
/** @var int $currentPage */
?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <h5 class="card-title mb-3"><?php echo __('Фильтры и список ссылок'); ?></h5>
        <form method="get" class="row g-3 align-items-end mb-4" id="crowdFiltersForm">
            <input type="hidden" name="crowd_page" value="1">
            <div class="col-md-3">
                <label for="crowdGroup" class="form-label"><?php echo __('Группа'); ?></label>
                <select class="form-select" id="crowdGroup" name="crowd_group">
                    <?php foreach ($groupOptions as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $crowdFilters['group'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="crowdStatus" class="form-label"><?php echo __('Статус'); ?></label>
                <select class="form-select" id="crowdStatus" name="crowd_status">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $crowdFilters['status'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="crowdDomain" class="form-label"><?php echo __('Домен'); ?></label>
                <input type="text" class="form-control" id="crowdDomain" name="crowd_domain" value="<?php echo htmlspecialchars($crowdFilters['domain'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="example.com">
            </div>
            <div class="col-md-3">
                <label for="crowdSearch" class="form-label"><?php echo __('Поиск по URL или ошибке'); ?></label>
                <input type="text" class="form-control" id="crowdSearch" name="crowd_search" value="<?php echo htmlspecialchars($crowdFilters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...">
            </div>
            <div class="col-md-2">
                <label for="crowdLanguage" class="form-label"><?php echo __('Язык'); ?></label>
                <input type="text" class="form-control" id="crowdLanguage" name="crowd_language" value="<?php echo htmlspecialchars($crowdFilters['language'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="ru">
            </div>
            <div class="col-md-2">
                <label for="crowdRegion" class="form-label"><?php echo __('Регион'); ?></label>
                <input type="text" class="form-control" id="crowdRegion" name="crowd_region" value="<?php echo htmlspecialchars($crowdFilters['region'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="RU">
            </div>
            <div class="col-md-2">
                <label for="crowdPerPage" class="form-label"><?php echo __('На странице'); ?></label>
                <select class="form-select" id="crowdPerPage" name="crowd_per_page">
                    <?php foreach ($perPageOptions as $value): ?>
                        <option value="<?php echo (int)$value; ?>" <?php echo (int)$crowdFilters['per_page'] === (int)$value ? 'selected' : ''; ?>><?php echo (int)$value; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="crowdOrder" class="form-label"><?php echo __('Сортировка'); ?></label>
                <select class="form-select" id="crowdOrder" name="crowd_order">
                    <?php foreach ($orderOptions as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $crowdFilters['order'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-filter me-1"></i><?php echo __('Применить'); ?></button>
                <a href="<?php echo htmlspecialchars(pp_url('admin/admin.php') . '#crowd-section', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light" title="<?php echo __('Сбросить фильтры'); ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <button type="submit" name="crowd_delete_errors" value="1" class="btn btn-outline-warning btn-sm"><i class="bi bi-trash me-1"></i><?php echo __('Удалить ошибки'); ?></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('<?php echo __('Удалить все ссылки?'); ?>');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="crowd_clear_all" value="1" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i><?php echo __('Очистить всё'); ?></button>
            </form>
            <div class="ms-auto small text-muted align-self-center">
                <?php echo __('Найдено записей'); ?>: <strong><?php echo (int)($crowdList['total'] ?? 0); ?></strong>
            </div>
        </div>

        <?php if ($crowdList['group'] === 'domains'): ?>
            <?php include __DIR__ . '/list_domains.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/list_links.php'; ?>
        <?php endif; ?>

        <?php include __DIR__ . '/pagination.php'; ?>
    </div>
</div>
