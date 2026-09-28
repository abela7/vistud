<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Styles that keep typing fast in a long note (the owner's review,
 * 2026-09-28): a :has() on the page itself (:root, html or body) makes the
 * browser restyle every element whenever anything changes, twice a key in
 * the editor. Set an attribute from the script that makes the change instead.
 */
class StylePerformanceTest extends TestCase
{
    public function test_no_style_asks_the_whole_page_what_it_has(): void
    {
        $offenders = [];
        foreach (glob(resource_path('css/*.css')) ?: [] as $path) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
            if (preg_match_all('/(?<![\w-])(?::root|html|body)\s*:has\(/i', $css, $matches) > 0) {
                $offenders[] = basename($path).': '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $offenders);
    }
}
