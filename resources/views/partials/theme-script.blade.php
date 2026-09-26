{{--
    Runs before the first paint so the page never flashes the wrong theme
    (ADR 0003 §6.4) or the wrong sidebar width. It must stay tiny, inline and free of colours; the
    same logic lives in resources/js/appearance.js for later changes.
--}}
<script>
    (function () {
        var root = document.documentElement, mode;
        try { mode = localStorage.getItem('vistud.appearance'); } catch (e) {}
        if (mode !== 'light' && mode !== 'dark') mode = 'system';
        var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.dataset.appearance = mode;
        root.dataset.theme = dark ? root.dataset.themeDark : root.dataset.themeLight;
        try { if (localStorage.getItem('vistud.sidebar') === 'collapsed') root.dataset.sidebar = 'collapsed'; } catch (e) {}
    })();
</script>
