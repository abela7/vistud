{{--
    Runs before the first paint so the page never flashes the wrong theme
    (ADR 0003 §6.4) or the wrong sidebar width. It must stay tiny, inline and free of colours; the
    same logic lives in resources/js/appearance.js for later changes. The light themes a student
    may choose are listed on window, which survives pages swapped in without reloading.
--}}
<script>
    (function () {
        var root = document.documentElement, mode, light;
        window.vistudLightThemes = @json(array_keys(app(\App\Appearance\Themes::class)->ofScheme('light')));
        try { mode = localStorage.getItem('vistud.appearance'); light = localStorage.getItem('vistud.theme-light'); } catch (e) {}
        if (mode !== 'light' && mode !== 'dark') mode = 'system';
        if (window.vistudLightThemes.indexOf(light) !== -1) root.dataset.themeLight = light;
        var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.dataset.appearance = mode;
        root.dataset.theme = dark ? root.dataset.themeDark : root.dataset.themeLight;
        var scheme = dark ? 'dark' : 'light';
        root.style.colorScheme = scheme;
        var meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) meta.content = scheme;
        try { if (localStorage.getItem('vistud.sidebar') === 'collapsed') root.dataset.sidebar = 'collapsed'; } catch (e) {}
    })();
</script>
