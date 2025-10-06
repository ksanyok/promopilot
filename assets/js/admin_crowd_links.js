(function () {
    const section = document.getElementById('crowd-section');
    if (!section) {
        return;
    }

    const parseConfig = () => {
        const raw = section.getAttribute('data-crowd-config') || '';
        if (!raw) {
            return {};
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            console.error('[crowd] Failed to parse crowd config', error);
            return {};
        }
    };

    const config = parseConfig();
    const simpleConfig = config.simple || {};
    const deepConfig = config.deep || {};

    const labels = Object.assign({}, simpleConfig.labels || {});
    labels.statusMap = simpleConfig.statusMap || {};
    labels.scopeMap = simpleConfig.scopeMap || {};
    labels.kindLabel = simpleConfig.kindLabel || 'Простая проверка';

    const deepLabels = Object.assign({}, deepConfig.labels || {});
    deepLabels.statusMap = deepConfig.statusMap || {};
    deepLabels.scopeMap = deepConfig.scopeMap || {};
    deepLabels.statusClasses = deepConfig.statusClasses || {};
    deepLabels.kindLabel = deepConfig.kindLabel || 'Глубокая проверка';
    deepLabels.records = deepLabels.records || 'Записей';
    deepLabels.openResponse = deepLabels.openResponse || 'Открыть ответ';

    const apiBase = section.getAttribute('data-crowd-api');
    const startBtn = section.querySelector('#crowdCheckStart');
    const cancelBtn = section.querySelector('#crowdCheckCancel');
    const scopeSelect = section.querySelector('#crowdCheckScope');
    const messageBox = section.querySelector('#crowdCheckMessage');
    const card = section.querySelector('#crowdCheckCard');
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
    const selectionCounter = section.querySelector('[data-crowd-selected-count]');
    const deleteBtn = section.querySelector('#crowdDeleteSelected');
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
    const deepStatsSuccessEl = section.querySelector('[data-deep-stats-success]');
    const deepStatsPartialEl = section.querySelector('[data-deep-stats-partial]');
    const deepStatsFailedEl = section.querySelector('[data-deep-stats-failed]');
    const deepStatsSkippedEl = section.querySelector('[data-deep-stats-skipped]');

    const formatNumber = (value) => {
        const num = Number(value || 0);
        if (!Number.isFinite(num)) {
            return '0';
        }
        try {
            return num.toLocaleString('ru-RU');
        } catch (error) {
            return String(num);
        }
    };

    const updateDeepStatsCards = (stats) => {
        if (!stats) {
            return;
        }
        if (deepStatsSuccessEl) deepStatsSuccessEl.textContent = formatNumber(stats.success || 0);
        if (deepStatsPartialEl) deepStatsPartialEl.textContent = formatNumber(stats.partial || 0);
        if (deepStatsFailedEl) deepStatsFailedEl.textContent = formatNumber(stats.failed || 0);
        if (deepStatsSkippedEl) deepStatsSkippedEl.textContent = formatNumber(stats.skipped || 0);
    };

    let pollTimer = null;
    let currentRunId = card ? parseInt(card.getAttribute('data-run-id') || '0', 10) : 0;
    const initialActive = card ? card.getAttribute('data-run-active') === '1' : false;
    let cancelAttempts = 0;

    let deepPollTimer = null;
    let currentDeepRunId = deepCard ? parseInt(deepCard.getAttribute('data-run-id') || '0', 10) : 0;
    const deepInitialActive = deepCard ? deepCard.getAttribute('data-run-active') === '1' : false;
    let deepCancelAttempts = 0;

    const updateMessage = (text, type = 'muted') => {
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
    };

    const updateDeepMessage = (text, type = 'muted') => {
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
    };

    const toggleDeepButtons = (runActive) => {
        if (deepStartBtn) deepStartBtn.disabled = !!runActive;
        if (deepCancelBtn) deepCancelBtn.disabled = !runActive;
    };

    const setDeepSpinner = (btn, spinning) => {
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
    };

    const renderDeepResults = (items) => {
        if (!deepResultsBody) return;
        deepResultsBody.innerHTML = '';
        if (!items || !items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'text-center text-muted py-3';
            cell.textContent = deepLabels.noResults || '—';
            row.appendChild(cell);
            deepResultsBody.appendChild(row);
            return;
        }
        const truncate = (text, max = 30) => {
            if (!text) return '';
            if (text.length <= max) return text;
            return text.slice(0, max) + '…';
        };
        items.forEach((item) => {
            const row = document.createElement('tr');
            const createdAt = item.created_at || '—';
            const status = item.status || 'pending';
            const badgeLabel = deepLabels.statusMap[status] || status;
            const badgeClass = deepLabels.statusClasses[status] || 'badge bg-secondary';
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
                    evidenceLink.title = deepLabels.openResponse;
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
    };

    const fetchDeepResults = async (runId, limit = 20) => {
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
                if (total) {
                    deepResultsMeta.textContent = `${deepLabels.records}: ${formatNumber(total)}`;
                } else {
                    deepResultsMeta.textContent = deepLabels.noResults || '—';
                }
            }
        } catch (error) {
            updateDeepMessage(error && error.message ? error.message : 'Error', 'warning');
        }
    };

    const gatherSelectedIds = () => {
        const ids = [];
        section.querySelectorAll('input[data-crowd-checkbox]:checked').forEach((cb) => {
            const val = parseInt(cb.value, 10);
            if (val > 0) {
                ids.push(val);
            }
        });
        return ids;
    };

    const setProgressVisible = (show) => {
        if (progressContainer) {
            progressContainer.classList.toggle('d-none', !show);
        }
        if (countsRow) {
            countsRow.classList.toggle('d-none', !show);
        }
    };

    const toggleButtons = (runActive) => {
        if (startBtn) startBtn.disabled = runActive;
        if (cancelBtn) cancelBtn.disabled = !runActive;
        setProgressVisible(runActive);
    };

    const setSpinner = (btn, spinning) => {
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
    };

    const updateCounts = () => {
        if (!selectionCounter) return;
        const count = gatherSelectedIds().length;
        selectionCounter.textContent = count;
        if (deleteBtn) {
            deleteBtn.disabled = count === 0;
        }
    };

    const updateRunCard = (data) => {
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
            statusLabel.textContent = labels.statusMap[data.status] || data.status || '—';
        }
        if (scopeEl) {
            scopeEl.textContent = labels.scopeMap[data.scope] || data.scope || '—';
        }
        const pct = data.progress_percent || 0;
        if (progressBar) {
            progressBar.style.width = `${pct}%`;
            progressBar.setAttribute('aria-valuenow', pct);
        }
        if (headerBarFill) {
            headerBarFill.style.width = `${pct}%`;
        }
        if (headerBar) {
            headerBar.setAttribute('aria-valuenow', pct);
        }
        if (headerPercent) {
            const pctText = (data.total_links || 0) > 0 ? `${pct}%` : '—';
            headerPercent.textContent = pctText;
        }
        if (processedEl) processedEl.textContent = data.processed_count || 0;
        if (totalEl) totalEl.textContent = data.total_links || 0;
        if (okEl) okEl.textContent = data.ok_count || 0;
        if (errorEl) errorEl.textContent = data.error_count || 0;
        if (headerCount) {
            if ((data.total_links || 0) > 0) {
                headerCount.textContent = `${data.processed_count || 0}/${data.total_links || 0}`;
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
            headerKind.textContent = labels.kindLabel || '—';
        }

        if (!active && pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (active && !pollTimer) {
            pollTimer = setTimeout(() => fetchStatus(currentRunId), 2000);
        }
        if (active && data.stalled && messageBox && messageBox.textContent.trim() === '') {
            updateMessage(labels.stallWarning || '', 'warning');
        }
        if (!active && (!messageBox || messageBox.textContent.trim() === '')) {
            if (data.status === 'cancelled') {
                updateMessage(labels.cancelComplete || '', 'success');
            } else if (data.status === 'failed') {
                updateMessage(labels.autoStopped || '', 'warning');
            }
        }
    };

    const fetchStatus = async (runId) => {
        if (!apiBase || !runId) {
            return;
        }
        try {
            const res = await fetch(`${apiBase}?action=status&run_id=${encodeURIComponent(runId)}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json().catch(() => ({}));
            if (!data || data.ok === false) {
                updateMessage((data && data.error) || 'Error', 'warning');
                return;
            }
            updateRunCard(data.run || null);
            if (data.run && data.run.in_progress) {
                pollTimer = setTimeout(() => fetchStatus(runId), 2000);
            } else if (data.run && !data.run.in_progress && data.run.status === 'cancelled' && data.run.notes && data.run.notes.indexOf('автоматически') !== -1) {
                if (!messageBox || messageBox.textContent.trim() === '') {
                    updateMessage(labels.autoStopped || '', 'info');
                }
            }
        } catch (error) {
            updateMessage(error && error.message ? error.message : 'Error', 'warning');
            pollTimer = setTimeout(() => fetchStatus(runId), 4000);
        }
    };

    const updateDeepRunCard = (data, linkStats) => {
        updateDeepStatsCards(linkStats);
        if (!deepCard) {
            return;
        }
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
            deepProgressBar.style.width = `${pct}%`;
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

        if (headerKind) {
            headerKind.textContent = deepLabels.kindLabel || '—';
        }
        if (headerStatus) {
            headerStatus.textContent = deepLabels.statusMap[data.status] || data.status || '—';
        }
        if (headerPercent) {
            const pctText = (data.total_links || 0) > 0 ? `${pct}%` : '—';
            headerPercent.textContent = pctText;
        }
        if (headerCount) {
            if ((data.total_links || 0) > 0) {
                headerCount.textContent = `${data.processed_count || 0}/${data.total_links || 0}`;
            } else {
                headerCount.textContent = '—';
            }
        }
        if (headerBarFill) {
            headerBarFill.style.width = `${pct}%`;
        }
        if (headerBar) {
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
            updateDeepMessage(deepLabels.stallWarning || '', 'warning');
        }
        if (!active && (!deepMessageBox || deepMessageBox.textContent.trim() === '')) {
            if (data.status === 'cancelled') {
                updateDeepMessage(deepLabels.cancelComplete || '', 'success');
            } else if (data.status === 'failed') {
                updateDeepMessage(deepLabels.autoStopped || '', 'warning');
            }
        }
        if (currentDeepRunId) {
            fetchDeepResults(currentDeepRunId, 20);
        }
    };

    const fetchDeepStatus = async (runId) => {
        if (!deepApiBase || !runId) {
            return;
        }
        try {
            const res = await fetch(`${deepApiBase}?action=deep_status&run_id=${encodeURIComponent(runId)}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateDeepMessage((data && data.error) || 'Error', 'warning');
                return;
            }
            updateDeepRunCard(data.run || null, data.link_stats || null);
            if (data.run && data.run.in_progress) {
                deepPollTimer = setTimeout(() => fetchDeepStatus(runId), 5000);
            }
        } catch (error) {
            updateDeepMessage(error && error.message ? error.message : 'Error', 'warning');
            deepPollTimer = setTimeout(() => fetchDeepStatus(runId), 7000);
        }
    };

    const startCheck = async () => {
        if (!apiBase || !startBtn) return;
        const scope = scopeSelect ? scopeSelect.value : 'all';
        const ids = scope === 'selection' ? gatherSelectedIds() : [];
        if (scope === 'selection' && ids.length === 0) {
            updateMessage(labels.selectSomething || '', 'warning');
            return;
        }
        setSpinner(startBtn, true);
        updateMessage('');
        try {
            const body = new URLSearchParams();
            body.set('scope', scope);
            if (ids.length) {
                ids.forEach((id) => body.append('ids[]', String(id)));
            }
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(`${apiBase}?action=start`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateMessage((data && data.error) || 'Error', 'danger');
                return;
            }
            if (data.alreadyRunning) {
                updateMessage(labels.alreadyRunning || '', 'info');
            } else {
                updateMessage(labels.startSuccess || '', 'success');
            }
            if (data.runId) {
                currentRunId = data.runId;
                card.setAttribute('data-run-id', String(currentRunId));
                card.setAttribute('data-run-active', '1');
                toggleButtons(true);
                if (progressBar) {
                    progressBar.style.width = '0%';
                    progressBar.setAttribute('aria-valuenow', '0');
                }
                if (processedEl) processedEl.textContent = '0';
                if (totalEl) totalEl.textContent = String(data.total || 0);
                if (okEl) okEl.textContent = '0';
                if (errorEl) errorEl.textContent = '0';
                cancelAttempts = 0;
                fetchStatus(currentRunId);
            }
        } catch (error) {
            updateMessage(error && error.message ? error.message : 'Error', 'danger');
        } finally {
            setSpinner(startBtn, false);
        }
    };

    const cancelCheck = async () => {
        if (!apiBase || !cancelBtn) return;
        const runId = currentRunId;
        if (!runId) {
            updateMessage(labels.cancelIdle || '', 'info');
            return;
        }
        setSpinner(cancelBtn, true);
        updateMessage('');
        setProgressVisible(false);
        if (statusLabel) {
            statusLabel.textContent = labels.stopping || statusLabel.textContent;
        }
        try {
            const body = new URLSearchParams();
            body.set('run_id', String(runId));
            cancelAttempts += 1;
            if (cancelAttempts > 1) {
                body.set('force', '1');
            }
            body.set('csrf_token', window.CSRF_TOKEN || '');
            const res = await fetch(`${apiBase}?action=cancel`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                updateMessage(labels.cancelFailed || 'Error', 'danger');
                setProgressVisible(true);
                return;
            }
            if (data.finished) {
                cancelAttempts = 0;
                const msg = data.forced ? labels.forceSuccess : labels.cancelComplete;
                updateMessage(msg || '', 'success');
            } else if (data.cancelRequested) {
                updateMessage(labels.cancelled || '', 'info');
            } else if (data.alreadyFinished || (data.status && data.status !== 'queued' && data.status !== 'running')) {
                cancelAttempts = 0;
                updateMessage(labels.cancelComplete || '', 'success');
            } else if (data.status === 'idle') {
                cancelAttempts = 0;
                updateMessage(labels.cancelIdle || '', 'info');
            }
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            fetchStatus(currentRunId);
        } catch (error) {
            updateMessage(labels.cancelFailed || 'Error', 'danger');
            setProgressVisible(true);
        } finally {
            setSpinner(cancelBtn, false);
        }
    };

    const startDeepCheck = async () => {
        if (!deepApiBase || !deepStartBtn) return;
        const scope = deepScopeSelect ? deepScopeSelect.value : 'all';
        const ids = scope === 'selection' ? gatherSelectedIds() : [];
        if (scope === 'selection' && ids.length === 0) {
            updateDeepMessage(deepLabels.selectSomething || '', 'warning');
            return;
        }
        setDeepSpinner(deepStartBtn, true);
        updateDeepMessage('');
        try {
            const body = new URLSearchParams();
            body.set('scope', scope);
            if (ids.length) {
                ids.forEach((id) => body.append('ids[]', String(id)));
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
                    updateDeepMessage((data && data.error) || 'Error', 'danger');
                }
                return;
            }
            if (data.alreadyRunning) {
                updateDeepMessage(deepLabels.alreadyRunning || '', 'info');
            } else {
                updateDeepMessage(deepLabels.startSuccess || '', 'success');
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
        } catch (error) {
            updateDeepMessage(error && error.message ? error.message : 'Error', 'danger');
        } finally {
            setDeepSpinner(deepStartBtn, false);
        }
    };

    const cancelDeepCheck = async () => {
        if (!deepApiBase || !deepCancelBtn) return;
        const runId = currentDeepRunId;
        if (!runId) {
            updateDeepMessage(deepLabels.cancelIdle || '', 'info');
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
                updateDeepMessage(deepLabels.cancelFailed || 'Error', 'danger');
                return;
            }
            if (data.finished) {
                deepCancelAttempts = 0;
                const msg = data.forced ? deepLabels.forceSuccess : deepLabels.cancelComplete;
                updateDeepMessage(msg || '', 'success');
            } else if (data.cancelRequested) {
                updateDeepMessage(deepLabels.cancelPending || '', 'info');
            } else if (data.alreadyFinished || (data.status && data.status !== 'queued' && data.status !== 'running')) {
                deepCancelAttempts = 0;
                updateDeepMessage(deepLabels.cancelComplete || '', 'success');
            } else if (data.status === 'idle') {
                deepCancelAttempts = 0;
                updateDeepMessage(deepLabels.cancelIdle || '', 'info');
            }
            if (deepPollTimer) {
                clearTimeout(deepPollTimer);
                deepPollTimer = null;
            }
            fetchDeepStatus(currentDeepRunId);
        } catch (error) {
            updateDeepMessage(deepLabels.cancelFailed || 'Error', 'danger');
        } finally {
            setDeepSpinner(deepCancelBtn, false);
        }
    };

    const handleSelectAction = (action) => {
        const checkboxes = section.querySelectorAll('input[data-crowd-checkbox]');
        if (action === 'toggle') {
            const allChecked = Array.from(checkboxes).every((cb) => cb.checked);
            checkboxes.forEach((cb) => {
                cb.checked = !allChecked;
            });
        } else if (action === 'all') {
            checkboxes.forEach((cb) => {
                cb.checked = true;
            });
        } else if (action === 'none') {
            checkboxes.forEach((cb) => {
                cb.checked = false;
            });
        }
        updateCounts();
    };

    selectAllBtns.forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            handleSelectAction(btn.getAttribute('data-crowd-select'));
        });
    });

    section.querySelectorAll('input[data-crowd-checkbox]').forEach((cb) => {
        cb.addEventListener('change', updateCounts);
    });

    if (startBtn) {
        startBtn.addEventListener('click', (event) => {
            event.preventDefault();
            startCheck();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', (event) => {
            event.preventDefault();
            cancelCheck();
        });
    }

    if (deepStartBtn) {
        deepStartBtn.addEventListener('click', (event) => {
            event.preventDefault();
            startDeepCheck();
        });
    }

    if (deepCancelBtn) {
        deepCancelBtn.addEventListener('click', (event) => {
            event.preventDefault();
            cancelDeepCheck();
        });
    }

    const showTab = (kind) => {
        tabButtons.forEach((btn) => {
            const k = btn.getAttribute('data-crowd-tab');
            if (k === kind) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        tabPanels.forEach((panel) => {
            const k = panel.getAttribute('data-crowd-tab-panel');
            if (k === kind) {
                panel.classList.add('show', 'active');
            } else {
                panel.classList.remove('show', 'active');
            }
        });
        try {
            localStorage.setItem('pp-crowd-tab', kind);
        } catch (error) {
            console.debug('[crowd] Unable to persist tab state', error);
        }
    };

    if (tabButtons && tabButtons.length) {
        tabButtons.forEach((btn) => {
            btn.addEventListener('click', () => showTab(btn.getAttribute('data-crowd-tab')));
        });
        let initialTab = 'simple';
        try {
            const saved = localStorage.getItem('pp-crowd-tab');
            if (saved === 'deep' || saved === 'simple') {
                initialTab = saved;
            }
        } catch (error) {
            console.debug('[crowd] Unable to read tab state', error);
        }
        if (deepInitialActive) {
            initialTab = 'deep';
        }
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
