/* Atlas — vanilla JS. No framework, no build-time magic. */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/* ---------- theme ---------- */
const THEME_KEY = 'atlas-theme';

function preferredTheme() {
    const saved = localStorage.getItem(THEME_KEY);
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    $$('[data-theme-toggle]').forEach((btn) => {
        btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
        btn.setAttribute('title', theme === 'dark' ? 'Light theme' : 'Dark theme');
    });
}

applyTheme(preferredTheme());

document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-theme-toggle]');
    if (toggle) {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    }
});

/* ---------- mobile sidebar ---------- */
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-sidebar-toggle]')) {
        $('.sidebar')?.classList.toggle('is-open');
        $('.scrim')?.classList.toggle('is-open');
        return;
    }
    if (e.target.closest('.scrim')) {
        $('.sidebar')?.classList.remove('is-open');
        $('.scrim')?.classList.remove('is-open');
    }
});

/* ---------- dropdown menus ---------- */
document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-menu-trigger]');
    $$('.menu.is-open').forEach((menu) => {
        if (!trigger || menu.id !== trigger.getAttribute('data-menu-trigger')) {
            menu.classList.remove('is-open');
            trigger?.setAttribute('aria-expanded', 'false');
        }
    });
    if (trigger) {
        const menu = document.getElementById(trigger.getAttribute('data-menu-trigger'));
        const open = menu?.classList.toggle('is-open');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        $$('.menu.is-open').forEach((m) => m.classList.remove('is-open'));
        $('.sidebar')?.classList.remove('is-open');
        $('.scrim')?.classList.remove('is-open');
    }
});

/* ---------- copy to clipboard ---------- */
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    let value = btn.getAttribute('data-copy');
    if (value === 'sibling') {
        value = btn.closest('.codeblock')?.querySelector('[data-copy-source]')?.textContent?.trim() ?? '';
    }
    try {
        await navigator.clipboard.writeText(value);
        const original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = original; }, 1400);
    } catch {
        btn.textContent = 'Press ⌘C';
    }
});

/* ---------- dangerous action confirmation ---------- */
document.addEventListener('submit', (e) => {
    const form = e.target;
    const message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});

/* ---------- auto-dismiss flash messages ---------- */
window.setTimeout(() => {
    $$('[data-flash]').forEach((el) => {
        el.style.transition = 'opacity .4s ease';
        el.style.opacity = '0';
        window.setTimeout(() => el.remove(), 420);
    });
}, 6000);

/* ---------- active nav highlight fallback ---------- */
$$('.nav-item').forEach((item) => {
    if (item.getAttribute('href') === window.location.pathname) {
        item.classList.add('is-active');
    }
});

/* ---------- reveal masked secrets (licence keys) ---------- */
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-reveal-toggle]');
    if (!btn) return;

    const scope = btn.closest('.row') || btn.parentElement;
    const masked = scope.querySelector('[data-masked]');
    const revealed = scope.querySelector('[data-revealed]');
    if (!masked || !revealed) return;

    const showing = !revealed.hidden;
    revealed.hidden = showing;
    masked.hidden = !showing;
    btn.textContent = showing ? 'Reveal' : 'Hide';
});
