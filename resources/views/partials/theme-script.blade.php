{{--
    Runs before the first paint so the page never flashes the wrong theme
    (ADR 0003 §6.4) or the wrong sidebar width. It must stay tiny, inline and free of colours; the
    same logic lives in resources/js/appearance.js for later changes. The themes a student may
    choose (each a light and a dark theme's ID) are put on window, which survives pages swapped in
    without reloading.
--}}
@php
    $choosable = array_map(fn (array $pair) => [$pair['light']->id, $pair['dark']->id], app(\App\Appearance\Themes::class)->pairs(config('vistud.appearance.themes')));
@endphp
<script>
    (function () {
        var root = document.documentElement, mode, id, chosen;
        window.vistudThemes = @json($choosable);
        try { mode = localStorage.getItem('vistud.appearance'); id = localStorage.getItem('vistud.theme'); } catch (e) {}
        if (Object.prototype.hasOwnProperty.call(window.vistudThemes, id)) chosen = window.vistudThemes[id];
        if (mode !== 'light' && mode !== 'dark') mode = 'system';
        if (chosen) { root.dataset.themeLight = chosen[0]; root.dataset.themeDark = chosen[1]; }
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
