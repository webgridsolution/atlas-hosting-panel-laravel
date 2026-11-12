/* Runs synchronously in <head>, before first paint, so the saved theme is
   applied without a flash of the wrong colours. Deliberately tiny: it must not
   delay the first paint by more than a few microseconds. */
(function () {
    try {
        var saved = localStorage.getItem('atlas-theme');
        var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.dataset.theme = saved || (dark ? 'dark' : 'light');
    } catch (e) {
        document.documentElement.dataset.theme = 'light';
    }
})();
