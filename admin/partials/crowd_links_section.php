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
$exportUrl = pp_url('admin/crowd_links.php') . '?action=export';
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
$runPercentText = $runTotal > 0 ? ($runProgress . '%') : '—';
$runCountSummary = $runTotal > 0 ? ($runProcessed . '/' . $runTotal) : '—';

$crowdDeepStatusLabels = [];
foreach ($crowdDeepStatusMeta as $key => $meta) {
    $crowdDeepStatusLabels[$key] = $meta['label'] ?? $key;
}

$crowdDeepStatusClasses = [];
foreach ($crowdDeepStatusMeta as $key => $meta) {
    $crowdDeepStatusClasses[$key] = $meta['class'] ?? 'badge bg-secondary';
}

$crowdDeepScopeLabels = [];
foreach ($crowdDeepScopeOptions as $scopeKey => $scopeLabel) {
    $crowdDeepScopeLabels[$scopeKey] = $scopeLabel;
}

$deepApiUrl = pp_url('admin/crowd_links.php');
$deepHasRun = is_array($crowdDeepCurrentRun);
$deepRunInProgress = $deepHasRun && !empty($crowdDeepCurrentRun['in_progress']);
$deepRunStatusLabel = '—';
$deepRunScopeLabel = '—';
$deepRunProgress = 0;
$deepRunProcessed = 0;
$deepRunTotal = 0;
$deepRunSuccess = 0;
$deepRunPartial = 0;
$deepRunFailed = 0;
$deepRunSkipped = 0;
$deepRunStartedAt = '—';
$deepRunFinishedAt = '—';
$deepRunNotes = __('Глубокая проверка ещё не запускалась');
$deepRunId = $deepHasRun ? (int)$crowdDeepCurrentRun['id'] : 0;
if ($deepHasRun) {
    $deepRunStatusLabel = $crowdDeepStatusLabels[$crowdDeepCurrentRun['status']] ?? (string)$crowdDeepCurrentRun['status'];
    $deepRunScopeLabel = $crowdDeepScopeLabels[$crowdDeepCurrentRun['scope']] ?? (string)$crowdDeepCurrentRun['scope'];
    $deepRunProgress = (int)($crowdDeepCurrentRun['progress_percent'] ?? 0);
    $deepRunProcessed = (int)($crowdDeepCurrentRun['processed_count'] ?? 0);
    $deepRunTotal = (int)($crowdDeepCurrentRun['total_links'] ?? 0);
    $deepRunSuccess = (int)($crowdDeepCurrentRun['success_count'] ?? 0);
    $deepRunPartial = (int)($crowdDeepCurrentRun['partial_count'] ?? 0);
    $deepRunFailed = (int)($crowdDeepCurrentRun['failed_count'] ?? 0);
    $deepRunSkipped = (int)($crowdDeepCurrentRun['skipped_count'] ?? 0);
    $deepRunStartedAt = (string)($crowdDeepCurrentRun['started_at'] ?? '—');
    $deepRunFinishedAt = (string)($crowdDeepCurrentRun['finished_at'] ?? '—');
    if ($deepRunStartedAt === '' || $deepRunStartedAt === '0000-00-00 00:00:00') { $deepRunStartedAt = '—'; }
    if ($deepRunFinishedAt === '' || $deepRunFinishedAt === '0000-00-00 00:00:00') { $deepRunFinishedAt = '—'; }
    $deepRunNotesRaw = (string)($crowdDeepCurrentRun['notes'] ?? '');
    $deepRunNotes = $deepRunNotesRaw !== '' ? $deepRunNotesRaw : '—';
}
$deepRunPercentText = $deepRunTotal > 0 ? ($deepRunProgress . '%') : '—';
$deepRunCountSummary = $deepRunTotal > 0 ? ($deepRunProcessed . '/' . $deepRunTotal) : '—';
$deepRecentItems = is_array($crowdDeepRecentResults) ? $crowdDeepRecentResults : [];
$deepFormTemplate = $deepHasRun ? (string)($crowdDeepCurrentRun['message_template'] ?? '') : (string)($crowdDeepDefaults['message_template'] ?? '');
if ($deepFormTemplate === '') { $deepFormTemplate = (string)($crowdDeepDefaults['message_template'] ?? ''); }
$deepFormLink = $deepHasRun ? (string)($crowdDeepCurrentRun['message_url'] ?? '') : (string)($crowdDeepDefaults['message_link'] ?? '');
if ($deepFormLink === '') { $deepFormLink = (string)($crowdDeepDefaults['message_link'] ?? ''); }
$deepFormName = (string)($crowdDeepDefaults['name'] ?? '');
$deepFormCompany = (string)($crowdDeepDefaults['company'] ?? '');
$deepFormEmailUser = (string)($crowdDeepDefaults['email_user'] ?? '');
$deepFormEmailDomain = (string)($crowdDeepDefaults['email_domain'] ?? '');
$deepFormPhone = (string)($crowdDeepDefaults['phone'] ?? '');
$deepFormTokenPrefix = $deepHasRun ? (string)($crowdDeepCurrentRun['token_prefix'] ?? '') : (string)($crowdDeepDefaults['token_prefix'] ?? '');
if ($deepFormTokenPrefix === '') { $deepFormTokenPrefix = (string)($crowdDeepDefaults['token_prefix'] ?? ''); }
// Header progress kind: which check is currently active
$headerKind = '—';
if ($deepRunInProgress) {
    $headerKind = __('Глубокая проверка');
} elseif ($runInProgress) {
    $headerKind = __('Простая проверка');
}

// Header progress metrics should reflect the active run on first render
$headerStatusLabel = $runStatusLabel;
$headerProgress = $runProgress;
$headerPercentText = $runPercentText;
$headerCountSummary = $runCountSummary;
if ($deepRunInProgress) {
    $headerStatusLabel = $deepRunStatusLabel;
    $headerProgress = $deepRunProgress;
    $headerPercentText = $deepRunPercentText;
    $headerCountSummary = $deepRunCountSummary;
}

// If deep run is active, align initial header metrics to deep run so UI is correct before JS polling kicks in
if ($deepRunInProgress) {
    $runStatusLabel = $deepRunStatusLabel;
    $runProgress = $deepRunProgress;
    $runProcessed = $deepRunProcessed;
    $runTotal = $deepRunTotal;
    $runPercentText = $deepRunTotal > 0 ? ($deepRunProgress . '%') : '—';
    $runCountSummary = $deepRunTotal > 0 ? ($deepRunProcessed . '/' . $deepRunTotal) : '—';
}

// Helper to truncate URL display to 30 characters
$pp_truncate = static function (string $text, int $length = 30): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $length) {
            return mb_substr($text, 0, $length, 'UTF-8') . '…';
        }
        return $text;
    }
    return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
};
?>
<div id="crowd-section" style="display:none;"
    data-crowd-api="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-crowd-deep-api="<?php echo htmlspecialchars($deepApiUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-crowd-deep-run-id="<?php echo $deepHasRun ? (int)$crowdDeepCurrentRun['id'] : ''; ?>"
    data-crowd-deep-active="<?php echo $deepRunInProgress ? '1' : '0'; ?>">
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

    <!-- Import (full width row) -->
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
            </div> <!-- /.card-body -->
        </div> <!-- /.card -->
    </div>

    <!-- Checks tabs (full width row) -->
    <div class="mb-4">
                    <ul class="nav nav-tabs" id="crowdTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-crowd-tab="simple" type="button" role="tab"><?php echo __('Простая проверка'); ?></button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-crowd-tab="deep" type="button" role="tab"><?php echo __('Глубокая проверка'); ?></button>
                        </li>
                    </ul>
                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" data-crowd-tab-panel="simple" role="tabpanel">
                            <div class="card crowd-panel crowd-panel--status h-100" id="crowdCheckCard" data-run-id="<?php echo $hasRun ? (int)$crowdCurrentRun['id'] : ''; ?>" data-run-active="<?php echo $runInProgress ? '1' : '0'; ?>">
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
                                    <div id="crowdCheckMessage" class="small text-muted crowd-check-message mb-3" role="status"></div>
                                    <div class="crowd-status-card rounded-3 p-3" id="crowdCheckStatusCard">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <div class="text-muted small"><?php echo __('Статус'); ?></div>
                                                <div class="fw-semibold" data-crowd-status"><?php echo htmlspecialchars($runStatusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                            <div class="text-end">
                                                <div class="text-muted small"><?php echo __('Диапазон'); ?></div>
                                                <div class="fw-semibold" data-crowd-scope"><?php echo htmlspecialchars($runScopeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </div>
                                        <div class="progress mb-2<?php echo $runInProgress ? '' : ' d-none'; ?>" id="crowdCheckProgressContainer" style="height: 10px;">
                                            <div class="progress-bar bg-success" id="crowdCheckProgressBar" role="progressbar" style="width: <?php echo $runProgress; ?>%;" aria-valuenow="<?php echo $runProgress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <div class="d-flex justify-content-between small<?php echo $runInProgress ? '' : ' d-none'; ?>" id="crowdCheckCountsRow">
                                            <div><span data-crowd-processed"><?php echo $runProcessed; ?></span>/<span data-crowd-total"><?php echo $runTotal; ?></span></div>
                                            <div class="text-success"><i class="bi bi-check-circle me-1"></i><span data-crowd-ok"><?php echo $runOk; ?></span></div>
                                            <div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><span data-crowd-errors"><?php echo $runErrors; ?></span></div>
                                        </div>
                                        <hr>
                                        <div class="row small g-2">
                                            <div class="col-sm-6">
                                                <div class="text-muted"><?php echo __('Запущено'); ?>:</div>
                                                <div data-crowd-started"><?php echo htmlspecialchars($runStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted"><?php echo __('Завершено'); ?>:</div>
                                                <div data-crowd-finished"><?php echo htmlspecialchars($runFinishedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="text-muted"><?php echo __('Комментарий'); ?>:</div>
                                                <div data-crowd-notes"><?php echo htmlspecialchars($runNotes, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" data-crowd-tab-panel="deep" role="tabpanel">
                            <div class="card crowd-panel crowd-panel--deep shadow-sm border-0 mb-4" id="crowdDeepCard"
                                 data-run-id="<?php echo $deepRunId ?: ''; ?>"
                                 data-run-active="<?php echo $deepRunInProgress ? '1' : '0'; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                                                <i class="bi bi-search-heart text-danger"></i>
                                                <span><?php echo __('Глубокая проверка публикаций'); ?></span>
                                            </h5>
                                            <p class="text-muted small mb-0"><?php echo __('Автоматически отправляет тестовое сообщение, ищет опубликованный текст и фиксирует результат.'); ?></p>
                                        </div>
                                        <i class="bi bi-bullseye text-danger fs-4"></i>
                                    </div>

                                    <?php if (!empty($crowdDeepStatusError)): ?>
                                        <div class="alert alert-warning mb-3"><?php echo __('Не удалось загрузить статус глубокой проверки.'); ?> (<?php echo htmlspecialchars((string)$crowdDeepStatusError, ENT_QUOTES, 'UTF-8'); ?>)</div>
                                    <?php endif; ?>

                                    <div id="crowdDeepMessage" class="small text-muted mb-3" role="status"></div>

                                    <div class="row g-4">
                                        <div class="col-lg-7">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="crowdDeepScope" class="form-label"><?php echo __('Диапазон'); ?></label>
                                                    <select class="form-select" id="crowdDeepScope">
                                                        <?php foreach ($crowdDeepScopeOptions as $scopeKey => $scopeLabel): ?>
                                                            <option value="<?php echo htmlspecialchars($scopeKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="crowdDeepTokenPrefix" class="form-label"><?php echo __('Префикс токена'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepTokenPrefix" value="<?php echo htmlspecialchars($deepFormTokenPrefix, ENT_QUOTES, 'UTF-8'); ?>" maxlength="12" autocomplete="off">
                                                    <div class="form-text"><?php echo __('Используется для идентификации отправленного сообщения.'); ?></div>
                                                </div>
                                                <div class="col-md-12">
                                                    <label for="crowdDeepMessageLink" class="form-label"><?php echo __('Ссылка в сообщении'); ?></label>
                                                    <input type="url" class="form-control" id="crowdDeepMessageLink" value="<?php echo htmlspecialchars($deepFormLink, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="crowdDeepName" class="form-label"><?php echo __('Имя отправителя'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepName" value="<?php echo htmlspecialchars($deepFormName, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="crowdDeepCompany" class="form-label"><?php echo __('Компания'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepCompany" value="<?php echo htmlspecialchars($deepFormCompany, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="crowdDeepEmailUser" class="form-label"><?php echo __('Email (логин)'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepEmailUser" value="<?php echo htmlspecialchars($deepFormEmailUser, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="crowdDeepEmailDomain" class="form-label"><?php echo __('Email (домен)'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepEmailDomain" value="<?php echo htmlspecialchars($deepFormEmailDomain, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                                </div>
                                                <div class="col-md-4">
                                                    <label for="crowdDeepPhone" class="form-label"><?php echo __('Телефон'); ?></label>
                                                    <input type="text" class="form-control" id="crowdDeepPhone" value="<?php echo htmlspecialchars($deepFormPhone, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="col-md-12">
                                                    <label for="crowdDeepTemplate" class="form-label"><?php echo __('Шаблон сообщения'); ?></label>
                                                    <textarea class="form-control" id="crowdDeepTemplate" rows="4" placeholder="{{token}} {{link}}"><?php echo htmlspecialchars($deepFormTemplate, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    <div class="form-text"><?php echo __('Поддерживаются плейсхолдеры {{token}} и {{link}}.'); ?></div>
                                                </div>
                                                <div class="col-12 d-flex gap-2">
                                                    <button type="button" class="btn btn-danger flex-grow-1" id="crowdDeepStart">
                                                        <span class="label-text"><i class="bi bi-broadcast-pin me-1"></i><?php echo __('Запустить глубокую проверку'); ?></span>
                                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary" id="crowdDeepCancel" <?php echo $deepRunInProgress ? '' : 'disabled'; ?>>
                                                        <span class="label-text"><i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить'); ?></span>
                                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-5">
                                            <div class="crowd-status-card rounded-3 p-3" id="crowdDeepStatusCard">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <div class="text-muted small"><?php echo __('Статус'); ?></div>
                                                        <div class="fw-semibold" data-deep-status"><?php echo htmlspecialchars($deepRunStatusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="text-muted small"><?php echo __('Диапазон'); ?></div>
                                                        <div class="fw-semibold" data-deep-scope"><?php echo htmlspecialchars($deepRunScopeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                </div>
                                                <div class="progress mb-2" style="height: 10px;">
                                                    <div class="progress-bar bg-danger" id="crowdDeepProgressBar" role="progressbar" style="width: <?php echo (int)$deepRunProgress; ?>%;" aria-valuenow="<?php echo (int)$deepRunProgress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="d-flex justify-content-between small mb-2">
                                                    <div><span data-deep-processed"><?php echo $deepRunProcessed; ?></span>/<span data-deep-total"><?php echo $deepRunTotal; ?></span></div>
                                                    <div class="text-success"><i class="bi bi-check-circle me-1"></i><span data-deep-success"><?php echo $deepRunSuccess; ?></span></div>
                                                    <div class="text-warning"><i class="bi bi-question-circle me-1"></i><span data-deep-partial"><?php echo $deepRunPartial; ?></span></div>
                                                    <div class="text-danger"><i class="bi bi-x-circle me-1"></i><span data-deep-failed"><?php echo $deepRunFailed; ?></span></div>
                                                    <div class="text-muted"><i class="bi bi-skip-forward-circle me-1"></i><span data-deep-skipped"><?php echo $deepRunSkipped; ?></span></div>
                                                </div>
                                                <hr>
                                                <div class="row small g-2">
                                                    <div class="col-sm-6">
                                                        <div class="text-muted"><?php echo __('Запущено'); ?>:</div>
                                                        <div data-deep-started"><?php echo htmlspecialchars($deepRunStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <div class="text-muted"><?php echo __('Завершено'); ?>:</div>
                                                        <div data-deep-finished"><?php echo htmlspecialchars($deepRunFinishedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                    <div class="col-sm-12">
                                                        <div class="text-muted"><?php echo __('Комментарий'); ?>:</div>
                                                        <div data-deep-notes"><?php echo htmlspecialchars($deepRunNotes, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm border-0 mb-0" id="crowdDeepResultsCard" data-run-id="<?php echo $deepRunId ?: ''; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                                            <i class="bi bi-clipboard2-data text-danger"></i>
                                            <span><?php echo __('Результаты глубокой проверки'); ?></span>
                                        </h5>
                                        <div class="small text-muted" data-deep-results-meta><?php echo $deepRunTotal > 0 ? sprintf(__('Последний запуск: %s записей'), number_format($deepRunTotal, 0, '.', ' ')) : __('Пока нет результатов'); ?></div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle" id="crowdDeepResultsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th><?php echo __('Время'); ?></th>
                                                    <th><?php echo __('Статус'); ?></th>
                                                    <th><?php echo __('URL'); ?></th>
                                                    <th><?php echo __('Фрагмент'); ?></th>
                                                    <th><?php echo __('Ошибка/Комментарий'); ?></th>
                                                    <th><?php echo __('HTTP'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($deepRecentItems)): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3" data-deep-empty><?php echo __('Результатов пока нет.'); ?></td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($deepRecentItems as $item): ?>
                                                        <?php
                                                            $deepStatus = (string)($item['status'] ?? 'pending');
                                                            $deepMeta = $crowdDeepStatusMeta[$deepStatus] ?? null;
                                                            $deepBadgeClass = $deepMeta['class'] ?? 'badge bg-secondary';
                                                            $deepBadgeLabel = $deepMeta['label'] ?? $deepStatus;
                                                            $deepUrl = (string)($item['url'] ?? '');
                                                            $evidenceUrl = (string)($item['evidence_url'] ?? '');
                                                            $messageExcerpt = (string)($item['message_excerpt'] ?? '');
                                                            $responseExcerpt = (string)($item['response_excerpt'] ?? '');
                                                            $errorText = (string)($item['error'] ?? '');
                                                            $createdAt = (string)($item['created_at'] ?? '');
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $createdAt !== '' ? htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                                            <td><span class="badge <?php echo htmlspecialchars($deepBadgeClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deepBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                                            <td class="text-break">
                                                                <?php if ($deepUrl !== ''): ?>
                                                                    <?php $deepUrlShort = $pp_truncate($deepUrl, 30); ?>
                                                                    <a href="<?php echo htmlspecialchars($deepUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($deepUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deepUrlShort, ENT_QUOTES, 'UTF-8'); ?></a>
                                                                    <?php if ($evidenceUrl !== ''): ?>
                                                                        <a href="<?php echo htmlspecialchars($evidenceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="ms-1" title="<?php echo __('Открыть ответ'); ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    —
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="small text-break"><?php echo $messageExcerpt !== '' ? htmlspecialchars($messageExcerpt, ENT_QUOTES, 'UTF-8') : ($responseExcerpt !== '' ? htmlspecialchars($responseExcerpt, ENT_QUOTES, 'UTF-8') : '—'); ?></td>
                                                            <td class="small text-break"><?php echo $errorText !== '' ? htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                                            <td><?php echo isset($item['http_status']) && $item['http_status'] ? (int)$item['http_status'] : '—'; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
    </div> <!-- /.checks row -->

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
                                    <th class="text-nowrap"><?php echo __('Код / Проверка'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Глубокая проверка'); ?></th>
                                    <th><?php echo __('Ответ / Ошибка'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Язык'); ?></th>
                                    <th class="text-nowrap"><?php echo __('Регион'); ?></th>
                                    <th><?php echo __('Домен'); ?></th>
                                    <th><?php echo __('HTTP комментарий'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4"><?php echo __('Записей не найдено.'); ?></td>
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
                                            $deepStatus = (string)($row['deep_status'] ?? 'pending');
                                            $deepMeta = $crowdDeepStatusMeta[$deepStatus] ?? null;
                                            $deepBadgeClass = $deepMeta['class'] ?? 'badge bg-secondary';
                                            $deepBadgeLabel = $deepMeta['label'] ?? $deepStatus;
                                            $deepProcessing = !empty($row['deep_processing_run_id']);
                                            $formRequired = (string)($row['form_required'] ?? '');
                                            $deepError = (string)($row['deep_error'] ?? '');
                                            $deepMessage = (string)($row['deep_message_excerpt'] ?? '');
                                            $deepEvidenceUrl = (string)($row['deep_evidence_url'] ?? '');
                                            $deepCheckedAt = (string)($row['deep_checked_at'] ?? '');
                                            $deepCheckedDisplay = '—';
                                            if ($deepCheckedAt !== '' && $deepCheckedAt !== '0000-00-00 00:00:00') {
                                                $ts = strtotime($deepCheckedAt);
                                                if ($ts) {
                                                    $deepCheckedDisplay = date('Y-m-d H:i', $ts);
                                                } else {
                                                    $deepCheckedDisplay = $deepCheckedAt;
                                                }
                                            }
                                        ?>
                                        <tr data-link-id="<?php echo $linkId; ?>">
                                            <td>
                                                <input type="checkbox" class="form-check-input" name="crowd_selected[]" value="<?php echo $linkId; ?>" data-crowd-checkbox <?php echo $checked ? 'checked' : ''; ?> aria-label="<?php echo __('Выбрать ссылку'); ?>">
                                            </td>
                                            <td>
                                                <?php $rowUrl = (string)($row['url'] ?? ''); $rowUrlShort = $pp_truncate($rowUrl, 30); ?>
                                                <div class="fw-semibold text-break">
                                                    <a href="<?php echo htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="link-primary" title="<?php echo htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo htmlspecialchars($rowUrlShort, ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                </div>
                                                <div class="small text-muted">ID <?php echo $linkId; ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>" data-crowd-status-badge title="<?php echo htmlspecialchars($formRequired !== '' ? ('Textarea: есть; Обязательные: ' . $formRequired) : '—', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($processing): ?>
                                                    <span class="spinner-border spinner-border-sm text-info ms-1" role="status" aria-hidden="true"></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div><?php echo $statusCode > 0 ? $statusCode : '—'; ?></div>
                                                <div class="small text-muted"><?php echo !empty($row['last_checked_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$row['last_checked_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($deepBadgeClass, ENT_QUOTES, 'UTF-8'); ?>" data-deep-status-badge><?php echo htmlspecialchars($deepStatus === 'pending' ? __('Не запускалась') : $deepBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($deepProcessing): ?>
                                                    <span class="spinner-border spinner-border-sm text-danger ms-1" role="status" aria-hidden="true"></span>
                                                <?php endif; ?>
                                                <div class="small text-muted"><?php echo htmlspecialchars($deepCheckedDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td class="small text-break">
                                                <?php if ($deepMessage !== ''): ?>
                                                    <div class="text-success fw-semibold mb-1"><?php echo htmlspecialchars($deepMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                                <?php if ($deepError !== ''): ?>
                                                    <div class="text-danger"><?php echo htmlspecialchars($deepError, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                                <?php if ($deepEvidenceUrl !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($deepEvidenceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Ответ'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($deepMessage === '' && $deepError === '' && $deepEvidenceUrl === ''): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-uppercase"><?php echo $row['language'] ? htmlspecialchars((string)$row['language'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td class="text-uppercase"><?php echo $row['region'] ? htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><?php $dom = (string)($row['domain'] ?? ''); $domShort = $pp_truncate($dom, 20); echo htmlspecialchars($domShort, ENT_QUOTES, 'UTF-8'); if ($dom !== '' && $domShort !== $dom) { echo ' <span class="text-muted" title="' . htmlspecialchars($dom, ENT_QUOTES, 'UTF-8') . '">…</span>'; } ?></td>
                                            <td class="text-break small">&nbsp;<?php echo $row['error'] ? htmlspecialchars((string)$row['error'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            
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
                <?php
                $pagesToRender = [];
                if ($totalPages <= 10) {
                    for ($p = 1; $p <= $totalPages; $p++) {
                        $pagesToRender[] = $p;
                    }
                } else {
                    $pagesToRender[] = 1;
                    $windowStart = max(2, $currentPage - 2);
                    $windowEnd = min($totalPages - 1, $currentPage + 2);
                    if ($windowStart > 2) {
                        $pagesToRender[] = 'ellipsis';
                    }
                    for ($p = $windowStart; $p <= $windowEnd; $p++) {
                        $pagesToRender[] = $p;
                    }
                    if ($windowEnd < $totalPages - 1) {
                        $pagesToRender[] = 'ellipsis';
                    }
                    $pagesToRender[] = $totalPages;
                }
                $prevDisabled = $currentPage <= 1;
                $nextDisabled = $currentPage >= $totalPages;
                ?>
                <nav aria-label="<?php echo __('Навигация по страницам'); ?>" class="mt-4 crowd-pagination-wrapper">
                    <ul class="pagination pagination-sm crowd-pagination mb-0">
                        <li class="page-item <?php echo $prevDisabled ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $prevDisabled ? '#' : htmlspecialchars($crowdBuildUrl(['crowd_page' => max(1, $currentPage - 1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo __('Предыдущая'); ?>">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php foreach ($pagesToRender as $pageItem): ?>
                            <?php if ($pageItem === 'ellipsis'): ?>
                                <li class="page-item disabled crowd-pagination__ellipsis"><span class="page-link">&hellip;</span></li>
                            <?php else: ?>
                                <?php $page = (int)$pageItem; ?>
                                <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($crowdBuildUrl(['crowd_page' => $page]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $page; ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
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
    const progressContainer = section.querySelector('#crowdCheckProgressContainer');
    const countsRow = section.querySelector('#crowdCheckCountsRow');
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
    const headerStatus = section.querySelector('[data-crowd-header-status]');
    const headerPercent = section.querySelector('[data-crowd-header-percent]');
    const headerCount = section.querySelector('[data-crowd-header-count]');
    const headerBar = section.querySelector('[data-crowd-progress-bar]');
    const headerBarFill = section.querySelector('[data-crowd-progress-header]');
    const headerKind = section.querySelector('[data-crowd-header-kind]');
    const tabsRoot = section.querySelector('#crowdTabs');
    const tabButtons = tabsRoot ? tabsRoot.querySelectorAll('[data-crowd-tab]') : [];
    const tabPanels = section.querySelectorAll('[data-crowd-tab-panel]');

    const deepApiBase = section.getAttribute('data-crowd-deep-api') || apiBase;
    const deepCard = section.querySelector('#crowdDeepCard');
    const deepStartBtn = section.querySelector('#crowdDeepStart');
    const deepCancelBtn = section.querySelector('#crowdDeepCancel');
    const deepScopeSelect = section.querySelector('#crowdDeepScope');
    const deepTokenPrefixInput = section.querySelector('#crowdDeepTokenPrefix');
    const deepMessageLinkInput = section.querySelector('#crowdDeepMessageLink');
    const deepNameInput = section.querySelector('#crowdDeepName');
    const deepCompanyInput = section.querySelector('#crowdDeepCompany');
    const deepEmailUserInput = section.querySelector('#crowdDeepEmailUser');
    const deepEmailDomainInput = section.querySelector('#crowdDeepEmailDomain');
    const deepPhoneInput = section.querySelector('#crowdDeepPhone');
    const deepTemplateInput = section.querySelector('#crowdDeepTemplate');
    const deepMessageBox = section.querySelector('#crowdDeepMessage');
    const deepStatusCard = section.querySelector('#crowdDeepStatusCard');
    const deepProgressBar = section.querySelector('#crowdDeepProgressBar');
    const deepStatusLabel = section.querySelector('[data-deep-status]');
    const deepScopeLabel = section.querySelector('[data-deep-scope]');
    const deepProcessedEl = section.querySelector('[data-deep-processed]');
    const deepTotalEl = section.querySelector('[data-deep-total]');
    const deepSuccessEl = section.querySelector('[data-deep-success]');
    const deepPartialEl = section.querySelector('[data-deep-partial]');
    const deepFailedEl = section.querySelector('[data-deep-failed]');
    const deepSkippedEl = section.querySelector('[data-deep-skipped]');
    const deepStartedEl = section.querySelector('[data-deep-started]');
    const deepFinishedEl = section.querySelector('[data-deep-finished]');
    const deepNotesEl = section.querySelector('[data-deep-notes]');
    const deepResultsTable = section.querySelector('#crowdDeepResultsTable');
    const deepResultsBody = deepResultsTable ? deepResultsTable.querySelector('tbody') : null;
    const deepResultsMeta = section.querySelector('[data-deep-results-meta]');

    const labels = {
        selectSomething: <?php echo json_encode(__('Выберите хотя бы одну ссылку.'), JSON_UNESCAPED_UNICODE); ?>,
        startSuccess: <?php echo json_encode(__('Проверка запущена.'), JSON_UNESCAPED_UNICODE); ?>,
        alreadyRunning: <?php echo json_encode(__('Проверка уже выполняется.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelled: <?php echo json_encode(__('Остановка запроса отправлена.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelIdle: <?php echo json_encode(__('Нет активных проверок.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelFailed: <?php echo json_encode(__('Не удалось отменить проверку.'), JSON_UNESCAPED_UNICODE); ?>,
        statusMap: <?php echo json_encode($crowdStatusLabels, JSON_UNESCAPED_UNICODE); ?>,
        scopeMap: <?php echo json_encode($crowdScopeLabels, JSON_UNESCAPED_UNICODE); ?>,
        noRuns: <?php echo json_encode(__('Проверка ещё не запускалась'), JSON_UNESCAPED_UNICODE); ?>,
        cancelComplete: <?php echo json_encode(__('Проверка остановлена.'), JSON_UNESCAPED_UNICODE); ?>,
        forceSuccess: <?php echo json_encode(__('Принудительная остановка выполнена.'), JSON_UNESCAPED_UNICODE); ?>,
        stallWarning: <?php echo json_encode(__('Похоже, проверка не отвечает. Повторите остановку для принудительного завершения.'), JSON_UNESCAPED_UNICODE); ?>,
        autoStopped: <?php echo json_encode(__('Проверка автоматически остановлена из-за отсутствия активности.'), JSON_UNESCAPED_UNICODE); ?>,
        stopping: <?php echo json_encode(__('Останавливается…'), JSON_UNESCAPED_UNICODE); ?>
    };

    const deepLabels = {
        selectSomething: <?php echo json_encode(__('Выберите ссылки для глубокой проверки.'), JSON_UNESCAPED_UNICODE); ?>,
        startSuccess: <?php echo json_encode(__('Глубокая проверка запущена.'), JSON_UNESCAPED_UNICODE); ?>,
        alreadyRunning: <?php echo json_encode(__('Глубокая проверка уже выполняется.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelPending: <?php echo json_encode(__('Запрос на остановку отправлен.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelIdle: <?php echo json_encode(__('Нет активной глубокой проверки.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelFailed: <?php echo json_encode(__('Не удалось остановить глубокую проверку.'), JSON_UNESCAPED_UNICODE); ?>,
        cancelComplete: <?php echo json_encode(__('Глубокая проверка остановлена.'), JSON_UNESCAPED_UNICODE); ?>,
        forceSuccess: <?php echo json_encode(__('Принудительная остановка глубокой проверки выполнена.'), JSON_UNESCAPED_UNICODE); ?>,
        stallWarning: <?php echo json_encode(__('Похоже, глубокая проверка зависла. Повторите остановку для принудительного завершения.'), JSON_UNESCAPED_UNICODE); ?>,
        autoStopped: <?php echo json_encode(__('Глубокая проверка автоматически остановлена из-за отсутствия активности.'), JSON_UNESCAPED_UNICODE); ?>,
        noRuns: <?php echo json_encode(__('Глубокая проверка ещё не запускалась'), JSON_UNESCAPED_UNICODE); ?>,
        statusMap: <?php echo json_encode($crowdDeepStatusLabels, JSON_UNESCAPED_UNICODE); ?>,
        scopeMap: <?php echo json_encode($crowdDeepScopeLabels, JSON_UNESCAPED_UNICODE); ?>,
        noResults: <?php echo json_encode(__('Результатов пока нет.'), JSON_UNESCAPED_UNICODE); ?>
    };

    let pollTimer = null;
    let currentRunId = card ? parseInt(card.getAttribute('data-run-id') || '0', 10) : 0;
    const initialActive = card ? card.getAttribute('data-run-active') === '1' : false;
    let cancelAttempts = 0;

    let deepPollTimer = null;
    let currentDeepRunId = deepCard ? parseInt(deepCard.getAttribute('data-run-id') || '0', 10) : 0;
    const deepInitialActive = deepCard ? deepCard.getAttribute('data-run-active') === '1' : false;
    let deepCancelAttempts = 0;

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

    function updateDeepMessage(text, type = 'muted') {
        if (!deepMessageBox) return;
        if (!text) {
            deepMessageBox.textContent = '';
            deepMessageBox.className = 'small text-muted mb-3';
            return;
        }
        const map = {
            success: 'small text-success mb-3',
            danger: 'small text-danger mb-3',
            warning: 'small text-warning mb-3',
            info: 'small text-info mb-3',
            muted: 'small text-muted mb-3'
        };
        deepMessageBox.textContent = text;
        deepMessageBox.className = map[type] || map.muted;
    }

    function toggleDeepButtons(runActive) {
        if (deepStartBtn) deepStartBtn.disabled = !!runActive;
        if (deepCancelBtn) deepCancelBtn.disabled = !runActive;
    }

    function setDeepSpinner(btn, spinning) {
        if (!btn) return;
        const spinner = btn.querySelector('.spinner-border');
        const label = btn.querySelector('.label-text');
        if (spinning) {
            btn.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (label) label.classList.add('d-none');
        } else {
            if (!deepCard || deepCard.getAttribute('data-run-active') !== '1') {
                btn.disabled = false;
            }
            if (spinner) spinner.classList.add('d-none');
            if (label) label.classList.remove('d-none');
        }
    }

    function renderDeepResults(items) {
        if (!deepResultsBody) return;
        deepResultsBody.innerHTML = '';
        if (!items || !items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'text-center text-muted py-3';
            cell.textContent = deepLabels.noResults;
            row.appendChild(cell);
            deepResultsBody.appendChild(row);
            return;
        }
        const truncate = (text, max = 30) => {
            if (!text) return '';
            if (text.length <= max) return text;
            return text.slice(0, max) + '…';
        };
        items.forEach(item => {
            const row = document.createElement('tr');
            const createdAt = item.created_at || '—';
            const status = item.status || 'pending';
            const badgeLabel = deepLabels.statusMap[status] || status;
            const badgeClass = (<?php echo json_encode($crowdDeepStatusClasses, JSON_UNESCAPED_UNICODE); ?>)[status] || 'badge bg-secondary';
            const url = item.url || '';
            const evidenceUrl = item.evidence_url || '';
            const messageExcerpt = item.message_excerpt || item.response_excerpt || '';
            const errorText = item.error || '';
            const httpStatus = item.http_status || '';

            const tdTime = document.createElement('td');
            tdTime.textContent = createdAt;
            row.appendChild(tdTime);

            const tdStatus = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = badgeClass;
            badge.textContent = badgeLabel;
            tdStatus.appendChild(badge);
            row.appendChild(tdStatus);

            const tdUrl = document.createElement('td');
            tdUrl.className = 'text-break';
            if (url) {
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = truncate(url, 30);
                link.title = url;
                tdUrl.appendChild(link);
                if (evidenceUrl) {
                    const evidenceLink = document.createElement('a');
                    evidenceLink.href = evidenceUrl;
                    evidenceLink.target = '_blank';
                    evidenceLink.rel = 'noopener';
                    evidenceLink.className = 'ms-1';
                    evidenceLink.title = '<?php echo addslashes(__('Открыть ответ')); ?>';
                    evidenceLink.innerHTML = '<i class="bi bi-box-arrow-up-right"></i>';
                    tdUrl.appendChild(evidenceLink);
                }
            } else {
                tdUrl.textContent = '—';
            }
            row.appendChild(tdUrl);

            const tdMessage = document.createElement('td');
            tdMessage.className = 'small text-break';
            tdMessage.textContent = messageExcerpt || '—';
            row.appendChild(tdMessage);

            const tdError = document.createElement('td');
            tdError.className = 'small text-break';
            tdError.textContent = errorText || '—';
            row.appendChild(tdError);

            const tdHttp = document.createElement('td');
            tdHttp.textContent = httpStatus ? String(httpStatus) : '—';
            row.appendChild(tdHttp);

            deepResultsBody.appendChild(row);
        });
    }

    async function fetchDeepResults(runId, limit = 20) {
        if (!deepApiBase || !runId) return;
        try {
            const res = await fetch(`${deepApiBase}?action=deep_results&run_id=${encodeURIComponent(runId)}&limit=${encodeURIComponent(limit)}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                if (!res.ok && data && data.error) {
                    updateDeepMessage(data.error, 'warning');
                }
                return;
            }
            renderDeepResults(data.items || []);
            if (deepResultsMeta) {
                const total = data.total || 0;
                deepResultsMeta.textContent = total ? `<?php echo __('Записей'); ?>: ${total}` : deepLabels.noResults;
            }
        } catch (err) {
            updateDeepMessage(err && err.message ? err.message : 'Error', 'warning');
        }
    }

    function gatherSelectedIds() {
        const ids = [];
        section.querySelectorAll('input[data-crowd-checkbox]:checked').forEach(cb => {
            const val = parseInt(cb.value, 10);
            if (val > 0) { ids.push(val); }
        });
        return ids;
    }

    function setProgressVisible(show) {
        if (progressContainer) {
            progressContainer.classList.toggle('d-none', !show);
        }
        if (countsRow) {
            countsRow.classList.toggle('d-none', !show);
        }
    }

    function toggleButtons(runActive) {
        if (startBtn) startBtn.disabled = runActive;
        if (cancelBtn) cancelBtn.disabled = !runActive;
        // Toggle progress UI visibility with the run state by default
        setProgressVisible(runActive);
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
            if (headerStatus) headerStatus.textContent = '—';
            if (headerPercent) headerPercent.textContent = '—';
            if (headerCount) headerCount.textContent = '—';
            if (headerKind) headerKind.textContent = '—';
            if (headerBar) headerBar.setAttribute('aria-valuenow', '0');
            if (headerBarFill) headerBarFill.style.width = '0%';
            return;
        }
        currentRunId = data.id || 0;
        card.setAttribute('data-run-id', currentRunId ? String(currentRunId) : '');
        const active = !!data.in_progress;
        card.setAttribute('data-run-active', active ? '1' : '0');
        toggleButtons(active);
        if (!active) {
            cancelAttempts = 0;
        }
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
            if (headerBarFill) {
                headerBarFill.style.width = pct + '%';
            }
            if (headerBar) {
                headerBar.setAttribute('aria-valuenow', pct);
            }
        }
        if (headerPercent) {
            const pctText = (data.total_links || 0) > 0 ? ((data.progress_percent || 0) + '%') : '—';
            headerPercent.textContent = pctText;
        }
    if (processedEl) processedEl.textContent = data.processed_count || 0;
        if (totalEl) totalEl.textContent = data.total_links || 0;
        if (okEl) okEl.textContent = data.ok_count || 0;
        if (errorEl) errorEl.textContent = data.error_count || 0;
        if (headerCount) {
            if ((data.total_links || 0) > 0) {
                headerCount.textContent = (data.processed_count || 0) + '/' + (data.total_links || 0);
            } else {
                headerCount.textContent = '—';
            }
        }
        if (startedEl) startedEl.textContent = data.started_at || '—';
        if (finishedEl) finishedEl.textContent = data.finished_at || '—';
        if (notesEl) notesEl.textContent = data.notes ? data.notes : '—';
        if (headerStatus) {
            headerStatus.textContent = labels.statusMap[data.status] || data.status || '—';
        }
        if (headerKind) {
            headerKind.textContent = '<?php echo addslashes(__('Простая проверка')); ?>';
        }

        if (!active && pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (active && !pollTimer) {
            pollTimer = setTimeout(() => fetchStatus(currentRunId), 2000);
        }
        if (active && data.stalled && messageBox && messageBox.textContent.trim() === '') {
            updateMessage(labels.stallWarning, 'warning');
        }
        if (!active && (!messageBox || messageBox.textContent.trim() === '')) {
            if (data.status === 'cancelled') {
                updateMessage(labels.cancelComplete, 'success');
            } else if (data.status === 'failed') {
                updateMessage(labels.autoStopped, 'warning');
            }
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
                pollTimer = setTimeout(() => fetchStatus(runId), 2000);
            } else if (data.run && !data.run.in_progress && data.run.status === 'cancelled' && data.run.notes && data.run.notes.indexOf('автоматически') !== -1) {
                if (!messageBox || messageBox.textContent.trim() === '') {
                    updateMessage(labels.autoStopped, 'info');
                }
            }
        } catch (err) {
            updateMessage(err && err.message ? err.message : 'Error', 'warning');
            pollTimer = setTimeout(() => fetchStatus(runId), 4000);
        }
    }

    function updateDeepRunCard(data) {
        if (!deepCard) { return; }
        if (!data) {
            currentDeepRunId = 0;
            deepCard.setAttribute('data-run-id', '');
            deepCard.setAttribute('data-run-active', '0');
            toggleDeepButtons(false);
            if (deepPollTimer) {
                clearTimeout(deepPollTimer);
                deepPollTimer = null;
            }
            if (deepStatusLabel) deepStatusLabel.textContent = '—';
            if (deepScopeLabel) deepScopeLabel.textContent = '—';
            if (deepProgressBar) {
                deepProgressBar.style.width = '0%';
                deepProgressBar.setAttribute('aria-valuenow', '0');
            }
            if (deepProcessedEl) deepProcessedEl.textContent = '0';
            if (deepTotalEl) deepTotalEl.textContent = '0';
            if (deepSuccessEl) deepSuccessEl.textContent = '0';
            if (deepPartialEl) deepPartialEl.textContent = '0';
            if (deepFailedEl) deepFailedEl.textContent = '0';
            if (deepSkippedEl) deepSkippedEl.textContent = '0';
            if (deepStartedEl) deepStartedEl.textContent = '—';
            if (deepFinishedEl) deepFinishedEl.textContent = '—';
            if (deepNotesEl) deepNotesEl.textContent = deepLabels.noRuns || '—';
            if (deepResultsMeta) deepResultsMeta.textContent = deepLabels.noResults || '—';
            // If no deep data and simple run inactive, keep headerKind as is
            renderDeepResults([]);
            return;
        }
        currentDeepRunId = data.id || 0;
        deepCard.setAttribute('data-run-id', currentDeepRunId ? String(currentDeepRunId) : '');
        const active = !!data.in_progress;
        deepCard.setAttribute('data-run-active', active ? '1' : '0');
        toggleDeepButtons(active);
        if (!active) {
            deepCancelAttempts = 0;
        }
        if (deepStatusLabel) {
            deepStatusLabel.textContent = deepLabels.statusMap[data.status] || data.status || '—';
        }
        if (deepScopeLabel) {
            deepScopeLabel.textContent = deepLabels.scopeMap[data.scope] || data.scope || '—';
        }
        const pct = data.progress_percent || 0;
        if (deepProgressBar) {
            deepProgressBar.style.width = pct + '%';
            deepProgressBar.setAttribute('aria-valuenow', pct);
        }
        if (deepProcessedEl) deepProcessedEl.textContent = data.processed_count || 0;
        if (deepTotalEl) deepTotalEl.textContent = data.total_links || 0;
        if (deepSuccessEl) deepSuccessEl.textContent = data.success_count || 0;
        if (deepPartialEl) deepPartialEl.textContent = data.partial_count || 0;
        if (deepFailedEl) deepFailedEl.textContent = data.failed_count || 0;
        if (deepSkippedEl) deepSkippedEl.textContent = data.skipped_count || 0;
        if (deepStartedEl) deepStartedEl.textContent = data.started_at || '—';
        if (deepFinishedEl) deepFinishedEl.textContent = data.finished_at || '—';
        if (deepNotesEl) deepNotesEl.textContent = data.notes ? data.notes : '—';

        // Update header to reflect deep run if active
        if (headerKind) {
            headerKind.textContent = '<?php echo addslashes(__('Глубокая проверка')); ?>';
        }
        if (headerStatus) {
            headerStatus.textContent = deepLabels.statusMap[data.status] || data.status || '—';
        }
        if (headerPercent) {
            const pctText = (data.total_links || 0) > 0 ? ((data.progress_percent || 0) + '%') : '—';
            headerPercent.textContent = pctText;
        }
        if (headerCount) {
            if ((data.total_links || 0) > 0) {
                headerCount.textContent = (data.processed_count || 0) + '/' + (data.total_links || 0);
            } else {
                headerCount.textContent = '—';
            }
        }
        if (headerBarFill) {
            const pct = data.progress_percent || 0;
            headerBarFill.style.width = pct + '%';
        }
        if (headerBar) {
            const pct = data.progress_percent || 0;
            headerBar.setAttribute('aria-valuenow', pct);
        }

        if (deepPollTimer) {
            clearTimeout(deepPollTimer);
            deepPollTimer = null;
        }
        if (active) {
            deepPollTimer = setTimeout(() => fetchDeepStatus(currentDeepRunId), 5000);
        }
        if (active && data.stalled && deepMessageBox && deepMessageBox.textContent.trim() === '') {
            updateDeepMessage(deepLabels.stallWarning, 'warning');
        }
        if (!active && (!deepMessageBox || deepMessageBox.textContent.trim() === '')) {
            if (data.status === 'cancelled') {
                updateDeepMessage(deepLabels.cancelComplete, 'success');
            } else if (data.status === 'failed') {
                updateDeepMessage(deepLabels.autoStopped, 'warning');
            }
        }
        if (currentDeepRunId) {
            fetchDeepResults(currentDeepRunId, 20);
        }
    }

    async function fetchDeepStatus(runId) {
        if (!deepApiBase || !runId) { return; }
        try {
            const res = await fetch(`${deepApiBase}?action=deep_status&run_id=${encodeURIComponent(runId)}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateDeepMessage(data && data.error ? data.error : 'Error', 'warning');
                return;
            }
            updateDeepRunCard(data.run || null);
            if (data.run && data.run.in_progress) {
                deepPollTimer = setTimeout(() => fetchDeepStatus(runId), 5000);
            }
        } catch (err) {
            updateDeepMessage(err && err.message ? err.message : 'Error', 'warning');
            deepPollTimer = setTimeout(() => fetchDeepStatus(runId), 7000);
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
                // Immediately show zeroed progress UI on start
                if (progressBar) { progressBar.style.width = '0%'; progressBar.setAttribute('aria-valuenow', '0'); }
                if (processedEl) processedEl.textContent = '0';
                if (totalEl) totalEl.textContent = String(data.total || 0);
                if (okEl) okEl.textContent = '0';
                if (errorEl) errorEl.textContent = '0';
                cancelAttempts = 0;
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
        // Optimistically hide progress UI and show stopping status
        setProgressVisible(false);
        if (statusLabel) { statusLabel.textContent = labels.stopping || statusLabel.textContent; }
        try {
            const body = new URLSearchParams();
            body.set('run_id', String(runId));
            cancelAttempts += 1;
            if (cancelAttempts > 1) {
                body.set('force', '1');
            }
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
                // Restore progress UI if cancel failed
                setProgressVisible(true);
                return;
            }
            if (data.finished) {
                cancelAttempts = 0;
                const msg = data.forced ? labels.forceSuccess : labels.cancelComplete;
                updateMessage(msg, 'success');
            } else if (data.cancelRequested) {
                updateMessage(labels.cancelled, 'info');
            } else if (data.alreadyFinished || (data.status && data.status !== 'queued' && data.status !== 'running')) {
                cancelAttempts = 0;
                updateMessage(labels.cancelComplete, 'success');
            } else if (data.status === 'idle') {
                cancelAttempts = 0;
                updateMessage(labels.cancelIdle, 'info');
            }
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            fetchStatus(currentRunId);
        } catch (err) {
            updateMessage(labels.cancelFailed, 'danger');
            setProgressVisible(true);
        } finally {
            setSpinner(cancelBtn, false);
        }
    }

    async function startDeepCheck() {
        if (!deepApiBase || !deepStartBtn) return;
        const scope = deepScopeSelect ? deepScopeSelect.value : 'all';
        const ids = scope === 'selection' ? gatherSelectedIds() : [];
        if (scope === 'selection' && ids.length === 0) {
            updateDeepMessage(deepLabels.selectSomething, 'warning');
            return;
        }
        setDeepSpinner(deepStartBtn, true);
        updateDeepMessage('');
        try {
            const body = new URLSearchParams();
            body.set('scope', scope);
            if (ids.length) {
                ids.forEach(id => body.append('ids[]', String(id)));
            }
            body.set('message_template', (deepTemplateInput ? deepTemplateInput.value : '') || '');
            body.set('message_link', (deepMessageLinkInput ? deepMessageLinkInput.value : '') || '');
            body.set('name', (deepNameInput ? deepNameInput.value : '') || '');
            body.set('company', (deepCompanyInput ? deepCompanyInput.value : '') || '');
            body.set('email_user', (deepEmailUserInput ? deepEmailUserInput.value : '') || '');
            body.set('email_domain', (deepEmailDomainInput ? deepEmailDomainInput.value : '') || '');
            body.set('phone', (deepPhoneInput ? deepPhoneInput.value : '') || '');
            body.set('token_prefix', (deepTokenPrefixInput ? deepTokenPrefixInput.value : '') || '');
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(`${deepApiBase}?action=deep_start`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                if (data && Array.isArray(data.messages) && data.messages.length) {
                    updateDeepMessage(data.messages.join(' '), 'danger');
                } else {
                    updateDeepMessage(data && data.error ? data.error : 'Error', 'danger');
                }
                return;
            }
            if (data.alreadyRunning) {
                updateDeepMessage(deepLabels.alreadyRunning, 'info');
            } else {
                updateDeepMessage(deepLabels.startSuccess, 'success');
            }
            if (data.runId) {
                currentDeepRunId = data.runId;
                if (deepCard) {
                    deepCard.setAttribute('data-run-id', String(currentDeepRunId));
                    deepCard.setAttribute('data-run-active', '1');
                }
                toggleDeepButtons(true);
                deepCancelAttempts = 0;
                fetchDeepStatus(currentDeepRunId);
            }
        } catch (err) {
            updateDeepMessage(err && err.message ? err.message : 'Error', 'danger');
        } finally {
            setDeepSpinner(deepStartBtn, false);
        }
    }

    async function cancelDeepCheck() {
        if (!deepApiBase || !deepCancelBtn) return;
        const runId = currentDeepRunId;
        if (!runId) {
            updateDeepMessage(deepLabels.cancelIdle, 'info');
            return;
        }
        setDeepSpinner(deepCancelBtn, true);
        updateDeepMessage('');
        try {
            const body = new URLSearchParams();
            body.set('run_id', String(runId));
            deepCancelAttempts += 1;
            if (deepCancelAttempts > 1) {
                body.set('force', '1');
            }
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(`${deepApiBase}?action=deep_cancel`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateDeepMessage(deepLabels.cancelFailed, 'danger');
                return;
            }
            if (data.finished) {
                deepCancelAttempts = 0;
                const msg = data.forced ? deepLabels.forceSuccess : deepLabels.cancelComplete;
                updateDeepMessage(msg, 'success');
            } else if (data.cancelRequested) {
                updateDeepMessage(deepLabels.cancelPending, 'info');
            } else if (data.alreadyFinished || (data.status && data.status !== 'queued' && data.status !== 'running')) {
                deepCancelAttempts = 0;
                updateDeepMessage(deepLabels.cancelComplete, 'success');
            } else if (data.status === 'idle') {
                deepCancelAttempts = 0;
                updateDeepMessage(deepLabels.cancelIdle, 'info');
            }
            if (deepPollTimer) {
                clearTimeout(deepPollTimer);
                deepPollTimer = null;
            }
            fetchDeepStatus(currentDeepRunId);
        } catch (err) {
            updateDeepMessage(deepLabels.cancelFailed, 'danger');
        } finally {
            setDeepSpinner(deepCancelBtn, false);
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

    if (deepStartBtn) {
        deepStartBtn.addEventListener('click', (e) => {
            e.preventDefault();
            startDeepCheck();
        });
    }

    if (deepCancelBtn) {
        deepCancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            cancelDeepCheck();
        });
    }

    // Tabs logic (simple/deep)
    function showTab(kind) {
        tabButtons.forEach(btn => {
            const k = btn.getAttribute('data-crowd-tab');
            if (k === kind) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        tabPanels.forEach(panel => {
            const k = panel.getAttribute('data-crowd-tab-panel');
            if (k === kind) {
                panel.classList.add('show');
                panel.classList.add('active');
            } else {
                panel.classList.remove('show');
                panel.classList.remove('active');
            }
        });
        try { localStorage.setItem('pp-crowd-tab', kind); } catch(e) {}
    }
    if (tabButtons && tabButtons.length) {
        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => showTab(btn.getAttribute('data-crowd-tab')));
        });
        let initialTab = 'simple';
        try {
            const saved = localStorage.getItem('pp-crowd-tab');
            if (saved === 'deep' || saved === 'simple') { initialTab = saved; }
        } catch(e) {}
        // If deep is running, prefer deep tab
        if (deepInitialActive) { initialTab = 'deep'; }
        showTab(initialTab);
    }

    toggleButtons(initialActive);
    toggleDeepButtons(deepInitialActive);
    updateCounts();
    if (initialActive && currentRunId) {
        fetchStatus(currentRunId);
    }
    if (deepInitialActive && currentDeepRunId) {
        fetchDeepStatus(currentDeepRunId);
    } else if (!deepInitialActive && currentDeepRunId) {
        fetchDeepResults(currentDeepRunId, 20);
    }
})();
</script>
