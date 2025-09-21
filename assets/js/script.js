// PromoPilot Scripts — animations + theme toggle

document.addEventListener('DOMContentLoaded', function() {
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

    // Animations
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        setTimeout(() => { card.classList.add('bounce-in'); }, index * 80);
    });

    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => { alert.classList.add('fade-in'); });

    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', function() { this.style.transform = 'translateY(-1px)'; });
        btn.addEventListener('mouseleave', function() { this.style.transform = 'translateY(0)'; });
    });

    const inputs = document.querySelectorAll('.form-control');
    inputs.forEach(input => {
        input.addEventListener('focus', function() { if (this.parentElement) this.parentElement.style.transform = 'scale(1.01)'; });
        input.addEventListener('blur', function() { if (this.parentElement) this.parentElement.style.transform = 'scale(1)'; });
    });

    // Language switcher auto-submit (if using a select)
    const langSelect = document.querySelector('select[name="lang"]');
    if (langSelect) {
        langSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }

    // Scroll fade-in
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => { if (entry.isIntersecting) entry.target.classList.add('fade-in'); });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

    document.querySelectorAll('.card, .alert, .table').forEach(el => observer.observe(el));

    // Admin sections toggle (if present)
    const usersSection = document.getElementById('users-section');
    const projectsSection = document.getElementById('projects-section');
    if (usersSection && projectsSection) {
        usersSection.style.display = 'block';
        projectsSection.style.display = 'none';
        window.ppShowSection = function(section) {
            usersSection.style.display = section === 'users' ? 'block' : 'none';
            projectsSection.style.display = section === 'projects' ? 'block' : 'none';
        }
    }
});