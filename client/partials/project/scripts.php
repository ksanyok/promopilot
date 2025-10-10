<?php /* Project page scripts extracted from client/project.php */ ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            try {
                new bootstrap.Tooltip(tooltipTriggerEl);
            } catch (e) {
                console.error('Tooltip init failed', e);
            }
        });
    }
    // Move modals to body
    const projectInfoModalEl = document.getElementById('projectInfoModal');
    if (projectInfoModalEl && projectInfoModalEl.parentElement !== document.body) { document.body.appendChild(projectInfoModalEl); }
    const analyzeModalEl = document.getElementById('analyzeModal');
    if (analyzeModalEl && analyzeModalEl.parentElement !== document.body) { document.body.appendChild(analyzeModalEl); }
    const wishModalEl = document.getElementById('wishModal');
    if (wishModalEl && wishModalEl.parentElement !== document.body) { document.body.appendChild(wishModalEl); }
    const promotionReportModalEl = document.getElementById('promotionReportModal');
    if (promotionReportModalEl && promotionReportModalEl.parentElement !== document.body) { document.body.appendChild(promotionReportModalEl); }
    const addLinkModalEl = document.getElementById('addLinkModal');
    if (addLinkModalEl && addLinkModalEl.parentElement !== document.body) { document.body.appendChild(addLinkModalEl); }
    const promotionConfirmModalEl = document.getElementById('promotionConfirmModal');
    if (promotionConfirmModalEl && promotionConfirmModalEl.parentElement !== document.body) { document.body.appendChild(promotionConfirmModalEl); }
    const insufficientFundsModalEl = document.getElementById('insufficientFundsModal');
    if (insufficientFundsModalEl && insufficientFundsModalEl.parentElement !== document.body) { document.body.appendChild(insufficientFundsModalEl); }

    let pageUnloading = false;
    const markPageUnloading = () => {
        pageUnloading = true;
        try { stopPolling(); } catch (_) {}
    };
    window.addEventListener('beforeunload', markPageUnloading, { passive: true });
    window.addEventListener('pagehide', markPageUnloading, { passive: true });
    function formatPromotionStatusLabel(status) {
        if (!status) {
            return '';
        }
        return PROMOTION_STATUS_LABELS[status] || status;
    }

    function truncateText(value, limit) {
        if (value === null || value === undefined) {
            return '';
        }
        const str = String(value);
        if (!Number.isFinite(limit) || limit <= 0 || str.length <= limit) {
            return str;
        }
        return str.slice(0, Math.max(0, limit - 1)).trimEnd() + '…';
    }

    function statusBadgeHtml(statusKey, customLabel) {
        const key = statusKey ? String(statusKey) : '';
        const derivedLabel = customLabel && customLabel !== key ? customLabel : formatPromotionStatusLabel(key) || key;
        if (!derivedLabel) {
            return '';
        }
        const classes = STATUS_BADGE_CLASS_MAP[key] || 'bg-secondary-subtle text-secondary-emphasis';
        return `<span class="badge ${classes}">${escapeHtml(derivedLabel)}</span>`;
    }

    function formatNodeStatusLabel(status, manualFallback) {
        if (manualFallback) {
            return '<?php echo __('Ручной fallback'); ?>';
        }
        const map = {
            'success': '<?php echo __('Готово'); ?>',
            'completed': '<?php echo __('Готово'); ?>',
            'running': '<?php echo __('В процессе'); ?>',
            'pending': '<?php echo __('Ожидают запуска'); ?>',
            'queued': '<?php echo __('Ожидают запуска'); ?>',
            'created': '<?php echo __('Создан'); ?>',
            'failed': '<?php echo __('Ошибка'); ?>',
            'cancelled': '<?php echo __('Отменено'); ?>'
        };
        const key = status ? String(status) : '';
        return map[key] || key;
    }

    function formatCrowdStatus(status, manualFallback) {
        if (manualFallback) {
            return { key: 'manual_fallback', label: '<?php echo __('Ручной fallback'); ?>' };
        }
        const key = (status || '').toString().toLowerCase();
        const map = {
            'success': '<?php echo __('Готово'); ?>',
            'completed': '<?php echo __('Готово'); ?>',
            'running': '<?php echo __('В процессе'); ?>',
            'pending': '<?php echo __('Ожидают запуска'); ?>',
            'queued': '<?php echo __('Ожидают запуска'); ?>',
            'planned': '<?php echo __('Ожидают запуска'); ?>',
            'created': '<?php echo __('Создан'); ?>',
            'failed': '<?php echo __('Ошибка'); ?>',
            'error': '<?php echo __('Ошибка'); ?>',
            'cancelled': '<?php echo __('Отменено'); ?>'
        };
        return { key, label: map[key] || key };
    }

    function buildReportLinkHtml(url, text, options = {}) {
        const href = typeof url === 'string' ? url.trim() : '';
        if (!href) {
            return '—';
        }
        const labelText = text ? String(text) : truncateText(href, options.truncate || 80);
        const className = options.className ? escapeAttribute(options.className) : 'link-primary';
        const titleAttr = options.title ? ` title="${escapeAttribute(options.title)}"` : '';
        return `<a href="${escapeAttribute(href)}" class="${className}" target="_blank" rel="noopener"${titleAttr}>${escapeHtml(labelText)}</a>`;
    }

    function renderPromotionReportOverview(ctx) {
        const toNumber = (value) => Number.isFinite(value) ? value : 0;
        const listOrEmpty = (value) => Array.isArray(value) ? value : [];
        const levelsEnabled = ctx.levelsEnabled || {};
        const level1 = levelsEnabled.level1 === false ? [] : listOrEmpty(ctx.level1);
        const level2 = levelsEnabled.level2 === false ? [] : listOrEmpty(ctx.level2);
        const level3 = levelsEnabled.level3 === false ? [] : listOrEmpty(ctx.level3);
        const crowd = levelsEnabled.crowd === false ? [] : listOrEmpty(ctx.crowd);
        const countNetworks = (items) => {
            try {
                return (new Set(items.map(item => (item?.network || '').trim()).filter(Boolean))).size;
            } catch (_) {
                return 0;
            }
        };
        const totals = {
            level1: toNumber(level1.length),
            level2: toNumber(level2.length),
            level3: toNumber(level3.length),
            crowd: toNumber(crowd.length)
        };
        const cascadeTotal = totals.level1 + totals.level2 + totals.level3;
        const overallTotal = cascadeTotal + totals.crowd;
        const summaryRows = [];
        if (ctx.targetUrl) {
            summaryRows.push(`
                <div class="promotion-report-kv">
                    <span class="kv-label"><?php echo __('Целевая страница'); ?></span>
                    <span class="kv-value text-truncate" title="${escapeAttribute(ctx.targetUrl)}">
                        ${buildReportLinkHtml(ctx.targetUrl, truncateText(ctx.targetUrl, 64), { className: 'link-emphasis', title: ctx.targetUrl })}
                    </span>
                </div>
            `);
        }
        summaryRows.push(`
            <div class="promotion-report-kv">
                <span class="kv-label"><?php echo __('Текущий статус'); ?></span>
                <span class="kv-value">${statusBadgeHtml(ctx.statusKey, ctx.statusLabel)}</span>
            </div>
        `);
        summaryRows.push(`
            <div class="promotion-report-kv">
                <span class="kv-label"><?php echo __('Всего публикаций'); ?></span>
                <span class="kv-value">${escapeHtml(String(ctx.totalPublications))}</span>
            </div>
        `);
        summaryRows.push(`
            <div class="promotion-report-kv">
                <span class="kv-label"><?php echo __('Уникальных сетей'); ?></span>
                <span class="kv-value">${escapeHtml(String(ctx.uniqueNetworksCount))}</span>
            </div>
        `);
        if (levelsEnabled.crowd !== false) {
            summaryRows.push(`
                <div class="promotion-report-kv">
                    <span class="kv-label"><?php echo __('Крауд-задачи'); ?></span>
                    <span class="kv-value">${escapeHtml(String(totals.crowd))}</span>
                </div>
            `);
        }
        if (ctx.manualFallbackCount > 0) {
            summaryRows.push(`
                <div class="promotion-report-kv">
                    <span class="kv-label"><?php echo __('Ручной fallback'); ?></span>
                    <span class="kv-value">${escapeHtml(String(ctx.manualFallbackCount))}</span>
                </div>
            `);
        }

        const networksLabel = <?php echo json_encode(__('Сетей')); ?>;
        const tasksLabel = <?php echo json_encode(__('Задач')); ?>;
        const metrics = [];
        if (levelsEnabled.level1 !== false) {
            metrics.push({
                key: 'level1',
                label: '<?php echo __('Уровень 1'); ?>',
                subtitle: `${networksLabel}: ${countNetworks(level1) || 0}`,
                value: totals.level1
            });
        }
        if (levelsEnabled.level2 !== false) {
            metrics.push({
                key: 'level2',
                label: '<?php echo __('Уровень 2'); ?>',
                subtitle: `${networksLabel}: ${countNetworks(level2) || 0}`,
                value: totals.level2
            });
        }
        if (levelsEnabled.level3 !== false) {
            metrics.push({
                key: 'level3',
                label: '<?php echo __('Уровень 3'); ?>',
                subtitle: `${networksLabel}: ${countNetworks(level3) || 0}`,
                value: totals.level3
            });
        }
        if (levelsEnabled.crowd !== false) {
            metrics.push({
                key: 'crowd',
                label: '<?php echo __('Крауд'); ?>',
                subtitle: `${tasksLabel}: ${totals.crowd}`,
                value: totals.crowd
            });
        }
        const metricCardsHtml = metrics.map(metric => `
            <div class="promotion-report-metric-card metric-${metric.key}${metric.value ? '' : ' is-empty'}">
                <div class="metric-label">${escapeHtml(metric.label)}</div>
                <div class="metric-value">${escapeHtml(String(metric.value))}</div>
                <div class="metric-sub">${escapeHtml(metric.subtitle)}</div>
            </div>
        `).join('');

        const ratioSegments = metrics.filter(metric => metric.value > 0);
        const totalForRatio = metrics.reduce((acc, item) => acc + (item.value || 0), 0);
        const ratioBarHtml = ratioSegments.length
            ? ratioSegments.map((segment) => {
                const percent = totalForRatio > 0 ? (segment.value / totalForRatio) * 100 : 0;
                const width = percent > 0 ? Math.max(percent, 6) : 0;
                const percentLabel = percent > 0 ? `${percent.toFixed(percent >= 10 ? 0 : 1)}%` : '';
                return `<div class="promotion-report-ratio-segment metric-${segment.key}" style="--segment-width:${width}%;--segment-grow:${Math.max(segment.value || 1, 1)}">
                    <span class="segment-label">${escapeHtml(segment.label)}</span>
                    ${percentLabel ? `<span class="segment-value">${escapeHtml(percentLabel)}</span>` : ''}
                </div>`;
            }).join('')
            : `<div class="promotion-flow-empty mb-0"><?php echo __('Нет данных для расчета.'); ?></div>`;
        const ratioLegendHtml = metrics.map(metric => `
            <div class="promotion-report-ratio-legend-item metric-${metric.key}">
                <span class="legend-dot"></span>
                <span class="legend-label">${escapeHtml(metric.label)}</span>
                <span class="legend-value">${escapeHtml(String(metric.value))}</span>
            </div>
        `).join('');

        return `
            <div class="promotion-report-overview">
                <div class="promotion-report-hero card border-0 mb-3">
                    <div class="card-body">
                        <div class="hero-text">
                            <div class="hero-kicker text-uppercase small fw-semibold text-muted mb-1"><?php echo __('Быстрый обзор'); ?></div>
                            <h5 class="hero-title mb-3"><?php echo __('Ключевые цифры по каскаду и статусу.'); ?></h5>
                            <p class="hero-description text-muted mb-4"><?php echo __('Сохраните отчет, чтобы отправить клиенту или сохранить для аудита.'); ?></p>
                        </div>
                        <div class="promotion-report-summary-grid">
                            ${summaryRows.join('')}
                        </div>
                    </div>
                </div>
                <div class="promotion-report-metric-grid">
                    ${metricCardsHtml}
                </div>
                <div class="promotion-report-ratio card border-0 mt-3">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                            <div>
                                <div class="text-uppercase small fw-semibold text-muted mb-1"><?php echo __('Распределение уровней'); ?></div>
                                <h6 class="mb-0"><?php echo __('Диаграмма загрузки по каскаду и крауду.'); ?></h6>
                            </div>
                            <div class="promotion-report-ratio-legend">
                                ${ratioLegendHtml}
                            </div>
                        </div>
                        <div class="promotion-report-ratio-bar">
                            ${ratioBarHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function renderPromotionReportCascade(ctx) {
        const levelsEnabled = ctx.levelsEnabled || {};
        const level1Enabled = levelsEnabled.level1 !== false;
        const level2Enabled = levelsEnabled.level2 !== false;
        const level3Enabled = levelsEnabled.level3 !== false;
        const crowdEnabled = levelsEnabled.crowd !== false;
        const level1 = level1Enabled && Array.isArray(ctx.level1) ? ctx.level1 : [];
        const level2 = level2Enabled && Array.isArray(ctx.level2) ? ctx.level2 : [];
        const level3 = level3Enabled && Array.isArray(ctx.level3) ? ctx.level3 : [];
        const crowd = crowdEnabled && Array.isArray(ctx.crowd) ? ctx.crowd : [];

        if (!level1Enabled && !level2Enabled && !level3Enabled && !crowdEnabled) {
            return `<div class="promotion-flow-empty"><?php echo __('Нет данных для расчета.'); ?></div>`;
        }

        if (!level1.length && !level2.length && !level3.length && !crowd.length) {
            return `<div class="promotion-flow-empty"><?php echo __('Нет данных для расчета.'); ?></div>`;
        }

        const level2ByParent = {};
        const level2Index = {};
        level2.forEach(item => {
            const parentId = item?.parent_id ?? null;
            if (!level2ByParent[parentId]) {
                level2ByParent[parentId] = [];
            }
            level2ByParent[parentId].push(item);
            if (item?.id !== undefined && item?.id !== null) {
                level2Index[item.id] = item;
            }
        });

        const level3ByParent = {};
        level3.forEach(item => {
            const parentId = item?.parent_id ?? null;
            if (!level3ByParent[parentId]) {
                level3ByParent[parentId] = [];
            }
            level3ByParent[parentId].push(item);
        });

        function getRootParentId(level2Id) {
            const parent = level2Index[level2Id];
            if (!parent) {
                return null;
            }
            const root = parent?.parent_id;
            return root !== undefined && root !== null ? String(root) : null;
        }

        function makeCard(entry, level, extra = {}) {
            const classes = ['promotion-flow-card'];
            if (level === 'crowd') {
                classes.push('level-crowd');
            } else {
                classes.push(`level-${level}`);
            }
            if (extra.clickable) {
                classes.push('is-clickable');
            }
            const dataAttrs = [];
            if (entry && entry.id !== undefined && entry.id !== null) {
                dataAttrs.push(`data-id="${escapeAttribute(String(entry.id))}"`);
            }
            if (extra.parentId !== undefined && extra.parentId !== null && extra.parentId !== '') {
                dataAttrs.push(`data-parent-id="${escapeAttribute(String(extra.parentId))}"`);
            }
            if (extra.rootParentId !== undefined && extra.rootParentId !== null && extra.rootParentId !== '') {
                dataAttrs.push(`data-root-parent-id="${escapeAttribute(String(extra.rootParentId))}"`);
            }
            const classesAttr = classes.join(' ');
            const dataAttr = dataAttrs.length ? ' ' + dataAttrs.join(' ') : '';
            const network = entry?.network || (level === 'crowd' ? '<?php echo __('Крауд'); ?>' : '—');
            const manualFallback = !!entry?.manual_fallback;
            const nodeStatus = level === 'crowd'
                ? formatCrowdStatus(entry?.status, manualFallback)
                : { key: entry?.status || '', label: formatNodeStatusLabel(entry?.status, manualFallback) };
            const badge = statusBadgeHtml(nodeStatus.key || entry?.status, nodeStatus.label);
            const linkUrl = entry?.url || entry?.result_url || entry?.link_url || entry?.crowd_url || '';
            const linkHtml = linkUrl
                ? buildReportLinkHtml(linkUrl, truncateText(linkUrl, 70), { className: 'card-link', title: linkUrl })
                : '<span class="card-link text-muted">—</span>';
            const metaParts = [];
            if (entry?.anchor) {
                metaParts.push(escapeHtml(truncateText(entry.anchor, 110)));
            } else if (entry?.message) {
                metaParts.push(escapeHtml(truncateText(entry.message, 110)));
            }
            if (entry?.target_url && level !== 'crowd') {
                metaParts.push(escapeHtml(truncateText(entry.target_url, 110)));
            }
            if (entry?.fallback_reason) {
                metaParts.push(`<span class="text-danger">${escapeHtml(truncateText(entry.fallback_reason, 100))}</span>`);
            }
            if (entry?.updated_at) {
                metaParts.push(`<span class="text-muted">${escapeHtml(truncateText(entry.updated_at, 32))}</span>`);
            }
            const metaHtml = metaParts.length ? `<div class="card-meta">${metaParts.join('<br>')}</div>` : '';
            return `<div class="${classesAttr}"${dataAttr}>
                <div class="card-title d-flex justify-content-between align-items-start gap-2">
                    <span>${escapeHtml(network)}</span>
                    ${badge}
                </div>
                ${linkHtml}
                ${metaHtml}
            </div>`;
        }

        const buildColumn = (key, title, subtitle, bodyHtml, count) => `
            <div class="promotion-flow-column" data-flow-level="${escapeAttribute(key)}">
                <div class="promotion-flow-header">
                    <span class="title">${escapeHtml(title)}</span>
                    <span class="text-muted small">${escapeHtml(String(count))}</span>
                    ${subtitle ? `<span class="subtitle text-muted small">${escapeHtml(subtitle)}</span>` : ''}
                </div>
                <div class="promotion-flow-body">
                    ${bodyHtml}
                </div>
            </div>
        `;

        const columns = [];

        if (level1Enabled) {
            const body = level1.length
                ? level1.map(entry => {
                    const hasChildren = (Array.isArray(level2ByParent[entry?.id]) && level2ByParent[entry.id].length > 0) ||
                        level3.some(node => {
                            const parentId = node?.parent_id;
                            const parent = parentId !== undefined && parentId !== null ? level2Index[parentId] : null;
                            return parent && parent.parent_id === entry?.id;
                        });
                    return makeCard(entry, 1, { clickable: hasChildren });
                }).join('')
                : `<div class="promotion-flow-empty"><?php echo __('Записей не найдено.'); ?></div>`;
            columns.push(buildColumn('1', '<?php echo __('Уровень 1'); ?>', '<?php echo __('Прямые публикации на целевой ресурс.'); ?>', body, level1.length));
        }

        if (level2Enabled) {
            const body = level2.length
                ? level2.map(entry => {
                    const hasChildren = Array.isArray(level3ByParent[entry?.id]) && level3ByParent[entry.id].length > 0;
                    const parentId = entry?.parent_id ?? '';
                    return makeCard(entry, 2, { clickable: hasChildren, parentId, rootParentId: parentId });
                }).join('')
                : `<div class="promotion-flow-empty"><?php echo __('Для этого уровня нет вложенных публикаций.'); ?></div>`;
            columns.push(buildColumn('2', '<?php echo __('Уровень 2'); ?>', '<?php echo __('Публикации, ссылающиеся на уровень 1.'); ?>', body, level2.length));
        }

        if (level3Enabled) {
            const body = level3.length
                ? level3.map(entry => {
                    const parentId = entry?.parent_id ?? '';
                    const rootParentId = parentId ? getRootParentId(parentId) : null;
                    return makeCard(entry, 3, { parentId, rootParentId });
                }).join('')
                : `<div class="promotion-flow-empty"><?php echo __('Для этого уровня нет вложенных публикаций.'); ?></div>`;
            columns.push(buildColumn('3', '<?php echo __('Уровень 3'); ?>', '<?php echo __('Поддерживающие публикации для уровня 2.'); ?>', body, level3.length));
        }

        if (crowdEnabled) {
            const body = crowd.length
                ? crowd.map(entry => makeCard(entry, 'crowd')).join('')
                : `<div class="promotion-flow-empty"><?php echo __('Записей не найдено.'); ?></div>`;
            columns.push(buildColumn('crowd', '<?php echo __('Крауд'); ?>', '<?php echo __('Закрепленные крауд-публикации.'); ?>', body, crowd.length));
        }

        if (!columns.length) {
            return `<div class="promotion-flow-empty"><?php echo __('Нет данных для расчета.'); ?></div>`;
        }

        return `
            <div class="promotion-report-flow" data-report-flow>
                ${columns.join('')}
            </div>
        `;
    }

    function renderPromotionReportTableSection(title, entries, columns, options = {}) {
        const safeTitle = escapeHtml(title || '');
        const anchorAttr = options.anchor ? ` data-report-anchor="${escapeAttribute(options.anchor)}"` : '';
        const extraClass = options.className ? ` ${options.className}` : '';
        const count = Array.isArray(entries) ? entries.length : 0;
        const description = options.description ? `<p class="section-description text-muted mb-0">${escapeHtml(options.description)}</p>` : '';
        const kicker = options.kicker ? `<div class="section-kicker text-uppercase small fw-semibold text-muted mb-1">${escapeHtml(options.kicker)}</div>` : '';
        const iconHtml = options.icon ? `<span class="section-icon"><i class="bi ${escapeAttribute(options.icon)}"></i></span>` : '';
        const headerHtml = `
            <div class="promotion-report-section-head d-flex align-items-start gap-3">
                ${iconHtml}
                <div class="section-headline flex-grow-1">
                    ${kicker}
                    <div class="d-flex align-items-center flex-wrap gap-2 section-headline-row">
                        <h6 class="section-title mb-0">${safeTitle}</h6>
                        <span class="section-count badge bg-secondary-subtle text-secondary-emphasis">${escapeHtml(String(count))}</span>
                    </div>
                    ${description}
                </div>
            </div>
        `;

        if (!Array.isArray(entries) || count === 0) {
            return `<section class="promotion-report-section mb-4${extraClass}"${anchorAttr}>
                ${headerHtml}
                <div class="promotion-report-table-card promotion-report-table-card--empty">
                    <div class="promotion-flow-empty mb-0"><?php echo __('Записей не найдено.'); ?></div>
                </div>
            </section>`;
        }

        const tableHead = columns.map(col => {
            const thClass = col.className ? ` class="${escapeAttribute(col.className)}"` : '';
            return `<th${thClass}>${escapeHtml(col.label || '')}</th>`;
        }).join('');

        const tableBody = entries.map(entry => `<tr>${columns.map(col => {
            const cellClass = col.className ? ` class="${escapeAttribute(col.className)}"` : '';
            const value = col.render ? col.render(entry) : '';
            return `<td${cellClass}>${value}</td>`;
        }).join('')}</tr>`).join('');

        return `<section class="promotion-report-section mb-4${extraClass}"${anchorAttr}>
            ${headerHtml}
            <div class="promotion-report-table-card">
                <div class="table-responsive">
                    <table class="table table-sm promotion-report-table">
                        <thead><tr>${tableHead}</tr></thead>
                        <tbody>${tableBody}</tbody>
                    </table>
                </div>
            </div>
        </section>`;
    }

    function renderPromotionReportTables(ctx) {
        const levelsEnabled = ctx.levelsEnabled || {};
        const showLevel1 = levelsEnabled.level1 !== false;
        const showLevel2 = levelsEnabled.level2 !== false;
        const showLevel3 = levelsEnabled.level3 !== false;
        const showCrowd = levelsEnabled.crowd !== false;

        const level1 = showLevel1 && Array.isArray(ctx.level1) ? ctx.level1 : [];
        const level2 = showLevel2 && Array.isArray(ctx.level2) ? ctx.level2 : [];
        const level3 = showLevel3 && Array.isArray(ctx.level3) ? ctx.level3 : [];
        const crowd = showCrowd && Array.isArray(ctx.crowd) ? ctx.crowd : [];

        const level1Map = new Map();
        level1.forEach(item => {
            if (item && item.id !== undefined && item.id !== null) {
                level1Map.set(String(item.id), item);
            }
        });
        const level2Map = new Map();
        level2.forEach(item => {
            if (item && item.id !== undefined && item.id !== null) {
                level2Map.set(String(item.id), item);
            }
        });

        const renderPublicationCell = (row, options = {}) => {
            const url = row?.url || row?.result_url || row?.link_url || '';
            const link = url ? buildReportLinkHtml(url, truncateText(url, 70), { title: url }) : '<span class="text-muted">—</span>';
            const meta = [];
            if (options.includeNetwork !== false && row?.network) {
                meta.push(`<span class="cell-chip cell-chip--network">${escapeHtml(truncateText(row.network, 46))}</span>`);
            }
            if (row?.id !== undefined && row?.id !== null) {
                meta.push(`<span class="cell-subtle">ID ${escapeHtml(String(row.id))}</span>`);
            }
            return `<div class="cell-stack">${link}${meta.join('')}</div>`;
        };

        const renderTargetCell = (url) => {
            if (!url) {
                return '<span class="text-muted">—</span>';
            }
            const host = hostFromUrl(url);
            const meta = host ? `<span class="cell-subtle">${escapeHtml(host)}</span>` : '';
            return `<div class="cell-stack">${buildReportLinkHtml(url, truncateText(url, 70), { title: url })}${meta}</div>`;
        };

        const renderAnchorCell = (anchor) => {
            if (!anchor) {
                return '<span class="text-muted">—</span>';
            }
            return `<div class="cell-stack"><span class="anchor-pill" title="${escapeAttribute(anchor)}">${escapeHtml(truncateText(anchor, 120))}</span></div>`;
        };

        const renderParentCell = (parentId, map, label) => {
            if (parentId === undefined || parentId === null) {
                return '<span class="text-muted">—</span>';
            }
            const key = String(parentId);
            const parent = map.get(key) || map.get(parentId);
            const pieces = [`<span class="cell-chip chip-neutral">${escapeHtml(label)} #${escapeHtml(key)}</span>`];
            if (parent) {
                const parentUrl = parent?.url || parent?.result_url || '';
                if (parentUrl) {
                    pieces.push(buildReportLinkHtml(parentUrl, truncateText(parentUrl, 60), { title: parentUrl }));
                }
                if (parent?.network) {
                    pieces.push(`<span class="cell-chip cell-chip--network">${escapeHtml(truncateText(parent.network, 46))}</span>`);
                }
            }
            return `<div class="cell-stack">${pieces.join('')}</div>`;
        };

        const renderStatusCell = (row) => {
            const manualFallback = !!row?.manual_fallback;
            const label = formatNodeStatusLabel(row?.status, manualFallback);
            const badge = statusBadgeHtml(row?.status, label);
            const extras = [];
            if (manualFallback) {
                extras.push('<span class="cell-chip chip-neutral"><?php echo __('Ручной fallback'); ?></span>');
            }
            if (row?.fallback_reason) {
                extras.push(`<span class="cell-note text-danger">${escapeHtml(truncateText(row.fallback_reason, 120))}</span>`);
            }
            if (row?.updated_at) {
                extras.push(`<span class="cell-subtle">${escapeHtml(truncateText(row.updated_at, 32))}</span>`);
            }
            return `<div class="cell-stack">${badge || ''}${extras.join('')}</div>`;
        };

        const levelColumns = [
            {
                label: '<?php echo __('Публикация'); ?>',
                className: 'col-publication',
                render: row => renderPublicationCell(row)
            },
            {
                label: '<?php echo __('Целевая ссылка'); ?>',
                className: 'col-target',
                render: row => renderTargetCell(row?.target_url || '')
            },
            {
                label: '<?php echo __('Анкор'); ?>',
                className: 'col-anchor',
                render: row => renderAnchorCell(row?.anchor)
            },
            {
                label: '<?php echo __('Статус'); ?>',
                className: 'col-status',
                render: row => renderStatusCell(row)
            }
        ];

        const parentLabelLevel1 = '<?php echo __('Родитель (уровень 1)'); ?>';
        const parentLabelLevel2 = '<?php echo __('Родитель (уровень 2)'); ?>';

        const level2Columns = [
            {
                label: '<?php echo __('Публикация'); ?>',
                className: 'col-publication',
                render: row => renderPublicationCell(row)
            },
            {
                label: parentLabelLevel1,
                className: 'col-parent',
                render: row => renderParentCell(row?.parent_id, level1Map, '<?php echo __('Уровень 1'); ?>')
            },
            {
                label: '<?php echo __('Целевая ссылка'); ?>',
                className: 'col-target',
                render: row => renderTargetCell(row?.target_url || '')
            },
            {
                label: '<?php echo __('Анкор'); ?>',
                className: 'col-anchor',
                render: row => renderAnchorCell(row?.anchor)
            },
            {
                label: '<?php echo __('Статус'); ?>',
                className: 'col-status',
                render: row => renderStatusCell(row)
            }
        ];

        const level3Columns = [
            {
                label: '<?php echo __('Публикация'); ?>',
                className: 'col-publication',
                render: row => renderPublicationCell(row)
            },
            {
                label: parentLabelLevel2,
                className: 'col-parent',
                render: row => renderParentCell(row?.parent_id, level2Map, '<?php echo __('Уровень 2'); ?>')
            },
            {
                label: '<?php echo __('Целевая ссылка'); ?>',
                className: 'col-target',
                render: row => renderTargetCell(row?.target_url || '')
            },
            {
                label: '<?php echo __('Анкор'); ?>',
                className: 'col-anchor',
                render: row => renderAnchorCell(row?.anchor)
            },
            {
                label: '<?php echo __('Статус'); ?>',
                className: 'col-status',
                render: row => renderStatusCell(row)
            }
        ];

        const crowdColumns = [
            {
                label: '<?php echo __('Публикация'); ?>',
                className: 'col-publication',
                render: row => {
                    const value = renderPublicationCell({ ...row, id: row?.task_id }, { includeNetwork: false });
                    return value;
                }
            },
            {
                label: '<?php echo __('Целевая ссылка'); ?>',
                className: 'col-target',
                render: row => renderTargetCell(row?.target_url || '')
            },
            {
                label: '<?php echo __('Статус'); ?>',
                className: 'col-status',
                render: row => {
                    const state = formatCrowdStatus(row?.status, row?.manual_fallback);
                    const badge = statusBadgeHtml(state.key || row?.status, state.label || row?.status);
                    const extras = [];
                    if (row?.manual_fallback) {
                        extras.push('<span class="cell-chip chip-neutral"><?php echo __('Ручной fallback'); ?></span>');
                    }
                    if (row?.updated_at) {
                        extras.push(`<span class="cell-subtle">${escapeHtml(truncateText(row.updated_at, 32))}</span>`);
                    }
                    return `<div class="cell-stack">${badge}${extras.join('')}</div>`;
                }
            },
            {
                label: '<?php echo __('Комментарий'); ?>',
                className: 'col-comment',
                render: row => {
                    const fragments = [];
                    if (row?.subject) {
                        fragments.push(`<div class="fw-semibold">${escapeHtml(truncateText(row.subject, 80))}</div>`);
                    }
                    if (row?.message) {
                        fragments.push(`<div class="text-muted small">${escapeHtml(truncateText(row.message, 140))}</div>`);
                    }
                    if (row?.fallback_reason) {
                        fragments.push(`<div class="text-danger small">${escapeHtml(truncateText(row.fallback_reason, 140))}</div>`);
                    }
                    if (row?.author_name || row?.author_email) {
                        const name = row?.author_name ? escapeHtml(truncateText(row.author_name, 60)) : '';
                        const email = row?.author_email ? escapeHtml(row.author_email) : '';
                        const tail = [name, email].filter(Boolean).join(' • ');
                        if (tail) {
                            fragments.push(`<div class="text-muted small">${tail}</div>`);
                        }
                    }
                    return fragments.length ? `<div class="cell-stack">${fragments.join('')}</div>` : '<span class="text-muted">—</span>';
                }
            }
        ];

        const sections = [];
        if (showLevel1 && level1.length) {
            sections.push(renderPromotionReportTableSection('<?php echo __('Публикации уровня 1'); ?>', level1, levelColumns, {
                anchor: 'table-level1',
                icon: 'bi-1-circle',
                description: '<?php echo __('Прямые публикации на целевой ресурс.'); ?>'
            }));
        }
        if (showLevel2 && level2.length) {
            sections.push(renderPromotionReportTableSection('<?php echo __('Публикации уровня 2'); ?>', level2, level2Columns, {
                anchor: 'table-level2',
                icon: 'bi-diagram-2',
                description: '<?php echo __('Публикации, ссылающиеся на уровень 1.'); ?>'
            }));
        }
        if (showLevel3 && level3.length) {
            sections.push(renderPromotionReportTableSection('<?php echo __('Публикации уровня 3'); ?>', level3, level3Columns, {
                anchor: 'table-level3',
                icon: 'bi-diagram-3',
                description: '<?php echo __('Поддерживающие публикации для уровня 2.'); ?>'
            }));
        }
        if (showCrowd && crowd.length) {
            sections.push(renderPromotionReportTableSection('<?php echo __('Крауд-задачи'); ?>', crowd, crowdColumns, {
                anchor: 'table-crowd',
                icon: 'bi-people',
                description: '<?php echo __('Закрепленные крауд-публикации.'); ?>'
            }));
        }

        if (!sections.length) {
            return `<div class="promotion-flow-empty"><?php echo __('Нет данных для расчета.'); ?></div>`;
        }
        return sections.join('');
    }

    function renderPromotionReportContent(data) {
        if (!data || !data.ok) {
            const message = (data && data.error) ? String(data.error) : '<?php echo __('Не удалось загрузить отчет.'); ?>';
            return `<div class="alert alert-danger" role="alert">${escapeHtml(message)}</div>`;
        }
        const report = data.report && typeof data.report === 'object' ? data.report : {};
        const levelsEnabled = data.levels_enabled && typeof data.levels_enabled === 'object' ? data.levels_enabled : {};
        const level1Raw = Array.isArray(report.level1) ? report.level1 : [];
        const level2Raw = Array.isArray(report.level2) ? report.level2 : [];
        const level3Raw = Array.isArray(report.level3) ? report.level3 : [];
        const crowdRaw = Array.isArray(report.crowd) ? report.crowd : [];
        const level1 = levelsEnabled.level1 === false ? [] : level1Raw;
        const level2 = levelsEnabled.level2 === false ? [] : level2Raw;
        const level3 = levelsEnabled.level3 === false ? [] : level3Raw;
        const crowd = levelsEnabled.crowd === false ? [] : crowdRaw;
        const allNetworks = [...level1, ...level2, ...level3]
            .map(item => (item?.network || '').trim())
            .filter(Boolean);
        const uniqueNetworksCount = (new Set(allNetworks)).size;
        const totalPublications = level1.length + level2.length + level3.length;
        const manualFallbackCount = crowd.filter(task => task && task.manual_fallback).length;
        const targetUrl = typeof data.target_url === 'string' ? data.target_url : '';
        const statusKey = data.status ? String(data.status) : '';
        const statusLabel = formatPromotionStatusLabel(statusKey);
        const context = {
            targetUrl,
            statusKey,
            statusLabel,
            level1,
            level2,
            level3,
            crowd,
            totalPublications,
            uniqueNetworksCount,
            manualFallbackCount,
            levelsEnabled
        };
        const overviewHtml = renderPromotionReportOverview(context);
        const cascadeHtml = renderPromotionReportCascade(context);
        const tablesHtml = renderPromotionReportTables(context);
        const toolbarActions = [];
        const quickLinks = [];
        const navLevels = levelsEnabled;
        if (navLevels.level1 !== false && level1.length) {
            quickLinks.push(`<button type="button" class="dropdown-item" data-report-jump="table-level1"><?php echo __('Уровень 1'); ?></button>`);
        }
        if (navLevels.level2 !== false && level2.length) {
            quickLinks.push(`<button type="button" class="dropdown-item" data-report-jump="table-level2"><?php echo __('Уровень 2'); ?></button>`);
        }
        if (navLevels.level3 !== false && level3.length) {
            quickLinks.push(`<button type="button" class="dropdown-item" data-report-jump="table-level3"><?php echo __('Уровень 3'); ?></button>`);
        }
        if (navLevels.crowd !== false && crowd.length) {
            quickLinks.push(`<button type="button" class="dropdown-item" data-report-jump="table-crowd"><?php echo __('Крауд'); ?></button>`);
        }
        if (level1.length || level2.length || level3.length || crowd.length) {
            quickLinks.unshift(`<button type="button" class="dropdown-item" data-report-jump="top"><?php echo __('К началу'); ?></button>`);
        }
        if (quickLinks.length) {
            toolbarActions.push(`
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-lightning-charge me-1"></i><?php echo __('Быстрые переходы'); ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        ${quickLinks.join('')}
                    </div>
                </div>
            `);
        }
        if (targetUrl) {
            toolbarActions.push(`<a class="btn btn-outline-secondary btn-sm" href="${escapeAttribute(targetUrl)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?php echo __('Открыть страницу'); ?></a>`);
        }
        toolbarActions.push(`<button type="button" class="btn btn-outline-secondary btn-sm" data-report-action="download-json"><i class="bi bi-filetype-json me-1"></i><?php echo __('Экспорт JSON'); ?></button>`);
        toolbarActions.push(`<button type="button" class="btn btn-outline-secondary btn-sm" data-report-action="download-csv"><i class="bi bi-table me-1"></i><?php echo __('Экспорт CSV'); ?></button>`);
        toolbarActions.push(`<button type="button" class="btn btn-outline-secondary btn-sm" data-report-action="copy-json"><i class="bi bi-clipboard-check me-1"></i><?php echo __('Скопировать JSON'); ?></button>`);
        const toolbarActionsHtml = toolbarActions.length ? `<div class="promotion-report-actions">${toolbarActions.join('')}</div>` : '';
        const inProgress = Array.isArray(PROMOTION_ACTIVE_STATUSES) && statusKey
            ? PROMOTION_ACTIVE_STATUSES.includes(statusKey)
            : false;
        const infoHtml = inProgress
            ? `<div class="alert alert-info small mb-3" role="alert"><i class="bi bi-hourglass-split me-2"></i><?php echo __('Запуск еще выполняется'); ?></div>`
            : '';
        return `
            <div class="promotion-report-wrapper" data-report-wrapper data-run-status="${escapeHtml(statusKey)}">
                <div class="promotion-report-toolbar">
                    <div class="promotion-report-tabs" role="tablist">
                        <button type="button" class="promotion-report-tab active" data-report-view="overview">
                            <i class="bi bi-speedometer2"></i>
                            <span><?php echo __('Обзор'); ?></span>
                        </button>
                        <button type="button" class="promotion-report-tab" data-report-view="cascade">
                            <i class="bi bi-diagram-3"></i>
                            <span><?php echo __('Каскад'); ?></span>
                        </button>
                        <button type="button" class="promotion-report-tab" data-report-view="tables">
                            <i class="bi bi-table"></i>
                            <span><?php echo __('Таблицы'); ?></span>
                        </button>
                    </div>
                    ${toolbarActionsHtml}
                </div>
                ${infoHtml}
                <div class="promotion-report-views">
                    <div class="promotion-report-view" data-report-view-panel="overview">${overviewHtml}</div>
                    <div class="promotion-report-view d-none" data-report-view-panel="cascade">${cascadeHtml}</div>
                    <div class="promotion-report-view d-none" data-report-view-panel="tables">${tablesHtml}</div>
                </div>
            </div>
        `;
    }

    function initPromotionReportInteractions(root, data) {
        if (!root) {
            return;
        }
        const viewButtons = Array.from(root.querySelectorAll('[data-report-view]'));
        const panels = Array.from(root.querySelectorAll('[data-report-view-panel]'));
        const setView = (view) => {
            panels.forEach(panel => {
                panel.classList.toggle('d-none', panel.getAttribute('data-report-view-panel') !== view);
            });
            viewButtons.forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-report-view') === view);
            });
        };
        if (viewButtons.length) {
            setView(viewButtons[0].getAttribute('data-report-view'));
        }
        viewButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.getAttribute('data-report-view');
                if (!view) {
                    return;
                }
                setView(view);
            });
        });

        const escapeSelector = (value) => {
            if (typeof value !== 'string') {
                return '';
            }
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(value);
            }
            return value.replace(/[^a-zA-Z0-9_-]/g, (match) => `\\${match}`);
        };

        const scrollToAnchor = (anchorId) => {
            if (!anchorId) {
                return;
            }
            const wrapper = root.querySelector('[data-report-wrapper]') || root;
            const target = anchorId === 'top'
                ? wrapper
                : root.querySelector(`[data-report-anchor="${escapeSelector(anchorId)}"]`);
            if (!target) {
                return;
            }
            try {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (_) {
                target.scrollIntoView(true);
            }
        };
        root.querySelectorAll('[data-report-jump]').forEach(btn => {
            btn.addEventListener('click', () => {
                const anchor = btn.getAttribute('data-report-jump') || '';
                if (!anchor) {
                    return;
                }
                const dropdownMenu = btn.closest('.dropdown-menu');
                if (dropdownMenu && window.bootstrap) {
                    const dropdownInstance = bootstrap.Dropdown.getInstance(dropdownMenu.previousElementSibling) || bootstrap.Dropdown.getOrCreateInstance(dropdownMenu.previousElementSibling);
                    dropdownInstance?.hide?.();
                }
                if (anchor !== 'top') {
                    setView('tables');
                }
                scrollToAnchor(anchor);
            });
        });

        const flowEl = root.querySelector('[data-report-flow]');
        if (flowEl) {
            const level1Cards = Array.from(flowEl.querySelectorAll('.promotion-flow-card.level-1'));
            const level2Cards = Array.from(flowEl.querySelectorAll('.promotion-flow-card.level-2'));
            const level3Cards = Array.from(flowEl.querySelectorAll('.promotion-flow-card.level-3'));
            let activeLevel1 = null;
            let activeLevel2 = null;

            const clearLevel2Highlight = () => {
                activeLevel2 = null;
                level2Cards.forEach(card => card.classList.remove('is-active', 'is-dimmed'));
                level3Cards.forEach(card => card.classList.remove('is-dimmed'));
            };

            const applyLevel2Highlight = (level2Id) => {
                activeLevel2 = level2Id;
                level2Cards.forEach(card => {
                    const isCurrent = card.dataset.id === level2Id;
                    card.classList.toggle('is-active', isCurrent);
                });
                level3Cards.forEach(card => {
                    const parentId = card.dataset.parentId || '';
                    card.classList.toggle('is-dimmed', parentId !== level2Id);
                });
            };

            const clearLevel1Highlight = () => {
                activeLevel1 = null;
                flowEl.classList.remove('has-filter');
                level1Cards.forEach(card => card.classList.remove('is-active'));
                level2Cards.forEach(card => card.classList.remove('is-dimmed', 'is-active'));
                level3Cards.forEach(card => card.classList.remove('is-dimmed'));
                clearLevel2Highlight();
            };

            const applyLevel1Highlight = (level1Id) => {
                activeLevel1 = level1Id;
                flowEl.classList.add('has-filter');
                level1Cards.forEach(card => {
                    const match = card.dataset.id === level1Id;
                    card.classList.toggle('is-active', match);
                });
                level2Cards.forEach(card => {
                    const parentId = card.dataset.parentId || '';
                    const match = parentId === level1Id;
                    card.classList.toggle('is-dimmed', !match);
                    if (!match) {
                        card.classList.remove('is-active');
                    }
                });
                level3Cards.forEach(card => {
                    const rootParentId = card.dataset.rootParentId || '';
                    card.classList.toggle('is-dimmed', rootParentId !== level1Id);
                });
                clearLevel2Highlight();
            };

            level1Cards.forEach(card => {
                card.addEventListener('click', () => {
                    const id = card.dataset.id || '';
                    if (!id) {
                        return;
                    }
                    if (activeLevel1 === id) {
                        clearLevel1Highlight();
                    } else {
                        applyLevel1Highlight(id);
                    }
                });
            });

            level2Cards.forEach(card => {
                card.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const id = card.dataset.id || '';
                    if (!id) {
                        return;
                    }
                    if (!activeLevel1) {
                        const parentId = card.dataset.parentId || '';
                        if (parentId) {
                            applyLevel1Highlight(parentId);
                        }
                    }
                    if (activeLevel2 === id) {
                        clearLevel2Highlight();
                    } else {
                        applyLevel2Highlight(id);
                    }
                });
            });
        }

        const baseFileName = (ext) => {
            const parts = ['promotion', 'report'];
            if (data?.project_id) {
                parts.push(`project${data.project_id}`);
            }
            if (data?.run_id) {
                parts.push(`run${data.run_id}`);
            }
            return parts.join('-') + '.' + ext;
        };
        const triggerDownload = (content, filename, mime) => {
            try {
                const blob = new Blob([content], { type: mime || 'application/octet-stream' });
                const url = URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = filename;
                document.body.appendChild(anchor);
                anchor.click();
                setTimeout(() => {
                    document.body.removeChild(anchor);
                    URL.revokeObjectURL(url);
                }, 0);
            } catch (error) {
                console.error('Download failed', error);
            }
        };

        const csvSeparator = ';';
        const csvHeaders = [
            <?php echo json_encode(__('Уровень/тип')); ?>,
            <?php echo json_encode(__('ID')); ?>,
            <?php echo json_encode(__('Сеть')); ?>,
            <?php echo json_encode(__('Целевая страница')); ?>,
            <?php echo json_encode(__('Публикация')); ?>,
            <?php echo json_encode(__('Анкор/сообщение')); ?>,
            <?php echo json_encode(__('Родитель (уровень 1)')); ?>,
            <?php echo json_encode(__('Родитель (уровень 2)')); ?>,
            <?php echo json_encode(__('Ручной fallback')); ?>,
            <?php echo json_encode(__('Статус')); ?>,
            <?php echo json_encode(__('Комментарий')); ?>,
            <?php echo json_encode(__('Создано')); ?>,
            <?php echo json_encode(__('Обновлено')); ?>
        ];
        const boolYes = <?php echo json_encode(__('Да')); ?>;
        const boolNo = <?php echo json_encode(__('Нет')); ?>;
        const crowdLabel = '<?php echo __('Крауд'); ?>';
        const levelLabels = {
            level1: '<?php echo __('Уровень 1'); ?>',
            level2: '<?php echo __('Уровень 2'); ?>',
            level3: '<?php echo __('Уровень 3'); ?>',
            crowd: crowdLabel
        };
        const escapeCsvValue = (value) => {
            if (value === null || value === undefined) {
                return '';
            }
            const str = String(value);
            if (str === '') {
                return '';
            }
            return /[";\n\r]/.test(str) ? `"${str.replace(/"/g, '""')}"` : str;
        };
        const buildCsvContent = () => {
            const rows = [csvHeaders.map(escapeCsvValue).join(csvSeparator)];
            const report = data?.report || {};
            const levelsEnabledExport = data?.levels_enabled || {};
            const level1Rows = levelsEnabledExport.level1 === false ? [] : (Array.isArray(report.level1) ? report.level1 : []);
            const level2Rows = levelsEnabledExport.level2 === false ? [] : (Array.isArray(report.level2) ? report.level2 : []);
            const level3Rows = levelsEnabledExport.level3 === false ? [] : (Array.isArray(report.level3) ? report.level3 : []);
            const crowdRows = levelsEnabledExport.crowd === false ? [] : (Array.isArray(report.crowd) ? report.crowd : []);
            const level1Map = new Map();
            level1Rows.forEach(item => {
                if (item && item.id !== undefined && item.id !== null) {
                    level1Map.set(String(item.id), item);
                }
            });
            const level2Map = new Map();
            level2Rows.forEach(item => {
                if (item && item.id !== undefined && item.id !== null) {
                    level2Map.set(String(item.id), item);
                }
            });
            const collectAnchor = (entry) => entry?.anchor || entry?.message || entry?.title || '';
            const collectPublication = (entry) => entry?.url || entry?.result_url || entry?.link_url || entry?.crowd_url || '';
            const collectTarget = (entry) => entry?.target_url || entry?.article_url || '';
            const collectComment = (entry) => entry?.fallback_reason || entry?.error || entry?.comment || '';
            const collectCreated = (entry) => entry?.created_at || entry?.created || entry?.queued_at || entry?.planned_at || '';
            const collectUpdated = (entry) => entry?.updated_at || entry?.finished_at || entry?.published_at || entry?.completed_at || '';
            const describeParent = (entry, map, label) => {
                const parentId = entry?.parent_id;
                if (parentId === undefined || parentId === null) {
                    return '';
                }
                const key = String(parentId);
                const parent = map.get(key) || map.get(parentId);
                const parts = [`${label} #${key}`];
                const parentUrl = parent?.url || parent?.result_url || '';
                if (parentUrl) {
                    parts.push(parentUrl);
                }
                return parts.join(' | ');
            };
            const pushRows = (entries, key) => {
                const label = levelLabels[key] || key;
                entries.forEach((entry) => {
                    const manualFallback = entry?.manual_fallback ? boolYes : boolNo;
                    const statusInfo = key === 'crowd'
                        ? formatCrowdStatus(entry?.status, entry?.manual_fallback)
                        : { label: formatNodeStatusLabel(entry?.status, entry?.manual_fallback) };
                    const parentLevel1 = key === 'level2' || key === 'level3'
                        ? describeParent(entry, level1Map, '<?php echo __('Уровень 1'); ?>')
                        : '';
                    const parentLevel2 = key === 'level3'
                        ? describeParent(entry, level2Map, '<?php echo __('Уровень 2'); ?>')
                        : '';
                    const row = [
                        label,
                        entry?.id ?? entry?.task_id ?? '',
                        entry?.network || (key === 'crowd' ? crowdLabel : ''),
                        collectTarget(entry),
                        collectPublication(entry) || collectTarget(entry),
                        collectAnchor(entry),
                        parentLevel1,
                        parentLevel2,
                        manualFallback,
                        statusInfo.label || '',
                        collectComment(entry),
                        collectCreated(entry),
                        collectUpdated(entry)
                    ];
                    rows.push(row.map(escapeCsvValue).join(csvSeparator));
                });
            };
            pushRows(level1Rows, 'level1');
            pushRows(level2Rows, 'level2');
            pushRows(level3Rows, 'level3');
            pushRows(crowdRows, 'crowd');
            return rows.join('\r\n');
        };

        const markSuccess = (button, originalHtml) => {
            button.innerHTML = `<i class="bi bi-check2 me-1"></i><?php echo __('Готово'); ?>`;
            button.classList.remove('btn-outline-secondary', 'btn-danger');
            button.classList.add('btn-success');
            setTimeout(() => {
                button.innerHTML = originalHtml;
                button.classList.remove('btn-success');
                button.classList.add('btn-outline-secondary');
            }, 1800);
        };
        const markError = (button) => {
            button.classList.remove('btn-outline-secondary', 'btn-success');
            button.classList.add('btn-danger');
            setTimeout(() => {
                button.classList.remove('btn-danger');
                button.classList.add('btn-outline-secondary');
            }, 1500);
        };

        const downloadJsonButton = root.querySelector('[data-report-action="download-json"]');
        if (downloadJsonButton && data) {
            const originalHtml = downloadJsonButton.innerHTML;
            downloadJsonButton.addEventListener('click', () => {
                try {
                    const rawJson = JSON.stringify(data, null, 2);
                    triggerDownload(rawJson, baseFileName('json'), 'application/json;charset=utf-8');
                    markSuccess(downloadJsonButton, originalHtml);
                } catch (error) {
                    console.error('JSON export failed', error);
                    markError(downloadJsonButton);
                }
            }, { passive: true });
        }

        const downloadCsvButton = root.querySelector('[data-report-action="download-csv"]');
        if (downloadCsvButton && data) {
            const originalHtml = downloadCsvButton.innerHTML;
            downloadCsvButton.addEventListener('click', () => {
                try {
                    const csvContent = buildCsvContent();
                    triggerDownload(csvContent, baseFileName('csv'), 'text/csv;charset=utf-8');
                    markSuccess(downloadCsvButton, originalHtml);
                } catch (error) {
                    console.error('CSV export failed', error);
                    markError(downloadCsvButton);
                }
            }, { passive: true });
        }

        const copyButton = root.querySelector('[data-report-action="copy-json"]');
        if (copyButton && data) {
            const rawJson = JSON.stringify(data, null, 2);
            const originalHtml = copyButton.innerHTML;
            copyButton.addEventListener('click', async () => {
                try {
                    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                        window.prompt('<?php echo __('Скопировать JSON'); ?>', rawJson);
                        return;
                    }
                    await navigator.clipboard.writeText(rawJson);
                    markSuccess(copyButton, originalHtml);
                } catch (error) {
                    markError(copyButton);
                }
            });
        }
    }

    async function openPromotionReport(btn) {
        const runId = btn?.dataset.runId || '';
        if (!runId) {
            return;
        }
        const modalEl = document.getElementById('promotionReportModal');
        if (!modalEl || !window.bootstrap) {
            return;
        }
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        const content = modalEl.querySelector('#promotionReportContent');
        if (content) {
            content.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
        }
        modalInstance.show();
        const params = new URLSearchParams();
        params.set('project_id', String(PROJECT_ID));
        params.set('run_id', runId);
        try {
            const response = await fetch('<?php echo pp_url('public/promotion_report.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
            let payload = null;
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                payload = await response.json();
            } else {
                const text = await response.text();
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    if (content) {
                        content.innerHTML = text;
                    }
                    return;
                }
            }
            if (!response.ok || !payload) {
                const message = payload && payload.error ? payload.error : (response.status ? String(response.status) : '<?php echo __('Не удалось загрузить отчет.'); ?>');
                if (content) {
                    content.innerHTML = `<div class="alert alert-danger" role="alert">${escapeHtml(String(message))}</div>`;
                }
                return;
            }
            if (content) {
                if (typeof payload === 'object' && payload !== null) {
                    payload.run_id = runId;
                    if (!payload.generated_at) {
                        try {
                            payload.generated_at = new Date().toISOString();
                        } catch (_) {}
                    }
                }
                content.innerHTML = renderPromotionReportContent(payload);
                initPromotionReportInteractions(content, payload);
                initTooltips(content);
            }
        } catch (error) {
            if (content) {
                content.innerHTML = '<div class="alert alert-danger"><?php echo __('Не удалось загрузить отчет.'); ?></div>';
            }
        }
    }
    const form = document.getElementById('project-form');
    const addLinkBtn = document.getElementById('add-link');
    const addedHidden = document.getElementById('added-hidden');
    const newLinkInput = document.getElementById('new_link_input');
    const newAnchorInput = document.getElementById('new_anchor_input');
    const newAnchorStrategy = document.getElementById('new_anchor_strategy');
    const newLangSelect = document.getElementById('new_language_select');
    const anchorPresetContainer = document.getElementById('anchor-preset-list');
    const anchorPresetWrapper = anchorPresetContainer ? anchorPresetContainer.closest('[data-anchor-presets-wrapper]') : null;
    const newWish = document.getElementById('new_wish');
    const globalWish = document.getElementById('global_wishes');
    const useGlobal = document.getElementById('use_global_wish');
    const projectInfoForm = document.getElementById('project-info-form');
    let addIndex = 0;
    let activeAnchorPreset = null;
    let anchorUpdateLock = false;
    let linkTableManager = null;

    const insufficientAmountEl = insufficientFundsModalEl?.querySelector('[data-insufficient-amount]');
    const insufficientRequiredEl = insufficientFundsModalEl?.querySelector('[data-insufficient-required]');
    const insufficientBalanceEl = insufficientFundsModalEl?.querySelector('[data-insufficient-balance]');

    const getAddLinkModalInstance = () => {
        const modalEl = addLinkModalEl || document.getElementById('addLinkModal');
        if (!modalEl || !window.bootstrap) { return null; }
        return bootstrap.Modal.getOrCreateInstance(modalEl);
    };

    if (window.bootstrap) {
        document.querySelectorAll('.project-hero__action-add').forEach(btn => {
            btn.addEventListener('click', (event) => {
                const modalInstance = getAddLinkModalInstance();
                if (modalInstance) {
                    event.preventDefault();
                    modalInstance.show();
                }
            }, { passive: false });
        });
    }

    if (addLinkModalEl && window.bootstrap) {
        addLinkModalEl.addEventListener('shown.bs.modal', () => {
            if (newLinkInput) {
                newLinkInput.focus();
                newLinkInput.select();
            }
        });
    }

    const PROJECT_ID = <?php echo (int)$project['id']; ?>;
    const PROJECT_HOST = '<?php echo htmlspecialchars(pp_normalize_host($project['domain_host'] ?? '')); ?>';
    const PROJECT_LANGUAGE = '<?php echo htmlspecialchars(strtolower(trim((string)($project['language'] ?? 'ru'))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROJECT_NAME = <?php echo json_encode((string)($project['name'] ?? '')); ?>;
    const ANCHOR_PRESETS = <?php echo json_encode(pp_project_anchor_presets(), JSON_UNESCAPED_UNICODE); ?>;
    const PROMOTION_LEVELS_ENABLED = <?php echo json_encode([
        'level1' => function_exists('pp_promotion_is_level_enabled') ? pp_promotion_is_level_enabled(1) : true,
        'level2' => function_exists('pp_promotion_is_level_enabled') ? pp_promotion_is_level_enabled(2) : false,
        'level3' => function_exists('pp_promotion_is_level_enabled') ? pp_promotion_is_level_enabled(3) : false,
        'crowd' => function_exists('pp_promotion_is_crowd_enabled') ? pp_promotion_is_crowd_enabled() : false,
    ]); ?>;
    const PROMOTION_CHARGE_AMOUNT = <?php echo json_encode($promotionChargeAmount); ?>;
    const PROMOTION_CHARGE_AMOUNT_FORMATTED = '<?php echo htmlspecialchars($promotionChargeFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_CHARGE_BASE = <?php echo json_encode($promotionBasePrice); ?>;
    const PROMOTION_CHARGE_BASE_FORMATTED = '<?php echo htmlspecialchars($promotionBaseFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_DISCOUNT_PERCENT = <?php echo json_encode($userPromotionDiscount); ?>;
    const PROMOTION_CHARGE_SAVINGS = <?php echo json_encode($promotionChargeSavings); ?>;
    const PROMOTION_CHARGE_SAVINGS_FORMATTED = '<?php echo htmlspecialchars($promotionChargeSavingsFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_ACTIVE_STATUSES = <?php echo json_encode($promotionActiveStates); ?>;
    const PROMOTION_STATUS_LABELS = {
        'queued': '<?php echo __('Уровень 1 выполняется'); ?>',
        'pending_level1': '<?php echo __('Уровень 1 выполняется'); ?>',
        'running': '<?php echo __('Уровень 1 выполняется'); ?>',
        'level1_active': '<?php echo __('Уровень 1 выполняется'); ?>',
        'pending_level2': '<?php echo __('Ожидание уровня 2'); ?>',
        'level2_active': '<?php echo __('Уровень 2 выполняется'); ?>',
        'pending_level3': '<?php echo __('Ожидание уровня 3'); ?>',
        'level3_active': '<?php echo __('Уровень 3 выполняется'); ?>',
    'pending_crowd': '<?php echo __('Подготовка крауда'); ?>',
    'crowd_ready': '<?php echo __('Крауд выполняется'); ?>',
        'report_ready': '<?php echo __('Формируется отчет'); ?>',
        'completed': '<?php echo __('Завершено'); ?>',
        'failed': '<?php echo __('Ошибка продвижения'); ?>',
        'cancelled': '<?php echo __('Отменено'); ?>',
        'idle': '<?php echo __('Продвижение не запускалось'); ?>'
    };

    const previewRoot = document.querySelector('[data-project-preview]');
    if (previewRoot) {
        initProjectPreview(previewRoot);
    }

    function initProjectPreview(root) {
        const frameEl = root.querySelector('.project-hero__preview-frame');
        let imageEl = root.querySelector('[data-preview-image]');
        const placeholderEl = root.querySelector('[data-preview-placeholder]');
        const statusEl = root.querySelector('[data-preview-status]');
        const statusTextEl = statusEl ? statusEl.querySelector('[data-preview-status-text]') : null;
        const statusIconEl = statusEl ? statusEl.querySelector('i') : null;
        const refreshButtons = Array.from(root.querySelectorAll('[data-action="refresh-preview"]'));
        const overlayButton = root.querySelector('.project-hero__refresh--overlay');
        const endpoint = root.dataset.endpoint || '';
        const projectId = root.dataset.projectId || '';
        const csrfToken = root.dataset.csrf || '';
        const textSuccess = root.dataset.textSuccess || '';
        const textWarning = root.dataset.textWarning || '';
        const textPending = root.dataset.textPending || '';
    const textError = root.dataset.textError || '';
    const textProcessing = root.dataset.textProcessing || '';
    const textFallback = root.dataset.textFallback || '';
        const previewAlt = root.dataset.previewAlt || '';
        const autoRefresh = root.dataset.autoRefresh === '1';
        const staleSeconds = 60 * 60 * 24 * 3;
        let abortController = null;
        let isLoadingPreview = false;

        const statusIcons = {
            ok: 'bi-check-circle',
            success: 'bi-check-circle',
            warning: 'bi-exclamation-triangle',
            pending: 'bi-hourglass-split',
            processing: 'bi-arrow-repeat',
            error: 'bi-exclamation-octagon'
        };

        const handleImageError = () => {
            if (imageEl) {
                imageEl.classList.add('d-none');
            }
            if (placeholderEl) {
                placeholderEl.classList.remove('d-none');
            }
            root.dataset.hasPreview = '0';
            root.dataset.hasPreviewUrl = '0';
        };

        const appendCacheBust = (url) => {
            if (!url) { return ''; }
            const sep = url.includes('?') ? '&' : '?';
            return `${url}${sep}cb=${Date.now().toString(36)}`;
        };

        const setStatus = (type, message) => {
            if (!statusEl) { return; }
            const trimmed = (message || '').trim();
            statusEl.dataset.status = type || '';
            if (statusIconEl) {
                const iconClass = statusIcons[type] || 'bi-info-circle';
                statusIconEl.className = `bi ${iconClass}`;
            }
            if (statusTextEl) {
                statusTextEl.textContent = trimmed;
            }
            statusEl.classList.toggle('d-none', trimmed === '');
        };

        const formatStatusMessage = (template, value) => {
            if (!template) { return value || ''; }
            if (template.includes('%s')) {
                return template.replace('%s', value || '');
            }
            return template;
        };

        const toggleButtonsLoading = (flag) => {
            refreshButtons.forEach((btn) => {
                if (flag) {
                    btn.classList.add('is-loading');
                    btn.setAttribute('disabled', 'disabled');
                } else {
                    btn.classList.remove('is-loading');
                    btn.removeAttribute('disabled');
                }
            });
        };

        const setPlaceholderVisible = (visible) => {
            if (placeholderEl) {
                placeholderEl.classList.toggle('d-none', !visible);
            }
        };

        const ensureImageElement = () => {
            if (imageEl) {
                return imageEl;
            }
            if (!frameEl) {
                return null;
            }
            const img = document.createElement('img');
            img.className = 'project-hero__screenshot';
            img.loading = 'lazy';
            img.decoding = 'async';
            img.setAttribute('data-preview-image', '');
            img.alt = previewAlt;
            frameEl.insertBefore(img, overlayButton || frameEl.firstChild);
            imageEl = img;
            img.addEventListener('error', handleImageError);
            return img;
        };

        const applyPreview = (payload, options = {}) => {
            const data = payload && typeof payload === 'object' && !Array.isArray(payload) ? payload : null;
            const forced = !!options.forced;
            const url = data ? ((data.preview_url || data.url || data.fallback_url || '')).trim() : (typeof payload === 'string' ? payload.trim() : '');
            const modifiedAtRaw = data && Object.prototype.hasOwnProperty.call(data, 'modified_at') ? data.modified_at : options.modifiedAt;
            const modifiedAt = Number(modifiedAtRaw || 0) || 0;
            const modifiedHuman = data && Object.prototype.hasOwnProperty.call(data, 'modified_human')
                ? (data.modified_human !== null && data.modified_human !== undefined ? String(data.modified_human) : '')
                : (options.modifiedHuman || '');
            const fallbackUsed = data ? !!(data.fallback || data.preview_fallback) : !!options.fallback;
            const previewSource = data && data.preview_source ? String(data.preview_source) : (fallbackUsed ? 'external' : (root.dataset.previewSource || 'local'));
            const statusHuman = modifiedHuman || (modifiedAt ? formatDateTimeShort(modifiedAt) : '');

            if (!url) {
                handleImageError();
                root.dataset.previewSource = 'none';
                root.dataset.previewFallback = fallbackUsed ? '1' : '0';
                root.dataset.previewUpdatedAt = '0';
                root.dataset.previewUpdatedHuman = '';
                if (forced) {
                    setStatus('error', textError);
                } else {
                    setStatus('pending', textPending);
                }
                if (data && (data.error || data.fallback_reason)) {
                    console.warn('Project preview update failed', data.error || data.fallback_reason, data);
                }
                return;
            }

            const target = ensureImageElement();
            if (!target) {
                return;
            }

            target.classList.add('d-none');
            setPlaceholderVisible(true);

            const finalUrl = appendCacheBust(url);
            const onLoad = () => {
                target.removeEventListener('load', onLoad);
                target.removeEventListener('error', onError);
                target.classList.remove('d-none');
                setPlaceholderVisible(false);
                root.dataset.hasPreview = '1';
                root.dataset.hasPreviewUrl = '1';
            };
            const onError = () => {
                target.removeEventListener('load', onLoad);
                target.removeEventListener('error', onError);
                handleImageError();
                root.dataset.previewSource = 'none';
                root.dataset.previewFallback = fallbackUsed ? '1' : '0';
                setStatus('error', textError);
            };

            target.addEventListener('load', onLoad);
            target.addEventListener('error', onError);
            target.src = finalUrl;
            target.alt = previewAlt;

            root.dataset.previewUpdatedAt = String(modifiedAt);
            root.dataset.previewUpdatedHuman = statusHuman;
            root.dataset.previewSource = previewSource || '';
            root.dataset.previewFallback = fallbackUsed ? '1' : '0';
            if (data && data.error) {
                root.dataset.previewError = String(data.error);
            } else {
                delete root.dataset.previewError;
            }
            if (data && data.fallback_reason) {
                root.dataset.previewFallbackReason = String(data.fallback_reason);
            } else {
                delete root.dataset.previewFallbackReason;
            }

            let statusType = 'ok';
            let template = textSuccess;
            if (fallbackUsed) {
                statusType = 'warning';
                template = textFallback || textWarning || textSuccess;
            } else if (modifiedAt > 0) {
                const age = Math.floor(Date.now() / 1000) - modifiedAt;
                const isStale = Number.isFinite(age) && age > staleSeconds;
                statusType = isStale ? 'warning' : 'ok';
                template = isStale ? textWarning : textSuccess;
            }

            const finalMessage = template ? formatStatusMessage(template, statusHuman) : '';
            setStatus(statusType, finalMessage);

            if (fallbackUsed && data && (data.error || data.fallback_reason)) {
                console.warn('Project preview fallback used', data.fallback_reason || data.error, data);
            }
        };

        const refreshPreview = async (force = true) => {
            if (!endpoint || !projectId || isLoadingPreview) {
                return;
            }
            if (abortController) {
                abortController.abort();
            }
            abortController = new AbortController();
            isLoadingPreview = true;
            toggleButtonsLoading(true);
            setStatus('processing', textProcessing);

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('project_id', projectId);
            if (force) {
                formData.append('force', '1');
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    signal: abortController.signal
                });
                const raw = await response.text();
                let payload = null;
                if (raw) {
                    try {
                        payload = JSON.parse(raw);
                    } catch (error) {
                        console.error('Preview response parse failed', error, raw);
                    }
                }
                if (!payload || !payload.ok || !response.ok) {
                    console.warn('Preview refresh failed', payload || raw);
                    setStatus('error', textError);
                    return;
                }
                applyPreview(payload, { forced: force });
            } catch (error) {
                if (isAbortError(error)) {
                    return;
                }
                console.error('Preview refresh error', error);
                setStatus('error', textError);
            } finally {
                isLoadingPreview = false;
                toggleButtonsLoading(false);
            }
        };

        refreshButtons.forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                refreshPreview(true);
            });
        });

        if (imageEl) {
            imageEl.addEventListener('error', handleImageError);
        }

        const initialUpdatedAt = Number(root.dataset.previewUpdatedAt || '0');
        const initialUpdatedHuman = root.dataset.previewUpdatedHuman || '';
        if (root.dataset.hasPreviewUrl === '1' && initialUpdatedHuman) {
            const age = Math.floor(Date.now() / 1000) - initialUpdatedAt;
            const isStale = Number.isFinite(age) && age > staleSeconds;
            const template = isStale ? textWarning : textSuccess;
            setStatus(isStale ? 'warning' : 'ok', formatStatusMessage(template, initialUpdatedHuman));
        } else if (textPending) {
            setStatus('pending', textPending);
        }

        if (autoRefresh) {
            root.dataset.autoRefresh = '0';
            setTimeout(() => refreshPreview(false), 450);
        }
    }
    const STATUS_BADGE_CLASS_MAP = {
        'success': 'bg-success-subtle text-success-emphasis',
        'completed': 'bg-success-subtle text-success-emphasis',
        'running': 'bg-info-subtle text-info-emphasis',
        'level1_active': 'bg-info-subtle text-info-emphasis',
        'level2_active': 'bg-info-subtle text-info-emphasis',
        'level3_active': 'bg-info-subtle text-info-emphasis',
        'queued': 'bg-warning-subtle text-warning-emphasis',
        'pending': 'bg-warning-subtle text-warning-emphasis',
        'pending_level1': 'bg-warning-subtle text-warning-emphasis',
        'pending_level2': 'bg-warning-subtle text-warning-emphasis',
        'pending_level3': 'bg-warning-subtle text-warning-emphasis',
    'pending_crowd': 'bg-warning-subtle text-warning-emphasis',
    'crowd_ready': 'bg-info-subtle text-info-emphasis',
        'report_ready': 'bg-primary-subtle text-primary-emphasis',
        'manual_fallback': 'bg-secondary-subtle text-secondary-emphasis',
        'cancelled': 'bg-secondary-subtle text-secondary-emphasis',
        'failed': 'bg-danger-subtle text-danger-emphasis'
    };
    const LANG_CODES = <?php echo json_encode(array_merge(['auto'], $pp_lang_codes)); ?>;
    const DUPLICATE_LABEL_TEMPLATE = <?php echo json_encode(__('Дубликатов: %d')); ?>;
    const CREATED_LABEL_TEMPLATE = <?php echo json_encode(__('Добавлена %s')); ?>;
    const PROMOTION_LAST_LABEL_TEMPLATE = <?php echo json_encode(__('Последний запуск: %s')); ?>;
    const PROMOTION_FINISHED_LABEL_TEMPLATE = <?php echo json_encode(__('Завершено: %s')); ?>;
    const NO_MATCHES_LABEL = <?php echo json_encode(__('Совпадений нет')); ?>;
    const PAGINATION_SUMMARY_TEMPLATE = <?php echo json_encode(__('Показаны %1$s–%2$s из %3$s')); ?>;
    const PAGINATION_SINGLE_TEMPLATE = <?php echo json_encode(__('Найдено %s ссылок')); ?>;

    const navBalanceValueEl = document.querySelector('[data-balance-target]');
    const navBalanceLocale = navBalanceValueEl?.dataset.balanceLocale || document.documentElement.getAttribute('lang') || navigator.language || 'ru-RU';
    let navBalanceCurrency = navBalanceValueEl?.dataset.balanceCurrency || 'RUB';
    let CURRENT_USER_BALANCE_RAW = (typeof window.PP_BALANCE === 'number' && !Number.isNaN(window.PP_BALANCE))
        ? window.PP_BALANCE
        : (() => {
            const rawAttr = navBalanceValueEl?.dataset.balanceRaw;
            if (typeof rawAttr === 'string' && rawAttr !== '') {
                const parsed = Number(rawAttr);
                return Number.isNaN(parsed) ? NaN : parsed;
            }
            return NaN;
        })();

    function coerceNumber(value) {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return value;
        }
        if (typeof value === 'string') {
            const normalized = value.replace(/\s+/g, '').replace(',', '.');
            if (normalized === '') { return NaN; }
            const parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : NaN;
        }
        if (typeof value === 'object' && value !== null) {
            if (Object.prototype.hasOwnProperty.call(value, 'amount')) {
                return coerceNumber(value.amount);
            }
        }
        return NaN;
    }

    function formatBalanceLocale(amount) {
        if (!Number.isFinite(amount)) { return ''; }
        try {
            const locale = navBalanceLocale || document.documentElement.getAttribute('lang') || 'ru-RU';
            const currency = navBalanceCurrency || 'RUB';
            return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount);
        } catch (e) {
            return amount.toFixed(2);
        }
    }

    function updateClientBalance(rawAmount, formattedText) {
        const numericAmount = coerceNumber(rawAmount);
        let finalAmount = numericAmount;
        if (!Number.isFinite(finalAmount) && Number.isFinite(CURRENT_USER_BALANCE_RAW)) {
            finalAmount = CURRENT_USER_BALANCE_RAW;
        }
        if (Number.isFinite(finalAmount)) {
            CURRENT_USER_BALANCE_RAW = finalAmount;
            window.PP_BALANCE = finalAmount;
        }

        let finalFormatted = typeof formattedText === 'string' ? formattedText.trim() : '';
        if (!finalFormatted || finalFormatted.length === 0) {
            finalFormatted = formatBalanceLocale(finalAmount);
        }

        if (navBalanceValueEl) {
            if (Number.isFinite(finalAmount)) {
                navBalanceValueEl.dataset.balanceRaw = finalAmount.toFixed(2);
            }
            if (finalFormatted) {
                navBalanceValueEl.textContent = finalFormatted;
            }
        }

        document.querySelectorAll('[data-current-balance-display]').forEach(el => {
            if (Number.isFinite(finalAmount)) {
                el.dataset.balanceRaw = finalAmount.toFixed(2);
            }
            if (finalFormatted) {
                el.textContent = finalFormatted;
            }
        });

        try {
            document.dispatchEvent(new CustomEvent('pp:balance-updated', {
                detail: {
                    amount: Number.isFinite(finalAmount) ? finalAmount : NaN,
                    formatted: finalFormatted
                }
            }));
        } catch (_) {}

        return { amount: finalAmount, formatted: finalFormatted };
    }

    function normalizeAnchorLanguage(lang) {
        const raw = (lang || '').toString().toLowerCase();
        if (!raw || raw === 'auto') {
            return (PROJECT_LANGUAGE || '').toLowerCase();
        }
        return raw;
    }

    function getAnchorPresetsForLanguage(lang) {
        if (!ANCHOR_PRESETS || typeof ANCHOR_PRESETS !== 'object') {
            return [];
        }
        const normalized = normalizeAnchorLanguage(lang);
        const candidates = [];
        if (normalized) {
            candidates.push(normalized);
            const dashIndex = normalized.indexOf('-');
            if (dashIndex > -1) {
                candidates.push(normalized.slice(0, dashIndex));
            }
        }
        if (PROJECT_LANGUAGE) {
            const baseProjectLang = PROJECT_LANGUAGE.toLowerCase();
            if (baseProjectLang && !candidates.includes(baseProjectLang)) {
                candidates.push(baseProjectLang);
            }
            const dashProject = baseProjectLang.indexOf('-');
            if (dashProject > -1) {
                const base = baseProjectLang.slice(0, dashProject);
                if (!candidates.includes(base)) {
                    candidates.push(base);
                }
            }
        }
        candidates.push('default', 'en');
        for (const candidate of candidates) {
            if (!candidate) { continue; }
            if (Object.prototype.hasOwnProperty.call(ANCHOR_PRESETS, candidate)) {
                const list = ANCHOR_PRESETS[candidate];
                if (Array.isArray(list) && list.length > 0) {
                    return list;
                }
            }
        }
        return [];
    }

    function extractHostFromUrl(url) {
        if (!url) { return ''; }
        try {
            const u = new URL(url);
            let host = (u.hostname || '').toLowerCase();
            if (host.startsWith('www.')) {
                host = host.slice(4);
            }
            return host;
        } catch (_) {
            const match = String(url).match(/^[\w.-]+/);
            return match ? match[0].replace(/^www\./, '').toLowerCase() : '';
        }
    }

    function resolveAnchorPresetValue(preset, context = {}) {
        if (!preset || typeof preset !== 'object') {
            return '';
        }
        const type = String(preset.type || 'static').toLowerCase();
        const raw = typeof preset.value === 'string' ? preset.value : '';
        const url = typeof context.url === 'string' ? context.url : '';
        const projectName = typeof context.projectName === 'string' ? context.projectName : PROJECT_NAME;
        const projectHost = typeof context.projectHost === 'string' ? context.projectHost : PROJECT_HOST;

        switch (type) {
            case 'none':
                return '';
            case 'domain': {
                const host = extractHostFromUrl(url) || projectHost || raw;
                return host || '';
            }
            case 'url': {
                if (url) {
                    try {
                        const clean = new URL(url).href;
                        return clean.replace(/^https?:\/\//i, '').replace(/\/$/, '') || clean;
                    } catch (_) {
                        return url.replace(/^https?:\/\//i, '').replace(/\/$/, '') || url;
                    }
                }
                if (projectHost) {
                    return projectHost;
                }
                return raw || '';
            }
            case 'project': {
                if (projectName && projectName.trim() !== '') {
                    return projectName.trim();
                }
                return raw || projectHost || '';
            }
            default:
                return raw || '';
        }
    }

    function truncateAnchorText(value) {
        if (!value) { return ''; }
        const limit = 64;
        const chars = Array.from(value);
        if (chars.length <= limit) {
            return value;
        }
        return chars.slice(0, limit - 1).join('').trimEnd() + '…';
    }

    function generateAutoAnchor(lang, url) {
        const presets = getAnchorPresetsForLanguage(lang);
        if (presets.length === 0) {
            return '';
        }
        let preset = presets.find(item => item && item.default);
        if (!preset) {
            preset = presets.find(item => item && String(item.type || '').toLowerCase() !== 'none') || presets[0];
        }
        if (!preset) { return ''; }
        const value = resolveAnchorPresetValue(preset, { url, projectName: PROJECT_NAME, projectHost: PROJECT_HOST });
        return truncateAnchorText(value);
    }

    function updateAnchorPresetActiveState() {
        if (!anchorPresetContainer) { return; }
        const buttons = anchorPresetContainer.querySelectorAll('button.anchor-preset');
        buttons.forEach(btn => {
            const id = btn.dataset.presetId || '';
            const lang = btn.dataset.presetLang || '';
            const isActive = !!activeAnchorPreset && activeAnchorPreset.id === id && activeAnchorPreset.lang === lang;
            btn.classList.toggle('is-active', isActive);
        });
    }

    function renderAnchorPresets(lang) {
        if (!anchorPresetContainer) { return; }
        const presets = getAnchorPresetsForLanguage(lang);
        anchorPresetContainer.innerHTML = '';
        if (presets.length === 0) {
            anchorPresetContainer.classList.add('d-none');
            if (anchorPresetWrapper) {
                anchorPresetWrapper.classList.add('d-none');
            }
            return;
        }
        anchorPresetContainer.classList.remove('d-none');
        if (anchorPresetWrapper) {
            anchorPresetWrapper.classList.remove('d-none');
        }
        const fragment = document.createDocumentFragment();
        const langKey = normalizeAnchorLanguage(lang) || (PROJECT_LANGUAGE || '').toLowerCase();
        presets.forEach(preset => {
            if (!preset) { return; }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'anchor-preset btn btn-outline-light btn-sm';
            btn.textContent = preset.label || preset.value || '—';
            if (preset.description) {
                btn.title = preset.description;
            }
            btn.dataset.presetId = preset.id || '';
            btn.dataset.presetLang = langKey || '';
            btn.dataset.presetType = preset.type || 'static';
            if (preset.default) {
                btn.classList.add('is-default');
            }
            btn.addEventListener('click', () => {
                applyAnchorPreset(preset, langKey || lang, btn);
            });
            fragment.appendChild(btn);
        });
        anchorPresetContainer.appendChild(fragment);
        updateAnchorPresetActiveState();
    }

    function refreshActivePresetValue() {
        if (!activeAnchorPreset || !newAnchorInput) { return; }
        const presets = getAnchorPresetsForLanguage(activeAnchorPreset.lang);
        const preset = presets.find(item => (item?.id || '') === activeAnchorPreset.id);
        if (!preset) { return; }
        const type = String(preset.type || '').toLowerCase();
        if (!['domain', 'url'].includes(type)) { return; }
        const value = truncateAnchorText(resolveAnchorPresetValue(preset, { url: newLinkInput?.value?.trim() || '', projectName: PROJECT_NAME, projectHost: PROJECT_HOST }));
        anchorUpdateLock = true;
        newAnchorInput.value = value;
        newAnchorInput.dispatchEvent(new Event('input', { bubbles: true }));
        anchorUpdateLock = false;
    }

    function applyAnchorPreset(preset, lang, button) {
        if (!preset) { return; }
        const langKey = normalizeAnchorLanguage(lang) || (PROJECT_LANGUAGE || '').toLowerCase();
        const type = String(preset.type || '').toLowerCase();
        const value = type === 'none'
            ? ''
            : truncateAnchorText(resolveAnchorPresetValue(preset, { url: newLinkInput?.value?.trim() || '', projectName: PROJECT_NAME, projectHost: PROJECT_HOST }));

        activeAnchorPreset = { id: preset.id || '', lang: langKey || '' };
        anchorUpdateLock = true;
        if (newAnchorInput) {
            newAnchorInput.value = value;
            newAnchorInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        anchorUpdateLock = false;
        if (newAnchorStrategy) {
            newAnchorStrategy.value = type === 'none' ? 'none' : 'preset';
        }
        updateAnchorPresetActiveState();
        if (type === 'domain' || type === 'url') {
            refreshActivePresetValue();
        }
    }

    function resetAnchorInputsForWizard(lang) {
        if (newAnchorStrategy && newAnchorStrategy.value !== 'manual') {
            newAnchorStrategy.value = 'auto';
            if (newAnchorStrategy.value === 'auto' && newAnchorInput && !anchorUpdateLock) {
                anchorUpdateLock = true;
                newAnchorInput.dispatchEvent(new Event('input', { bubbles: true }));
                anchorUpdateLock = false;
            }
        }
        if (newAnchorStrategy && newAnchorStrategy.value === 'manual') {
            renderAnchorPresets(lang);
            return;
        }
        activeAnchorPreset = null;
        updateAnchorPresetActiveState();
        renderAnchorPresets(lang);
    }

    if (anchorPresetContainer) {
        const initialLang = newLangSelect ? newLangSelect.value : (PROJECT_LANGUAGE || '');
        renderAnchorPresets(initialLang);
    }

    if (newLangSelect) {
        newLangSelect.addEventListener('change', () => {
            const langVal = newLangSelect.value || '';
            resetAnchorInputsForWizard(langVal);
        });
    }

    if (newLinkInput) {
        newLinkInput.addEventListener('input', () => {
            if (activeAnchorPreset) {
                refreshActivePresetValue();
            }
        });
    }

    if (newAnchorInput) {
        newAnchorInput.addEventListener('input', () => {
            if (anchorUpdateLock) { return; }
            const trimmed = newAnchorInput.value.trim();
            if (trimmed === '') {
                if (newAnchorStrategy && newAnchorStrategy.value !== 'none') {
                    newAnchorStrategy.value = 'auto';
                }
            } else if (newAnchorStrategy && newAnchorStrategy.value !== 'preset') {
                newAnchorStrategy.value = 'manual';
            }
            if (activeAnchorPreset) {
                activeAnchorPreset = null;
                updateAnchorPresetActiveState();
            }
        });
    }

    function showInsufficientFundsModal(info) {
        const modalInstance = getInsufficientFundsModalInstance();
        if (!modalInstance) {
            alert('<?php echo __('Недостаточно средств на балансе.'); ?>');
            return;
        }
        const requiredRaw = coerceNumber(info?.required);
        const balanceRaw = coerceNumber(info?.balance);
        const shortfallRaw = coerceNumber(info?.shortfall);
        const fallbackRequired = Number.isFinite(requiredRaw) ? requiredRaw : coerceNumber(PROMOTION_CHARGE_AMOUNT);
        const computedShortfall = Number.isFinite(shortfallRaw)
            ? shortfallRaw
            : (Number.isFinite(fallbackRequired) && Number.isFinite(balanceRaw) ? Math.max(0, fallbackRequired - balanceRaw) : NaN);

        if (insufficientAmountEl) {
            insufficientAmountEl.textContent = Number.isFinite(computedShortfall)
                ? formatBalanceLocale(computedShortfall)
                : '—';
        }

        if (insufficientRequiredEl) {
            const displayRequired = Number.isFinite(requiredRaw)
                ? requiredRaw
                : (Number.isFinite(fallbackRequired) ? fallbackRequired : NaN);
            insufficientRequiredEl.textContent = Number.isFinite(displayRequired)
                ? formatBalanceLocale(displayRequired)
                : (PROMOTION_CHARGE_AMOUNT_FORMATTED || '—');
        }

        if (insufficientBalanceEl) {
            const displayBalance = Number.isFinite(balanceRaw)
                ? balanceRaw
                : (Number.isFinite(CURRENT_USER_BALANCE_RAW) ? CURRENT_USER_BALANCE_RAW : coerceNumber(CURRENT_USER_BALANCE_RAW));
            insufficientBalanceEl.textContent = Number.isFinite(displayBalance)
                ? formatBalanceLocale(displayBalance)
                : '—';
        }

        modalInstance.show();
    }

    function setButtonLoading(btn, loading) {
        if (!btn) return;
        const spinner = btn.querySelector('[data-loading-spinner]');
        const labelEl = btn.querySelector('[data-loading-label]');
        if (labelEl && !btn.dataset.loadingLabelDefault) {
            btn.dataset.loadingLabelDefault = labelEl.textContent.trim();
        }
        const defaultLabel = btn.dataset.loadingLabelDefault || '';
        const loadingLabel = btn.dataset.loadingLabelLoading || btn.dataset.loadingLabel;
        if (spinner) {
            spinner.classList.toggle('d-none', !loading);
        }
        if (labelEl) {
            if (loading && loadingLabel) {
                labelEl.textContent = loadingLabel;
            } else if (!loading && defaultLabel) {
                labelEl.textContent = defaultLabel;
            }
            labelEl.classList.toggle('opacity-75', !!loading);
        }
        btn.classList.toggle('disabled', !!loading);
        if (loading) {
            btn.setAttribute('disabled', 'disabled');
            btn.dataset.loading = '1';
        } else {
            btn.removeAttribute('disabled');
            delete btn.dataset.loading;
        }
    }

    function getCsrfToken() {
        const tokenField = document.querySelector('input[name="csrf_token"]');
        return tokenField ? tokenField.value : '';
    }

    function ensureLinksTable() {
        let tbody = document.querySelector('table.table-links tbody');
        if (tbody) { return tbody; }
        const cardBody = document.querySelector('#links-card .card-body');
        if (!cardBody) { return null; }
        const emptyState = cardBody.querySelector('.empty-state');
        if (emptyState && emptyState.dataset && emptyState.dataset.linkEmpty !== undefined) {
            emptyState.classList.add('d-none');
        } else if (emptyState) {
            emptyState.remove();
        }
        const filtersBlock = cardBody.querySelector('[data-link-filters]');
        if (filtersBlock) {
            filtersBlock.classList.remove('d-none');
        }
        const tableWrapperExisting = cardBody.querySelector('[data-link-table-wrapper]');
        if (tableWrapperExisting) {
            tableWrapperExisting.classList.remove('d-none');
            tbody = tableWrapperExisting.querySelector('table.table-links tbody');
            if (tbody) { return tbody; }
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        wrapper.setAttribute('data-link-table-wrapper', '');
        wrapper.innerHTML = `
            <table class="table table-striped table-hover table-sm align-middle table-links" data-page-size="15">
                <thead>
                    <tr>
                        <th style="width:44px;">#</th>
                        <th><?php echo __('Ссылка'); ?></th>
                        <th><?php echo __('Анкор'); ?></th>
                        <th><?php echo __('Язык'); ?></th>
                        <th><?php echo __('Пожелание'); ?></th>
                        <th><?php echo __('Статус'); ?></th>
                        <th class="text-end" style="width:200px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        `;
        cardBody.appendChild(wrapper);
        initTooltips(wrapper);
        tbody = wrapper.querySelector('tbody');
        const paginationWrapper = cardBody.querySelector('[data-link-pagination-wrapper]');
        if (paginationWrapper) {
            paginationWrapper.classList.add('d-none');
        }
        return tbody;
    }

    function refreshRowNumbers() {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        let counter = 0;
        rows.forEach(tr => {
            if (tr.dataset.placeholder === '1') { return; }
            const cell = tr.querySelector('td[data-label="#"], td:first-child');
            counter += 1;
            if (cell) { cell.textContent = String(counter); }
            tr.dataset.index = String(counter - 1);
        });
    }

    function refreshActionsCell(tr) {
        if (!tr) return;
        const status = tr.dataset.promotionStatus || 'idle';
        const runId = tr.dataset.promotionRunId || '';
        const reportReady = tr.dataset.promotionReportReady === '1';
        const actionsContainer = tr.querySelector('.link-actions');
        if (!actionsContainer) return;

        const promoteBtn = actionsContainer.querySelector('.action-promote');
        const progressWrapper = actionsContainer.querySelector('.link-actions-progress');
        const progressBtn = actionsContainer.querySelector('.action-promotion-progress');
        const reportBtn = actionsContainer.querySelector('.action-promotion-report');

        if (PROMOTION_ACTIVE_STATUSES.includes(status)) {
            promoteBtn?.classList.add('d-none');
            if (progressWrapper) {
                progressWrapper.classList.remove('d-none');
                const runningBtn = progressWrapper.querySelector('.btn-progress-running');
                if (runningBtn) {
                    runningBtn.disabled = true;
                    runningBtn.setAttribute('data-loading', '1');
                }
            }
            if (progressBtn && runId) {
                progressBtn.dataset.runId = runId;
                progressBtn.classList.remove('d-none');
            }
        } else {
            promoteBtn?.classList.remove('d-none');
            if (progressWrapper) {
                progressWrapper.classList.add('d-none');
            }
            if (progressBtn) {
                progressBtn.classList.toggle('d-none', !runId);
                if (runId) {
                    progressBtn.dataset.runId = runId;
                }
            }
        }

        if (reportBtn) {
            if (reportReady && runId) {
                reportBtn.classList.remove('d-none');
                reportBtn.dataset.runId = runId;
            } else {
                reportBtn.classList.add('d-none');
            }
        }
    }

    function initWishAutoFill() {
        if (!useGlobal || !globalWish) return;
        useGlobal.addEventListener('change', () => {
            if (useGlobal.checked) {
                newWish.value = globalWish.value || '';
            }
        });
    }

    function getPromotionConfirmModalInstance() {
        if (!promotionConfirmModalEl || !window.bootstrap) return null;
        return bootstrap.Modal.getOrCreateInstance(promotionConfirmModalEl);
    }

    function getInsufficientFundsModalInstance() {
        if (!insufficientFundsModalEl || !window.bootstrap) return null;
        return bootstrap.Modal.getOrCreateInstance(insufficientFundsModalEl);
    }

    function applyProjectHost(host) {
        if (!host) return;
        const normalized = host.toLowerCase().replace(/^www\./, '');
        const domainHint = document.getElementById('domain-hint');
        const domainCode = document.getElementById('domain-host-code');
        if (domainHint) {
            domainHint.style.display = '';
        }
        if (domainCode) {
            domainCode.textContent = normalized;
        }
        CURRENT_PROJECT_HOST = normalized;
    }

    function handleRemoveButton(btn) {
        const id = parseInt(btn.getAttribute('data-id') || '0', 10);
        if (!id || !confirm('<?php echo __('Удалить ссылку?'); ?>')) {
            return;
        }
        const row = btn.closest('tr');
        if (!row) return;
        const removeInput = document.createElement('input');
        removeInput.type = 'hidden';
        removeInput.name = `remove_links[]`;
        removeInput.value = String(id);
        addedHidden?.appendChild(removeInput);
        row.remove();
        refreshRowNumbers();
        recalcPromotionStats();
        if (linkTableManager) {
            try {
                linkTableManager.sync();
            } catch (e) {
                console.error('Link table manager sync failed', e);
            }
        }
    }

    function refreshTooltip(el) {
        if (!el || !window.bootstrap || !bootstrap.Tooltip) return;
        try {
            const existing = bootstrap.Tooltip.getInstance(el);
            if (existing) {
                existing.dispose();
            }
            bootstrap.Tooltip.getOrCreateInstance(el);
        } catch (e) {}
    }

    function applyRowValues(row, values) {
        if (!row || !values) return;
        const { url, anchor, language, wish } = values;
        const normalizedLang = (typeof language === 'string' && language.trim() !== '') ? language.trim().toLowerCase() : 'auto';

        if (typeof url === 'string') {
            const trimmedUrl = url.trim();
            const urlInput = row.querySelector('.edit-url');
            if (urlInput) { urlInput.value = trimmedUrl; }
            const hostEl = row.querySelector('.host-muted');
            if (hostEl) {
                const hostDisp = hostFromUrl(trimmedUrl) || trimmedUrl;
                hostEl.innerHTML = '<i class="bi bi-globe2 me-1"></i>' + escapeHtml(hostDisp);
            }
            const viewUrl = row.querySelector('.view-url');
            if (viewUrl) {
                const pathDisp = pathFromUrl(trimmedUrl) || trimmedUrl;
                viewUrl.setAttribute('href', trimmedUrl);
                viewUrl.textContent = pathDisp;
                viewUrl.setAttribute('title', trimmedUrl);
                refreshTooltip(viewUrl);
            }
            const promoteBtn = row.querySelector('.action-promote');
            if (promoteBtn) {
                promoteBtn.setAttribute('data-url', trimmedUrl);
            }
        }

        if (typeof anchor === 'string') {
            const anchorVal = anchor.trim();
            const anchorInput = row.querySelector('.edit-anchor');
            if (anchorInput) { anchorInput.value = anchorVal; }
            const viewAnchor = row.querySelector('.view-anchor');
            if (viewAnchor) {
                viewAnchor.textContent = anchorVal;
                viewAnchor.setAttribute('title', anchorVal);
                refreshTooltip(viewAnchor);
            }
        }

        const langInput = row.querySelector('.edit-language');
        if (langInput) {
            langInput.value = normalizedLang || 'auto';
        }
        const viewLang = row.querySelector('.view-language');
        if (viewLang) {
            viewLang.textContent = (normalizedLang || 'auto').toUpperCase();
        }

        if (typeof wish === 'string') {
            const wishVal = wish;
            const wishTextarea = row.querySelector('.edit-wish');
            if (wishTextarea) { wishTextarea.value = wishVal; }
            const viewWish = row.querySelector('.view-wish');
            if (viewWish) { viewWish.textContent = wishVal; }
            const wishBtn = row.querySelector('.action-show-wish');
            if (wishBtn) { wishBtn.setAttribute('data-wish', wishVal); }
        }
    }

    async function persistEditedLink(row, payload, previousState, btn) {
        if (!row || !payload || !payload.id) {
            return;
        }
        try {
            if (btn) {
                btn.disabled = true;
                btn.dataset.saving = '1';
            }
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('update_project', '1');
            fd.append('ajax', '1');
            fd.append(`edited_links[${payload.id}][url]`, payload.url);
            fd.append(`edited_links[${payload.id}][anchor]`, payload.anchor);
            fd.append(`edited_links[${payload.id}][language]`, payload.language);
            fd.append(`edited_links[${payload.id}][wish]`, payload.wish);
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || !data.ok) {
                const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'ERROR';
                throw new Error(msg);
            }
            if (data.domain_host) {
                applyProjectHost(data.domain_host);
            }
            delete row.dataset.edited;
            row.classList.add('link-row-saved');
            setTimeout(() => row.classList.remove('link-row-saved'), 1200);
        } catch (error) {
            row.dataset.edited = '1';
            applyRowValues(row, previousState);
            row.classList.add('editing');
            const viewSelector = '.view-url, .view-anchor, .view-language, .view-wish';
            const editSelector = '.edit-url, .edit-anchor, .edit-language, .edit-wish';
            row.querySelectorAll(viewSelector).forEach(el => el.classList.add('d-none'));
            row.querySelectorAll(editSelector).forEach(el => {
                el.classList.remove('d-none');
                el.removeAttribute('disabled');
            });
            const urlInput = row.querySelector('.edit-url');
            if (urlInput) { urlInput.value = payload.url; }
            const anchorInput = row.querySelector('.edit-anchor');
            if (anchorInput) { anchorInput.value = payload.anchor; }
            const langInput = row.querySelector('.edit-language');
            if (langInput) { langInput.value = payload.language || 'auto'; }
            const wishInput = row.querySelector('.edit-wish');
            if (wishInput) { wishInput.value = payload.wish; }
            if (btn) {
                btn.dataset.mode = 'save';
                btn.setAttribute('title', '<?php echo __('Сохранить изменения'); ?>');
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.remove('bi-pencil');
                    icon.classList.add('bi-check-lg');
                }
                refreshTooltip(btn);
            }
            alert('<?php echo __('Не удалось сохранить ссылку'); ?>: ' + (error && error.message ? error.message : 'ERROR'));
        } finally {
            if (btn) {
                btn.disabled = false;
                delete btn.dataset.saving;
            }
        }
    }

    function handleEditButton(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        if (btn && btn.dataset.saving === '1') return;
        const isEditing = row.classList.contains('editing');
        const editFieldsSelector = '.edit-url, .edit-anchor, .edit-language, .edit-wish';
        const viewFieldsSelector = '.view-url, .view-anchor, .view-language, .view-wish';

        if (!isEditing) {
            row.classList.add('editing');
            row.querySelectorAll(viewFieldsSelector).forEach(el => el.classList.add('d-none'));
            row.querySelectorAll(editFieldsSelector).forEach(el => {
                el.classList.remove('d-none');
                el.removeAttribute('disabled');
            });
            btn.setAttribute('title', '<?php echo __('Сохранить изменения'); ?>');
            btn.dataset.mode = 'save';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.classList.remove('bi-pencil');
                icon.classList.add('bi-check-lg');
            }
            refreshTooltip(btn);
            row.querySelector('.edit-url')?.focus();
            return;
        }

        const rowId = parseInt(row.getAttribute('data-id') || '0', 10);
        if (!rowId) {
            alert('<?php echo __('Не удалось определить ссылку для сохранения. Обновите страницу.'); ?>');
            return;
        }
        const urlInput = row.querySelector('.edit-url');
        const anchorInput = row.querySelector('.edit-anchor');
        const langSelect = row.querySelector('.edit-language');
        const wishTextarea = row.querySelector('.edit-wish');
        const urlVal = (urlInput?.value || '').trim();
        if (!isValidUrl(urlVal)) {
            alert('<?php echo __('Введите корректный URL'); ?>');
            urlInput?.focus();
            return;
        }
        if (CURRENT_PROJECT_HOST) {
            try {
                const host = (new URL(urlVal).hostname || '').toLowerCase().replace(/^www\./, '');
                if (host && host !== CURRENT_PROJECT_HOST) {
                    alert('<?php echo __('Ссылка должна быть в рамках домена проекта'); ?>: ' + CURRENT_PROJECT_HOST);
                    urlInput?.focus();
                    return;
                }
            } catch (e) {}
        }
        const anchorVal = (anchorInput?.value || '').trim();
        const langVal = (langSelect?.value || '').trim() || 'auto';
        const wishVal = (wishTextarea?.value || '').trim();

        const viewUrl = row.querySelector('.view-url');
        const prevUrl = viewUrl ? (viewUrl.getAttribute('href') || '') : '';
        const viewAnchor = row.querySelector('.view-anchor');
        const prevAnchor = viewAnchor ? (viewAnchor.textContent || '').trim() : '';
        const viewLang = row.querySelector('.view-language');
        const prevLang = viewLang ? ((viewLang.textContent || '').trim().toLowerCase()) : '';
        const viewWish = row.querySelector('.view-wish');
        const prevWish = viewWish ? (viewWish.textContent || '').trim() : '';
        const previousState = {
            url: prevUrl,
            anchor: prevAnchor,
            language: prevLang,
            wish: prevWish
        };

        applyRowValues(row, { url: urlVal, anchor: anchorVal, language: langVal, wish: wishVal });

        const changed = (prevUrl !== urlVal) || (prevAnchor !== anchorVal) || (prevLang !== langVal.toLowerCase()) || (prevWish !== wishVal);
        if (changed) {
            row.dataset.edited = '1';
        } else {
            delete row.dataset.edited;
        }

        row.classList.remove('editing');
        row.querySelectorAll(viewFieldsSelector).forEach(el => el.classList.remove('d-none'));
        row.querySelectorAll(editFieldsSelector).forEach(el => {
            el.classList.add('d-none');
            el.setAttribute('disabled', 'disabled');
        });
        btn.removeAttribute('data-mode');
        btn.setAttribute('title', '<?php echo __('Редактировать'); ?>');
        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.remove('bi-check-lg');
            icon.classList.add('bi-pencil');
        }
        refreshTooltip(btn);

        if (changed) {
            const payload = {
                id: rowId,
                url: urlVal,
                anchor: anchorVal,
                language: langVal || 'auto',
                wish: wishVal
            };
            persistEditedLink(row, payload, previousState, btn);
        }
    }

    function pathFromUrl(url) {
        try {
            const u = new URL(url);
            return (u.pathname || '/') + (u.search || '') + (u.hash || '');
        } catch (e) {
            return url;
        }
    }

    function hostFromUrl(url) {
        try {
            const u = new URL(url);
            return (u.hostname || '').toLowerCase().replace(/^www\./, '');
        } catch (e) {
            return '';
        }
    }

    function normalizeLinkKey(url) {
        if (typeof url !== 'string' || url.trim() === '') {
            return '';
        }
        try {
            const parsed = new URL(url);
            const host = (parsed.hostname || '').toLowerCase().replace(/^www\./, '');
            let path = parsed.pathname || '/';
            if (path !== '/' && path.endsWith('/')) {
                path = path.slice(0, -1);
            }
            let key = host + path;
            if (parsed.search) {
                key += parsed.search.toLowerCase();
            }
            if (parsed.hash) {
                key += parsed.hash.toLowerCase();
            }
            return key.trim();
        } catch (e) {
            return url.trim().toLowerCase();
        }
    }

    function formatDateTimeShort(timestampSeconds) {
        if (!Number.isFinite(timestampSeconds) || timestampSeconds <= 0) {
            return '';
        }
        const lang = document.documentElement.getAttribute('lang') || navigator.language || 'ru-RU';
        try {
            const formatter = new Intl.DateTimeFormat(lang, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
            return formatter.format(new Date(timestampSeconds * 1000));
        } catch (e) {
            const date = new Date(timestampSeconds * 1000);
            return date.toISOString().replace('T', ' ').slice(0, 16);
        }
    }

    function parseTimestampToSeconds(value) {
        if (value === null || value === undefined) {
            return 0;
        }
        if (typeof value === 'number') {
            if (!Number.isFinite(value)) { return 0; }
            if (value > 1e12) {
                return Math.floor(value / 1000);
            }
            return Math.floor(value);
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed === '') { return 0; }
            const parsed = Date.parse(trimmed);
            if (Number.isNaN(parsed)) { return 0; }
            return Math.floor(parsed / 1000);
        }
        if (value instanceof Date) {
            const time = value.getTime();
            return Number.isFinite(time) ? Math.floor(time / 1000) : 0;
        }
        return 0;
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"]+/g, s => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[s] || s));
    }

    function escapeAttribute(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }

    function recalcPromotionStats() {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        let total = 0, active = 0, completed = 0, idle = 0, issues = 0;
        rows.forEach(tr => {
            if (tr.dataset.placeholder === '1') { return; }
            if (tr.dataset.filterHidden === '1' || tr.dataset.pageHidden === '1') { return; }
            total++;
            const status = tr.dataset.promotionStatus || 'idle';
            if (status === 'completed') {
                completed++;
            } else if (status === 'failed' || status === 'cancelled') {
                issues++;
            } else if (PROMOTION_ACTIVE_STATUSES.includes(status)) {
                active++;
            } else {
                idle++;
            }
        });
        const totalEl = document.querySelector('[data-stat-total]');
        const activeEl = document.querySelector('[data-stat-active]');
        const completedEl = document.querySelector('[data-stat-completed]');
        const idleEl = document.querySelector('[data-stat-idle]');
        const issuesEl = document.querySelector('[data-stat-issues]');
        if (totalEl) totalEl.textContent = total.toString();
        if (activeEl) activeEl.textContent = active.toString();
        if (completedEl) completedEl.textContent = completed.toString();
        if (idleEl) idleEl.textContent = idle.toString();
        if (issuesEl) issuesEl.textContent = issues.toString();
    }

    function createLinkTableManager() {
        const state = {
            search: '',
            status: 'all',
            history: 'all',
            duplicates: 'all',
            language: 'all',
            page: 1,
            perPage: 15,
            rows: []
        };

        const dom = {
            filters: null,
            tableWrapper: null,
            table: null,
            paginationWrapper: null,
            pagination: null,
            summary: null,
            empty: null,
            searchInput: null,
            statusSelect: null,
            historySelect: null,
            duplicatesSelect: null,
            languageSelect: null
        };

        let searchTimer = null;
        let bound = false;

        function cacheDom() {
            dom.filters = document.querySelector('[data-link-filters]');
            dom.tableWrapper = document.querySelector('[data-link-table-wrapper]');
            dom.table = dom.tableWrapper ? dom.tableWrapper.querySelector('table.table-links') : document.querySelector('table.table-links');
            dom.paginationWrapper = document.querySelector('[data-link-pagination-wrapper]');
            dom.pagination = dom.paginationWrapper ? dom.paginationWrapper.querySelector('[data-link-pagination]') : null;
            dom.summary = dom.paginationWrapper ? dom.paginationWrapper.querySelector('[data-link-pagination-summary]') : document.querySelector('[data-link-pagination-summary]');
            dom.empty = document.querySelector('[data-link-empty]');
            dom.searchInput = document.querySelector('[data-link-filter-search]');
            dom.statusSelect = document.querySelector('[data-link-filter-status]');
            dom.historySelect = document.querySelector('[data-link-filter-history]');
            dom.duplicatesSelect = document.querySelector('[data-link-filter-duplicates]');
            dom.languageSelect = document.querySelector('[data-link-filter-language]');
            if (dom.table && dom.table.dataset && dom.table.dataset.pageSize) {
                const parsed = Number(dom.table.dataset.pageSize);
                if (Number.isFinite(parsed) && parsed > 0) {
                    state.perPage = parsed;
                }
            }
        }

        function collectRows() {
            if (!dom.table) {
                state.rows = [];
                return;
            }
            const rawRows = Array.from(dom.table.querySelectorAll('tbody tr'));
            state.rows = rawRows.filter(tr => tr.dataset.placeholder !== '1');
        }

        function updateLayoutVisibility() {
            const hasRows = state.rows.length > 0;
            if (dom.filters) {
                dom.filters.classList.toggle('d-none', !hasRows);
            }
            if (dom.tableWrapper) {
                dom.tableWrapper.classList.toggle('d-none', !hasRows);
            }
            if (dom.empty) {
                dom.empty.classList.toggle('d-none', hasRows);
            }
        }

        function updateCreatedLabels() {
            state.rows.forEach(row => {
                const labelEl = row.querySelector('[data-created-label]');
                if (!labelEl) { return; }
                let timestamp = Number(row.dataset.createdAt || '0');
                if (!Number.isFinite(timestamp) || timestamp <= 0) {
                    const raw = row.dataset.createdAtRaw || '';
                    if (raw) {
                        const parsed = Date.parse(raw);
                        if (!Number.isNaN(parsed)) {
                            timestamp = Math.floor(parsed / 1000);
                            row.dataset.createdAt = String(timestamp);
                        }
                    }
                }
                if (!Number.isFinite(timestamp) || timestamp <= 0) {
                    timestamp = Math.floor(Date.now() / 1000);
                    row.dataset.createdAt = String(timestamp);
                }
                const formatted = formatDateTimeShort(timestamp);
                if (formatted) {
                    const text = CREATED_LABEL_TEMPLATE.replace('%s', formatted);
                    labelEl.innerHTML = `<i class="bi bi-calendar3 me-1"></i>${escapeHtml(text)}`;
                    labelEl.classList.remove('d-none');
                } else {
                    labelEl.classList.add('d-none');
                }
            });
        }

        function updateDuplicateBadges() {
            const counters = {};
            state.rows.forEach(row => {
                const viewUrl = row.querySelector('.view-url');
                const rawUrl = viewUrl ? (viewUrl.getAttribute('href') || '') : '';
                let key = (row.dataset.duplicateKey || '').trim();
                if (!key) {
                    key = normalizeLinkKey(rawUrl);
                    if (key) {
                        row.dataset.duplicateKey = key;
                    }
                }
                if (!key) { return; }
                counters[key] = (counters[key] || 0) + 1;
            });
            state.rows.forEach(row => {
                const key = (row.dataset.duplicateKey || '').trim();
                const badgeEl = row.querySelector('[data-duplicate-badge]');
                const count = key && counters[key] ? counters[key] : 1;
                row.dataset.duplicateCount = String(count);
                if (!badgeEl) { return; }
                if (count > 1) {
                    badgeEl.textContent = DUPLICATE_LABEL_TEMPLATE.replace('%d', count);
                    badgeEl.classList.remove('d-none');
                } else {
                    badgeEl.classList.add('d-none');
                }
            });
        }

        function updateMetadata() {
            updateDuplicateBadges();
            updateCreatedLabels();
        }

        function formatNumber(value) {
            const lang = document.documentElement.getAttribute('lang') || navigator.language || 'ru-RU';
            try {
                return new Intl.NumberFormat(lang, { maximumFractionDigits: 0 }).format(value);
            } catch (e) {
                return String(value);
            }
        }

        function rowMatches(row) {
            const searchIndex = (row.dataset.searchIndex || '').toLowerCase();
            if (state.search && searchIndex.indexOf(state.search) === -1) {
                return false;
            }
            const status = row.dataset.promotionStatus || 'idle';
            if (state.status === 'active' && !PROMOTION_ACTIVE_STATUSES.includes(status)) {
                return false;
            }
            if (state.status === 'completed' && status !== 'completed') {
                return false;
            }
            if (state.status === 'idle' && status !== 'idle') {
                return false;
            }
            if (state.status === 'issues' && !(status === 'failed' || status === 'cancelled')) {
                return false;
            }
            if (state.status === 'report_ready' && row.dataset.promotionReportReady !== '1') {
                return false;
            }
            const hasHistory = row.dataset.hasPromotion === '1'
                || Number(row.dataset.promotionCreated || 0) > 0
                || Number(row.dataset.promotionStarted || 0) > 0
                || Number(row.dataset.promotionUpdated || 0) > 0
                || PROMOTION_ACTIVE_STATUSES.includes(status)
                || status === 'completed';
            if (state.history === 'with' && !hasHistory) {
                return false;
            }
            if (state.history === 'without' && hasHistory) {
                return false;
            }
            const duplicateCount = Number(row.dataset.duplicateCount || '1');
            if (state.duplicates === 'duplicates' && duplicateCount < 2) {
                return false;
            }
            if (state.duplicates === 'unique' && duplicateCount > 1) {
                return false;
            }
            const language = (row.dataset.language || '').toLowerCase();
            if (state.language !== 'all' && language !== state.language) {
                return false;
            }
            return true;
        }

        function updateSummary(total, pages, startIndex, endIndex) {
            if (!dom.summary) { return; }
            if (total === 0) {
                dom.summary.textContent = NO_MATCHES_LABEL;
                return;
            }
            if (total <= state.perPage) {
                dom.summary.textContent = PAGINATION_SINGLE_TEMPLATE.replace('%s', formatNumber(total));
                return;
            }
            const text = PAGINATION_SUMMARY_TEMPLATE
                .replace('%1$s', formatNumber(startIndex))
                .replace('%2$s', formatNumber(endIndex))
                .replace('%3$s', formatNumber(total));
            dom.summary.textContent = text;
        }

        function renderPagination(pages) {
            if (!dom.paginationWrapper || !dom.pagination) { return; }
            dom.pagination.innerHTML = '';
            if (pages <= 1) {
                dom.paginationWrapper.classList.add('d-none');
                return;
            }
            dom.paginationWrapper.classList.remove('d-none');
            for (let i = 1; i <= pages; i++) {
                const li = document.createElement('li');
                li.className = 'page-item' + (i === state.page ? ' active' : '');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'page-link';
                btn.textContent = String(i);
                btn.dataset.page = String(i);
                btn.addEventListener('click', () => {
                    if (state.page === i) { return; }
                    state.page = i;
                    applyFilters();
                });
                li.appendChild(btn);
                dom.pagination.appendChild(li);
            }
        }

        function applyFilters() {
            if (!dom.table) {
                if (dom.summary) { dom.summary.textContent = NO_MATCHES_LABEL; }
                if (dom.paginationWrapper) { dom.paginationWrapper.classList.add('d-none'); }
                recalcPromotionStats();
                return;
            }
            const filteredRows = [];
            state.rows.forEach(row => {
                const matches = rowMatches(row);
                row.dataset.filterHidden = matches ? '0' : '1';
                if (matches) {
                    filteredRows.push(row);
                }
            });

            const total = filteredRows.length;
            const totalPages = total > 0 ? Math.ceil(total / state.perPage) : 1;
            if (state.page > totalPages) {
                state.page = totalPages;
            }
            const pageStartIndex = total === 0 ? 0 : (state.page - 1) * state.perPage;
            const pageEndIndex = total === 0 ? 0 : Math.min(total, pageStartIndex + state.perPage);

            state.rows.forEach(row => {
                row.dataset.pageHidden = '1';
                if (row.dataset.filterHidden === '1') {
                    row.classList.add('d-none');
                }
            });

            filteredRows.forEach((row, index) => {
                const onPage = index >= pageStartIndex && index < pageStartIndex + state.perPage;
                row.dataset.pageHidden = onPage ? '0' : '1';
                row.classList.toggle('d-none', !onPage);
            });

            updateSummary(total, totalPages, total === 0 ? 0 : pageStartIndex + 1, pageEndIndex);
            renderPagination(totalPages);
            recalcPromotionStats();
        }

        function bind() {
            if (bound) { return; }
            bound = true;
            if (dom.searchInput) {
                dom.searchInput.addEventListener('input', (event) => {
                    const value = (event.target.value || '').toLowerCase();
                    if (searchTimer) { clearTimeout(searchTimer); }
                    searchTimer = setTimeout(() => {
                        state.search = value.trim();
                        state.page = 1;
                        applyFilters();
                    }, 180);
                });
            }
            if (dom.statusSelect) {
                dom.statusSelect.addEventListener('change', () => {
                    state.status = (dom.statusSelect.value || 'all');
                    state.page = 1;
                    applyFilters();
                });
            }
            if (dom.historySelect) {
                dom.historySelect.addEventListener('change', () => {
                    state.history = (dom.historySelect.value || 'all');
                    state.page = 1;
                    applyFilters();
                });
            }
            if (dom.duplicatesSelect) {
                dom.duplicatesSelect.addEventListener('change', () => {
                    state.duplicates = (dom.duplicatesSelect.value || 'all');
                    state.page = 1;
                    applyFilters();
                });
            }
            if (dom.languageSelect) {
                dom.languageSelect.addEventListener('change', () => {
                    state.language = (dom.languageSelect.value || 'all');
                    state.page = 1;
                    applyFilters();
                });
            }
        }

        function sync() {
            cacheDom();
            collectRows();
            updateLayoutVisibility();
            if (state.rows.length === 0) {
                if (dom.summary) { dom.summary.textContent = NO_MATCHES_LABEL; }
                if (dom.paginationWrapper) { dom.paginationWrapper.classList.add('d-none'); }
                recalcPromotionStats();
                return;
            }
            updateMetadata();
            applyFilters();
        }

        function init() {
            cacheDom();
            bind();
            collectRows();
            updateLayoutVisibility();
            if (state.rows.length > 0) {
                updateMetadata();
            }
            applyFilters();
        }

        return { init, sync, apply: applyFilters };
    }

    function createLinkPlaceholderRow(url) {
        const tr = document.createElement('tr');
        tr.dataset.placeholder = '1';
        const display = url ? (pathFromUrl(url) || url) : '';
        const extra = display ? `<span class="d-block small mt-1 text-break">${escapeHtml(display)}</span>` : '';
        tr.innerHTML = `
            <td colspan="7" class="text-center text-muted py-3">
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                <?php echo __('Сохраняем ссылку...'); ?>${extra}
            </td>
        `;
        return tr;
    }

    function removePlaceholderRow(row) {
        if (row && row.parentNode) {
            row.remove();
        }
        if (linkTableManager) {
            try { linkTableManager.sync(); } catch (e) { console.error('Link table manager sync failed', e); }
        }
    }

    function buildLinkRow(payload) {
        const tr = document.createElement('tr');
        const newId = parseInt(payload?.id || '0', 10) || 0;
        const urlVal = payload?.url || '';
        const anchorVal = payload?.anchor || '';
        const wishVal = payload?.wish || '';
        const langRaw = (payload?.language || payload?.lang || '').toString().trim().toLowerCase();
        const languageValue = langRaw || (newLangSelect ? (newLangSelect.value || 'auto') : 'auto');
        const displayLang = languageValue ? languageValue.toUpperCase() : 'AUTO';
        const hostDisp = hostFromUrl(urlVal);
        const pathDisp = pathFromUrl(urlVal);
        const langOptions = LANG_CODES.map(code => {
            const val = String(code || '').trim().toLowerCase();
            const selected = val === languageValue ? 'selected' : '';
            return `<option value="${escapeHtml(val)}" ${selected}>${escapeHtml(val.toUpperCase())}</option>`;
        }).join('');

        tr.setAttribute('data-id', String(newId));
        tr.dataset.index = '0';
        tr.dataset.postUrl = payload?.post_url || '';
        tr.dataset.network = payload?.network || '';
        tr.dataset.publicationStatus = payload?.publication_status || 'not_published';
        tr.dataset.promotionStatus = payload?.promotion_status || 'idle';
        tr.dataset.promotionStage = payload?.promotion_stage || '';
        tr.dataset.promotionRunId = payload?.promotion_run_id ? String(payload.promotion_run_id) : '';
        tr.dataset.promotionReportReady = payload?.promotion_report_ready ? '1' : '0';
        tr.dataset.promotionTotal = String(payload?.promotion_total || 0);
        tr.dataset.promotionDone = String(payload?.promotion_done || 0);
        tr.dataset.promotionTarget = String(payload?.promotion_target || 0);
        tr.dataset.promotionAttempted = String(payload?.promotion_attempted || 0);
        tr.dataset.level1Total = String(payload?.level1_total || 0);
        tr.dataset.level1Success = String(payload?.level1_success || 0);
        tr.dataset.level1Required = String(payload?.level1_required || 0);
        tr.dataset.level2Total = String(payload?.level2_total || 0);
        tr.dataset.level2Success = String(payload?.level2_success || 0);
        tr.dataset.level2Required = String(payload?.level2_required || 0);
        tr.dataset.level3Total = String(payload?.level3_total || 0);
        tr.dataset.level3Success = String(payload?.level3_success || 0);
        tr.dataset.level3Required = String(payload?.level3_required || 0);
        tr.dataset.crowdPlanned = String(payload?.crowd_planned || 0);
        const crowdTargetRaw = payload?.crowd_target ?? payload?.crowd_total ?? 0;
        const crowdTarget = Number(crowdTargetRaw || 0) || 0;
        const crowdAttemptedRaw = payload?.crowd_attempted ?? payload?.crowd_attempted_total ?? payload?.crowd_total_attempted ?? 0;
        const crowdAttempted = Number(crowdAttemptedRaw || 0) || 0;
        tr.dataset.crowdTotal = String(crowdTarget);
        tr.dataset.crowdTarget = String(crowdTarget);
        tr.dataset.crowdAttempted = String(crowdAttempted);
        tr.dataset.crowdCompleted = String(payload?.crowd_completed || 0);
        tr.dataset.crowdRunning = String(payload?.crowd_running || 0);
        tr.dataset.crowdQueued = String(payload?.crowd_queued || 0);
        tr.dataset.crowdFailed = String(payload?.crowd_failed || 0);
        tr.dataset.crowdManual = String(payload?.crowd_manual ?? payload?.crowd_manual_fallback ?? 0);

        const createdRaw = payload?.created_at || payload?.createdAt || '';
        let createdTimestamp = 0;
        if (createdRaw) {
            const parsed = Date.parse(createdRaw);
            if (!Number.isNaN(parsed)) {
                createdTimestamp = Math.floor(parsed / 1000);
            }
        }
        if (!Number.isFinite(createdTimestamp) || createdTimestamp <= 0) {
            createdTimestamp = Math.floor(Date.now() / 1000);
        }
        const createdIso = createdRaw || new Date(createdTimestamp * 1000).toISOString();
        tr.dataset.createdAt = String(createdTimestamp);
        tr.dataset.createdAtRaw = createdIso;
        tr.dataset.createdAtHuman = formatDateTimeShort(createdTimestamp);
        const duplicateKey = normalizeLinkKey(urlVal);
        tr.dataset.duplicateKey = duplicateKey;
        tr.dataset.duplicateCount = '1';
        tr.dataset.language = languageValue.toLowerCase();
        tr.dataset.searchIndex = `${urlVal} ${anchorVal} ${wishVal}`.toLowerCase();
        tr.dataset.hasPromotion = '0';
        tr.dataset.promotionCreated = '0';
        tr.dataset.promotionStarted = '0';
        tr.dataset.promotionUpdated = '0';
        tr.dataset.promotionFinished = '0';

        const promotionChargeAmount = escapeHtml(String(PROMOTION_CHARGE_AMOUNT ?? ''));
        const promotionChargeBase = escapeHtml(String(PROMOTION_CHARGE_BASE ?? ''));
        const promotionChargeSavings = escapeHtml(String(PROMOTION_CHARGE_SAVINGS ?? ''));

        const isLevel1Enabled = !(PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level1 === false);
        const isLevel2Enabled = Boolean(PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level2);
        const isLevel3Enabled = Boolean(PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level3);
        const isCrowdEnabled = Boolean(PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.crowd);
        const progressLevelSections = [];
        if (isLevel1Enabled) {
            progressLevelSections.push(`
                        <div class="promotion-progress-level promotion-progress-level1 d-none" data-level="1">
                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                <span><?php echo __('Уровень 1'); ?></span>
                                <span class="promotion-progress-value">0 / 0</span>
                            </div>
                            <div class="progress progress-thin">
                                <div class="progress-bar promotion-progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                            </div>
                        </div>`);
        }
        if (isLevel2Enabled) {
            progressLevelSections.push(`
                        <div class="promotion-progress-level promotion-progress-level2 d-none" data-level="2">
                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                <span><?php echo __('Уровень 2'); ?></span>
                                <span class="promotion-progress-value">0 / 0</span>
                            </div>
                            <div class="progress progress-thin">
                                <div class="progress-bar promotion-progress-bar bg-info" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                            </div>
                        </div>`);
        }
        if (isLevel3Enabled) {
            progressLevelSections.push(`
                        <div class="promotion-progress-level promotion-progress-level3 d-none" data-level="3">
                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                <span><?php echo __('Уровень 3'); ?></span>
                                <span class="promotion-progress-value">0 / 0</span>
                            </div>
                            <div class="progress progress-thin">
                                <div class="progress-bar promotion-progress-bar bg-warning" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                            </div>
                        </div>`);
        }
        if (isCrowdEnabled) {
            progressLevelSections.push(`
                        <div class="promotion-progress-level promotion-progress-crowd d-none" data-level="crowd">
                            <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                <span><?php echo __('Крауд'); ?></span>
                                <span class="promotion-progress-value">0 / 0</span>
                            </div>
                            <div class="progress progress-thin">
                                <div class="progress-bar promotion-progress-bar bg-success" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                            </div>
                        </div>`);
        }
        const progressLevelsMarkup = progressLevelSections.join('');

        tr.innerHTML = `
            <td data-label="#"></td>
            <td class="url-cell" data-label="<?php echo __('Ссылка'); ?>">
                <div class="small text-muted host-muted"><i class="bi bi-globe2 me-1"></i>${escapeHtml(hostDisp)}</div>
                <a href="${escapeHtml(urlVal)}" target="_blank" class="view-url text-truncate-path" title="${escapeHtml(urlVal)}" data-bs-toggle="tooltip">${escapeHtml(pathDisp)}</a>
                <div class="link-meta small text-muted mt-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="link-meta__created d-none" data-created-label></span>
                    <span class="badge bg-warning-subtle text-warning-emphasis d-none" data-duplicate-badge></span>
                </div>
                <input type="url" class="form-control d-none edit-url" name="edited_links[${newId}][url]" value="${escapeAttribute(urlVal)}" disabled />
            </td>
            <td class="anchor-cell" data-label="<?php echo __('Анкор'); ?>">
                <span class="view-anchor text-truncate-anchor" title="${escapeHtml(anchorVal)}" data-bs-toggle="tooltip">${escapeHtml(anchorVal)}</span>
                <input type="text" class="form-control d-none edit-anchor" name="edited_links[${newId}][anchor]" value="${escapeAttribute(anchorVal)}" disabled />
            </td>
            <td class="language-cell" data-label="<?php echo __('Язык'); ?>">
                <span class="badge bg-secondary-subtle text-light-emphasis view-language text-uppercase">${escapeHtml(displayLang)}</span>
                <select class="form-select form-select-sm d-none edit-language" name="edited_links[${newId}][language]" disabled>
                    ${langOptions}
                </select>
            </td>
            <td class="wish-cell" data-label="<?php echo __('Пожелание'); ?>">
                <button type="button" class="icon-btn action-show-wish" data-wish="${escapeAttribute(wishVal)}" title="<?php echo __('Показать пожелание'); ?>" data-bs-toggle="tooltip"><i class="bi bi-journal-text"></i></button>
                <div class="view-wish d-none">${escapeHtml(wishVal)}</div>
                <textarea class="form-control d-none edit-wish" rows="2" name="edited_links[${newId}][wish]" disabled>${escapeHtml(wishVal)}</textarea>
            </td>
            <td data-label="<?php echo __('Статус'); ?>" class="status-cell">
                <div class="promotion-status-block small mt-2 text-muted"
                     data-run-id=""
                     data-status="idle"
                     data-stage=""
                     data-total="0"
                     data-done="0"
                     data-report-ready="0"
                     data-level1-total="0"
                     data-level1-success="0"
                     data-level1-required="0"
                     data-level2-total="0"
                     data-level2-success="0"
                     data-level2-required="0"
                     data-level3-total="0"
                     data-level3-success="0"
                     data-level3-required="0"
                     data-crowd-planned="0"
                     data-crowd-total="0"
                     data-crowd-target="0"
                     data-crowd-attempted="0"
                     data-crowd-completed="0"
                     data-crowd-running="0"
                     data-crowd-queued="0"
                     data-crowd-failed="0"
                     data-level1-enabled="${PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level1 === false ? '0' : '1'}"
                     data-level2-enabled="${PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level2 ? '1' : '0'}"
                     data-level3-enabled="${PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.level3 ? '1' : '0'}"
                     data-crowd-enabled="${PROMOTION_LEVELS_ENABLED && PROMOTION_LEVELS_ENABLED.crowd ? '1' : '0'}">
                    <div class="promotion-status-top">
                        <span class="promotion-status-heading"><?php echo __('Продвижение'); ?>:</span>
                        <span class="promotion-status-label ms-1"><?php echo __('Продвижение не запускалось'); ?></span>
                        <span class="promotion-progress-count ms-1 d-none"></span>
                    </div>
                    <div class="promotion-progress-visual mt-2 d-none">
                        ${progressLevelsMarkup}
                    </div>
                    <div class="promotion-progress-details text-muted d-none"></div>
                    <div class="promotion-status-dates small text-muted mt-2 d-none" data-promotion-dates>
                        <i class="bi bi-clock-history me-1"></i>
                        <span data-promotion-last></span>
                        <span class="dot">•</span>
                        <span data-promotion-finished></span>
                    </div>
                    <div class="promotion-status-complete mt-2 d-none" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo __('Передача ссылочного веса займет 2-3 месяца, мы продолжаем мониторинг.'); ?>">
                        <i class="bi bi-patch-check-fill text-success"></i>
                        <span class="promotion-status-complete-text"><?php echo __('Продвижение завершено'); ?></span>
                    </div>
                </div>
            </td>
            <td class="text-end" data-label="<?php echo __('Действия'); ?>">
                <div class="link-actions d-flex flex-wrap justify-content-end gap-2">
                    <button type="button" class="icon-btn action-analyze" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                    <button type="button"
                            class="btn btn-sm btn-publish action-promote"
                            data-url="${escapeHtml(urlVal)}"
                            data-id="${String(newId)}"
                            data-charge-amount="${promotionChargeAmount}"
                            data-charge-formatted="${escapeHtml(PROMOTION_CHARGE_AMOUNT_FORMATTED)}"
                            data-charge-base="${promotionChargeBase}"
                            data-charge-base-formatted="${escapeHtml(PROMOTION_CHARGE_BASE_FORMATTED)}"
                            data-charge-savings="${promotionChargeSavings}"
                            data-charge-savings-formatted="${escapeHtml(PROMOTION_CHARGE_SAVINGS_FORMATTED)}"
                            data-discount-percent="${escapeHtml(String(PROMOTION_DISCOUNT_PERCENT))}">
                        <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span>
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm action-promotion-progress d-none" data-run-id="0" data-url="${escapeHtml(urlVal)}">
                        <i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span>
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm action-promotion-report d-none" data-run-id="0" data-url="${escapeHtml(urlVal)}">
                        <i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span>
                    </button>
                    <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="icon-btn action-remove" data-id="${String(newId)}" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        `;

        return tr;
    }

    function isPromotionActiveStatus(status) {
        return PROMOTION_ACTIVE_STATUSES.includes(status);
    }

    function updatePromotionBlock(tr, data) {
        if (!tr || !data) return;
        const block = tr.querySelector('.promotion-status-block');
        if (!block) return;
        const linkIdRaw = data.link_id ?? data.linkId ?? data.id ?? null;
        if (linkIdRaw !== null && linkIdRaw !== undefined) {
            const linkIdNum = Number(linkIdRaw);
            if (Number.isFinite(linkIdNum) && linkIdNum > 0) {
                const linkIdStr = String(linkIdNum);
                tr.setAttribute('data-id', linkIdStr);
                tr.dataset.id = linkIdStr;
                tr.dataset.linkId = linkIdStr;
                tr.dataset.promotionLinkId = linkIdStr;
                const actionsContainer = tr.querySelector('.link-actions');
                if (actionsContainer) {
                    const promoteBtn = actionsContainer.querySelector('.action-promote');
                    if (promoteBtn) {
                        promoteBtn.setAttribute('data-id', linkIdStr);
                        promoteBtn.dataset.id = linkIdStr;
                    }
                    const progressBtn = actionsContainer.querySelector('.action-promotion-progress');
                    if (progressBtn) {
                        progressBtn.dataset.linkId = linkIdStr;
                    }
                    const reportBtn = actionsContainer.querySelector('.action-promotion-report');
                    if (reportBtn) {
                        reportBtn.dataset.linkId = linkIdStr;
                    }
                }
            }
        }
        if (data.target_url) {
            tr.dataset.promotionTargetUrl = data.target_url;
        }
        const status = data.status || 'idle';
        tr.dataset.promotionStatus = status;
        tr.dataset.promotionStage = data.stage || '';
        tr.dataset.promotionRunId = data.run_id ? String(data.run_id) : '';
        tr.dataset.promotionReportReady = data.report_ready ? '1' : '0';
        tr.dataset.promotionTotal = data.target || data.total || 0;
        tr.dataset.promotionDone = data.done || 0;
        block.dataset.status = status;
        block.dataset.stage = data.stage || '';
        block.dataset.runId = tr.dataset.promotionRunId;
        block.dataset.reportReady = tr.dataset.promotionReportReady;

        const topEl = block.querySelector('.promotion-status-top');
        const labelEl = block.querySelector('.promotion-status-label');
        const countEl = block.querySelector('.promotion-progress-count');
        const resolveLevelEnabled = (lvl) => {
            const datasetKey = `level${lvl}Enabled`;
            if (datasetKey in block.dataset) {
                const raw = (block.dataset[datasetKey] || '').trim().toLowerCase();
                if (raw === '0' || raw === 'false' || raw === 'no' || raw === 'off') {
                    return false;
                }
                if (raw === '1' || raw === 'true' || raw === 'yes' || raw === 'on') {
                    return true;
                }
                if (raw === '') {
                    return false;
                }
                return Boolean(raw);
            }
            if (PROMOTION_LEVELS_ENABLED && Object.prototype.hasOwnProperty.call(PROMOTION_LEVELS_ENABLED, `level${lvl}`)) {
                return Boolean(PROMOTION_LEVELS_ENABLED[`level${lvl}`]);
            }
            return false;
        };
        const resolveCrowdEnabled = () => {
            if ('crowdEnabled' in block.dataset) {
                const raw = (block.dataset.crowdEnabled || '').trim().toLowerCase();
                if (raw === '0' || raw === 'false' || raw === 'no' || raw === 'off') {
                    return false;
                }
                if (raw === '1' || raw === 'true' || raw === 'yes' || raw === 'on') {
                    return true;
                }
                if (raw === '') {
                    return false;
                }
                return Boolean(raw);
            }
            if (PROMOTION_LEVELS_ENABLED && Object.prototype.hasOwnProperty.call(PROMOTION_LEVELS_ENABLED, 'crowd')) {
                return Boolean(PROMOTION_LEVELS_ENABLED.crowd);
            }
            return false;
        };
        const showStatusTop = status !== 'completed';
        if (topEl) {
            topEl.classList.toggle('d-none', !showStatusTop);
        }
        if (countEl) {
            const shouldShowCount = showStatusTop && data.target;
            if (shouldShowCount) {
                countEl.classList.remove('d-none');
                countEl.textContent = `(${data.done || 0} / ${data.target})`;
            } else {
                countEl.classList.add('d-none');
                countEl.textContent = '';
            }
        }

        if (labelEl) {
            const resolved = PROMOTION_STATUS_LABELS[status] || (status === 'idle' ? '<?php echo __('Продвижение не запускалось'); ?>' : status);
            labelEl.textContent = resolved;
        }

        const progressVisual = block.querySelector('.promotion-progress-visual');
        if (progressVisual) {
            progressVisual.classList.toggle('d-none', !isPromotionActiveStatus(status));
        }

        if (data.crowd && typeof data.crowd === 'object') {
            const crowd = data.crowd;
            tr.dataset.crowdPlanned = String(crowd.planned ?? 0);
            const crowdTargetVal = Number(crowd.target ?? crowd.total ?? 0) || 0;
            tr.dataset.crowdTotal = String(crowdTargetVal);
            tr.dataset.crowdTarget = String(crowdTargetVal);
            tr.dataset.crowdAttempted = String(crowd.attempted ?? 0);
            tr.dataset.crowdCompleted = String(crowd.completed ?? 0);
            tr.dataset.crowdRunning = String(crowd.running ?? 0);
            tr.dataset.crowdQueued = String(crowd.queued ?? 0);
            tr.dataset.crowdFailed = String(crowd.failed ?? 0);
            tr.dataset.crowdManual = String(crowd.manual_fallback ?? 0);
        }

        const createdTs = parseTimestampToSeconds(data.created_at ?? data.createdAt ?? data.createdAtSeconds ?? 0);
        const startedTs = parseTimestampToSeconds(data.started_at ?? data.startedAt ?? data.startedAtSeconds ?? 0);
        const updatedTs = parseTimestampToSeconds(data.updated_at ?? data.updatedAt ?? data.updatedAtSeconds ?? 0);
        const finishedTs = parseTimestampToSeconds(data.finished_at ?? data.finishedAt ?? data.finishedAtSeconds ?? 0);
        tr.dataset.promotionCreated = String(createdTs || 0);
        tr.dataset.promotionStarted = String(startedTs || 0);
        tr.dataset.promotionUpdated = String(updatedTs || 0);
        tr.dataset.promotionFinished = String(finishedTs || 0);
        const hasHistory = createdTs > 0 || startedTs > 0 || updatedTs > 0 || finishedTs > 0 || isPromotionActiveStatus(status) || status === 'completed';
        tr.dataset.hasPromotion = hasHistory ? '1' : '0';

        const datesBlock = block.querySelector('[data-promotion-dates]');
        if (datesBlock) {
            const lastSpan = datesBlock.querySelector('[data-promotion-last]');
            const finishedSpan = datesBlock.querySelector('[data-promotion-finished]');
            const dotEl = datesBlock.querySelector('.dot');
            const lastTs = updatedTs || startedTs || createdTs;
            const showLast = lastTs > 0;
            const showFinished = finishedTs > 0;
            if (lastSpan) {
                lastSpan.textContent = showLast ? PROMOTION_LAST_LABEL_TEMPLATE.replace('%s', formatDateTimeShort(lastTs)) : '';
            }
            if (finishedSpan) {
                finishedSpan.textContent = showFinished ? PROMOTION_FINISHED_LABEL_TEMPLATE.replace('%s', formatDateTimeShort(finishedTs)) : '';
            }
            if (dotEl) {
                dotEl.classList.toggle('d-none', !showFinished);
            }
            datesBlock.classList.toggle('d-none', !showLast && !showFinished);
        }

        if (data.progress && typeof data.progress === 'object') {
            const levels = ['level1', 'level2', 'level3'];
            levels.forEach((level, idx) => {
                const levelBlock = block.querySelector(`.promotion-progress-level[data-level="${idx + 1}"]`);
                const levelData = data.levels?.[idx + 1] || {};
                if (!levelBlock) return;
                if (!resolveLevelEnabled(idx + 1)) {
                    levelBlock.classList.add('d-none');
                    const valueFallback = levelBlock.querySelector('.promotion-progress-value');
                    const barFallback = levelBlock.querySelector('.promotion-progress-bar');
                    if (valueFallback) {
                        valueFallback.textContent = '0 / 0';
                    }
                    if (barFallback) {
                        barFallback.style.width = '0%';
                    }
                    return;
                }
                const total = levelData.required || levelData.total || 0;
                const done = levelData.success || 0;
                const valueEl = levelBlock.querySelector('.promotion-progress-value');
                const barEl = levelBlock.querySelector('.promotion-progress-bar');
                if (total > 0) {
                    levelBlock.classList.remove('d-none');
                    if (valueEl) valueEl.textContent = `${done} / ${total}`;
                    if (barEl) barEl.style.width = Math.min(100, Math.round((done / total) * 100)) + '%';
                } else {
                    levelBlock.classList.add('d-none');
                }
            });
            const crowdBlock = block.querySelector('.promotion-progress-crowd');
            if (crowdBlock) {
                if (!resolveCrowdEnabled()) {
                    crowdBlock.classList.add('d-none');
                    const valueReset = crowdBlock.querySelector('.promotion-progress-value');
                    const barReset = crowdBlock.querySelector('.promotion-progress-bar');
                    if (valueReset) {
                        valueReset.textContent = '0 / 0';
                    }
                    if (barReset) {
                        barReset.style.width = '0%';
                    }
                } else {
                    const crowd = data.crowd || {};
                    const total = crowd.total || crowd.planned || 0;
                    const done = crowd.completed || 0;
                    const valueEl = crowdBlock.querySelector('.promotion-progress-value');
                    const barEl = crowdBlock.querySelector('.promotion-progress-bar');
                    if (total > 0) {
                        crowdBlock.classList.remove('d-none');
                        if (valueEl) valueEl.textContent = `${done} / ${total}`;
                        if (barEl) barEl.style.width = Math.min(100, Math.round((done / total) * 100)) + '%';
                    } else {
                        crowdBlock.classList.add('d-none');
                    }
                }
            }
        }

        const completeBlock = block.querySelector('.promotion-status-complete');
        if (completeBlock) {
            completeBlock.classList.toggle('d-none', status !== 'completed');
        }
        const detailsBlock = block.querySelector('.promotion-progress-details');
        if (detailsBlock) {
            const hasDetails = detailsBlock.children && detailsBlock.children.length > 0;
            const shouldShowDetails = hasDetails && isPromotionActiveStatus(status);
            detailsBlock.classList.toggle('d-none', !shouldShowDetails);
        }
    }

    function parseDatasetNumber(tr, key) {
        if (!tr || !tr.dataset) return 0;
        const raw = tr.dataset[key];
        if (raw === undefined) { return 0; }
        const num = Number(raw);
        return Number.isFinite(num) ? num : 0;
    }

    function snapshotPromotionState(tr) {
        if (!tr) return null;
        const read = (key, fallback = 0) => {
            const value = parseDatasetNumber(tr, key);
            return Number.isFinite(value) ? value : fallback;
        };
        const levels = {
            1: {
                total: read('level1Total'),
                success: read('level1Success'),
                required: read('level1Required')
            },
            2: {
                total: read('level2Total'),
                success: read('level2Success'),
                required: read('level2Required')
            },
            3: {
                total: read('level3Total'),
                success: read('level3Success'),
                required: read('level3Required')
            }
        };
        const crowd = {
            planned: read('crowdPlanned'),
            total: read('crowdTotal'),
            target: read('crowdTarget'),
            attempted: read('crowdAttempted'),
            completed: read('crowdCompleted'),
            running: read('crowdRunning'),
            queued: read('crowdQueued'),
            failed: read('crowdFailed'),
            manual_fallback: read('crowdManual')
        };
        const targetRaw = read('promotionTarget');
        const totalRaw = read('promotionTotal');
        return {
            status: tr.dataset.promotionStatus || 'idle',
            stage: tr.dataset.promotionStage || '',
            runId: tr.dataset.promotionRunId || '',
            reportReady: tr.dataset.promotionReportReady === '1',
            target: targetRaw > 0 ? targetRaw : totalRaw,
            done: read('promotionDone'),
            levels,
            crowd
        };
    }

    function buildPayloadFromSnapshot(snapshot) {
        if (!snapshot) return null;
        const levels = {};
        [1, 2, 3].forEach(level => {
            if (snapshot.levels && snapshot.levels[level]) {
                levels[level] = {
                    required: snapshot.levels[level].required,
                    total: snapshot.levels[level].total,
                    success: snapshot.levels[level].success
                };
            }
        });
        const payload = {
            status: snapshot.status,
            stage: snapshot.stage,
            run_id: snapshot.runId,
            report_ready: snapshot.reportReady,
            target: snapshot.target,
            done: snapshot.done,
            levels
        };
        if (snapshot.crowd) {
            payload.crowd = {
                planned: snapshot.crowd.planned,
                total: snapshot.crowd.total,
                target: snapshot.crowd.target,
                attempted: snapshot.crowd.attempted,
                completed: snapshot.crowd.completed,
                running: snapshot.crowd.running,
                queued: snapshot.crowd.queued,
                failed: snapshot.crowd.failed,
                manual_fallback: snapshot.crowd.manual_fallback
            };
        }
        return payload;
    }

    function applyPromotionPayload(tr, payload, options = {}) {
        if (!tr || !payload) return;
        updatePromotionBlock(tr, payload);
        refreshActionsCell(tr);
        if (!options.skipStats) {
            recalcPromotionStats();
        }
    }

    function applyOptimisticPromotionState(tr) {
        if (!tr) return { snapshot: null, applied: false };
        const snapshot = snapshotPromotionState(tr);
        const payload = buildPayloadFromSnapshot(snapshot);
        if (!payload) {
            return { snapshot, applied: false };
        }
        payload.status = 'queued';
        payload.stage = 'pending_level1';
        payload.run_id = '';
        payload.report_ready = false;
        applyPromotionPayload(tr, payload);
        return { snapshot, applied: true };
    }

    function restorePromotionState(tr, snapshot) {
        if (!tr || !snapshot) return;
        const payload = buildPayloadFromSnapshot(snapshot);
        if (!payload) return;
        applyPromotionPayload(tr, payload);
    }

    function isAbortError(error) {
        if (!error) return false;
        if (error.name === 'AbortError') return true;
        if (typeof error.code === 'number' && error.code === 20) return true;
        return false;
    }

    function updateRowUI(url, status, payload) {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        rows.forEach(tr => {
            const linkEl = tr.querySelector('.url-cell .view-url');
            if (!linkEl || linkEl.getAttribute('href') !== url) return;
            tr.dataset.publicationStatus = status;
            tr.dataset.postUrl = payload?.post_url || '';
            tr.dataset.network = payload?.network || '';
            const badge = tr.querySelector('.publication-status-badge');
            if (badge) {
                badge.textContent = status;
            }
        });
    }

    

    function openPromotionConfirm(btn, url) {
        const linkId = btn?.getAttribute('data-id') || btn?.dataset?.id || btn?.closest('tr')?.getAttribute('data-id') || btn?.closest('tr')?.dataset?.id || '';
        const modalInstance = getPromotionConfirmModalInstance();
        if (!promotionConfirmModalEl || !modalInstance) {
            startPromotion(btn, url, linkId);
            return;
        }
        const chargeFormatted = btn?.getAttribute('data-charge-formatted') || PROMOTION_CHARGE_AMOUNT_FORMATTED;
        const chargeBaseFormatted = btn?.getAttribute('data-charge-base-formatted') || PROMOTION_CHARGE_BASE_FORMATTED;
        const chargeSavingsFormatted = btn?.getAttribute('data-charge-savings-formatted') || PROMOTION_CHARGE_SAVINGS_FORMATTED;
        const chargeSavingsRaw = Number((btn?.getAttribute('data-charge-savings') ?? PROMOTION_CHARGE_SAVINGS) || 0);
        const discountPercentAttribute = btn?.getAttribute('data-discount-percent');
        const discountPercent = Number(discountPercentAttribute !== null ? discountPercentAttribute : (PROMOTION_DISCOUNT_PERCENT || 0));
    promotionConfirmContext = { btn, url, linkId };
        if (promotionConfirmAmountEl) {
            promotionConfirmAmountEl.textContent = chargeFormatted;
        }
        if (promotionConfirmBaseEl) {
            promotionConfirmBaseEl.textContent = chargeBaseFormatted;
        }
        if (promotionConfirmUrlEl) {
            promotionConfirmUrlEl.textContent = url;
            promotionConfirmUrlEl.setAttribute('href', url);
        }
        if (promotionConfirmDiscountBlock) {
            if (discountPercent > 0.0001) {
                promotionConfirmDiscountBlock.classList.remove('d-none');
                if (promotionConfirmDiscountValue) {
                    const percentText = discountPercent % 1 === 0 ? discountPercent.toFixed(0) : discountPercent.toFixed(2);
                    promotionConfirmDiscountValue.textContent = percentText.replace('.', ',');
                }
            } else {
                promotionConfirmDiscountBlock.classList.add('d-none');
            }
        }
        if (promotionConfirmSavingsBlock) {
            if (chargeSavingsRaw > 0.0001) {
                promotionConfirmSavingsBlock.classList.remove('d-none');
                if (promotionConfirmSavingsValue) {
                    promotionConfirmSavingsValue.textContent = chargeSavingsFormatted;
                }
            } else {
                promotionConfirmSavingsBlock.classList.add('d-none');
            }
        }
        // Align modal balance currency with app's global currency code
        const modalChargeCard = document.querySelector('.promotion-confirm-amount');
        const modalCurrency = modalChargeCard?.getAttribute('data-currency-code') || navBalanceCurrency;
        if (modalCurrency) {
            promotionPrevCurrency = navBalanceCurrency;
            navBalanceCurrency = modalCurrency;
        }
        const currentBalanceFormatted = formatBalanceLocale(CURRENT_USER_BALANCE_RAW);
        document.querySelectorAll('[data-current-balance-display]').forEach(el => {
            if (Number.isFinite(CURRENT_USER_BALANCE_RAW)) {
                el.dataset.balanceRaw = CURRENT_USER_BALANCE_RAW.toFixed(2);
            }
            if (currentBalanceFormatted) {
                el.textContent = currentBalanceFormatted;
            }
        });
        promotionConfirmAcceptBtn?.classList.remove('disabled');
        if (promotionConfirmAcceptBtn) {
            promotionConfirmAcceptBtn.disabled = false;
        }
        modalInstance.show();
    }

    const promotionConfirmAmountEl = document.querySelector('[data-promotion-charge-amount]');
    const promotionConfirmBaseEl = document.querySelector('[data-promotion-charge-base]');
    const promotionConfirmSavingsBlock = document.querySelector('[data-promotion-savings-block]');
    const promotionConfirmSavingsValue = document.querySelector('[data-promotion-charge-savings]');
    const promotionConfirmDiscountBlock = document.querySelector('[data-promotion-discount-block]');
    const promotionConfirmDiscountValue = document.querySelector('[data-promotion-discount-value]');
    const promotionConfirmUrlEl = document.querySelector('[data-promotion-link]');
    const promotionConfirmAcceptBtn = document.getElementById('promotionConfirmAccept');
    let promotionConfirmContext = null;
    let promotionPrevCurrency = null;

    if (promotionConfirmAcceptBtn) {
        promotionConfirmAcceptBtn.addEventListener('click', async () => {
            if (!promotionConfirmContext) {
                getPromotionConfirmModalInstance()?.hide();
                return;
            }
            const { btn, url, linkId } = promotionConfirmContext;
            promotionConfirmAcceptBtn.disabled = true;
            getPromotionConfirmModalInstance()?.hide();
            promotionConfirmContext = null;
            try {
                await startPromotion(btn, url, linkId);
            } finally {
                promotionConfirmAcceptBtn.disabled = false;
            }
        });
    }

    function bindDynamicRowActions() {
        document.querySelectorAll('.action-promote').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url') || (btn.closest('tr')?.querySelector('.url-cell .view-url')?.getAttribute('href')) || '';
                openPromotionConfirm(btn, url);
            });
        });
        document.querySelectorAll('.action-promotion-progress').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                openPromotionReport(btn);
            });
        });
        document.querySelectorAll('.action-promotion-report').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                openPromotionReport(btn);
            });
        });
        document.querySelectorAll('.action-analyze').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                const linkEl = tr?.querySelector('.url-cell .view-url');
                const url = linkEl ? linkEl.getAttribute('href') : '';
                if (url) openAnalyzeModal(url);
            });
        });
        document.querySelectorAll('.action-show-wish').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => {
                const wish = btn.getAttribute('data-wish') || btn.closest('tr')?.querySelector('.view-wish')?.textContent || '';
                openWishModal(wish);
            });
        });
        document.querySelectorAll('.action-edit').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => handleEditButton(btn));
        });
        document.querySelectorAll('.action-remove').forEach(btn => {
            if (btn.dataset.bound==='1') return; btn.dataset.bound='1';
            btn.addEventListener('click', () => handleRemoveButton(btn));
        });
    }

    if (!linkTableManager) {
        linkTableManager = createLinkTableManager();
        try {
            linkTableManager.init();
        } catch (e) {
            console.error('Link table manager init failed', e);
        }
    } else {
        try {
            linkTableManager.sync();
        } catch (e) {
            console.error('Link table manager sync failed', e);
        }
    }
    bindDynamicRowActions();
    recalcPromotionStats();

    async function pollStatusesOnce() {
        try {
            const rows = document.querySelectorAll('table.table-links tbody tr');
            for (const tr of rows) {
                const linkEl = tr.querySelector('.url-cell .view-url');
                if (!linkEl) continue;
                const currentStatus = tr.dataset.publicationStatus || 'not_published';
                if (currentStatus !== 'pending') continue;
                const url = linkEl.getAttribute('href');
                const fd = new URLSearchParams();
                fd.set('project_id', String(PROJECT_ID));
                fd.set('url', url);
                const res = await fetch('<?php echo pp_url('public/publication_status.php'); ?>?' + fd.toString(), { credentials:'same-origin' });
                const data = await res.json().catch(()=>null);
                if (!data || !data.ok) continue;
                if (data.status === 'published') {
                    updateRowUI(url, 'published', data);
                } else if (data.status === 'manual_review') {
                    updateRowUI(url, 'manual_review', data);
                } else if (data.status === 'failed') {
                    updateRowUI(url, 'not_published', {});
                }
            }
        } catch (_e) { /* ignore */ }
        await pollPromotionStatusesOnce();
    }

    async function pollPromotionStatusesOnce() {
        try {
            const rows = document.querySelectorAll('table.table-links tbody tr');
            for (const tr of rows) {
                const promotionStatus = tr.dataset.promotionStatus || 'idle';
                if (!isPromotionActiveStatus(promotionStatus) && promotionStatus !== 'report_ready') { continue; }
                const linkEl = tr.querySelector('.url-cell .view-url');
                if (!linkEl) continue;
                const url = linkEl.getAttribute('href');
                if (!url) continue;
                const linkIdRaw = tr.getAttribute('data-id') || tr.dataset?.id || tr.dataset?.promotionLinkId || '';
                const linkIdVal = Number(linkIdRaw);
                const params = new URLSearchParams();
                params.set('project_id', String(PROJECT_ID));
                params.set('url', url);
                const runId = tr.dataset.promotionRunId || '';
                if (runId) { params.set('run_id', runId); }
                if (Number.isFinite(linkIdVal) && linkIdVal > 0) {
                    params.set('link_id', String(linkIdVal));
                }
                const res = await fetch('<?php echo pp_url('public/promotion_status.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
                const data = await res.json().catch(()=>null);
                if (!data || !data.ok) continue;
                if (!data.link_id && Number.isFinite(linkIdVal) && linkIdVal > 0) {
                    data.link_id = linkIdVal;
                }
                if (!data.target_url) {
                    data.target_url = url;
                }
                updatePromotionBlock(tr, data);
                refreshActionsCell(tr);
            }
        } catch (_e) { /* ignore */ }
    }

    let pollTimer = null;
    function startPolling(){ if (pollTimer) return; pollTimer = setInterval(pollStatusesOnce, 4000); }
    function stopPolling(){ if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPolling(); else startPolling(); });
    startPolling();

    function openAnalyzeModal(url){
        const modalEl = document.getElementById('analyzeModal');
        const resEl = document.getElementById('analyze-result');
        const loadEl = document.getElementById('analyze-loading');
        if (!modalEl) return;
        const m = new bootstrap.Modal(modalEl);
        resEl.classList.add('d-none');
        resEl.innerHTML = '';
        loadEl.classList.remove('d-none');
        m.show();
        (async () => {
            const csrf = getCsrfToken();
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('project_id', String(PROJECT_ID));
            fd.append('url', url);
            try {
                const resp = await fetch('<?php echo pp_url('public/analyze_url.php'); ?>', { method:'POST', body: fd, credentials:'same-origin' });
                const data = await resp.json();
                loadEl.classList.add('d-none');
                if (!data || !data.ok) {
                    resEl.innerHTML = '<div class="alert alert-danger">' + (escapeHtml(data?.error || 'ERROR')) + '</div>';
                    resEl.classList.remove('d-none');
                    return;
                }
                const d = data.data || {};
                const rows = [];
                const addRow = (k, v) => { if (v && String(v).trim() !== '') rows.push(`<tr><th>${escapeHtml(k)}</th><td>${escapeHtml(String(v))}</td></tr>`); };
                addRow('<?php echo __('URL'); ?>', d.final_url || url);
                addRow('<?php echo __('Язык'); ?>', d.lang || '');
                addRow('<?php echo __('Регион'); ?>', d.region || '');
                addRow('<?php echo __('Заголовок'); ?>', d.title || '');
                addRow('<?php echo __('Описание'); ?>', d.description || '');
                addRow('<?php echo __('Canonical'); ?>', d.canonical || '');
                addRow('<?php echo __('Дата публикации'); ?>', d.published_time || '');
                addRow('<?php echo __('Дата обновления'); ?>', d.modified_time || '');
                const extended = d.hreflang && Array.isArray(d.hreflang) && d.hreflang.length ? `<details class="mt-3"><summary class="fw-semibold"><?php echo __('Альтернативы hreflang'); ?></summary><div class="mt-2 small">${d.hreflang.map(h=>escapeHtml(`${h.hreflang || ''} → ${h.href || ''}`)).join('<br>')}</div></details>` : '';
                resEl.innerHTML = `
                    <div class="table-responsive">
                      <table class="table table-sm">
                        <tbody>${rows.join('')}</tbody>
                      </table>
                    </div>
                    ${extended}
                `;
                resEl.classList.remove('d-none');
            } catch (e) {
                loadEl.classList.add('d-none');
                resEl.innerHTML = '<div class="alert alert-danger"><?php echo __('Сетевая ошибка'); ?></div>';
                resEl.classList.remove('d-none');
            }
        })();
    }

    function openWishModal(text){
        const el = document.getElementById('wishModal');
        const body = document.getElementById('wishContent');
        const copyBtn = document.getElementById('wishCopyBtn');
        if (!el || !body) return;
        body.textContent = text || '<?php echo __('Пусто'); ?>';
        const modal = new bootstrap.Modal(el);
        modal.show();
        if (copyBtn) {
            copyBtn.onclick = async () => {
                try { await navigator.clipboard.writeText(text || ''); copyBtn.classList.add('btn-success'); setTimeout(()=>copyBtn.classList.remove('btn-success'), 1000); } catch(e) {}
            };
        }
    }

    function initTooltips(root) {
        try {
            if (!window.bootstrap || !bootstrap.Tooltip) return;
            const scope = root || document;
            scope.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                try { bootstrap.Tooltip.getOrCreateInstance(el); } catch (e) {}
            });
        } catch (e) {}
    }

    initTooltips(document);

    if (useGlobal && globalWish) {
        useGlobal.addEventListener('change', () => {
            if (useGlobal.checked) {
                newWish.value = globalWish.value || '';
            }
        });
    }

    let CURRENT_PROJECT_HOST = PROJECT_HOST;

    if (useGlobal && globalWish) {
        useGlobal.addEventListener('change', () => {
            if (useGlobal.checked) {
                newWish.value = globalWish.value || '';
            }
        });
    }

    function serializeEditedRows() {
        const edited = {};
        document.querySelectorAll('table.table-links tbody tr').forEach(tr => {
            if (tr.dataset.edited !== '1') return;
            const id = tr.getAttribute('data-id');
            if (!id) return;
            const urlInput = tr.querySelector('.edit-url');
            const anchorInput = tr.querySelector('.edit-anchor');
            const langSelect = tr.querySelector('.edit-language');
            const wishTextarea = tr.querySelector('.edit-wish');
            edited[id] = {
                url: (urlInput?.value || '').trim(),
                anchor: (anchorInput?.value || '').trim(),
                language: (langSelect?.value || '').trim(),
                wish: (wishTextarea?.value || '').trim()
            };
        });
        return edited;
    }

    if (form) {
        form.addEventListener('submit', (event) => {
            const editingRow = form.querySelector('tr.editing');
            if (editingRow) {
                event.preventDefault();
                alert('<?php echo __('Завершите редактирование ссылки перед сохранением.'); ?>');
                editingRow.querySelector('.edit-url')?.focus();
                return;
            }
            const edited = serializeEditedRows();
            form.querySelectorAll('input[data-edited-hidden="1"]').forEach(el => el.remove());
            Object.keys(edited).forEach(id => {
                const data = edited[id];
                const makeHidden = (name, value) => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = `edited_links[${id}][${name}]`;
                    hidden.value = value;
                    hidden.dataset.editedHidden = '1';
                    form.appendChild(hidden);
                };
                makeHidden('url', data.url);
                makeHidden('anchor', data.anchor);
                makeHidden('language', data.language);
                makeHidden('wish', data.wish);
            });
        });
    }

    initWishAutoFill();

    if (addLinkBtn) {
        addLinkBtn.addEventListener('click', async function() {
            if (!newLinkInput || !newAnchorInput || !newWish) {
                alert('<?php echo __('Не удалось найти поля формы. Пожалуйста, обновите страницу.'); ?>');
                return;
            }
            const url = newLinkInput.value.trim();
            let anchor = newAnchorInput.value.trim();
            const lang = (newLangSelect ? newLangSelect.value.trim() : 'auto');
            const wish = newWish.value.trim();
            let anchorStrategyValue = newAnchorStrategy ? (newAnchorStrategy.value || '') : '';
            if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
            try {
                const u = new URL(url);
                const host = (u.hostname || '').toLowerCase().replace(/^www\./,'');
                if (CURRENT_PROJECT_HOST && host !== CURRENT_PROJECT_HOST) {
                    alert('<?php echo __('Ссылка должна быть в рамках домена проекта'); ?>: ' + CURRENT_PROJECT_HOST);
                    return;
                }
            } catch (e) {}

            if (!anchor && anchorStrategyValue !== 'none') {
                const autoAnchor = generateAutoAnchor(lang, url);
                if (autoAnchor) {
                    anchor = autoAnchor;
                    if (newAnchorStrategy) {
                        anchorStrategyValue = 'auto';
                        newAnchorStrategy.value = 'auto';
                    }
                    anchorUpdateLock = true;
                    newAnchorInput.value = anchor;
                    newAnchorInput.dispatchEvent(new Event('input', { bubbles: true }));
                    anchorUpdateLock = false;
                }
            }

            if (!anchorStrategyValue) {
                anchorStrategyValue = anchor ? 'manual' : 'auto';
            }

            setButtonLoading(addLinkBtn, true);
            let placeholderRow = null;
            let tbodyRef = ensureLinksTable();
            if (tbodyRef) {
                placeholderRow = createLinkPlaceholderRow(url);
                tbodyRef.appendChild(placeholderRow);
            }
            try {
                const fd = new FormData();
                fd.append('csrf_token', getCsrfToken());
                fd.append('update_project', '1');
                fd.append('ajax', '1');
                fd.append('wishes', globalWish.value || '');
                fd.append('added_links[0][url]', url);
                fd.append('added_links[0][anchor]', anchor);
                fd.append('added_links[0][language]', lang || 'auto');
                fd.append('added_links[0][wish]', wish);
                fd.append('added_links[0][anchor_strategy]', anchorStrategyValue);

                const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'Accept':'application/json' }, credentials: 'same-origin' });
                const data = await res.json();
                if (!data || !data.ok) {
                    removePlaceholderRow(placeholderRow);
                    alert('<?php echo __('Ошибка'); ?>: ' + (data && data.message ? data.message : 'ERROR'));
                    return;
                }
                if (data.domain_host) {
                    applyProjectHost(data.domain_host);
                }
                if (data.domain_errors && Number(data.domain_errors) > 0) {
                    removePlaceholderRow(placeholderRow);
                    alert('<?php echo __('Отклонено ссылок с другим доменом'); ?>: ' + data.domain_errors);
                }
                const payload = data.new_link || { id: 0, url, anchor, language: lang, wish: wish, anchor_strategy: anchorStrategyValue };
                removePlaceholderRow(placeholderRow);
                tbodyRef = ensureLinksTable();
                if (tbodyRef) {
                    const tr = buildLinkRow(payload);
                    tbodyRef.appendChild(tr);
                    refreshRowNumbers();
                    bindDynamicRowActions();
                    initTooltips(tr);
                    recalcPromotionStats();
                    refreshActionsCell(tr);
                    if (linkTableManager) {
                        try {
                            linkTableManager.sync();
                        } catch (e) {
                            console.error('Link table manager sync failed', e);
                        }
                    }
                }

                if (window.bootstrap) {
                    const addLinkModalEl = document.getElementById('addLinkModal');
                    if (addLinkModalEl && addLinkModalEl.classList.contains('show')) {
                        const modalInstance = bootstrap.Modal.getInstance(addLinkModalEl);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    }
                }

                newLinkInput.value = '';
                anchorUpdateLock = true;
                newAnchorInput.value = '';
                newAnchorInput.dispatchEvent(new Event('input', { bubbles: true }));
                anchorUpdateLock = false;
                if (newAnchorStrategy) { newAnchorStrategy.value = 'auto'; }
                newWish.value = '';
                if (newLangSelect) newLangSelect.value = newLangSelect.querySelector('option')?.value || newLangSelect.value;
                if (anchorPresetContainer) {
                    renderAnchorPresets(newLangSelect ? newLangSelect.value : PROJECT_LANGUAGE);
                }
            } catch (e) {
                removePlaceholderRow(placeholderRow);
                if (!isAbortError(e) && !pageUnloading) {
                    alert('<?php echo __('Сетевая ошибка'); ?>');
                }
            } finally {
                setButtonLoading(addLinkBtn, false);
            }
        });
    }

    async function startPromotion(btn, url, linkId = null) {
        if (!url) return false;
        let promotionTriggered = false;
        setButtonLoading(btn, true);
        let row = btn && typeof btn.closest === 'function' ? btn.closest('tr') : null;
        const linkIdNum = (() => {
            const tryParse = (value) => {
                if (value === null || value === undefined || value === '') return 0;
                const parsed = Number(value);
                return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
            };
            const direct = tryParse(linkId);
            if (direct) return direct;
            const fromBtn = tryParse(btn?.getAttribute?.('data-id') || btn?.dataset?.id);
            if (fromBtn) return fromBtn;
            return tryParse(row?.getAttribute?.('data-id') || row?.dataset?.id);
        })();
        const { snapshot: previousSnapshot, applied: optimisticApplied } = applyOptimisticPromotionState(row);
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('project_id', String(PROJECT_ID));
            fd.append('url', url);
            fd.append('charge_amount', String(PROMOTION_CHARGE_AMOUNT));
            if (linkIdNum > 0) {
                fd.append('link_id', String(linkIdNum));
            }
            const res = await fetch('<?php echo pp_url('public/promote_link.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data) {
                if (optimisticApplied) {
                    restorePromotionState(row, previousSnapshot);
                }
                alert('<?php echo __('Ошибка'); ?>: ' + (res.status ? String(res.status) : 'ERROR'));
                return false;
            }
            if (!data.ok) {
                if (optimisticApplied) {
                    restorePromotionState(row, previousSnapshot);
                }
                const errorCode = data.error || data.error_code || 'ERROR';
                if (errorCode === 'INSUFFICIENT_FUNDS') {
                    showInsufficientFundsModal({
                        required: data.required,
                        balance: data.balance,
                        shortfall: data.shortfall
                    });
                } else if (errorCode === 'URL_NOT_IN_PROJECT') {
                    alert('<?php echo __('Эта ссылка не принадлежит проекту.'); ?>');
                } else if (errorCode === 'LEVEL1_DISABLED') {
                    alert('<?php echo __('Продвижение временно недоступно. Попробуйте позже.'); ?>');
                } else {
                    alert('<?php echo __('Ошибка'); ?>: ' + errorCode);
                }
                return false;
            }
            const balanceAmount = Object.prototype.hasOwnProperty.call(data, 'balance_after')
                ? data.balance_after
                : (Object.prototype.hasOwnProperty.call(data, 'balance') ? data.balance : null);
            const balanceFormatted = data.balance_after_formatted || data.balance_formatted || '';
            if (balanceAmount !== null && balanceAmount !== undefined) {
                updateClientBalance(balanceAmount, balanceFormatted);
            }
            const promotionDataRaw = data.promotion ? { ...data.promotion } : { ...data };
            if (!promotionDataRaw.run_id && data.run_id) {
                promotionDataRaw.run_id = data.run_id;
            }
            if (!promotionDataRaw.status && data.status) {
                promotionDataRaw.status = data.status;
            }
            if (!promotionDataRaw.link_id && data.link_id) {
                promotionDataRaw.link_id = data.link_id;
            }
            if (!promotionDataRaw.target_url && data.target_url) {
                promotionDataRaw.target_url = data.target_url;
            }
            if (!promotionDataRaw.link_id && linkIdNum > 0) {
                promotionDataRaw.link_id = linkIdNum;
            }
            if (!promotionDataRaw.target_url) {
                promotionDataRaw.target_url = url;
            }
            let updated = false;
            const tbody = ensureLinksTable();
            if (tbody) {
                const rows = tbody.querySelectorAll('tr');
                for (const tr of rows) {
                    const trLinkIdRaw = tr.getAttribute('data-id') || tr.dataset?.id || tr.dataset?.promotionLinkId || '';
                    const trLinkId = Number(trLinkIdRaw);
                    const linkEl = tr.querySelector('.url-cell .view-url');
                    const urlMatches = linkEl && linkEl.getAttribute('href') === url;
                    const idMatches = linkIdNum > 0 && Number.isFinite(trLinkId) && trLinkId === linkIdNum;
                    if (idMatches || urlMatches) {
                        applyPromotionPayload(tr, promotionDataRaw);
                        if (!row) {
                            row = tr;
                        }
                        updated = true;
                        break;
                    }
                }
            }
            if (!updated && row) {
                applyPromotionPayload(row, promotionDataRaw);
            }
            startPolling();
            setTimeout(() => { try { pollPromotionStatusesOnce(); } catch (e) {} }, 1200);
            setTimeout(() => {
                try { window.location.reload(); } catch (e) {}
            }, 2600);
            promotionTriggered = true;
        } catch (e) {
            if (optimisticApplied) {
                restorePromotionState(row, previousSnapshot);
            }
            if (!isAbortError(e) && !pageUnloading) {
                alert('<?php echo __('Сетевая ошибка'); ?>');
            }
        } finally {
            setButtonLoading(btn, false);
        }
        return promotionTriggered;
    }

    initWishAutoFill();

    const promotionConfirmModalElement = document.getElementById('promotionConfirmModal');
    if (promotionConfirmModalElement && window.bootstrap) {
        promotionConfirmModalElement.addEventListener('hidden.bs.modal', () => {
            promotionConfirmContext = null;
            if (promotionPrevCurrency) {
                navBalanceCurrency = promotionPrevCurrency;
                promotionPrevCurrency = null;
            }
        });
    }

    if (projectInfoForm) {
        projectInfoForm.addEventListener('submit', () => {
            const submitBtn = projectInfoForm.querySelector('button[type="submit"]');
            setButtonLoading(submitBtn, true);
        });
    }
});
</script>
