// PromoPilot Scripts — animations & UI polish

document.addEventListener('DOMContentLoaded', function() {
    // Respect reduced motion
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const root = document.documentElement;
    root.removeAttribute('data-theme');
    if (typeof window.ppBgfxUpdateColors === 'function') {
        window.ppBgfxUpdateColors();
    }

    // Animations (UI)
    if (!prefersReducedMotion) {
        const cards = document.querySelectorAll('.card');
        cards.forEach((card, index) => {
            setTimeout(() => { card.classList.add('bounce-in'); }, index * 80);
        });

        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => { alert.classList.add('fade-in'); });

        // Scroll fade-in
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => { if (entry.isIntersecting) entry.target.classList.add('fade-in'); });
        }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

        document.querySelectorAll('.card, .alert, .table').forEach(el => observer.observe(el));
    }

    const buttons = document.querySelectorAll('.btn');
    if (!prefersReducedMotion) {
        buttons.forEach(btn => {
            btn.addEventListener('mouseenter', function() { this.style.transform = 'translateY(-1px)'; });
            btn.addEventListener('mouseleave', function() { this.style.transform = 'translateY(0)'; });
        });
    }

    const inputs = document.querySelectorAll('.form-control');
    if (!prefersReducedMotion) {
        inputs.forEach(input => {
            input.addEventListener('focus', function() { if (this.parentElement) this.parentElement.style.transform = 'scale(1.01)'; });
            input.addEventListener('blur', function() { if (this.parentElement) this.parentElement.style.transform = 'scale(1)'; });
        });
    }

    // Animated developer brand wordmark (scramble reveal)
    const brandEl = document.querySelector('[data-brand-scramble="true"]');
    if (brandEl) {
        const targetText = (brandEl.dataset.brandText || brandEl.textContent || '').trim();
        const glyphSource = brandEl.dataset.brandGlyphs || '▮░▒▓█BRSDUYAEIOT1234567890';
        const glyphs = glyphSource.length ? Array.from(glyphSource) : ['*'];
        const frameInterval = parseInt(brandEl.dataset.brandInterval || '', 10) || 110;
        const pauseDuration = parseInt(brandEl.dataset.brandPause || '', 10) || 5200;
        if (targetText.length) {
            let revealIndex = -1;
            let intervalId = null;
            let restartTimeout = null;

            function clearTimers() {
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                }
                if (restartTimeout) {
                    clearTimeout(restartTimeout);
                    restartTimeout = null;
                }
            }

            function showFinalState() {
                brandEl.textContent = targetText;
                brandEl.classList.add('is-static');
            }

            function renderFrame() {
                revealIndex += 1;
                if (revealIndex >= targetText.length) {
                    clearTimers();
                    showFinalState();
                    if (!prefersReducedMotion) {
                        restartTimeout = window.setTimeout(startCycle, pauseDuration);
                    }
                    return;
                }

                let output = '';
                for (let i = 0; i < targetText.length; i++) {
                    if (i <= revealIndex) {
                        output += targetText[i];
                    } else {
                        const idx = Math.floor(Math.random() * glyphs.length);
                        output += glyphs[idx] || targetText[i];
                    }
                }
                brandEl.textContent = output;
            }

            function startCycle() {
                if (prefersReducedMotion) {
                    clearTimers();
                    showFinalState();
                    return;
                }
                brandEl.classList.remove('is-static');
                clearTimers();
                revealIndex = -1;
                intervalId = window.setInterval(renderFrame, frameInterval);
            }

            function stopCycle() {
                clearTimers();
                showFinalState();
            }

            brandEl.addEventListener('mouseenter', stopCycle);
            brandEl.addEventListener('focus', stopCycle);
            brandEl.addEventListener('mouseleave', () => { if (!prefersReducedMotion) { startCycle(); } });
            brandEl.addEventListener('blur', () => { if (!prefersReducedMotion) { startCycle(); } });

            startCycle();
        }
    }

    // Language switcher auto-submit (if using a select)
    const langSelect = document.querySelector('select[name="lang"]');
    if (langSelect) {
        langSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }

    // Admin sections toggle (users, projects, settings, networks, diagnostics)
    const sectionKeys = ['users','projects','settings','crowd','networks','diagnostics'];
    const sections = {};
    sectionKeys.forEach(key => { sections[key] = document.getElementById(key + '-section'); });
    const hasSections = Object.values(sections).some(Boolean);
    if (hasSections) {
        const storageAvailable = (() => { try { return !!window.localStorage; } catch (_) { return false; } })();
        function show(sectionKey) {
            Object.keys(sections).forEach(k => {
                if (sections[k]) sections[k].style.display = (k === sectionKey) ? 'block' : 'none';
            });
            if (storageAvailable) {
                try { localStorage.setItem('pp-admin-section', sectionKey); } catch (_) { /* ignore */ }
            }
        }
        window.ppShowSection = show;
        let initial = storageAvailable ? localStorage.getItem('pp-admin-section') : null;
        if (!initial || !sections[initial]) {
            initial = sections.users ? 'users'
                : (sections.projects ? 'projects'
                : (sections.settings ? 'settings'
                : (sections.crowd ? 'crowd'
                : (sections.networks ? 'networks'
                : Object.keys(sections).find(k => sections[k])))));
        }
        show(initial || 'users');
        const navLinks = document.querySelectorAll('.menu-item[data-admin-section]');
        if (navLinks.length) {
            const highlight = (sectionKey) => {
                navLinks.forEach(link => {
                    const linkedSection = link.getAttribute('data-admin-section');
                    link.classList.toggle('active', linkedSection === sectionKey);
                });
            };
            navLinks.forEach(link => {
                link.addEventListener('click', function (event) {
                    const targetSection = this.getAttribute('data-admin-section');
                    if (!targetSection) { return; }
                    event.preventDefault();
                    show(targetSection);
                    highlight(targetSection);
                });
            });
            highlight(initial || 'users');
        }
    }

    // OpenAI key checker (admin settings)
    const checkBtn = document.getElementById('checkOpenAiKey');
    if (checkBtn) {
        const input = document.getElementById('openaiApiKeyInput');
        const statusEl = document.getElementById('openaiCheckStatus');
        const msgBox = document.getElementById('openaiCheckMessages');
        const msg = msgBox ? msgBox.dataset : {};
        const defaultText = statusEl ? statusEl.textContent : '';
        const runCheck = async () => {
            if (!input) return;
            const key = input.value.trim();
            if (!statusEl) return;
            if (key === '') {
                statusEl.className = 'form-text text-danger';
                statusEl.textContent = msg.empty || 'Введите ключ перед проверкой.';
                return;
            }
            const url = checkBtn.dataset.checkUrl;
            if (!url) return;
            checkBtn.disabled = true;
            const prevHtml = checkBtn.innerHTML;
            checkBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (msg.checkingShort || 'Проверка...');
            statusEl.className = 'form-text text-muted';
            statusEl.textContent = msg.checking || 'Проверяем ключ...';
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'key=' + encodeURIComponent(key),
                    credentials: 'same-origin'
                });
                const data = await res.json().catch(() => ({}));
                if (!data.ok) {
                    let message = data.error || 'Ошибка';
                    const map = {
                        'EMPTY_KEY': msg.empty,
                        'UNAUTHORIZED': msg.unauthorized,
                        'NO_CURL': msg.noCurl,
                        'REQUEST_FAILED': msg.request,
                        'API_ERROR': msg.error,
                        'FORBIDDEN': msg.forbidden,
                    };
                    if (message && map[message]) { message = map[message]; }
                    if (data.details) {
                        message += ' (' + data.details + ')';
                    }
                    statusEl.className = 'form-text text-danger';
                    statusEl.textContent = message;
                } else {
                    const cnt = typeof data.models === 'number' ? data.models : 0;
                    const okMsg = (msg.ok || 'Ключ подтверждён. Доступно моделей:') + ' ' + cnt;
                    statusEl.className = 'form-text text-success';
                    statusEl.textContent = okMsg;
                }
            } catch (err) {
                statusEl.className = 'form-text text-danger';
                statusEl.textContent = msg.connection || 'Не удалось соединиться с OpenAI.';
            } finally {
                checkBtn.disabled = false;
                checkBtn.innerHTML = prevHtml;
            }
        };
        checkBtn.addEventListener('click', runCheck);
        if (statusEl) {
            statusEl.className = 'form-text';
            statusEl.textContent = defaultText;
        }
    }

    // Futuristic neutral background (particle network)
    (function initBgfx(){
        if (prefersReducedMotion) return; // skip background animation
        const wrapper = document.getElementById('bgfx');
        const canvas = document.getElementById('bgfx-canvas');
        if (!wrapper || !canvas) return;
        const ctx = canvas.getContext('2d');
        let dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
        let W = 0, H = 0;
        let particles = [];
        let lineColor = 'rgba(255,255,255,0.15)';
        let dotColor = 'rgba(255,255,255,0.7)';
        let animId = 0;

        function updateColors() {
            const cs = getComputedStyle(document.documentElement);
            const txt = cs.getPropertyValue('--text').trim() || '#e5e7eb';
            // Convert to rgba with different alpha
            function hexToRgb(h){
                const c = h.replace('#','');
                const n = parseInt(c.length===3? c.split('').map(x=>x+x).join(''): c, 16);
                return {r:(n>>16)&255, g:(n>>8)&255, b:n&255};
            }
            let r=229,g=231,b=235;
            if (/^#/.test(txt)) { const rgb=hexToRgb(txt); r=rgb.r; g=rgb.g; b=rgb.b; }
            lineColor = `rgba(${r},${g},${b},0.12)`;
            dotColor = `rgba(${r},${g},${b},0.65)`;
        }
        window.ppBgfxUpdateColors = updateColors;
        updateColors();

        function resize(){
            const rect = wrapper.getBoundingClientRect();
            W = Math.floor(rect.width);
            H = Math.floor(rect.height);
            dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
            canvas.width = Math.floor(W * dpr);
            canvas.height = Math.floor(H * dpr);
            canvas.style.width = W + 'px';
            canvas.style.height = H + 'px';
            if (ctx) ctx.setTransform(dpr,0,0,dpr,0,0);
            initParticles();
        }

        function initParticles(){
            const area = W * H;
            const base = Math.round(area / 16000); // density
            const count = Math.max(60, Math.min(160, base));
            particles = new Array(count).fill(0).map(()=>{
                return {
                    x: Math.random()*W,
                    y: Math.random()*H,
                    vx: (Math.random()-0.5)*0.25,
                    vy: (Math.random()-0.5)*0.25,
                    r: Math.random()*1.2 + 0.4
                };
            });
        }

        function step(){
            if (!ctx) return;
            ctx.clearRect(0,0,W,H);
            // draw connections
            const maxDist = Math.min(160, Math.max(90, Math.min(W,H)*0.25));
            for (let i=0;i<particles.length;i++){
                const p = particles[i];
                // move
                p.x += p.vx; p.y += p.vy;
                // drift
                p.vx += (Math.random()-0.5)*0.02;
                p.vy += (Math.random()-0.5)*0.02;
                // limit speed
                const sp = Math.hypot(p.vx,p.vy);
                if (sp>0.35){ p.vx*=0.35/sp; p.vy*=0.35/sp; }
                // wrap
                if (p.x<-10) p.x=W+10; if (p.x>W+10) p.x=-10;
                if (p.y<-10) p.y=H+10; if (p.y>H+10) p.y=-10;

                // dots
                ctx.beginPath();
                ctx.fillStyle = dotColor;
                ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
                ctx.fill();

                // connections
                for (let j=i+1;j<particles.length;j++){
                    const q = particles[j];
                    const dx = p.x-q.x, dy = p.y-q.y;
                    const d = dx*dx+dy*dy;
                    if (d < maxDist*maxDist){
                        const dist = Math.sqrt(d);
                        const a = 1 - dist/maxDist;
                        ctx.strokeStyle = lineColor.replace(/\d?\.\d+\)$/,'') + (0.12*a).toFixed(3) + ')';
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(p.x,p.y);
                        ctx.lineTo(q.x,q.y);
                        ctx.stroke();
                    }
                }
            }
            animId = requestAnimationFrame(step);
        }

        function start(){ cancelAnimationFrame(animId); step(); }

        window.addEventListener('resize', resize);
        resize();
        start();
    })();

    // Initialize Bootstrap tooltips (добавлено)
    if (window.bootstrap && document.querySelector('[data-bs-toggle="tooltip"]')) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            try { new bootstrap.Tooltip(el); } catch(e) { /* noop */ }
        });
    }

    // Sidebar "Add link" button should open the modal on project page
    const sidebarAddLinkBtn = document.getElementById('sidebar-add-link-btn');
    if (sidebarAddLinkBtn) {
        sidebarAddLinkBtn.addEventListener('click', function(event) {
            const modalEl = document.getElementById('addLinkModal');
            if (!modalEl) {
                return;
            }
            if (window.bootstrap && typeof bootstrap.Modal === 'function') {
                event.preventDefault();
                const modal = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                    ? bootstrap.Modal.getOrCreateInstance(modalEl)
                    : new bootstrap.Modal(modalEl);
                modal.show();
                const urlInput = modalEl.querySelector('#new_link_input');
                if (urlInput) {
                    setTimeout(() => { urlInput.focus(); }, 250);
                }
            }
        });
    }

    // Project creation preloader overlay
    const projectCreateForm = document.querySelector('form.add-project-form');
    const projectCreateOverlay = document.getElementById('project-create-overlay');

    if (projectCreateForm) {
        const analyzeButton = projectCreateForm.querySelector('[data-action="fetch-project-brief"]');
        const homepageInput = projectCreateForm.querySelector('#project-homepage');
        const payloadInput = projectCreateForm.querySelector('#project-brief-payload');
        const csrfInput = projectCreateForm.querySelector('input[name="csrf_token"]');
        const nameInput = projectCreateForm.querySelector('#project-name');
        const descriptionInput = projectCreateForm.querySelector('#project-description');
        const languageSelect = projectCreateForm.querySelector('#project-language');
        const regionSelect = projectCreateForm.querySelector('#project-region');
        const topicSelect = projectCreateForm.querySelector('#project-topic');
        const resultCard = projectCreateForm.querySelector('[data-brief-result]');
        const statusBadge = resultCard ? resultCard.querySelector('[data-brief-status]') : null;
        const metaTitle = resultCard ? resultCard.querySelector('[data-brief-meta-title]') : null;
        const metaDescription = resultCard ? resultCard.querySelector('[data-brief-meta-description]') : null;
        const metaLang = resultCard ? resultCard.querySelector('[data-brief-meta-lang]') : null;
        const metaHreflang = resultCard ? resultCard.querySelector('[data-brief-meta-hreflang]') : null;

        const stepStateInput = projectCreateForm.querySelector('[data-step-state]');
        const stepper = projectCreateForm.querySelector('[data-stepper]');
        const stepPanels = projectCreateForm.querySelectorAll('[data-step-panel]');
        const analysisFeedback = projectCreateForm.querySelector('[data-analysis-feedback]');
        const manualProceedButton = projectCreateForm.querySelector('[data-action="step-proceed-manual"]');
        const stepBackButton = projectCreateForm.querySelector('[data-action="step-back"]');

        let unlockedSteps = new Set([1]);
        let currentStep = 1;

        const setButtonLoading = (isLoading) => {
            if (!analyzeButton) { return; }
            analyzeButton.dataset.loading = isLoading ? '1' : '0';
            const labelText = analyzeButton.querySelector('[data-label-text]');
            const spinner = analyzeButton.querySelector('[data-loading-spinner]');
            const labelKey = isLoading ? 'labelLoading' : 'labelDefault';
            if (labelText) {
                const text = analyzeButton.dataset[labelKey] || labelText.textContent;
                if (text) { labelText.textContent = text; }
            }
            if (spinner) {
                spinner.classList.toggle('d-none', !isLoading);
            }
            analyzeButton.classList.toggle('disabled', isLoading);
            analyzeButton.toggleAttribute('disabled', isLoading);
        };

        const updateAnalyzeButtonState = () => {
            if (!analyzeButton) { return; }
            if (analyzeButton.dataset.loading === '1') { return; }
            const endpoint = analyzeButton.dataset.endpoint;
            const value = homepageInput ? homepageInput.value.trim() : '';
            const isEnabled = !!endpoint && value.length > 4;
            analyzeButton.classList.toggle('disabled', !isEnabled);
            analyzeButton.toggleAttribute('disabled', !isEnabled);
        };

        const toggleResultVisibility = (forceVisible) => {
            if (!resultCard) { return; }
            if (forceVisible) {
                resultCard.classList.remove('d-none');
            } else {
                resultCard.classList.add('d-none');
            }
        };

        const setCardState = (state) => {
            if (!resultCard) { return; }
            if (!state) {
                resultCard.removeAttribute('data-state');
            } else {
                resultCard.setAttribute('data-state', state);
            }
        };

        const setStatus = (mode) => {
            if (!statusBadge) { return; }
            const map = {
                Default: 'bg-secondary',
                Loading: 'bg-info',
                Success: 'bg-success',
                SuccessAi: 'bg-success',
                SuccessFallback: 'bg-warning',
                Error: 'bg-danger'
            };
            const normalized = Object.prototype.hasOwnProperty.call(map, mode) ? mode : 'Default';
            const key = `status${normalized}`;
            const text = statusBadge.dataset[key];
            if (text) { statusBadge.textContent = text; }
            statusBadge.classList.remove('bg-secondary','bg-success','bg-danger','bg-info','bg-warning');
            statusBadge.classList.add(map[normalized]);
        };

        const resetMetaPreview = () => {
            if (metaTitle) { metaTitle.textContent = '—'; }
            if (metaDescription) { metaDescription.textContent = '—'; }
            if (metaLang) { metaLang.textContent = '—'; }
            if (metaHreflang) { metaHreflang.textContent = '—'; }
        };

        const setAnalysisFeedback = (state) => {
            if (!analysisFeedback) { return; }
            const rawState = (state || 'idle').toString();
            const camelKey = rawState
                .split(/[-_\s]+/)
                .filter(Boolean)
                .map(part => part.charAt(0).toUpperCase() + part.slice(1))
                .join('');
            const key = 'text' + (camelKey || 'Idle');
            const fallback = analysisFeedback.dataset.textIdle || '';
            let datasetKey = key;
            if (rawState.toLowerCase() === 'success' && analysisFeedback.dataset.aiError && analysisFeedback.dataset.textSuccessFallback) {
                datasetKey = 'textSuccessFallback';
            }
            const text = analysisFeedback.dataset[datasetKey] || fallback;
            analysisFeedback.textContent = text || fallback;
            const normalized = rawState.replace(/[^a-z0-9-]+/gi, '-').toLowerCase() || 'idle';
            const stateToken = (normalized === 'success' && analysisFeedback.dataset.aiError) ? 'success-fallback' : normalized;
            if (normalized === 'idle') {
                delete analysisFeedback.dataset.state;
            } else {
                analysisFeedback.dataset.state = stateToken;
            }
        };

        const updateStepStateInput = () => {
            if (!stepStateInput) { return; }
            stepStateInput.value = String(currentStep);
        };

        const updateStepperUI = () => {
            if (!stepper) { return; }
            const items = stepper.querySelectorAll('[data-step]');
            items.forEach(item => {
                const stepValue = parseInt(item.dataset.step, 10) || 0;
                const isUnlocked = unlockedSteps.has(stepValue);
                const isCurrent = stepValue === currentStep;
                item.classList.toggle('is-active', isCurrent);
                item.classList.toggle('is-complete', stepValue < currentStep);
                item.classList.toggle('is-locked', !isUnlocked);
                item.classList.toggle('is-available', isUnlocked && !isCurrent);
                if (isCurrent) {
                    item.setAttribute('aria-current', 'step');
                } else {
                    item.removeAttribute('aria-current');
                }
            });
            stepper.dataset.currentStep = String(currentStep);
        };

        const showPanelForStep = (step) => {
            if (!stepPanels || !stepPanels.length) { return; }
            const value = Number(step) || 1;
            stepPanels.forEach(panel => {
                const panelStep = parseInt(panel.getAttribute('data-step-panel'), 10) || 0;
                const isActive = panelStep === value;
                panel.classList.toggle('is-active', isActive);
                panel.classList.toggle('d-none', !isActive);
            });
        };

        const goToStep = (step, options = {}) => {
            const target = Number(step) || 1;
            if (!unlockedSteps.has(target)) { return; }
            currentStep = target;
            updateStepStateInput();
            updateStepperUI();
            showPanelForStep(target);
            if (target === 1) {
                updateAnalyzeButtonState();
            }
            if (options && options.focusSelector) {
                const focusElement = projectCreateForm.querySelector(options.focusSelector);
                if (focusElement && typeof focusElement.focus === 'function') {
                    window.setTimeout(() => {
                        try { focusElement.focus({ preventScroll: true }); }
                        catch (_) { focusElement.focus(); }
                    }, 120);
                }
            }
        };

        const unlockStep = (step) => {
            const value = Number(step) || 0;
            if (!value) { return; }
            if (!unlockedSteps.has(value)) {
                unlockedSteps.add(value);
                updateStepperUI();
            }
        };

        const resetSteps = (options = {}) => {
            unlockedSteps = new Set([1]);
            setAnalysisFeedback('idle');
            if (analysisFeedback) {
                delete analysisFeedback.dataset.aiUsed;
                delete analysisFeedback.dataset.aiError;
            }
            if (resultCard) {
                delete resultCard.dataset.aiUsed;
                delete resultCard.dataset.aiError;
            }
            toggleResultVisibility(false);
            setCardState(null);
            setStatus('Default');
            resetMetaPreview();
            goToStep(1, options);
        };

        const initAutofillTracking = (input) => {
            if (!input) { return; }
            const update = () => {
                input.dataset.fillState = input.value.trim() === '' ? 'auto' : 'user';
            };
            update();
            input.addEventListener('input', update);
        };

        const setAutofillValue = (input, value) => {
            if (!input) { return; }
            const state = input.dataset.fillState;
            if (state === 'user' && input.value.trim() !== '') { return; }
            input.value = value || '';
            input.dataset.fillState = 'auto';
        };

        const initSelectTracking = (select) => {
            if (!select) { return; }
            select.dataset.fillState = 'auto';
            select.addEventListener('change', () => {
                select.dataset.fillState = select.value ? 'user' : 'auto';
            });
        };

        const setSelectValue = (select, value, options = {}) => {
            if (!select || !value) { return; }
            const state = select.dataset.fillState;
            if (state === 'user' && select.value && select.value !== value) { return; }
            const opts = typeof options === 'object' && options !== null ? options : {};
            const allowCreate = Boolean(opts.allowCreate);
            const labelOverride = typeof opts.label === 'string' && opts.label.trim() !== '' ? opts.label.trim() : null;
            const normalized = String(value).trim();
            if (!normalized) { return; }
            const optionsList = Array.from(select.options);
            const exactOption = optionsList.find(opt => opt.value === normalized);
            const caseOption = exactOption || optionsList.find(opt => opt.value.toLowerCase() === normalized.toLowerCase());
            const targetValue = caseOption ? caseOption.value : normalized;
            if (!caseOption && !allowCreate) { return; }
            if (!caseOption && allowCreate) {
                const option = document.createElement('option');
                option.value = targetValue;
                option.textContent = labelOverride || targetValue.toUpperCase();
                select.appendChild(option);
            }
            select.value = targetValue;
            select.dataset.fillState = 'auto';
        };

        const extractText = (value) => {
            if (typeof value === 'string') { return value; }
            if (Array.isArray(value)) {
                const first = value.find(item => typeof item === 'string' && item.trim() !== '');
                return typeof first === 'string' ? first : '';
            }
            if (value && typeof value === 'object') {
                const keys = ['text','content','title'];
                for (const key of keys) {
                    if (typeof value[key] === 'string' && value[key].trim() !== '') {
                        return value[key];
                    }
                }
            }
            return '';
        };

        const pickString = (...candidates) => {
            for (const candidate of candidates) {
                const text = extractText(candidate);
                if (typeof text === 'string') {
                    const trimmed = text.trim();
                    if (trimmed !== '') { return trimmed; }
                }
            }
            return '';
        };

        const getMetaValue = (meta, keys) => {
            if (!meta || typeof meta !== 'object') { return ''; }
            for (const key of keys) {
                const parts = key.split('.');
                let current = meta;
                for (const part of parts) {
                    if (current && typeof current === 'object') {
                        current = current[part];
                    } else {
                        current = undefined;
                        break;
                    }
                }
                const text = extractText(current);
                if (text) { return text.trim(); }
            }
            return '';
        };

        const formatHreflang = (meta) => {
            if (!meta || typeof meta !== 'object') { return ''; }
            const hreflang = Array.isArray(meta.hreflang) ? meta.hreflang : [];
            if (!hreflang.length) { return ''; }
            const result = hreflang
                .map(item => {
                    if (!item || typeof item !== 'object') { return ''; }
                    const code = extractText(item.hreflang || item.lang || item.code);
                    return code ? code.toUpperCase() : '';
                })
                .filter(Boolean);
            return result.slice(0, 6).join(', ');
        };

        const findFirstAvailable = (select, candidates, fallback) => {
            if (!select) { return ''; }
            const values = Array.from(select.options).map(opt => opt.value);
            if (Array.isArray(candidates)) {
                for (const candidate of candidates) {
                    if (values.includes(candidate)) { return candidate; }
                }
            }
            if (fallback && values.includes(fallback)) { return fallback; }
            return values[0] || '';
        };

        const applyTargetingDefaults = (languageCode, meta) => {
            const lang = (languageCode || '').toLowerCase();
            const metaRegion = meta && typeof meta.region === 'string' ? meta.region : '';
            if (regionSelect) {
                const candidates = [];
                if (metaRegion) { candidates.push(metaRegion); }
                if (lang === 'ru') {
                    candidates.push('RU', 'CIS');
                }
                candidates.push('Global');
                const targetRegion = findFirstAvailable(regionSelect, candidates, 'Global');
                if (targetRegion) {
                    setSelectValue(regionSelect, targetRegion, { allowCreate: false });
                }
            }
            if (topicSelect) {
                const metaTopic = meta && typeof meta.topic === 'string' ? meta.topic : '';
                const topics = [];
                if (metaTopic) { topics.push(metaTopic); }
                topics.push('General');
                const targetTopic = findFirstAvailable(topicSelect, topics, 'General');
                if (targetTopic) {
                    setSelectValue(topicSelect, targetTopic, { allowCreate: false });
                }
            }
        };

        const truncate = (text, maxLength = 260) => {
            if (!text) { return ''; }
            if (text.length <= maxLength) { return text; }
            return `${text.slice(0, maxLength - 1).trim()}…`;
        };

        const setMetaPreview = (meta, data) => {
            if (metaTitle) {
                const title = getMetaValue(meta, ['title','og_title','open_graph.title','twitter.title']) || extractText(data && data.name);
                metaTitle.textContent = title || '—';
            }
            if (metaDescription) {
                const desc = getMetaValue(meta, ['description','og_description','open_graph.description','twitter.description']) || extractText(data && data.description);
                metaDescription.textContent = desc ? truncate(desc, 320) : '—';
            }
            if (metaLang) {
                const lang = (meta && (meta.lang || getMetaValue(meta, ['language']))) || (data && data.language) || '';
                metaLang.textContent = lang ? lang.toString().toUpperCase() : '—';
            }
            if (metaHreflang) {
                const list = formatHreflang(meta);
                metaHreflang.textContent = list || '—';
            }
        };

        const friendlyErrorMessage = (code) => {
            switch ((code || '').toUpperCase()) {
                case 'INVALID_URL':
                    return 'Сервер отклонил URL. Проверьте адрес.';
                case 'FORBIDDEN':
                    return 'Недостаточно прав для анализа. Обновите страницу.';
                case 'CSRF_FAILED':
                    return 'Сессия устарела. Обновите страницу и попробуйте снова.';
                case 'METHOD_NOT_ALLOWED':
                    return 'Метод запроса не поддерживается.';
                case 'ANALYSIS_FAILED':
                default:
                    return 'Не удалось провести анализ. Попробуйте ещё раз.';
            }
        };

        const handleAnalysisError = (errorMessage) => {
            setCardState('error');
            setStatus('Error');
            resetMetaPreview();
            if (metaDescription) {
                const message = errorMessage ? friendlyErrorMessage(errorMessage) : friendlyErrorMessage('ANALYSIS_FAILED');
                metaDescription.textContent = truncate(message, 320);
            }
            if (payloadInput) {
                payloadInput.value = '';
            }
            setAnalysisFeedback('error');
            toggleResultVisibility(false);
            goToStep(1, { focusSelector: '#project-homepage' });
        };

        const runAnalysis = async () => {
            if (!analyzeButton || analyzeButton.dataset.loading === '1') { return; }
            const endpoint = analyzeButton.dataset.endpoint;
            const url = homepageInput ? homepageInput.value.trim() : '';
            if (!endpoint || !url) { return; }

            setAnalysisFeedback('loading');
            toggleResultVisibility(false);
            setCardState('loading');
            setStatus('Loading');
            resetMetaPreview();
            if (payloadInput) { payloadInput.value = ''; }
            setButtonLoading(true);

            const body = new URLSearchParams();
            body.append('url', url);
            if (csrfInput && csrfInput.value) {
                body.append('csrf_token', csrfInput.value);
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString(),
                    credentials: 'same-origin'
                });

                const json = await response.json().catch(() => null);
                if (!response.ok || !json || !json.ok) {
                    const message = json && json.error ? json.error : response.statusText;
                    throw new Error(message || 'ANALYSIS_FAILED');
                }

                const data = json.data || {};
                const meta = (data.meta && typeof data.meta === 'object') ? data.meta : {};
                const brief = (data.brief && typeof data.brief === 'object') ? data.brief : {};
                const aiUsed = Boolean(data.ai_used || (brief && brief.used_ai));
                const aiError = data.ai_error || (brief && brief.ai_error) || (brief.ai && brief.ai.error);

                if (payloadInput) {
                    payloadInput.value = JSON.stringify(data);
                }

                const suggestedName = pickString(data.suggested_name, data.name_suggested_by_ai, brief.name, data.name, meta.title);
                const suggestedDescription = pickString(data.suggested_description, data.description_suggested_by_ai, brief.description, data.description, meta.description);
                const suggestedLanguage = pickString(data.suggested_language, data.language, brief.language, data.lang, meta.lang);

                if (nameInput) {
                    setAutofillValue(nameInput, suggestedName);
                }
                if (descriptionInput) {
                    setAutofillValue(descriptionInput, suggestedDescription);
                }
                if (languageSelect && suggestedLanguage) {
                    const normalized = suggestedLanguage.toLowerCase();
                    const base = normalized.split(/[-_]/)[0] || normalized;
                    const optionsList = Array.from(languageSelect.options).map(opt => opt.value);
                    const hasExact = optionsList.includes(normalized);
                    const hasBase = optionsList.includes(base);
                    const target = hasExact ? normalized : (hasBase ? base : normalized);
                    setSelectValue(languageSelect, target, { allowCreate: true, label: (target || '').toUpperCase() });
                }

                applyTargetingDefaults(suggestedLanguage, meta);

                setMetaPreview(meta, {
                    name: suggestedName,
                    description: suggestedDescription,
                    language: suggestedLanguage
                });
                setCardState('success');
                if (resultCard) {
                    resultCard.dataset.aiUsed = aiUsed ? '1' : '0';
                    if (aiError && !aiUsed) {
                        resultCard.dataset.aiError = aiError;
                    } else {
                        delete resultCard.dataset.aiError;
                    }
                }
                const statusMode = aiUsed ? 'SuccessAi' : (aiError ? 'SuccessFallback' : 'Success');
                setStatus(statusMode);
                toggleResultVisibility(true);
                if (analysisFeedback) {
                    analysisFeedback.dataset.aiUsed = aiUsed ? '1' : '0';
                    if (aiError && !aiUsed) {
                        analysisFeedback.dataset.aiError = aiError;
                    } else {
                        delete analysisFeedback.dataset.aiError;
                    }
                }
                setAnalysisFeedback(aiUsed ? 'successAi' : 'success');
                unlockStep(2);
                goToStep(2, { focusSelector: '#project-name' });
            } catch (error) {
                const message = error && error.message ? error.message : 'ANALYSIS_FAILED';
                handleAnalysisError(message);
            } finally {
                setButtonLoading(false);
                if (!prefersReducedMotion && currentStep === 2 && resultCard && !resultCard.classList.contains('d-none')) {
                    try {
                        resultCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } catch (_) {
                        resultCard.scrollIntoView();
                    }
                }
            }
        };

        if (analyzeButton) {
            setButtonLoading(false);
            analyzeButton.addEventListener('click', runAnalysis);
        }

        if (manualProceedButton) {
            manualProceedButton.addEventListener('click', () => {
                if (payloadInput) { payloadInput.value = ''; }
                setCardState(null);
                setStatus('Default');
                resetMetaPreview();
                toggleResultVisibility(false);
                unlockStep(2);
                setAnalysisFeedback('manual');
                goToStep(2, { focusSelector: '#project-name' });
            });
        }

        if (stepBackButton) {
            stepBackButton.addEventListener('click', () => {
                goToStep(1, { focusSelector: '#project-homepage' });
            });
        }

        if (homepageInput) {
            initAutofillTracking(homepageInput);
            let lastHomepageValue = homepageInput.value.trim();
            const resetPayload = () => {
                if (payloadInput) { payloadInput.value = ''; }
                resetSteps();
                lastHomepageValue = homepageInput.value.trim();
                updateAnalyzeButtonState();
            };
            homepageInput.addEventListener('input', () => {
                updateAnalyzeButtonState();
                if (!homepageInput.value.trim()) {
                    resetPayload();
                }
            });
            homepageInput.addEventListener('change', () => {
                const currentValue = homepageInput.value.trim();
                if (currentValue === lastHomepageValue) { return; }
                resetPayload();
                if (currentValue) {
                    setAnalysisFeedback('idle');
                }
            });
            updateAnalyzeButtonState();
        }

        initAutofillTracking(nameInput);
        initAutofillTracking(descriptionInput);
        initSelectTracking(languageSelect);
        initSelectTracking(regionSelect);
        initSelectTracking(topicSelect);

        if (statusBadge) { setStatus('Default'); }
        resetMetaPreview();
        toggleResultVisibility(false);
        setAnalysisFeedback('idle');
        updateStepperUI();
        showPanelForStep(currentStep);
        updateStepStateInput();
        updateAnalyzeButtonState();
    }

    if (projectCreateForm && projectCreateOverlay) {
        const tipTarget = projectCreateOverlay.querySelector('[data-tip-text]');
        const submitButton = projectCreateForm.querySelector('[type="submit"]');
        const tipsRaw = projectCreateOverlay.dataset.tips || '';
        const tips = tipsRaw.split('|').map(t => t.trim()).filter(Boolean);
        let tipIndex = 0;
        let tipTimer = null;
        let overlayActive = false;

        const clearTipTimer = () => {
            if (tipTimer) {
                clearInterval(tipTimer);
                tipTimer = null;
            }
        };

        const setTip = (index) => {
            if (!tipTarget || !tips.length) return;
            const normalizedIndex = ((index % tips.length) + tips.length) % tips.length;
            tipTarget.textContent = tips[normalizedIndex];
        };

        const startTipCycle = () => {
            if (prefersReducedMotion || !tipTarget || tips.length <= 1) return;
            clearTipTimer();
            tipTimer = window.setInterval(() => {
                tipIndex = (tipIndex + 1) % tips.length;
                setTip(tipIndex);
            }, 4600);
        };

        const showOverlay = () => {
            if (overlayActive) return;
            overlayActive = true;
            document.body.classList.add('project-create-loading');
            projectCreateOverlay.classList.remove('d-none');
            projectCreateOverlay.classList.add('is-active');
            projectCreateOverlay.setAttribute('aria-hidden', 'false');
            if (typeof projectCreateOverlay.focus === 'function') {
                try { projectCreateOverlay.focus({ preventScroll: true }); }
                catch (_) { projectCreateOverlay.focus(); }
            }
            tipIndex = 0;
            setTip(tipIndex);
            startTipCycle();
        };

        const teardownOverlay = () => {
            clearTipTimer();
            overlayActive = false;
            document.body.classList.remove('project-create-loading');
            projectCreateOverlay.classList.remove('is-active');
            projectCreateOverlay.classList.add('d-none');
            projectCreateOverlay.setAttribute('aria-hidden', 'true');
            if (submitButton) {
                submitButton.classList.remove('disabled');
                submitButton.removeAttribute('aria-disabled');
                submitButton.removeAttribute('disabled');
            }
        };

        projectCreateForm.addEventListener('submit', (event) => {
            if (overlayActive) return;
            if (typeof projectCreateForm.checkValidity === 'function' && !projectCreateForm.checkValidity()) {
                return;
            }
            if (submitButton) {
                submitButton.classList.add('disabled');
                submitButton.setAttribute('aria-disabled', 'true');
                submitButton.setAttribute('disabled', 'disabled');
            }
            window.setTimeout(showOverlay, 80);
        });

        window.addEventListener('pagehide', clearTipTimer);
        window.addEventListener('beforeunload', clearTipTimer);

        // If the page was restored from cache (bfcache), ensure overlay isn't stuck
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                teardownOverlay();
            }
        });
    }
});
