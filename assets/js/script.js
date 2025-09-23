// PromoPilot Scripts — animations + theme toggle

document.addEventListener('DOMContentLoaded', function() {
    // Respect reduced motion
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // THEME TOGGLE
    const root = document.documentElement;
    const storageKey = 'pp-theme';
    const btn = document.getElementById('themeToggle');

    function applyTheme(mode) {
        if (mode === 'light') {
            root.setAttribute('data-theme', 'light');
        } else {
            root.removeAttribute('data-theme'); // dark by default
        }
        if (btn) btn.innerHTML = mode === 'light' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
        // update bgfx colors on theme change
        if (typeof window.ppBgfxUpdateColors === 'function') {
            window.ppBgfxUpdateColors();
        }
    }

    const saved = localStorage.getItem(storageKey);
    if (saved === 'light' || saved === 'dark') {
        applyTheme(saved);
    } else {
        const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        applyTheme(prefersLight ? 'light' : 'dark');
    }

    if (btn) {
        btn.addEventListener('click', function() {
            const current = root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            const next = current === 'light' ? 'dark' : 'light';
            localStorage.setItem(storageKey, next);
            applyTheme(next);
        });
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

    // Language switcher auto-submit (if using a select)
    const langSelect = document.querySelector('select[name="lang"]');
    if (langSelect) {
        langSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }

    // Admin sections toggle (now supports users, projects, settings)
    const sections = {
        users: document.getElementById('users-section'),
        projects: document.getElementById('projects-section'),
        settings: document.getElementById('settings-section')
    };
    if (sections.users || sections.projects || sections.settings) {
        function show(sectionKey) {
            Object.keys(sections).forEach(k => {
                if (sections[k]) sections[k].style.display = (k === sectionKey) ? 'block' : 'none';
            });
        }
        window.ppShowSection = show;
        // default
        if (sections.users) show('users');
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
});