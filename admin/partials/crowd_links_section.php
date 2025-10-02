<?php
// Crowd marketing section: imports, filters, checks

$crowdSelectedSet = [];
if (!empty($crowdSelectedIds)) {
    foreach ($crowdSelectedIds as $val) {
        $id = (int)$val;
        if ($id > 0) {
            $crowdSelectedSet[$id] = true;
        }
    }
}

$statusOptions = [];
$statusOptions[''] = __('Все статусы');
foreach ($crowdStatusMeta as $key => $meta) {
    $statusOptions[$key] = $meta['label'] ?? $key;
}
$statusOptions['errors'] = __('Ошибочные статусы');

$groupOptions = [
    'links' => __('Ссылки'),
    'domains' => __('Домены'),
];

$perPageOptions = [25, 50, 100, 200];
if (!in_array($crowdFilters['per_page'], $perPageOptions, true)) {
    $perPageOptions[] = $crowdFilters['per_page'];
    sort($perPageOptions);
}

$orderOptions = [
    'recent' => __('Новые сверху'),
    'oldest' => __('Старые сверху'),
    'status' => __('По статусу'),
    'domain' => __('По домену'),
    'checked' => __('По дате проверки'),
];

$crowdQueryBase = [
    'crowd_group' => $crowdFilters['group'],
    'crowd_status' => $crowdFilters['status'],
    'crowd_domain' => $crowdFilters['domain'],
    'crowd_language' => $crowdFilters['language'],
    'crowd_region' => $crowdFilters['region'],
    'crowd_search' => $crowdFilters['search'],
    'crowd_per_page' => $crowdFilters['per_page'],
    'crowd_order' => $crowdFilters['order'],
];

$crowdBuildUrl = static function(array $params = []) use ($crowdQueryBase) {
    $query = array_merge($crowdQueryBase, $params);
    $filtered = [];
    foreach ($query as $key => $value) {
        if ($value === null) { continue; }
        if ($value === '') {
            if (in_array($key, ['crowd_status', 'crowd_domain', 'crowd_language', 'crowd_region', 'crowd_search'], true)) {
                continue;
            }
        }
        $filtered[$key] = $value;
    }
    $base = pp_url('admin/admin.php');
    $suffix = http_build_query($filtered);
    $anchor = '#crowd-section';
    return $suffix ? ($base . '?' . $suffix . $anchor) : ($base . $anchor);
};

$crowdStatusLabels = [
    'queued' => __('В ожидании'),
    'running' => __('Выполняется'),
    'completed' => __('Завершено (с ошибками)'),
    'success' => __('Завершено без ошибок'),
    'failed' => __('Сбой обработки'),
    'cancelled' => __('Отменено'),
];

$crowdScopeLabels = [];
foreach ($crowdScopeOptions as $scopeKey => $scopeLabel) {
    $crowdScopeLabels[$scopeKey] = $scopeLabel;
}

$apiUrl = pp_url('admin/crowd_links.php');
$hasRun = is_array($crowdCurrentRun);
$runInProgress = $hasRun && !empty($crowdCurrentRun['in_progress']);
$totalPages = (int)($crowdList['pages'] ?? 0);
$currentPage = (int)($crowdList['page'] ?? 1);
$items = $crowdList['items'] ?? [];

$runStatusLabel = '—';
$runScopeLabel = '—';
$runProgress = 0;
$runProcessed = 0;
$runTotal = 0;
$runOk = 0;
$runErrors = 0;
$runStartedAt = '—';
$runFinishedAt = '—';
$runNotes = __('Проверка ещё не запускалась');
if ($hasRun) {
    $runStatusLabel = $crowdStatusLabels[$crowdCurrentRun['status']] ?? (string)$crowdCurrentRun['status'];
    $runScopeLabel = $crowdScopeLabels[$crowdCurrentRun['scope']] ?? (string)$crowdCurrentRun['scope'];
    $runProgress = (int)($crowdCurrentRun['progress_percent'] ?? 0);
    $runProcessed = (int)($crowdCurrentRun['processed_count'] ?? 0);
    $runTotal = (int)($crowdCurrentRun['total_links'] ?? 0);
    $runOk = (int)($crowdCurrentRun['ok_count'] ?? 0);
    $runErrors = (int)($crowdCurrentRun['error_count'] ?? 0);
    $runStartedAt = (string)($crowdCurrentRun['started_at'] ?? '—');
    $runFinishedAt = (string)($crowdCurrentRun['finished_at'] ?? '—');
    if ($runStartedAt === '' || $runStartedAt === '0000-00-00 00:00:00') { $runStartedAt = '—'; }
    if ($runFinishedAt === '' || $runFinishedAt === '0000-00-00 00:00:00') { $runFinishedAt = '—'; }
    $notesRaw = (string)($crowdCurrentRun['notes'] ?? '');
    $runNotes = $notesRaw !== '' ? $notesRaw : '—';
}
?>
<div id="crowd-section" style="display:none;" data-crowd-api="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <h3 class="mb-4 d-flex align-items-center gap-2">
        <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill"><i class="bi bi-people-fill me-1"></i><?php echo __('Крауд маркетинг'); ?></span>
        <span><?php echo __('Управление крауд-ссылками'); ?></span>
    </h3>

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

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small"><?php echo __('Всего ссылок'); ?></div>
                    <div class="display-6 fw-semibold"><?php echo number_format((int)($crowdStats['total'] ?? 0), 0, '.', ' '); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small"><?php echo __('Проверено и активно'); ?></div>
                    <div class="display-6 text-success fw-semibold"><?php echo number_format((int)($crowdStats['ok'] ?? 0), 0, '.', ' '); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small"><?php echo __('Ожидают проверки'); ?></div>
                    <div class="display-6 text-warning fw-semibold"><?php echo number_format((int)($crowdStats['pending'] ?? 0), 0, '.', ' '); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small"><?php echo __('Ошибки'); ?></div>
                    <div class="display-6 text-danger fw-semibold"><?php echo number_format((int)($crowdStats['errors'] ?? 0), 0, '.', ' '); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="card-title mb-0"><?php echo __('Импорт ссылок'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo __('Загрузите TXT файлы, по одной ссылке в строке.'); ?></p>
                        </div>
                        <i class="bi bi-upload text-primary fs-4"></i>
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
                    <?php if (is_array($crowdImportSummary) && !empty($crowdImportSummary['ok'])): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <div class="fw-semibold mb-1"><?php echo __('Результаты импорта'); ?>:</div>
                            <ul class="mb-0 ps-3">
                                <li><?php echo __('Добавлено новых ссылок'); ?>: <strong><?php echo (int)($crowdImportSummary['imported'] ?? 0); ?></strong></li>
                                <li><?php echo __('Найдено дубликатов'); ?>: <strong><?php echo (int)($crowdImportSummary['duplicates'] ?? 0); ?></strong></li>
                                <li><?php echo __('Пропущено строк'); ?>: <strong><?php echo (int)($crowdImportSummary['invalid'] ?? 0); ?></strong></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100" id="crowdCheckCard" data-run-id="<?php echo $hasRun ? (int)$crowdCurrentRun['id'] : ''; ?>" data-run-active="<?php echo $runInProgress ? '1' : '0'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="card-title mb-0"><?php echo __('Проверка ссылок'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo __('Фоновая проверка HTTP статусов и hreflang.'); ?></p>
                        </div>
                        <i class="bi bi-robot text-success fs-4"></i>
                    </div>
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-6">
                            <label for="crowdCheckScope" class="form-label"><?php echo __('Диапазон проверки'); ?></label>
                            <select class="form-select" id="crowdCheckScope">
                                <?php foreach ($crowdScopeOptions as $scopeKey => $scopeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success flex-grow-1" id="crowdCheckStart">
                                    <span class="label-text"><i class="bi bi-play-circle me-1"></i><?php echo __('Запустить проверку'); ?></span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="crowdCheckCancel" <?php echo $runInProgress ? '' : 'disabled'; ?>>
                                    <span class="label-text"><i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить'); ?></span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="crowdCheckMessage" class="small text-muted mb-3" role="status"></div>
                    <div class="bg-light rounded-3 p-3" id="crowdCheckStatusCard">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="text-muted small"><?php echo __('Статус'); ?></div>
                                <div class="fw-semibold" data-crowd-status><?php echo htmlspecialchars($runStatusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small"><?php echo __('Диапазон'); ?></div>
                                <div class="fw-semibold" data-crowd-scope><?php echo htmlspecialchars($runScopeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar bg-success" id="crowdCheckProgressBar" role="progressbar" style="width: <?php echo $runProgress; ?>%;" aria-valuenow="<?php echo $runProgress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <div><span data-crowd-processed><?php echo $runProcessed; ?></span>/<span data-crowd-total><?php echo $runTotal; ?></span></div>
                            <div class="text-success"><i class="bi bi-check-circle me-1"></i><span data-crowd-ok><?php echo $runOk; ?></span></div>
                            <div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><span data-crowd-errors><?php echo $runErrors; ?></span></div>
                        </div>
                        <hr>
                        <div class="row small g-2">
                            <div class="col-sm-6">
                                <div class="text-muted"><?php echo __('Запущено'); ?>:</div>
                                <div data-crowd-started><?php echo htmlspecialchars($runStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <div class="text-muted"><?php echo __('Завершено'); ?>:</div>
                                <div data-crowd-finished><?php echo htmlspecialchars($runFinishedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="col-sm-12">
                                <div class="text-muted"><?php echo __('Комментарий'); ?>:</div>
                                <div data-crowd-notes><?php echo htmlspecialchars($runNotes, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo __('Домен'); ?></th>
                                <th class="text-center"><?php echo __('Всего'); ?></th>
                                <th class="text-center text-success"><?php echo __('OK'); ?></th>
                                <th class="text-center text-warning"><?php echo __('В ожидании'); ?></th>
                                <th class="text-center text-info"><?php echo __('В процессе'); ?></th>
                                <th class="text-center text-danger"><?php echo __('Ошибки'); ?></th>
                                <th><?php echo __('Последняя проверка'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4"><?php echo __('Записей не найдено.'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars((string)($row['domain'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td class="text-center"><span class="badge bg-secondary-subtle text-dark"><?php echo (int)($row['total_links'] ?? 0); ?></span></td>
                                        <td class="text-center text-success"><?php echo (int)($row['ok_links'] ?? 0); ?></td>
                                        <td class="text-center text-warning"><?php echo (int)($row['pending_links'] ?? 0); ?></td>
                                        <td class="text-center text-info"><?php echo (int)($row['checking_links'] ?? 0); ?></td>
                                        <td class="text-center text-danger"><?php echo (int)($row['error_links'] ?? 0); ?></td>
                                        <td><?php echo !empty($row['last_checked_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['last_checked_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <form method="post" id="crowdSelectionForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="crowd_delete_selected" value="1">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle" id="crowdLinksTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:32px;">
                                        <input type="checkbox" class="form-check-input" data-crowd-select="toggle"
                                               aria-label="<?php echo __('Выделить всё на странице'); ?>">
                                    </th>
                                    <th><?php echo __('URL'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Статус'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Код'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Язык'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Регион'); ?></th>
                                    <th><?php echo __('Домен'); ?></th>
                                    <th><?php echo __('Комментарий'); ?></th>
                                    <th><?php echo __('Обновлено'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4"><?php echo __('Записей не найдено.'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $row): ?>
                                        <?php
                                            $linkId = (int)($row['id'] ?? 0);
                                            $status = (string)($row['status'] ?? 'pending');
                                            $meta = $crowdStatusMeta[$status] ?? null;
                                            $badgeClass = $meta['class'] ?? 'badge bg-secondary';
                                            $badgeLabel = $meta['label'] ?? $status;
                                            $checked = isset($crowdSelectedSet[$linkId]);
                                            $statusCode = (int)($row['status_code'] ?? 0);
                                            $processing = !empty($row['processing_run_id']);
                                        ?>
                                        <tr data-link-id="<?php echo $linkId; ?>">
                                            <td>
                                                <input type="checkbox" class="form-check-input" name="crowd_selected[]" value="<?php echo $linkId; ?>" data-crowd-checkbox <?php echo $checked ? 'checked' : ''; ?> aria-label="<?php echo __('Выбрать ссылку'); ?>">
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-break">
                                                    <a href="<?php echo htmlspecialchars((string)($row['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="link-primary">
                                                        <?php echo htmlspecialchars((string)($row['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                </div>
                                                <div class="small text-muted">ID <?php echo $linkId; ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>" data-crowd-status-badge><?php echo htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($processing): ?>
                                                    <span class="spinner-border spinner-border-sm text-info ms-1" role="status" aria-hidden="true"></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $statusCode > 0 ? $statusCode : '—'; ?></td>
                                            <td class="text-uppercase"><?php echo $row['language'] ? htmlspecialchars((string)$row['language'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td class="text-uppercase"><?php echo $row['region'] ? htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><?php echo htmlspecialchars((string)($row['domain'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-break small">&nbsp;<?php echo $row['error'] ? htmlspecialchars((string)$row['error'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><?php echo !empty($row['last_checked_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['last_checked_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                        <button type="submit" class="btn btn-outline-danger btn-sm" id="crowdDeleteSelected" <?php echo empty($items) ? 'disabled' : ''; ?>>
                            <i class="bi bi-trash me-1"></i><?php echo __('Удалить выбранные'); ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-crowd-select="all"><?php echo __('Выбрать всё'); ?></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-crowd-select="none"><?php echo __('Снять выделение'); ?></button>
                        <div class="small text-muted ms-auto" id="crowdSelectionCounter"><?php echo __('Выбрано ссылок'); ?>: <span data-crowd-selected-count>0</span></div>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="<?php echo __('Навигация по страницам'); ?>" class="mt-4">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $prevDisabled = $currentPage <= 1;
                        $nextDisabled = $currentPage >= $totalPages;
                        ?>
                        <li class="page-item <?php echo $prevDisabled ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $prevDisabled ? '#' : htmlspecialchars($crowdBuildUrl(['crowd_page' => max(1, $currentPage - 1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo __('Предыдущая'); ?>">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                            <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($crowdBuildUrl(['crowd_page' => $page]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $page; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $nextDisabled ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $nextDisabled ? '#' : htmlspecialchars($crowdBuildUrl(['crowd_page' => min($totalPages, $currentPage + 1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo __('Следующая'); ?>">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
    const section = document.getElementById('crowd-section');
    if (!section) { return; }
    const apiBase = section.getAttribute('data-crowd-api');
    const startBtn = section.querySelector('#crowdCheckStart');
    const cancelBtn = section.querySelector('#crowdCheckCancel');
    const scopeSelect = section.querySelector('#crowdCheckScope');
    const messageBox = section.querySelector('#crowdCheckMessage');
    const card = section.querySelector('#crowdCheckCard');
    const statusBox = section.querySelector('#crowdCheckStatusCard');
    const progressBar = section.querySelector('#crowdCheckProgressBar');
    const statusLabel = section.querySelector('[data-crowd-status]');
    const totalEl = section.querySelector('[data-crowd-total]');
    const processedEl = section.querySelector('[data-crowd-processed]');
    const okEl = section.querySelector('[data-crowd-ok]');
    const errorEl = section.querySelector('[data-crowd-errors]');
    const scopeEl = section.querySelector('[data-crowd-scope]');
    const startedEl = section.querySelector('[data-crowd-started]');
    const finishedEl = section.querySelector('[data-crowd-finished]');
    const notesEl = section.querySelector('[data-crowd-notes]');
    const selectAllBtns = section.querySelectorAll('[data-crowd-select]');
    const checkboxes = section.querySelectorAll('input[data-crowd-checkbox]');
    const selectionCounter = section.querySelector('[data-crowd-selected-count]');
    const deleteBtn = section.querySelector('#crowdDeleteSelected');
    const table = section.querySelector('#crowdLinksTable');

    const labels = {
        selectSomething: <?php echo json_encode(__('Выберите хотя бы одну ссылку.'), JSON_UNESCAPED_UNICODE); ?>,
        startSuccess: <?php echo json_encode(__('Проверка запущена.'), JSON_UNESCAPED_UNICODE); ?>,
        alreadyRunning: <?php echo json_encode(__('Проверка уже выполняется.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelled: <?php echo json_encode(__('Остановка запроса отправлена.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelIdle: <?php echo json_encode(__('Нет активных проверок.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelFailed: <?php echo json_encode(__('Не удалось отменить проверку.'), JSON_UNESCAPED_UNICODE); ?>,
        statusMap: <?php echo json_encode($crowdStatusLabels, JSON_UNESCAPED_UNICODE); ?>,
        scopeMap: <?php echo json_encode($crowdScopeLabels, JSON_UNESCAPED_UNICODE); ?>,
        noRuns: <?php echo json_encode(__('Проверка ещё не запускалась'), JSON_UNESCAPED_UNICODE); ?>
    };

    let pollTimer = null;
    let currentRunId = card ? parseInt(card.getAttribute('data-run-id') || '0', 10) : 0;
    const initialActive = card ? card.getAttribute('data-run-active') === '1' : false;

    function updateMessage(text, type = 'muted') {
        if (!messageBox) return;
        if (!text) {
            messageBox.textContent = '';
            messageBox.className = 'small text-muted mb-3';
            return;
        }
        const map = {
            success: 'small text-success mb-3',
            danger: 'small text-danger mb-3',
            warning: 'small text-warning mb-3',
            info: 'small text-info mb-3',
            muted: 'small text-muted mb-3'
        };
        messageBox.textContent = text;
        messageBox.className = map[type] || map.muted;
    }

    function gatherSelectedIds() {
        const ids = [];
        section.querySelectorAll('input[data-crowd-checkbox]:checked').forEach(cb => {
            const val = parseInt(cb.value, 10);
            if (val > 0) { ids.push(val); }
        });
        return ids;
    }

    function toggleButtons(runActive) {
        if (startBtn) startBtn.disabled = runActive;
        if (cancelBtn) cancelBtn.disabled = !runActive;
    }

    function setSpinner(btn, spinning) {
        if (!btn) return;
        const spinner = btn.querySelector('.spinner-border');
        const label = btn.querySelector('.label-text');
        if (spinning) {
            btn.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (label) label.classList.add('d-none');
        } else {
            if (!card || card.getAttribute('data-run-active') !== '1') {
                btn.disabled = false;
            }
            if (spinner) spinner.classList.add('d-none');
            if (label) label.classList.remove('d-none');
        }
    }

    function updateCounts() {
        if (!selectionCounter) return;
        const count = gatherSelectedIds().length;
        selectionCounter.textContent = count;
        if (deleteBtn) {
            deleteBtn.disabled = count === 0;
        }
    }

    function updateRunCard(data) {
        if (!data || !card) {
            currentRunId = 0;
            card.setAttribute('data-run-id', '');
            card.setAttribute('data-run-active', '0');
            toggleButtons(false);
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            if (statusLabel) statusLabel.textContent = '—';
            if (scopeEl) scopeEl.textContent = '—';
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.setAttribute('aria-valuenow', '0');
            }
            if (processedEl) processedEl.textContent = '0';
            if (totalEl) totalEl.textContent = '0';
            if (okEl) okEl.textContent = '0';
            if (errorEl) errorEl.textContent = '0';
            if (startedEl) startedEl.textContent = '—';
            if (finishedEl) finishedEl.textContent = '—';
            if (notesEl) notesEl.textContent = labels.noRuns || '—';
            return;
        }
        currentRunId = data.id || 0;
        card.setAttribute('data-run-id', currentRunId ? String(currentRunId) : '');
        const active = !!data.in_progress;
        card.setAttribute('data-run-active', active ? '1' : '0');
        toggleButtons(active);
        if (statusLabel) {
            statusLabel.textContent = labels.statusMap[data.status] || data.status;
        }
        if (scopeEl) {
            scopeEl.textContent = labels.scopeMap[data.scope] || data.scope;
        }
        if (progressBar) {
            const pct = data.progress_percent || 0;
            progressBar.style.width = pct + '%';
            progressBar.setAttribute('aria-valuenow', pct);
        }
        if (processedEl) processedEl.textContent = data.processed_count || 0;
        if (totalEl) totalEl.textContent = data.total_links || 0;
        if (okEl) okEl.textContent = data.ok_count || 0;
        if (errorEl) errorEl.textContent = data.error_count || 0;
        if (startedEl) startedEl.textContent = data.started_at || '—';
    if (finishedEl) finishedEl.textContent = data.finished_at || '—';
    if (notesEl) notesEl.textContent = data.notes ? data.notes : '—';

        if (!active && pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (active && !pollTimer) {
            pollTimer = setTimeout(() => fetchStatus(currentRunId), 4000);
        }
    }

    async function fetchStatus(runId) {
        if (!apiBase || !runId) { return; }
        try {
            const res = await fetch(apiBase + '?action=status&run_id=' + encodeURIComponent(runId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json().catch(() => ({}));
            if (!data || data.ok === false) {
                updateMessage(data && data.error ? data.error : 'Error', 'warning');
                return;
            }
            updateRunCard(data.run || null);
            if (data.run && data.run.in_progress) {
                pollTimer = setTimeout(() => fetchStatus(runId), 4000);
            }
        } catch (err) {
            updateMessage(err && err.message ? err.message : 'Error', 'warning');
            pollTimer = setTimeout(() => fetchStatus(runId), 6000);
        }
    }

    async function startCheck() {
        if (!apiBase || !startBtn) return;
        const scope = scopeSelect ? scopeSelect.value : 'all';
        const ids = scope === 'selection' ? gatherSelectedIds() : [];
        if (scope === 'selection' && ids.length === 0) {
            updateMessage(labels.selectSomething, 'warning');
            return;
        }
        setSpinner(startBtn, true);
        updateMessage('');
        try {
            const body = new URLSearchParams();
            body.set('scope', scope);
            if (ids.length) {
                ids.forEach(id => body.append('ids[]', String(id)));
            }
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(apiBase + '?action=start', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                let msg = data && data.error ? data.error : 'Error';
                updateMessage(msg, 'danger');
                return;
            }
            if (data.alreadyRunning) {
                updateMessage(labels.alreadyRunning, 'info');
            } else {
                updateMessage(labels.startSuccess, 'success');
            }
            if (data.runId) {
                currentRunId = data.runId;
                card.setAttribute('data-run-id', String(currentRunId));
                card.setAttribute('data-run-active', '1');
                toggleButtons(true);
                fetchStatus(currentRunId);
            }
        } catch (err) {
            updateMessage(err && err.message ? err.message : 'Error', 'danger');
        } finally {
            setSpinner(startBtn, false);
        }
    }

    async function cancelCheck() {
        if (!apiBase || !cancelBtn) return;
        const runId = currentRunId;
        if (!runId) {
            updateMessage(labels.cancelIdle, 'info');
            return;
        }
        setSpinner(cancelBtn, true);
        updateMessage('');
        try {
            const body = new URLSearchParams();
            body.set('run_id', String(runId));
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(apiBase + '?action=cancel', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateMessage(labels.cancelFailed, 'danger');
                return;
            }
            updateMessage(labels.cancelled, 'info');
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            fetchStatus(currentRunId);
        } catch (err) {
            updateMessage(labels.cancelFailed, 'danger');
        } finally {
            setSpinner(cancelBtn, false);
        }
    }

    function handleSelectAction(action) {
        if (action === 'toggle') {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
        } else if (action === 'all') {
            checkboxes.forEach(cb => { cb.checked = true; });
        } else if (action === 'none') {
            checkboxes.forEach(cb => { cb.checked = false; });
        }
        updateCounts();
    }

    selectAllBtns.forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            handleSelectAction(this.getAttribute('data-crowd-select'));
        });
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateCounts);
    });

    if (startBtn) {
        startBtn.addEventListener('click', (e) => {
            e.preventDefault();
            startCheck();
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            cancelCheck();
        });
    }

    updateCounts();
    if (initialActive && currentRunId) {
        fetchStatus(currentRunId);
    }
})();
</script>
