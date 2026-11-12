/*
 * Atlas panel — the entire client-side script.
 *
 * No framework, no bundler, no build step: this file is served as-is from
 * public/assets and loaded with `defer`. Everything is wired through data
 * attributes so a Content-Security-Policy without 'unsafe-inline' works and so
 * the markup stays readable.
 *
 * Behaviours (each one is a real control in the UI — nothing decorative):
 *   1. [data-theme-toggle]  dark ⇄ light, persisted in a cookie so the very
 *                           first paint on the next request is already correct
 *                           (a flash of the wrong theme in a control panel is
 *                           not acceptable when an operator has 30 tabs open).
 *   2. [data-sidebar-toggle] opens/closes the off-canvas navigation on phones.
 *   3. [data-confirm]       destructive-action guard; replaces the browser
 *                           confirm() with nothing — it *is* confirm(), but the
 *                           message lives in the markup, not in an inline
 *                           handler.
 *   4. [data-autosubmit]    filter/search selects submit their form on change.
 *   5. [data-copy]          copies a value (licence key, path) to the clipboard
 *                           and flashes "Copied" on the button.
 *   6. [data-dismiss]       closes the flash message it lives in.
 *   7. [data-menu]          top-bar dropdown menus, Esc/outside-click aware.
 */
(() => {
    'use strict';

    const THEME_COOKIE = 'atlas-theme';

    const readCookie = (name) => {
        const hit = document.cookie.split('; ').find((row) => row.startsWith(name + '='));
        return hit ? decodeURIComponent(hit.split('=').slice(1).join('=')) : null;
    };

    const writeCookie = (name, value) => {
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';path=/;max-age=31536000;samesite=lax';
    };

    /* 1. theme ------------------------------------------------------------ */
    const applyTheme = (theme) => {
        document.documentElement.dataset.theme = theme;
        document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
            const label = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        });
    };

    // The server already rendered data-theme from the cookie; only reconcile
    // the button labels here so there is no repaint of the page itself.
    applyTheme(document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');

    /* 7. dropdown menus --------------------------------------------------- */
    const closeMenus = (except) => {
        document.querySelectorAll('[data-menu-panel]').forEach((panel) => {
            if (panel === except) return;
            panel.hidden = true;
            const trigger = panel.parentElement?.querySelector('[data-menu]');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    };

    const toggleMenu = (trigger) => {
        const panel = trigger.parentElement?.querySelector('[data-menu-panel]');
        if (!panel) return;
        const willOpen = panel.hidden;
        closeMenus(panel);
        panel.hidden = !willOpen;
        trigger.setAttribute('aria-expanded', String(willOpen));
    };

    /* delegated clicks ---------------------------------------------------- */
    document.addEventListener('click', (event) => {
        const theme = event.target.closest('[data-theme-toggle]');
        if (theme) {
            const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
            writeCookie(THEME_COOKIE, next);
            applyTheme(next);
            return;
        }

        const sidebar = event.target.closest('[data-sidebar-toggle]');
        if (sidebar) {
            const nav = document.getElementById('sidebar');
            if (nav) {
                nav.classList.toggle('open');
                sidebar.setAttribute(
                    'aria-expanded',
                    String(nav.classList.contains('open')),
                );
            }
            return;
        }

        const menu = event.target.closest('[data-menu]');
        if (menu) {
            event.preventDefault();
            toggleMenu(menu);
            return;
        }

        // Clicking anywhere else closes an open menu.
        if (!event.target.closest('[data-menu-panel]')) closeMenus();

        const dismiss = event.target.closest('[data-dismiss]');
        if (dismiss) {
            dismiss.closest('[data-flash]')?.remove();
            return;
        }

        const copy = event.target.closest('[data-copy]');
        if (copy) {
            const value = copy.getAttribute('data-copy');
            const done = () => {
                const original = copy.textContent;
                copy.textContent = 'Copied';
                setTimeout(() => { copy.textContent = original; }, 1400);
            };
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(value).then(done, () => {});
            } else {
                // Fallback for plain-http origins, where the async clipboard
                // API is unavailable. The panel is served over https in
                // production, but 2087 on a LAN box may be http.
                const field = document.createElement('textarea');
                field.value = value;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                try { document.execCommand('copy'); done(); } catch (_) { /* noop */ }
                field.remove();
            }
            return;
        }

        // Any anchor/button carrying data-confirm asks before it goes on.
        const guard = event.target.closest('[data-confirm]');
        if (guard && !window.confirm(guard.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenus();
    });

    document.addEventListener('change', (event) => {
        const auto = event.target.closest('[data-autosubmit]');
        if (auto && auto.form) auto.form.submit();
    });

    // Forms that carry a destructive verb get the same guard at submit time,
    // so Enter-to-submit cannot skip it.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const guard = form.getAttribute('data-confirm');
        if (guard && !window.confirm(guard)) event.preventDefault();
    });
})();
