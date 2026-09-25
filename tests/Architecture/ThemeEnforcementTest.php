<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Colours and gradients come only from themes (ADR 0003 §6.3, DESIGN.md §3.5).
 * Layer 1 scans the sources, layer 2 the built stylesheet. Layer 3, the
 * sentinel theme, runs in the browser (tests/Browser/theme-sentinel.spec.js).
 */
class ThemeEnforcementTest extends TestCase
{
    /** Scanned for colours and gradients. Theme data lives elsewhere (resources/themes, resources/brand). */
    private const SOURCES = ['resources/css', 'resources/js', 'resources/views', 'app/Appearance', 'app/Livewire', 'app/View'];

    /** The only exemption inside the scanned folders: the generated theme stylesheet. */
    private const EXEMPT = ['resources/css/themes/'];

    /**
     * The theme writer serialises theme data into CSS, so it names colour
     * and gradient functions; it must still hold no colour values itself.
     */
    private const THEME_WRITER = 'app/Appearance/';

    private const NAMED_COLORS = 'aliceblue|antiquewhite|aqua|aquamarine|azure|beige|bisque|black|blanchedalmond|blue|blueviolet|brown|burlywood|cadetblue|chartreuse|chocolate|coral|cornflowerblue|cornsilk|crimson|cyan|darkblue|darkcyan|darkgoldenrod|darkgray|darkgreen|darkgrey|darkkhaki|darkmagenta|darkolivegreen|darkorange|darkorchid|darkred|darksalmon|darkseagreen|darkslateblue|darkslategray|darkslategrey|darkturquoise|darkviolet|deeppink|deepskyblue|dimgray|dimgrey|dodgerblue|firebrick|floralwhite|forestgreen|fuchsia|gainsboro|ghostwhite|gold|goldenrod|gray|green|greenyellow|grey|honeydew|hotpink|indianred|indigo|ivory|khaki|lavender|lavenderblush|lawngreen|lemonchiffon|lightblue|lightcoral|lightcyan|lightgoldenrodyellow|lightgray|lightgreen|lightgrey|lightpink|lightsalmon|lightseagreen|lightskyblue|lightslategray|lightslategrey|lightsteelblue|lightyellow|lime|limegreen|linen|magenta|maroon|mediumaquamarine|mediumblue|mediumorchid|mediumpurple|mediumseagreen|mediumslateblue|mediumspringgreen|mediumturquoise|mediumvioletred|midnightblue|mintcream|mistyrose|moccasin|navajowhite|navy|oldlace|olive|olivedrab|orange|orangered|orchid|palegoldenrod|palegreen|paleturquoise|palevioletred|papayawhip|peachpuff|peru|pink|plum|powderblue|purple|rebeccapurple|red|rosybrown|royalblue|saddlebrown|salmon|sandybrown|seagreen|seashell|sienna|silver|skyblue|slateblue|slategray|slategrey|snow|springgreen|steelblue|tan|teal|thistle|tomato|turquoise|violet|wheat|white|whitesmoke|yellow|yellowgreen|canvas|canvastext|buttonface|buttontext|field|fieldtext|highlight|highlighttext|graytext|linktext|visitedtext|activetext|mark|marktext|accentcolor|accentcolortext';

    private const COLOR_PROPERTIES = 'color|background|background-color|background-image|border|border-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?(?:-color)?|border-color|outline|outline-color|fill|stroke|box-shadow|text-shadow|text-decoration|text-decoration-color|caret-color|accent-color|column-rule|column-rule-color|scrollbar-color|-webkit-tap-highlight-color|-webkit-text-fill-color|stop-color|flood-color|lighting-color';

    public function test_sources_contain_no_colours_or_gradients(): void
    {
        $named = self::NAMED_COLORS;
        $properties = self::COLOR_PROPERTIES;
        $rules = [
            'a hex colour' => '/(?<![&\w-])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/i',
            'a colour function' => '/(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\s*\(/i',
            'a gradient (themes own every gradient)' => '/(?<![\w-])(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i',
            'a named colour in a colour property' => "/(?<![\\w-])(?:{$properties})\\s*:\\s*[^;{}\"'>]*(?<![\\w-])(?:{$named})(?![\\w-])/i",
            'a named colour in an SVG paint attribute' => "/(?<![\\w-])(?:fill|stroke|stop-color|color)\\s*=\\s*[\"'](?:{$named})[\"']/i",
            'a Tailwind gradient utility' => '/(?<![\w:-])(?:bg-(?:linear|radial|conic|gradient)(?:-[\w\/\[\]().%-]+)?|(?:from|via|to)-(?:\[[^\]]*\]|(?:canvas|surface|fg|on|accent|border|divider|hover|pressed|selected|selection|danger|warning|success|info|role|logo|focus|overlay|drag|drop)[\w\/-]*))(?![\w-])/',
            'a Tailwind arbitrary colour' => "/-\\[(?:color:|image:)?(?:{$named}|var\\()/i",
            'a colour string in JavaScript' => "/[\"'`](?:{$named})[\"'`]/i",
        ];

        $offenders = [];
        foreach ($this->files(self::SOURCES) as $file) {
            $isJs = str_ends_with($file, '.js') || str_ends_with($file, '.mjs');
            $contents = $this->withoutComments($file, file_get_contents(base_path($file)));
            foreach ($rules as $what => $pattern) {
                if ($what === 'a colour string in JavaScript' && ! $isJs) {
                    continue;
                }
                if (str_starts_with($file, self::THEME_WRITER) && $what !== 'a hex colour') {
                    continue;
                }
                if (preg_match_all($pattern, $contents, $matches) > 0) {
                    $offenders[] = "{$file}: {$what} (".implode(', ', array_unique($matches[0])).')';
                }
            }
        }

        $this->assertSame([], $offenders, 'Use theme tokens (DESIGN.md §3). Colours and gradients live only in resources/themes/*.json.');
    }

    public function test_the_built_stylesheet_has_colours_and_gradients_only_in_theme_blocks(): void
    {
        $stylesheets = glob(public_path('build/assets/*.css')) ?: [];
        if ($stylesheets === []) {
            $this->markTestSkipped('No built assets. Run `npm run build` (CI builds before this suite).');
        }

        $offenders = [];
        foreach ($stylesheets as $path) {
            $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($path));
            foreach ($this->declarationBlocks($css) as [$selector, $declarations]) {
                if ($this->isThemeBlock($selector, $declarations)) {
                    continue;
                }
                foreach ($this->literalColors($declarations) as $literal) {
                    $offenders[] = basename($path).": {$selector} { … {$literal} … }";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'Every colour in the built CSS must sit inside a theme block (ADR 0003 §6.3).');
    }

    public function test_the_generated_theme_files_are_up_to_date(): void
    {
        $status = Artisan::call('vistud:themes:build', ['--check' => true]);

        $this->assertSame(0, $status, Artisan::output());
    }

    /** @return list<string> literal colours and gradients in a declaration block */
    private function literalColors(string $declarations): array
    {
        $found = [];
        $named = self::NAMED_COLORS;
        foreach (preg_split('/;(?![^(]*\))/', $declarations) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));

            // Hex colours, except fully transparent ones (Tailwind's "0 0 #0000" shadow defaults).
            preg_match_all('/#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/i', $value, $m);
            foreach ($m[0] as $hex) {
                $digits = substr($hex, 1);
                $transparent = (strlen($digits) === 4 && $digits[3] === '0') || (strlen($digits) === 8 && substr($digits, 6) === '00');
                if (! $transparent) {
                    $found[] = "{$property}: {$hex}";
                }
            }
            if (preg_match('/(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|light-dark)\(/i', $value) === 1) {
                $found[] = "{$property}: {$value}";
            }
            // color-mix() only derives from tokens and currentColor here (Tailwind's
            // placeholder default); one with a literal colour is caught above.
            if (preg_match('/(?<![\w-])(?:repeating-)?(?:linear|radial|conic)-gradient\(/i', $value) === 1) {
                $found[] = "{$property}: {$value}";
            }
            if (preg_match('/^(?:'.self::COLOR_PROPERTIES.'|--[\w-]+)$/i', $property) === 1
                && preg_match("/(?<![\\w-])(?:{$named})(?![\\w-])/i", $value) === 1
                && ! str_starts_with($property, '--tw-')) {
                $found[] = "{$property}: {$value}";
            }
        }

        return $found;
    }

    /** A theme block: :root or [data-theme] selectors that only set custom properties and color-scheme. */
    private function isThemeBlock(string $selector, string $declarations): bool
    {
        $theme = '(?::root|\[data-theme="?[a-z0-9-]+"?\])';
        if (preg_match("/^{$theme}(?:\\s*,\\s*{$theme})*$/", trim($selector)) !== 1) {
            return false;
        }
        foreach (preg_split('/;(?![^(]*\))/', $declarations) as $declaration) {
            $property = trim(explode(':', $declaration, 2)[0]);
            if ($property !== '' && ! str_starts_with($property, '--') && $property !== 'color-scheme') {
                return false;
            }
        }

        return true;
    }

    /**
     * Every innermost declaration block with its selector.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function declarationBlocks(string $css): array
    {
        $blocks = [];
        $length = strlen($css);
        $prelude = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '{') {
                $depth = 1;
                $start = $i + 1;
                for ($j = $start; $j < $length && $depth > 0; $j++) {
                    $depth += $css[$j] === '{' ? 1 : ($css[$j] === '}' ? -1 : 0);
                }
                $body = substr($css, $start, $j - $start - 1);
                if (str_contains($body, '{')) {
                    array_push($blocks, ...$this->declarationBlocks($body));
                } else {
                    $blocks[] = [trim($prelude), $body];
                }
                $prelude = '';
                $i = $j - 1;
            } elseif ($char === ';' && trim($prelude) !== '' && str_starts_with(trim($prelude), '@')) {
                $prelude = ''; // @import, @charset, @layer a, b;
            } else {
                $prelude .= $char;
            }
        }

        return $blocks;
    }

    private function withoutComments(string $file, string $contents): string
    {
        if (str_ends_with($file, '.blade.php')) {
            $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $contents);
        }
        if (preg_match('/\.(css|js|mjs|php)$/', $file) === 1) {
            $contents = preg_replace('#/\*.*?\*/#s', '', $contents);
        }
        if (preg_match('/\.(js|mjs)$/', $file) === 1 || (str_ends_with($file, '.php') && ! str_ends_with($file, '.blade.php'))) {
            $contents = preg_replace('#(?<![:"\'])//[^\n]*#', '', $contents);
        }

        return $contents;
    }

    /** @return list<string> */
    private function files(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (! is_dir(base_path($directory))) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (preg_match('/\.(css|js|mjs|php)$/', $relative) !== 1) {
                    continue;
                }
                foreach (self::EXEMPT as $exempt) {
                    if (str_starts_with($relative, $exempt)) {
                        continue 2;
                    }
                }
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }
}
