<?php
// Crowd links admin section
$crowdStatusOptions = [
    'all' => __('Все'),
    'pending' => __('Не проверено'),
    'checking' => __('В процессе'),
    'success' => __('Рабочие'),
    'needs_review' => __('Нужна проверка'),
    'failed' => __('Ошибки'),
    'cancelled' => __('Отменено'),
];
$crowdStatusBadges = [
    'pending' => 'badge-secondary',
    'checking' => 'badge-primary',
    'success' => 'badge-success',
    'needs_review' => 'badge-warning text-dark',
    'failed' => 'badge-danger',
    'cancelled' => 'badge-secondary',
];
$crowdFollowLabels = [
    'follow' => __('DoFollow'),
    'nofollow' => __('NoFollow'),
    'unknown' => __('Неизвестно'),
];
$crowdIndexLabels = [
    'index' => __('Индексируется'),
    'noindex' => __('NoIndex'),
    'unknown' => __('Неизвестно'),
];
$formatTs = static function (?string $ts): string {
    if (!$ts || $ts === '0000-00-00 00:00:00') {
        return '—';
    }
    $time = strtotime($ts);
    if ($time === false) {
        return htmlspecialchars($ts);
    }
    return htmlspecialchars(date('Y-m-d H:i', $time));
};
$buildCrowdPageUrl = static function (int $page) use ($crowdStatusFilter, $crowdSearch): string {
    $query = ['crowd_page' => $page];
    if ($crowdStatusFilter && $crowdStatusFilter !== 'all') {
        $query['crowd_status'] = $crowdStatusFilter;
    }
    if ($crowdSearch !== '') {
        $query['crowd_search'] = $crowdSearch;
    }
    return pp_url('admin/admin.php') . '?' . http_build_query($query);
};
$crowdRunPayload = json_encode([
    'run' => $crowdCurrentRun,
    'results' => $crowdCurrentResults,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
$crowdStatsPayload = json_encode($crowdStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
$crowdApiUrl = pp_url('admin/crowd_links_api.php');
?>
<div id="crowd-links-section" style="display:none;">
    <h3 class="mb-3"><?php echo __('Крауд ссылки'); ?></h3>

    <?php if ($crowdMsg): ?>
        <div class="alert alert-info fade-in"><?php echo htmlspecialchars($crowdMsg); ?></div>
    <?php endif; ?>
    <?php if ($crowdSettingsMsg): ?>
        <div class="alert alert-success fade-in"><?php echo htmlspecialchars($crowdSettingsMsg); ?></div>
    <?php endif; ?>

    <div class="row g-3 crowd-stats mb-4">
        <div class="col-md-4">
            <div class="crowd-stat-card">
                <div class="crowd-stat-circle crowd-stat-total"><span data-crowd-stat="total"><?php echo number_format((int)($crowdStats['total'] ?? 0), 0, '.', ' '); ?></span></div>
                <div class="crowd-stat-label"><?php echo __('Всего ссылок'); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="crowd-stat-card">
                <div class="crowd-stat-circle crowd-stat-checked"><span data-crowd-stat="checked"><?php echo number_format((int)($crowdStats['checked'] ?? 0), 0, '.', ' '); ?></span></div>
                <div class="crowd-stat-label"><?php echo __('Проверено'); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="crowd-stat-card">
                <div class="crowd-stat-circle crowd-stat-success"><span data-crowd-stat="success"><?php echo number_format((int)($crowdStats['success'] ?? 0), 0, '.', ' '); ?></span></div>
                <div class="crowd-stat-label"><?php echo __('Рабочие'); ?></div>
            </div>
        </div>
    </div>

    <form method="get" class="card p-3 mb-3 crowd-filters" autocomplete="off">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label form-label-sm" for="crowdStatusFilter"><?php echo __('Статус'); ?></label>
                <select id="crowdStatusFilter" name="crowd_status" class="form-select form-select-sm">
                    <?php foreach ($crowdStatusOptions as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $value === $crowdStatusFilter ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label form-label-sm" for="crowdSearch"><?php echo __('Поиск по URL'); ?></label>
                <input type="text" id="crowdSearch" name="crowd_search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($crowdSearch); ?>" placeholder="https://example.com/page">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm" for="crowdPage"><?php echo __('Страница'); ?></label>
                <input type="number" id="crowdPage" name="crowd_page" class="form-control form-control-sm" min="1" value="<?php echo (int)$crowdPage; ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i><?php echo __('Применить'); ?></button>
                <a href="<?php echo pp_url('admin/admin.php'); ?>#crowd-links-section" class="btn btn-outline-secondary btn-sm" title="<?php echo __('Сбросить фильтры'); ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </div>
    </form>

    <form method="post" enctype="multipart/form-data" class="card p-3 mb-3" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="crowdImportFiles"><?php echo __('Импорт баз ссылок'); ?></label>
                <input type="file" class="form-control" id="crowdImportFiles" name="crowd_files[]" multiple accept=".txt">
                <div class="form-text"><?php echo __('Файлы .txt с адресами по одному в строке. Можно выбрать несколько файлов сразу.'); ?></div>
            </div>
            <div class="col-md-4 text-md-end">
                <button type="submit" class="btn btn-primary mt-3 mt-md-0" name="crowd_import_submit" value="1"><i class="bi bi-cloud-upload me-1"></i><?php echo __('Импортировать'); ?></button>
            </div>
        </div>
    </form>

    <form method="post" class="card p-3 mb-3" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="crowdConcurrency"><?php echo __('Параллельные проверки'); ?></label>
                <input type="number" class="form-control" id="crowdConcurrency" name="crowd_concurrency" min="1" max="20" value="<?php echo (int)$crowdDefaultConcurrency; ?>">
                <div class="form-text"><?php echo __('Число одновременных запросов.'); ?></div>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="crowdTimeout"><?php echo __('Таймаут запроса, сек.'); ?></label>
                <input type="number" class="form-control" id="crowdTimeout" name="crowd_timeout" min="5" max="180" value="<?php echo (int)$crowdDefaultTimeout; ?>">
                <div class="form-text"><?php echo __('Максимальное время ожидания ответа площадки.'); ?></div>
            </div>
        </div>
        <div class="text-end mt-3">
            <button type="submit" class="btn btn-outline-primary" name="crowd_settings_submit" value="1"><i class="bi bi-save me-1"></i><?php echo __('Сохранить настройки'); ?></button>
        </div>
    </form>

    <div class="card p-3 mb-3" id="crowdRunCard" data-api="<?php echo htmlspecialchars($crowdApiUrl); ?>">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start align-items-lg-center">
            <div>
                <div class="fw-semibold mb-1" data-crowd-run-status><?php echo $crowdCurrentRun ? htmlspecialchars($crowdCurrentRun['status']) : __('Проверка не запущена'); ?></div>
                <div class="small text-muted" data-crowd-run-meta>
                    <?php if ($crowdCurrentRun): ?>
                        <?php echo sprintf(__('Обработано %d из %d.'), (int)$crowdCurrentRun['processed'], (int)$crowdCurrentRun['total']); ?>
                    <?php else: ?>
                        <?php echo __('Импортируйте ссылки и запустите проверку.'); ?>
                    <?php endif; ?>
                </div>
                <div class="progress mt-2" style="height:8px;">
                    <?php
                    $progress = 0;
                    if ($crowdCurrentRun && $crowdCurrentRun['total'] > 0) {
                        $progress = (int)min(100, round(($crowdCurrentRun['processed'] / max(1, $crowdCurrentRun['total'])) * 100));
                    }
                    ?>
                    <div class="progress-bar" role="progressbar" style="width: <?php echo $progress; ?>%;" data-crowd-run-progress><?php echo $progress; ?>%</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success" id="crowdStartAll" data-mode="all"><i class="bi bi-play-circle me-1"></i><?php echo __('Проверить все (фильтр)'); ?></button>
                <button type="button" class="btn btn-outline-primary" id="crowdStartPending" data-mode="pending"><i class="bi bi-lightning-charge me-1"></i><?php echo __('Проверить новые'); ?></button>
                <button type="button" class="btn btn-outline-info" id="crowdStartSelected" data-mode="selection" disabled><i class="bi bi-check2-circle me-1"></i><?php echo __('Проверить выбранные'); ?></button>
                <button type="button" class="btn btn-outline-danger" id="crowdStopRun" style="display: <?php echo ($crowdCurrentRun && $crowdCurrentRun['status'] === 'running') ? 'inline-flex' : 'none'; ?>;"><i class="bi bi-stop-circle me-1"></i><?php echo __('Остановить'); ?></button>
                <button type="button" class="btn btn-outline-secondary" id="crowdRefresh"><i class="bi bi-arrow-repeat me-1"></i><?php echo __('Обновить'); ?></button>
                <div class="vr mx-1 d-none d-lg-inline"></div>
                <button type="button" class="btn btn-outline-warning" id="crowdDeleteSelected" disabled><i class="bi bi-trash3 me-1"></i><?php echo __('Удалить выбранные'); ?></button>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-broom me-1"></i><?php echo __('Очистить'); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" data-crowd-clear="errors"><?php echo __('Только ошибки/отменённые'); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" data-crowd-clear="all"><?php echo __('Полностью базу'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2" data-crowd-run-note>
            <?php if ($crowdCurrentRun && !empty($crowdCurrentRun['notes'])): ?>
                <?php echo htmlspecialchars($crowdCurrentRun['notes']); ?>
            <?php endif; ?>
        </div>
        <div class="alert alert-warning mt-3 d-none" id="crowdRunMessage"></div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold"><?php echo __('Список площадок'); ?></div>
            <div class="small text-muted">
                <?php echo sprintf(__('Показано %d из %d'), count($crowdLinks), $crowdTotalLinks); ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle" id="crowdLinksTable">
                <thead>
                <tr>
                    <th style="width:48px;" class="text-center">
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" id="crowdSelectAll" aria-label="<?php echo __('Выбрать все'); ?>">
                        </div>
                    </th>
                    <th><?php echo __('URL'); ?></th>
                    <th style="width:130px;" class="text-center"><?php echo __('Статус'); ?></th>
                    <th style="width:110px;" class="text-center"><?php echo __('Регион'); ?></th>
                    <th style="width:110px;" class="text-center"><?php echo __('Язык'); ?></th>
                    <th style="width:120px;" class="text-center"><?php echo __('Ссылка'); ?></th>
                    <th style="width:120px;" class="text-center"><?php echo __('Индексация'); ?></th>
                    <th style="width:100px;" class="text-center"><?php echo __('HTTP'); ?></th>
                    <th style="width:160px;" class="text-center"><?php echo __('Последняя проверка'); ?></th>
                    <th style="width:120px;" class="text-end"><?php echo __('Действия'); ?></th>
                </tr>
                </thead>
                <tbody id="crowdLinksTbody">
                <?php if (!empty($crowdLinks)): ?>
                    <?php foreach ($crowdLinks as $link): ?>
                        <?php
                        $status = (string)($link['status'] ?? 'pending');
                        $statusClass = $crowdStatusBadges[$status] ?? 'badge-secondary';
                        $statusLabel = $crowdStatusOptions[$status] ?? __('Неизвестно');
                        $linkId = (int)$link['id'];
                        $follow = (string)($link['follow_type'] ?? 'unknown');
                        $index = (string)($link['is_indexed'] ?? 'unknown');
                        $http = $link['http_status'] !== null ? (int)$link['http_status'] : null;
                        $lastCheck = $formatTs($link['last_checked_at'] ?? null);
                        ?>
                        <tr data-link-id="<?php echo $linkId; ?>" data-status="<?php echo htmlspecialchars($status); ?>">
                            <td class="text-center">
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input crowd-select" aria-label="<?php echo __('Выбрать ссылку'); ?>">
                                </div>
                            </td>
                            <td>
                                <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener" class="crowd-link-url">
                                    <?php echo htmlspecialchars(mb_strimwidth($link['url'], 0, 90, '…')); ?>
                                </a>
                            </td>
                            <td class="text-center"><span class="badge <?php echo $statusClass; ?>" data-status-label><?php echo htmlspecialchars($statusLabel); ?></span></td>
                            <td class="text-center" data-region><?php echo $link['region'] ? htmlspecialchars($link['region']) : '—'; ?></td>
                            <td class="text-center" data-language><?php echo $link['language'] ? htmlspecialchars($link['language']) : '—'; ?></td>
                            <td class="text-center" data-follow><?php echo htmlspecialchars($crowdFollowLabels[$follow] ?? __('Неизвестно')); ?></td>
                            <td class="text-center" data-index><?php echo htmlspecialchars($crowdIndexLabels[$index] ?? __('Неизвестно')); ?></td>
                            <td class="text-center" data-http><?php echo $http !== null ? $http : '—'; ?></td>
                            <td class="text-center" data-last-check><?php echo $lastCheck; ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary crowd-check-single" data-link-id="<?php echo $linkId; ?>"><i class="bi bi-play-circle me-1"></i><?php echo __('Проверить'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4"><?php echo __('Ссылок нет. Импортируйте базу, чтобы начать.'); ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <nav aria-label="<?php echo __('Пагинация крауд ссылок'); ?>" class="d-flex justify-content-between align-items-center mb-5">
        <div class="small text-muted"><?php echo sprintf(__('Страница %d из %d'), $crowdPage, $crowdTotalPages); ?></div>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?php echo $crowdPage <= 1 ? ' disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $crowdPage <= 1 ? '#' : $buildCrowdPageUrl(max(1, $crowdPage - 1)); ?>">&laquo;</a>
            </li>
            <li class="page-item<?php echo $crowdPage >= $crowdTotalPages ? ' disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $crowdPage >= $crowdTotalPages ? '#' : $buildCrowdPageUrl(min($crowdTotalPages, $crowdPage + 1)); ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
</div>

<script type="application/json" id="crowdRunData"><?php echo $crowdRunPayload ?: '{}'; ?></script>
<script type="application/json" id="crowdStatsData"><?php echo $crowdStatsPayload ?: '{}'; ?></script>

<script>
(function(){
    const section = document.getElementById('crowd-links-section');
    if (!section) { return; }
    const apiUrl = section.querySelector('#crowdRunCard')?.dataset.api || '';
    const statusLabels = <?php echo json_encode($crowdStatusOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const statusBadges = <?php echo json_encode($crowdStatusBadges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const followLabels = <?php echo json_encode($crowdFollowLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const indexLabels = <?php echo json_encode($crowdIndexLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const tbody = document.getElementById('crowdLinksTbody');
    const selectAll = document.getElementById('crowdSelectAll');
    const startAllBtn = document.getElementById('crowdStartAll');
    const startPendingBtn = document.getElementById('crowdStartPending');
    const startSelectedBtn = document.getElementById('crowdStartSelected');
    const deleteSelectedBtn = document.getElementById('crowdDeleteSelected');
    const stopBtn = document.getElementById('crowdStopRun');
    const refreshBtn = document.getElementById('crowdRefresh');
    const runMessage = document.getElementById('crowdRunMessage');
    const runStatusEl = section.querySelector('[data-crowd-run-status]');
    const runMetaEl = section.querySelector('[data-crowd-run-meta]');
    const runNoteEl = section.querySelector('[data-crowd-run-note]');
    const runProgressBar = section.querySelector('[data-crowd-run-progress]');
    const statNodes = {
        total: section.querySelector('[data-crowd-stat="total"]'),
        checked: section.querySelector('[data-crowd-stat="checked"]'),
        success: section.querySelector('[data-crowd-stat="success"]'),
    };
    const csrfToken = window.CSRF_TOKEN || '';
    let pollTimer = null;
    let currentRunId = null;
    let visibilityTimer = null;

    function parseJsonScript(id) {
        try {
            const el = document.getElementById(id);
            if (!el) { return null; }
            return JSON.parse(el.textContent || '{}');
        } catch (err) {
            return null;
        }
    }

    function setRunMessage(message, type = 'warning') {
        if (!runMessage) { return; }
        if (!message) {
            runMessage.classList.add('d-none');
            runMessage.textContent = '';
            return;
        }
        runMessage.className = 'alert alert-' + type + ' mt-3';
        runMessage.textContent = message;
    }

    function selectedIds() {
        const ids = [];
        tbody.querySelectorAll('tr').forEach(row => {
            const checkbox = row.querySelector('.crowd-select');
            if (checkbox && checkbox.checked) {
                const id = parseInt(row.getAttribute('data-link-id'), 10);
                if (id > 0) { ids.push(id); }
            }
        });
        return ids;
    }

    function updateSelectionState() {
    const ids = selectedIds();
    startSelectedBtn.disabled = ids.length === 0;
    if (deleteSelectedBtn) deleteSelectedBtn.disabled = ids.length === 0;
        if (!selectAll) { return; }
        const rows = tbody.querySelectorAll('tr');
        let selectable = 0;
        let selected = 0;
        rows.forEach(row => {
            const checkbox = row.querySelector('.crowd-select');
            if (!checkbox) { return; }
            selectable++;
            if (checkbox.checked) { selected++; }
        });
        if (selectable === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (selected === selectable) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else if (selected === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else {
            selectAll.indeterminate = true;
        }
    }

    function formatNumber(n) {
        return (Intl && Intl.NumberFormat) ? new Intl.NumberFormat().format(n) : String(n);
    }

    function updateStats(stats) {
        if (!stats) { return; }
        if (statNodes.total) { statNodes.total.textContent = formatNumber(stats.total || 0); }
        if (statNodes.checked) { statNodes.checked.textContent = formatNumber(stats.checked || 0); }
        if (statNodes.success) { statNodes.success.textContent = formatNumber(stats.success || 0); }
    }

    function updateRun(run) {
        currentRunId = run && run.id ? run.id : null;
        if (!runStatusEl || !runMetaEl || !runProgressBar) { return; }
        if (!run) {
            runStatusEl.textContent = '<?php echo addslashes(__('Проверка не запущена')); ?>';
            runMetaEl.textContent = '<?php echo addslashes(__('Импортируйте ссылки и запустите проверку.')); ?>';
            runProgressBar.style.width = '0%';
            runProgressBar.textContent = '0%';
            if (stopBtn) { stopBtn.style.display = 'none'; }
            return;
        }
        runStatusEl.textContent = run.status;
        runMetaEl.textContent = '<?php echo addslashes(__('Обработано')); ?> ' + (run.processed || 0) + ' / ' + (run.total || 0);
        const progress = run.total > 0 ? Math.min(100, Math.round((run.processed / run.total) * 100)) : 0;
        runProgressBar.style.width = progress + '%';
        runProgressBar.textContent = progress + '%';
        if (stopBtn) {
            stopBtn.style.display = run.status === 'running' ? 'inline-flex' : 'none';
        }
        if (runNoteEl) {
            runNoteEl.textContent = run.notes ? run.notes : '';
        }
    }

    function updateTable(html) {
        if (!tbody || typeof html !== 'string') { return; }
        tbody.innerHTML = html;
        // re-bind checkbox listeners
        tbody.querySelectorAll('.crowd-select').forEach(cb => {
            cb.addEventListener('change', updateSelectionState);
        });
        tbody.querySelectorAll('.crowd-check-single').forEach(btn => {
            btn.addEventListener('click', () => {
                const linkId = parseInt(btn.getAttribute('data-link-id'), 10);
                if (linkId > 0) {
                    startRun('single', [linkId]);
                }
            });
        });
        // re-init tooltips for shortened URLs
        if (window.bootstrap) {
            tbody.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                try { new bootstrap.Tooltip(el); } catch(e) { /* noop */ }
            });
        }
        updateSelectionState();
    }

    function apiRequest(action, method = 'GET', payload = {}) {
        if (!apiUrl) { return Promise.reject(new Error('No API URL')); }
        const headers = {};
        let body = null;
        let url = apiUrl + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            const params = new URLSearchParams(payload);
            url += '&' + params.toString();
        } else {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            const data = { ...payload };
            if (csrfToken) { data.csrf_token = csrfToken; }
            body = new URLSearchParams(data).toString();
        }
        return fetch(url, { method, headers, body, credentials: 'same-origin' })
            .then(res => res.json())
            .catch(() => ({ ok: false, error: 'NETWORK' }));
    }

    function deleteSelected() {
        const ids = selectedIds();
        if (!ids.length) return;
        if (!confirm('<?php echo addslashes(__('Удалить выбранные ссылки? Это действие необратимо.')); ?>')) return;
        const data = { };
        ids.forEach((id, i) => data['ids['+i+']'] = id);
        return apiRequest('delete', 'POST', data).then(() => { refreshStatus(); refreshList(); });
    }

    function clearBase(mode) {
        if (mode === 'all') {
            if (!confirm('<?php echo addslashes(__('Полностью очистить базу крауд ссылок? Это действие необратимо.')); ?>')) return;
        }
        return apiRequest('clear', 'POST', { mode }).then(() => { refreshStatus(); refreshList(); });
    }

    function refreshStatus(silent = false) {
        const params = new URLSearchParams(window.location.search);
        const payload = Object.fromEntries(params.entries());
        return apiRequest('status', 'GET', payload).then(data => {
            if (!data || !data.ok) {
                if (!silent) {
                    setRunMessage('<?php echo addslashes(__('Не удалось получить статус.')); ?>', 'danger');
                }
                return;
            }
            setRunMessage('');
            updateRun(data.run || null);
            updateStats(data.stats || data.run || null);
            if (data.tableHtml) {
                updateTable(data.tableHtml);
            }
            if (data.stats) {
                updateStats(data.stats);
            }
            // Show current checking hint
            if (runNoteEl) {
                if (data.run && data.run.status === 'running' && Array.isArray(data.nowUrls) && data.nowUrls.length) {
                    const sample = data.nowUrls.slice(0, 3).join(', ');
                    runNoteEl.textContent = '<?php echo addslashes(__('Сейчас проверяется:')); ?> ' + sample + (data.nowUrls.length > 3 ? '…' : '');
                }
            }
            if (data.run && data.run.status === 'running') {
                schedulePoll();
            }
        });
    }

    function refreshList() {
        const params = new URLSearchParams(window.location.search);
        return apiRequest('list', 'GET', Object.fromEntries(params.entries())).then(data => {
            if (!data || !data.ok) {
                setRunMessage('<?php echo addslashes(__('Не удалось обновить список.')); ?>', 'danger');
                return;
            }
            if (data.table) { updateTable(data.table); }
            if (data.stats) { updateStats(data.stats); }
            if (data.run) { updateRun(data.run); }
            setRunMessage('');
        });
    }

    function schedulePoll() {
        if (pollTimer) { clearTimeout(pollTimer); }
        pollTimer = setTimeout(() => refreshStatus(true), 4000);
    }

    // Refresh when page becomes visible again (user returned to tab)
    function onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            refreshStatus(true);
        }
    }

    function startRun(mode, ids = []) {
        const payload = { mode };
        if (mode === 'selection' || mode === 'single') {
            if (!ids || ids.length === 0) {
                setRunMessage('<?php echo addslashes(__('Выберите ссылки для проверки.')); ?>', 'warning');
                return;
            }
            ids.forEach((id, index) => { payload['ids[' + index + ']'] = id; });
        }
        if (mode === 'single' && ids.length === 1) {
            payload.link_id = ids[0];
        }
        const params = new URLSearchParams(window.location.search);
        const statusFilter = params.get('crowd_status');
        const search = params.get('crowd_search');
        if (statusFilter) { payload.status = statusFilter; }
        if (search) { payload.search = search; }
        setRunMessage('<?php echo addslashes(__('Запускаем проверку...')); ?>', 'info');
        apiRequest('start', 'POST', payload).then(data => {
            if (!data || !data.ok) {
                setRunMessage('<?php echo addslashes(__('Не удалось запустить проверку.')); ?>', 'danger');
                return;
            }
            setRunMessage('');
            refreshStatus();
        });
    }

    function cancelRun() {
        if (!currentRunId) { return; }
        setRunMessage('<?php echo addslashes(__('Останавливаем проверку...')); ?>', 'info');
        apiRequest('cancel', 'POST', { run_id: currentRunId }).then(() => refreshStatus());
    }

    const initialRunData = parseJsonScript('crowdRunData');
    if (initialRunData && initialRunData.run) {
        updateRun(initialRunData.run);
        if (initialRunData.run.status === 'running') {
            schedulePoll();
        }
    }
    const initialStats = parseJsonScript('crowdStatsData');
    if (initialStats) { updateStats(initialStats); }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            const checked = selectAll.checked;
            tbody.querySelectorAll('.crowd-select').forEach(cb => {
                cb.checked = checked;
            });
            updateSelectionState();
        });
    }
    tbody.querySelectorAll('.crowd-select').forEach(cb => cb.addEventListener('change', updateSelectionState));
    tbody.querySelectorAll('.crowd-check-single').forEach(btn => {
        btn.addEventListener('click', () => {
            const linkId = parseInt(btn.getAttribute('data-link-id'), 10);
            if (linkId > 0) {
                startRun('single', [linkId]);
            }
        });
    });
    updateSelectionState();

    startAllBtn?.addEventListener('click', () => startRun('all'));
    startPendingBtn?.addEventListener('click', () => startRun('pending'));
    startSelectedBtn?.addEventListener('click', () => {
        const ids = selectedIds();
        if (ids.length > 0) { startRun('selection', ids); }
    });
    deleteSelectedBtn?.addEventListener('click', deleteSelected);
    stopBtn?.addEventListener('click', cancelRun);
    refreshBtn?.addEventListener('click', () => {
        refreshStatus();
        refreshList();
    });
    document.querySelectorAll('[data-crowd-clear]')?.forEach(el => {
        el.addEventListener('click', (e) => { e.preventDefault(); const m = el.getAttribute('data-crowd-clear') || 'errors'; clearBase(m); });
    });

    document.addEventListener('pp-admin-section-changed', (event) => {
        if (event.detail && event.detail.section === 'crowd-links') {
            refreshStatus(true);
        }
    });

    document.addEventListener('visibilitychange', onVisibilityChange);
})();
</script>
