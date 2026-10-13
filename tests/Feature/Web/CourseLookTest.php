<?php

namespace Tests\Feature\Web;

use App\Appearance\CourseColour;
use App\Livewire\Workspaces\CourseNew;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * How a course looks (the owner's ask, 2026-10-12): a theme colour or one of the student's own, and its icon from many,
 * in groups. A colour of their own is mixed with the theme's text and surface, so it reads in light and dark.
 */
class CourseLookTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
    }

    public function test_a_course_takes_a_colour_of_its_own_and_any_icon_of_the_groups(): void
    {
        $course = app(Workspaces::class)->create($this->by, ['name' => 'Astronomy', 'colour' => '3366FF', 'icon' => 'telescope']);
        $this->assertSame(['#3366ff', 'telescope'], [$course->colour, $course->icon]);
        $this->assertSame('ws-colour-custom', $course->colourClass());
        $this->assertStringContainsString('--ws: color-mix(in oklab, #3366ff', $course->colourStyle());

        // A theme colour stays a class, with nothing written on the element.
        $plain = app(Workspaces::class)->update($this->by, $course->id, ['name' => 'Astronomy', 'colour' => 'teal', 'icon' => 'orbit']);
        $this->assertSame(['ws-colour-teal', ''], [$plain->colourClass(), $plain->colourStyle()]);

        foreach ([['colour' => 'tomato'], ['colour' => '#12345'], ['icon' => 'skull']] as $wrong) {
            $this->assertThrows(fn () => app(Workspaces::class)->create($this->by, ['name' => 'X', ...$wrong]), Unprocessable::class);
        }
    }

    public function test_a_colour_of_their_own_takes_more_of_the_themes_text_the_lighter_or_darker_it_is(): void
    {
        $share = fn (string $hex) => (int) preg_replace('/.*#[0-9a-f]{6} (\d+)%, var\(--text\).*/', '$1', CourseColour::style($hex));
        $this->assertSame(35, $share('#ffffff'));
        $this->assertSame(35, $share('#000000'));
        $this->assertGreaterThan(55, $share('#3366ff'));
        $this->assertSame('', CourseColour::style('blue'));
        $this->assertNull(CourseColour::normalise('#GGGGGG'));
    }

    public function test_the_pages_show_the_colour_and_offer_every_icon(): void
    {
        $course = app(Workspaces::class)->create($this->by, ['name' => 'Astronomy', 'colour' => '#3366ff', 'icon' => 'telescope']);
        $this->actingAs($this->ada)->get(route('home'))->assertOk()
            ->assertSee('ws-colour-custom', false)->assertSee('--ws: color-mix(in oklab, #3366ff', false);

        $this->actingAs($this->ada)->get(route('workspaces.create'))->assertOk()
            ->assertSee('A colour of your own')->assertSee('More icons')->assertSee('Maths and science')->assertSee('value="telescope"', false);

        $this->actingAs($this->ada);
        Livewire::test(CourseNew::class)->set('name', 'Geology')->set('colour', '#8a5a2b')->set('icon', 'mountain')->set('how', 'myself')->call('create');
        $made = collect(app(Workspaces::class)->list($this->by))->firstWhere('name', 'Geology');
        $this->assertSame(['#8a5a2b', 'mountain'], [$made->colour, $made->icon]);
        $this->assertNotNull($course);
    }
}
