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
