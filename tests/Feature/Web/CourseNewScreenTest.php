<?php

namespace Tests\Feature\Web;

use App\Engine\Settings;
use App\Livewire\Workspaces\CourseNew;
use App\Models\User;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The New course page: a page of its own, with the choice of the AI's guide or setting it up by hand (docs/specs/vistud-2-blueprint.md, Phase 8). */
class CourseNewScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
    }

    private function aiReady(): void
    {
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->principal($this->ada), ['tutor_model' => 'fake/tutor', 'consent' => true]);
    }

    private function page()
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(CourseNew::class);
    }

    private function courses(): array
    {
        return app(Workspaces::class)->list($this->principal($this->ada));
    }

    public function test_the_page_has_the_name_the_look_with_its_preview_some_details_and_the_two_ways_to_set_up(): void
    {
        $this->aiReady();

        $this->actingAs($this->ada)->get(route('workspaces.create'))
            ->assertOk()->assertSee('<title>New course', false)->assertSeeLivewire(CourseNew::class)
            ->assertSeeInOrder(['New course', 'Name', 'Colour', 'Icon', 'Details', 'How do you want to set it up?', 'Guide me', 'I&#039;ll do it myself', 'Cancel', 'Create course'], false)
            ->assertSee('How it will look')->assertSee('Only the name is needed.')
            ->assertSee(route('home'), false)->assertDontSee('Set up your AI first');
    }

    public function test_every_way_to_a_new_course_leads_to_the_page(): void
    {
        $this->actingAs($this->ada)->get(route('home'))->assertOk()->assertSee(route('workspaces.create'), false);
        $this->actingAs($this->ada)->get('/?new=1')->assertRedirect(route('workspaces.create'));
        $course = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
        $this->actingAs($this->ada)->get(route('workspaces.show', $course->id))->assertOk()->assertSee(route('workspaces.create'), false);
    }

    public function test_choosing_the_guide_makes_the_course_and_goes_to_the_guide(): void
    {
        $this->aiReady();

        $this->page()->assertSet('how', 'guide')
            ->set('name', 'Operating Systems')->set('colour', 'green')->set('icon', 'code')->set('code', 'CMP4001')->set('term', 'Autumn 2026')
            ->call('create');

        $made = $this->courses()[0];
        $this->assertSame(['Operating Systems', 'green', 'code', 'CMP4001', 'Autumn 2026'], [$made->name, $made->colour, $made->icon, $made->code, $made->term]);
        $this->page()->set('name', 'Maths')->call('create')->assertRedirect(route('workspaces.guide', app(Workspaces::class)->list($this->principal($this->ada))[1]->id));
    }

    public function test_choosing_to_do_it_myself_goes_to_the_courses_own_page_with_its_setup_open(): void
    {
        $this->aiReady();

        $this->page()->set('name', 'Biology')->set('how', 'myself')->call('create');

        $this->assertSame('Biology', $this->courses()[0]->name);
        $this->page()->set('name', 'Spanish')->set('how', 'myself')->call('create')
            ->assertRedirect(route('workspaces.show', ['workspace' => $this->courses()[1]->id, 'setup' => 1]));
    }

    public function test_without_the_ai_set_up_the_guide_cannot_be_chosen_and_says_why(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.create'))->assertOk()->assertSee('Set up your AI first to use the guide.')->assertSee(route('settings', ['part' => 'ai']), false);

        // Even a guide choice sent by hand ends on the course's own page.
        $page = $this->page()->assertSet('how', 'myself')->set('name', 'Biology')->set('how', 'guide')->call('create');
        $page->assertRedirect(route('workspaces.show', ['workspace' => $this->courses()[0]->id, 'setup' => 1]));
    }

    public function test_the_services_refusals_show_on_their_fields_and_nothing_is_made(): void
    {
        $this->page()
            ->set('name', '')->set('startsOn', '2026-10-01')->set('endsOn', '2026-09-01')
            ->call('create')
            ->assertHasErrors(['name', 'endsOn'])->assertSee('Give the course a name.')->assertSee('The end date is before the start date.')->assertNoRedirect();
        $this->assertSame([], $this->courses());
    }

    public function test_an_account_that_is_not_a_student_cannot_open_the_page(): void
    {
        $adminOnly = $this->admin(student: false);

        $this->actingAs($adminOnly)->get(route('workspaces.create'))->assertForbidden();
    }
}
