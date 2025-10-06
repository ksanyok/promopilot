<?php
// Crowd marketing section assembly via modular partials

/**
 * Expected scoped variables provided by controller:
 * - arrays: $crowdSelectedIds, $crowdStatusMeta, $crowdScopeOptions, $crowdFilters, $crowdList
 * - data: $crowdCurrentRun, $crowdStats, $crowdMsg, $crowdStatusError, $crowdImportSummary
 * - deep data: $crowdDeepStatusMeta, $crowdDeepScopeOptions, $crowdDeepCurrentRun, $crowdDeepRecentResults,
 *   $crowdDeepDefaults, $crowdDeepLinkStats, $crowdDeepStatusError
 */

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
    $perPageOptions[] = (int)$crowdFilters['per_page'];
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

$crowdBuildUrl = static function (array $params = []) use ($crowdQueryBase) {
    $query = array_merge($crowdQueryBase, $params);
    $filtered = [];
    foreach ($query as $key => $value) {
        if ($value === null) {
            continue;
        }
        if ($value === '' && in_array($key, ['crowd_status', 'crowd_domain', 'crowd_language', 'crowd_region', 'crowd_search'], true)) {
            continue;
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
    if ($runStartedAt === '' || $runStartedAt === '0000-00-00 00:00:00') {
        $runStartedAt = '—';
    }
    if ($runFinishedAt === '' || $runFinishedAt === '0000-00-00 00:00:00') {
        $runFinishedAt = '—';
    }
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

$deepLinkStats = is_array($crowdDeepLinkStats ?? null) ? $crowdDeepLinkStats : pp_crowd_deep_get_link_stats();
$deepStatsSuccess = (int)($deepLinkStats['success'] ?? 0);
$deepStatsPartial = (int)($deepLinkStats['partial'] ?? 0);
$deepStatsFailed = (int)($deepLinkStats['failed'] ?? 0);
$deepStatsSkipped = (int)($deepLinkStats['skipped'] ?? 0);

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
    if ($deepRunStartedAt === '' || $deepRunStartedAt === '0000-00-00 00:00:00') {
        $deepRunStartedAt = '—';
    }
    if ($deepRunFinishedAt === '' || $deepRunFinishedAt === '0000-00-00 00:00:00') {
        $deepRunFinishedAt = '—';
    }
    $deepRunNotesRaw = (string)($crowdDeepCurrentRun['notes'] ?? '');
    $deepRunNotes = $deepRunNotesRaw !== '' ? $deepRunNotesRaw : '—';
}
$deepRunPercentText = $deepRunTotal > 0 ? ($deepRunProgress . '%') : '—';
$deepRunCountSummary = $deepRunTotal > 0 ? ($deepRunProcessed . '/' . $deepRunTotal) : '—';
$deepRecentItems = is_array($crowdDeepRecentResults) ? $crowdDeepRecentResults : [];
$deepFormTemplate = $deepHasRun ? (string)($crowdDeepCurrentRun['message_template'] ?? '') : (string)($crowdDeepDefaults['message_template'] ?? '');
if ($deepFormTemplate === '') {
    $deepFormTemplate = (string)($crowdDeepDefaults['message_template'] ?? '');
}
$deepFormLink = $deepHasRun ? (string)($crowdDeepCurrentRun['message_url'] ?? '') : (string)($crowdDeepDefaults['message_link'] ?? '');
if ($deepFormLink === '') {
    $deepFormLink = (string)($crowdDeepDefaults['message_link'] ?? '');
}
$deepFormName = (string)($crowdDeepDefaults['name'] ?? '');
$deepFormCompany = (string)($crowdDeepDefaults['company'] ?? '');
$deepFormEmailUser = (string)($crowdDeepDefaults['email_user'] ?? '');
$deepFormEmailDomain = (string)($crowdDeepDefaults['email_domain'] ?? '');
$deepFormPhone = (string)($crowdDeepDefaults['phone'] ?? '');
$deepFormTokenPrefix = $deepHasRun ? (string)($crowdDeepCurrentRun['token_prefix'] ?? '') : (string)($crowdDeepDefaults['token_prefix'] ?? '');
if ($deepFormTokenPrefix === '') {
    $deepFormTokenPrefix = (string)($crowdDeepDefaults['token_prefix'] ?? '');
}

$headerKind = '—';
if ($deepRunInProgress) {
    $headerKind = __('Глубокая проверка');
} elseif ($runInProgress) {
    $headerKind = __('Простая проверка');
}

$headerStatusLabel = $runStatusLabel;
$headerProgress = $runProgress;
$headerPercentText = $runPercentText;
$headerCountSummary = $runCountSummary;
if ($deepRunInProgress) {
    $headerStatusLabel = $deepRunStatusLabel;
    $headerProgress = $deepRunProgress;
    $headerPercentText = $deepRunPercentText;
    $headerCountSummary = $deepRunCountSummary;

    $runStatusLabel = $deepRunStatusLabel;
    $runProgress = $deepRunProgress;
    $runProcessed = $deepRunProcessed;
    $runTotal = $deepRunTotal;
    $runPercentText = $deepRunPercentText;
    $runCountSummary = $deepRunCountSummary;
}

$pp_truncate = static function (string $text, int $length = 30): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $length) {
            return mb_substr($text, 0, $length, 'UTF-8') . '…';
        }
        return $text;
    }

    return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
};

$crowdJsConfig = [
    'simple' => [
        'labels' => [
            'selectSomething' => __('Выберите хотя бы одну ссылку.'),
            'startSuccess' => __('Проверка запущена.'),
            'alreadyRunning' => __('Проверка уже выполняется.'),
            'cancelled' => __('Остановка запроса отправлена.'),
            'cancelIdle' => __('Нет активных проверок.'),
            'cancelFailed' => __('Не удалось отменить проверку.'),
            'cancelComplete' => __('Проверка остановлена.'),
            'forceSuccess' => __('Принудительная остановка выполнена.'),
            'stallWarning' => __('Похоже, проверка не отвечает. Повторите остановку для принудительного завершения.'),
            'autoStopped' => __('Проверка автоматически остановлена из-за отсутствия активности.'),
            'noRuns' => __('Проверка ещё не запускалась'),
            'stopping' => __('Останавливается…'),
        ],
        'statusMap' => $crowdStatusLabels,
        'scopeMap' => $crowdScopeLabels,
        'kindLabel' => __('Простая проверка'),
    ],
    'deep' => [
        'labels' => [
            'selectSomething' => __('Выберите ссылки для глубокой проверки.'),
            'startSuccess' => __('Глубокая проверка запущена.'),
            'alreadyRunning' => __('Глубокая проверка уже выполняется.'),
            'cancelPending' => __('Запрос на остановку отправлен.'),
            'cancelIdle' => __('Нет активной глубокой проверки.'),
            'cancelFailed' => __('Не удалось остановить глубокую проверку.'),
            'cancelComplete' => __('Глубокая проверка остановлена.'),
            'forceSuccess' => __('Принудительная остановка глубокой проверки выполнена.'),
            'stallWarning' => __('Похоже, глубокая проверка зависла. Повторите остановку для принудительного завершения.'),
            'autoStopped' => __('Глубокая проверка автоматически остановлена из-за отсутствия активности.'),
            'noRuns' => __('Глубокая проверка ещё не запускалась'),
            'noResults' => __('Результатов пока нет.'),
            'openResponse' => __('Открыть ответ'),
            'records' => __('Записей'),
        ],
        'statusMap' => $crowdDeepStatusLabels,
        'scopeMap' => $crowdDeepScopeLabels,
        'statusClasses' => $crowdDeepStatusClasses,
        'kindLabel' => __('Глубокая проверка'),
    ],
];

$crowdJsConfigJson = htmlspecialchars(json_encode($crowdJsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

?>
<div id="crowd-section" style="display:none;"
     data-crowd-api="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-crowd-deep-api="<?php echo htmlspecialchars($deepApiUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-crowd-deep-run-id="<?php echo $deepHasRun ? (int)$crowdDeepCurrentRun['id'] : ''; ?>"
     data-crowd-deep-active="<?php echo $deepRunInProgress ? '1' : '0'; ?>"
     data-crowd-config="<?php echo $crowdJsConfigJson; ?>">
    <?php include __DIR__ . '/crowd/header.php'; ?>
    <?php include __DIR__ . '/crowd/alerts.php'; ?>
    <?php include __DIR__ . '/crowd/stats.php'; ?>
    <?php include __DIR__ . '/crowd/import.php'; ?>
    <?php include __DIR__ . '/crowd/checks.php'; ?>
    <?php include __DIR__ . '/crowd/filters.php'; ?>
</div>

<?php if (!defined('PP_ADMIN_CROWD_SCRIPT_INCLUDED')): ?>
    <?php define('PP_ADMIN_CROWD_SCRIPT_INCLUDED', true); ?>
    <script src="<?php echo asset_url('js/admin_crowd_links.js?v=' . rawurlencode(get_version())); ?>"></script>
<?php endif; ?>
