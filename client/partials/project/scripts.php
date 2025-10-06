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

    const form = document.getElementById('project-form');
    const addLinkBtn = document.getElementById('add-link');
    const addedHidden = document.getElementById('added-hidden');
    const newLinkInput = document.getElementById('new_link_input');
    const newAnchorInput = document.getElementById('new_anchor_input');
    const newLangSelect = document.getElementById('new_language_select');
    const newWish = document.getElementById('new_wish');
    const globalWish = document.getElementById('global_wishes');
    const useGlobal = document.getElementById('use_global_wish');
    const projectInfoForm = document.getElementById('project-info-form');
    let addIndex = 0;

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
    const PROMOTION_CHARGE_AMOUNT = <?php echo json_encode($promotionChargeAmount); ?>;
    const PROMOTION_CHARGE_AMOUNT_FORMATTED = '<?php echo htmlspecialchars($promotionChargeFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_CHARGE_BASE = <?php echo json_encode($promotionBasePrice); ?>;
    const PROMOTION_CHARGE_BASE_FORMATTED = '<?php echo htmlspecialchars($promotionBaseFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_DISCOUNT_PERCENT = <?php echo json_encode($userPromotionDiscount); ?>;
    const PROMOTION_CHARGE_SAVINGS = <?php echo json_encode($promotionChargeSavings); ?>;
    const PROMOTION_CHARGE_SAVINGS_FORMATTED = '<?php echo htmlspecialchars($promotionChargeSavingsFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>';
    const PROMOTION_ACTIVE_STATUSES = <?php echo json_encode($promotionActiveStates); ?>;
    const LANG_CODES = <?php echo json_encode(array_merge(['auto'], $pp_lang_codes)); ?>;

    const navBalanceValueEl = document.querySelector('[data-balance-target]');
    const navBalanceLocale = navBalanceValueEl?.dataset.balanceLocale || document.documentElement.getAttribute('lang') || navigator.language || 'ru-RU';
    const navBalanceCurrency = navBalanceValueEl?.dataset.balanceCurrency || 'RUB';
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
        if (loading) {
            btn.classList.add('disabled');
            btn.setAttribute('disabled', 'disabled');
            btn.dataset.loading = '1';
        } else {
            btn.classList.remove('disabled');
            btn.removeAttribute('disabled');
            delete btn.dataset.loading;
        }
    }

    function getCsrfToken() {
        const tokenField = document.querySelector('input[name="csrf_token"]');
        return tokenField ? tokenField.value : '';
    }

    function ensureLinksTable() {
        return document.querySelector('table.table-links tbody');
    }

    function refreshRowNumbers() {
        const rows = document.querySelectorAll('table.table-links tbody tr');
        rows.forEach((tr, idx) => {
            const cell = tr.querySelector('td[data-label="#"], td:first-child');
            if (cell) { cell.textContent = String(idx + 1); }
            tr.dataset.index = String(idx);
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
    }

    function handleEditButton(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        row.classList.toggle('editing');
        row.querySelectorAll('.view-url, .view-anchor, .view-language, .view-wish').forEach(el => el.classList.toggle('d-none'));
        row.querySelectorAll('.edit-url, .edit-anchor, .edit-language, .edit-wish').forEach(el => el.classList.toggle('d-none'));
        if (row.classList.contains('editing')) {
            row.querySelectorAll('input, textarea, select').forEach(el => el.removeAttribute('disabled'));
        } else {
            row.querySelectorAll('input, textarea, select').forEach(el => el.setAttribute('disabled', 'disabled'));
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

    function isPromotionActiveStatus(status) {
        return PROMOTION_ACTIVE_STATUSES.includes(status);
    }

    function updatePromotionBlock(tr, data) {
        if (!tr || !data) return;
        const block = tr.querySelector('.promotion-status-block');
        if (!block) return;
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

        const labelEl = block.querySelector('.promotion-status-label');
        const countEl = block.querySelector('.promotion-progress-count');
        if (countEl) {
            if (data.target) {
                countEl.classList.remove('d-none');
                countEl.textContent = `(${data.done || 0} / ${data.target})`;
            } else {
                countEl.classList.add('d-none');
                countEl.textContent = '';
            }
        }

        if (labelEl) {
            const labels = {
                'queued': '<?php echo __('Уровень 1 выполняется'); ?>',
                'running': '<?php echo __('Уровень 1 выполняется'); ?>',
                'level1_active': '<?php echo __('Уровень 1 выполняется'); ?>',
                'pending_level2': '<?php echo __('Ожидание уровня 2'); ?>',
                'level2_active': '<?php echo __('Уровень 2 выполняется'); ?>',
                'pending_level3': '<?php echo __('Ожидание уровня 3'); ?>',
                'level3_active': '<?php echo __('Уровень 3 выполняется'); ?>',
                'pending_crowd': '<?php echo __('Подготовка крауда'); ?>',
                'crowd_ready': '<?php echo __('Крауд готов'); ?>',
                'report_ready': '<?php echo __('Формируется отчет'); ?>',
                'completed': '<?php echo __('Завершено'); ?>',
                'failed': '<?php echo __('Ошибка продвижения'); ?>',
                'cancelled': '<?php echo __('Отменено'); ?>'
            };
            labelEl.textContent = labels[status] || (status === 'idle' ? '<?php echo __('Продвижение не запускалось'); ?>' : status);
        }

        const progressVisual = block.querySelector('.promotion-progress-visual');
        if (progressVisual) {
            progressVisual.classList.toggle('d-none', !isPromotionActiveStatus(status));
        }

        if (data.progress && typeof data.progress === 'object') {
            const levels = ['level1', 'level2', 'level3'];
            levels.forEach((level, idx) => {
                const levelBlock = block.querySelector(`.promotion-progress-level[data-level="${idx + 1}"]`);
                const levelData = data.levels?.[idx + 1] || {};
                if (!levelBlock) return;
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

        const completeBlock = block.querySelector('.promotion-status-complete');
        if (completeBlock) {
            completeBlock.classList.toggle('d-none', status !== 'completed');
        }
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

    function openPromotionReport(btn) {
        const runId = btn?.dataset.runId || '';
        const url = btn?.dataset.url || '';
        if (!runId) return;
        const modalEl = document.getElementById('promotionReportModal');
        if (!modalEl || !window.bootstrap) return;
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        const content = modalEl.querySelector('#promotionReportContent');
        if (content) {
            content.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
        }
        modalInstance.show();
        const params = new URLSearchParams();
        params.set('project_id', String(PROJECT_ID));
        params.set('run_id', runId);
        fetch('<?php echo pp_url('public/promotion_report.php'); ?>?' + params.toString(), { credentials: 'same-origin' })
            .then(res => res.text())
            .then(html => {
                if (content) content.innerHTML = html;
            })
            .catch(() => {
                if (content) content.innerHTML = '<div class="alert alert-danger"><?php echo __('Не удалось загрузить отчет.'); ?></div>';
            });
    }

    function openPromotionConfirm(btn, url) {
        const modalInstance = getPromotionConfirmModalInstance();
        if (!promotionConfirmModalEl || !modalInstance) {
            startPromotion(btn, url);
            return;
        }
        const chargeFormatted = btn?.getAttribute('data-charge-formatted') || PROMOTION_CHARGE_AMOUNT_FORMATTED;
        const chargeBaseFormatted = btn?.getAttribute('data-charge-base-formatted') || PROMOTION_CHARGE_BASE_FORMATTED;
        const chargeSavingsFormatted = btn?.getAttribute('data-charge-savings-formatted') || PROMOTION_CHARGE_SAVINGS_FORMATTED;
        const chargeSavingsRaw = Number((btn?.getAttribute('data-charge-savings') ?? PROMOTION_CHARGE_SAVINGS) || 0);
        const discountPercentAttribute = btn?.getAttribute('data-discount-percent');
        const discountPercent = Number(discountPercentAttribute !== null ? discountPercentAttribute : (PROMOTION_DISCOUNT_PERCENT || 0));
        promotionConfirmContext = { btn, url };
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

    if (promotionConfirmAcceptBtn) {
        promotionConfirmAcceptBtn.addEventListener('click', async () => {
            if (!promotionConfirmContext) {
                getPromotionConfirmModalInstance()?.hide();
                return;
            }
            const { btn, url } = promotionConfirmContext;
            promotionConfirmAcceptBtn.disabled = true;
            getPromotionConfirmModalInstance()?.hide();
            promotionConfirmContext = null;
            try {
                await startPromotion(btn, url);
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
                const params = new URLSearchParams();
                params.set('project_id', String(PROJECT_ID));
                params.set('url', url);
                const runId = tr.dataset.promotionRunId || '';
                if (runId) { params.set('run_id', runId); }
                const res = await fetch('<?php echo pp_url('public/promotion_status.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
                const data = await res.json().catch(()=>null);
                if (!data || !data.ok) continue;
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

    if (form) {
        form.addEventListener('submit', () => {
            if (!form.querySelector('.editing')) return;
            alert('<?php echo __('Сохраните изменения в ссылке перед отправкой формы.'); ?>');
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
            if (!tr.classList.contains('editing')) return;
            const id = tr.getAttribute('data-id');
            if (!id) return;
            const urlInput = tr.querySelector('.edit-url');
            const anchorInput = tr.querySelector('.edit-anchor');
            const langSelect = tr.querySelector('.edit-language');
            const wishTextarea = tr.querySelector('.edit-wish');
            edited[id] = {
                url: urlInput?.value || '',
                anchor: anchorInput?.value || '',
                language: langSelect?.value || '',
                wish: wishTextarea?.value || ''
            };
        });
        return edited;
    }

    if (form) {
        form.addEventListener('submit', () => {
            const edited = serializeEditedRows();
            Object.keys(edited).forEach(id => {
                const data = edited[id];
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `edited_links[${id}][url]`;
                hidden.value = data.url;
                form.appendChild(hidden);
                const hiddenAnchor = document.createElement('input');
                hiddenAnchor.type = 'hidden';
                hiddenAnchor.name = `edited_links[${id}][anchor]`;
                hiddenAnchor.value = data.anchor;
                form.appendChild(hiddenAnchor);
                const hiddenLang = document.createElement('input');
                hiddenLang.type = 'hidden';
                hiddenLang.name = `edited_links[${id}][language]`;
                hiddenLang.value = data.language;
                form.appendChild(hiddenLang);
                const hiddenWish = document.createElement('input');
                hiddenWish.type = 'hidden';
                hiddenWish.name = `edited_links[${id}][wish]`;
                hiddenWish.value = data.wish;
                form.appendChild(hiddenWish);
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
            const anchor = newAnchorInput.value.trim();
            const lang = (newLangSelect ? newLangSelect.value.trim() : 'auto');
            const wish = newWish.value.trim();
            if (!isValidUrl(url)) { alert('<?php echo __('Введите корректный URL'); ?>'); return; }
            try {
                const u = new URL(url);
                const host = (u.hostname || '').toLowerCase().replace(/^www\./,'');
                if (CURRENT_PROJECT_HOST && host !== CURRENT_PROJECT_HOST) {
                    alert('<?php echo __('Ссылка должна быть в рамках домена проекта'); ?>: ' + CURRENT_PROJECT_HOST);
                    return;
                }
            } catch (e) {}

            setButtonLoading(addLinkBtn, true);
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

                const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'Accept':'application/json' }, credentials: 'same-origin' });
                const data = await res.json();
                if (!data || !data.ok) {
                    alert('<?php echo __('Ошибка'); ?>: ' + (data && data.message ? data.message : 'ERROR'));
                    return;
                }
                if (data.domain_host) {
                    applyProjectHost(data.domain_host);
                }
                if (data.domain_errors && Number(data.domain_errors) > 0) {
                    alert('<?php echo __('Отклонено ссылок с другим доменом'); ?>: ' + data.domain_errors);
                }
                const payload = data.new_link || { id: 0, url, anchor, language: lang, wish: wish };
                const tbody = ensureLinksTable();
                if (tbody) {
                    const tr = document.createElement('tr');
                    const newId = parseInt(payload.id || '0', 10) || 0;
                    const newIndex = (data.links_count && data.links_count > 0) ? (data.links_count - 1) : 0;
                    tr.setAttribute('data-id', String(newId));
                    tr.setAttribute('data-index', String(newIndex));
                    tr.dataset.postUrl = '';
                    tr.dataset.network = '';
                    tr.dataset.publicationStatus = 'not_published';
                    tr.dataset.promotionStatus = 'idle';
                    tr.dataset.promotionStage = '';
                    tr.dataset.promotionRunId = '';
                    tr.dataset.promotionReportReady = '0';
                    tr.dataset.promotionTotal = '0';
                    tr.dataset.promotionDone = '0';
                    tr.dataset.promotionTarget = '0';
                    tr.dataset.promotionAttempted = '0';
                    tr.dataset.level1Total = '0';
                    tr.dataset.level1Success = '0';
                    tr.dataset.level1Required = '0';
                    tr.dataset.level2Total = '0';
                    tr.dataset.level2Success = '0';
                    tr.dataset.level2Required = '0';
                    tr.dataset.level3Total = '0';
                    tr.dataset.level3Success = '0';
                    tr.dataset.level3Required = '0';
                    tr.dataset.crowdPlanned = '0';
                    tr.dataset.crowdTotal = '0';
                    tr.dataset.crowdCompleted = '0';
                    tr.dataset.crowdRunning = '0';
                    tr.dataset.crowdQueued = '0';
                    tr.dataset.crowdFailed = '0';
                    const pathDisp = pathFromUrl(url);
                    const hostDisp = hostFromUrl(url);
                    tr.innerHTML = `
                        <td></td>
                        <td class="url-cell">
                            <div class="small text-muted host-muted"><i class="bi bi-globe2 me-1"></i>${escapeHtml(hostDisp)}</div>
                            <a href="${escapeHtml(url)}" target="_blank" class="view-url text-truncate-path" title="${escapeHtml(url)}" data-bs-toggle="tooltip">${escapeHtml(pathDisp)}</a>
                            <input type="url" class="form-control d-none edit-url" value="${escapeAttribute(url)}" />
                        </td>
                        <td class="anchor-cell">
                            <span class="view-anchor text-truncate-anchor" title="${escapeHtml(anchor)}" data-bs-toggle="tooltip">${escapeHtml(anchor)}</span>
                            <input type="text" class="form-control d-none edit-anchor" value="${escapeAttribute(anchor)}" />
                        </td>
                        <td class="language-cell">
                            <span class="badge bg-secondary-subtle text-light-emphasis view-language text-uppercase">${(payload.language || lang).toUpperCase()}</span>
                            <select class="form-select form-select-sm d-none edit-language">
                                ${LANG_CODES.map(l=>`<option value="${l}" ${l===(payload.language||lang)?'selected':''}>${l.toUpperCase()}</option>`).join('')}
                            </select>
                        </td>
                        <td class="wish-cell">
                            <button type="button" class="icon-btn action-show-wish" data-wish="${escapeHtml(payload.wish || wish)}" title="<?php echo __('Показать пожелание'); ?>" data-bs-toggle="tooltip"><i class="bi bi-journal-text"></i></button>
                            <div class="view-wish d-none">${escapeHtml(payload.wish || wish)}</div>
                            <textarea class="form-control d-none edit-wish" rows="2">${escapeHtml(payload.wish || wish)}</textarea>
                        </td>
                        <td class="status-cell">
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
                                 data-crowd-completed="0"
                                 data-crowd-running="0"
                                 data-crowd-queued="0"
                                 data-crowd-failed="0">
                                <div class="promotion-status-top">
                                    <span class="promotion-status-heading"><?php echo __('Продвижение'); ?>:</span>
                                    <span class="promotion-status-label ms-1"><?php echo __('Продвижение не запускалось'); ?></span>
                                    <span class="promotion-progress-count ms-1 d-none"></span>
                                </div>
                                <div class="promotion-progress-visual mt-2 d-none">
                                    <div class="promotion-progress-level promotion-progress-level1 d-none" data-level="1">
                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                            <span><?php echo __('Уровень 1'); ?></span>
                                            <span class="promotion-progress-value">0 / 0</span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar promotion-progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                        </div>
                                    </div>
                                    <div class="promotion-progress-level promotion-progress-level2 d-none" data-level="2">
                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                            <span><?php echo __('Уровень 2'); ?></span>
                                            <span class="promotion-progress-value">0 / 0</span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="promotion-progress-bar promotion-progress-bar bg-info" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                        </div>
                                    </div>
                                    <div class="promotion-progress-level promotion-progress-level3 d-none" data-level="3">
                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                            <span><?php echo __('Уровень 3'); ?></span>
                                            <span class="promotion-progress-value">0 / 0</span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar promotion-progress-bar bg-warning" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                        </div>
                                    </div>
                                    <div class="promotion-progress-level promotion-progress-crowd d-none" data-level="crowd">
                                        <div class="promotion-progress-meta d-flex justify-content-between small text-muted mb-1">
                                            <span><?php echo __('Крауд'); ?></span>
                                            <span class="promotion-progress-value">0 / 0</span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar promotion-progress-bar bg-success" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="promotion-progress-details text-muted d-none"></div>
                                <div class="promotion-status-complete mt-2 d-none" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo __('Передача ссылочного веса займет 2-3 месяца, мы продолжаем мониторинг.'); ?>">
                                    <i class="bi bi-patch-check-fill text-success"></i>
                                    <span class="promotion-status-complete-text"><?php echo __('Продвижение завершено'); ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="text-end">
                            <button type="button" class="icon-btn action-analyze me-1" title="<?php echo __('Анализ'); ?>"><i class="bi bi-search"></i></button>
                            <button type="button" class="btn btn-sm btn-publish me-1 action-promote"
                                data-url="${escapeHtml(url)}"
                                data-id="${String(newId)}"
                                data-charge-amount="${escapeHtml(String(PROMOTION_CHARGE_AMOUNT))}"
                                data-charge-formatted="${escapeHtml(PROMOTION_CHARGE_AMOUNT_FORMATTED)}"
                                data-charge-base="${escapeHtml(String(PROMOTION_CHARGE_BASE))}"
                                data-charge-base-formatted="${escapeHtml(PROMOTION_CHARGE_BASE_FORMATTED)}"
                                data-charge-savings="${escapeHtml(String(PROMOTION_CHARGE_SAVINGS))}"
                                data-charge-savings-formatted="${escapeHtml(PROMOTION_CHARGE_SAVINGS_FORMATTED)}"
                                data-discount-percent="${escapeHtml(String(PROMOTION_DISCOUNT_PERCENT))}">
                                <i class="bi bi-rocket-takeoff rocket"></i><span class="label d-none d-md-inline ms-1"><?php echo __('Продвинуть'); ?></span>
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm me-1 action-promotion-progress d-none" data-run-id="0" data-url="${escapeHtml(url)}">
                                <i class="bi bi-list-task me-1"></i><span class="d-none d-lg-inline"><?php echo __('Прогресс'); ?></span>
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm me-1 action-promotion-report d-none" data-run-id="0" data-url="${escapeHtml(url)}">
                                <i class="bi bi-file-earmark-text me-1"></i><span class="d-none d-lg-inline"><?php echo __('Отчет'); ?></span>
                            </button>
                            <button type="button" class="icon-btn action-edit" title="<?php echo __('Редактировать'); ?>"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="icon-btn action-remove" data-id="${String(newId)}" title="<?php echo __('Удалить'); ?>"><i class="bi bi-trash"></i></button>
                        </td>`;
                    tbody.appendChild(tr);
                    refreshRowNumbers();
                    bindDynamicRowActions();
                    initTooltips(tr);
                    recalcPromotionStats();
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
                newAnchorInput.value = '';
                newWish.value = '';
                if (newLangSelect) newLangSelect.value = newLangSelect.querySelector('option')?.value || newLangSelect.value;
            } catch (e) {
                alert('<?php echo __('Сетевая ошибка'); ?>');
            } finally {
                setButtonLoading(addLinkBtn, false);
            }
        });
    }

    async function startPromotion(btn, url) {
        if (!url) return;
        setButtonLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('csrf_token', getCsrfToken());
            fd.append('project_id', String(PROJECT_ID));
            fd.append('url', url);
            fd.append('charge_amount', String(PROMOTION_CHARGE_AMOUNT));
            const res = await fetch('<?php echo pp_url('public/promotion_launch.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json().catch(()=>null);
            if (!data || !data.ok) {
                if (data && data.error_code === 'INSUFFICIENT_FUNDS') {
                    showInsufficientFundsModal(data.details || {});
                } else {
                    alert('<?php echo __('Ошибка'); ?>: ' + (data && data.error ? data.error : 'ERROR'));
                }
                return;
            }
            if (data.balance) {
                updateClientBalance(data.balance.amount ?? data.balance, data.balance.formatted ?? '');
            }
            const tbody = ensureLinksTable();
            if (tbody) {
                tbody.querySelectorAll('tr').forEach(tr => {
                    const linkEl = tr.querySelector('.url-cell .view-url');
                    if (linkEl && linkEl.getAttribute('href') === url) {
                        tr.dataset.promotionStatus = data.status || 'queued';
                        tr.dataset.promotionRunId = data.run_id ? String(data.run_id) : '';
                        tr.dataset.promotionReportReady = '0';
                        updatePromotionBlock(tr, data);
                        refreshActionsCell(tr);
                    }
                });
            }
            recalcPromotionStats();
        } catch (e) {
            alert('<?php echo __('Сетевая ошибка'); ?>');
        } finally {
            setButtonLoading(btn, false);
        }
    }

    initWishAutoFill();

    const promotionConfirmModalElement = document.getElementById('promotionConfirmModal');
    if (promotionConfirmModalElement && window.bootstrap) {
        promotionConfirmModalElement.addEventListener('hidden.bs.modal', () => {
            promotionConfirmContext = null;
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
