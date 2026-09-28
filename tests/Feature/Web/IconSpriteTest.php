<?php

namespace Tests\Feature\Web;

use App\Appearance\Icons;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Tests\TestCase;

/** Icons come once, from a sprite the browser keeps (App\Appearance\Icons). */
class IconSpriteTest extends TestCase
{
    public function test_the_sprite_holds_every_icon_once_and_is_kept_for_a_year(): void
    {
        $response = $this->get('/icons.svg?v='.Icons::version())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        $svg = $response->getContent();
        $icons = count(glob(resource_path('icons/*.svg')));
        $this->assertSame($icons, substr_count($svg, '<symbol '));
        $this->assertStringContainsString('<symbol id="check" viewBox="0 0 24 24" fill="none" stroke="currentColor"', $svg);
        $this->assertStringNotContainsString('<!--', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_an_icon_names_its_symbol_instead_of_carrying_its_drawing(): void
    {
        $html = Blade::render('<x-icon name="check" class="size-4" /><x-icon name="x" label="Close" />');

        $this->assertStringContainsString('<use href="/icons.svg?v='.Icons::version().'#check"></use>', $html);
        $this->assertStringContainsString('<svg fill="none" aria-hidden="true" focusable="false" class="shrink-0 size-4">', $html);
        $this->assertStringContainsString('role="img" aria-label="Close"', $html);
        $this->assertStringNotContainsString('<path', $html);
    }

    public function test_an_unknown_icon_is_a_mistake_caught_at_once(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Icons::url('../../.env');
    }
}
