<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The course's six doors, the phone's tab bar with Ask and More, and Settings in four parts (docs/specs/vistud-2-blueprint.md §3.4). */
class NavigationTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->workspace = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Operating Systems'])->id;
        $this->actingAs($this->ada);
    }

    public function test_a_course_has_six_doors_in_its_sidebar_and_the_everywhere_group_has_three(): void
    {
        $html = $this->get(route('workspaces.show', $this->workspace))->assertOk()->getContent();
        $sidebar = substr($html, (int) strpos($html, '<aside class="app-sidebar">'), 6000);

        preg_match_all('/<a href="[^"]*" class="nav-item" title="([^"]+)"/', $sidebar, $found);
        $this->assertSame(['Home', 'Modules', 'Cards', 'Questions', 'Assignments', 'Progress', 'All courses', 'The calendar of every course', 'Settings'], $found[1]);
        // Notes & files, the Journal and AI settings are not doors of their own any more.
        foreach (['Notes &amp; files', 'Journal', 'AI settings'] as $gone) {
            $this->assertStringNotContainsString("title=\"{$gone}\"", $sidebar);
        }
        $this->assertSame(['overview', 'modules', 'flashcards', 'questions', 'assignments', 'progress'], Workspaces::NAV);
    }

    public function test_every_door_opens_and_the_two_that_are_reached_from_elsewhere_still_do(): void
    {
        foreach (['modules', 'flashcards', 'questions', 'assignments', 'progress', 'notes', 'calendar'] as $section) {
            $this->get(route('workspaces.show', [$this->workspace, $section]))->assertOk();
        }
        $this->get(route('workspaces.show', [$this->workspace, 'nonsense']))->assertNotFound();
        // The current door is marked.
        $this->get(route('workspaces.show', [$this->workspace, 'questions']))->assertOk()
            ->assertSee('<title>Questions · Operating Systems', false)->assertSee('title="Questions"  aria-current="page"', false);
    }

    public function test_the_questions_door_lists_every_question_of_the_course_and_says_which_module(): void
    {
        $by = $this->principal($this->ada);
        $week = app(Modules::class)->create($by, $this->workspace, ['title' => 'Week 3: Scheduling'])->id;
        app(Questions::class)->ask($by, $this->workspace, 'Why does round robin starve long jobs?', null, $week);
        app(Questions::class)->ask($by, $this->workspace, 'What is an OS?');

        $this->get(route('workspaces.show', [$this->workspace, 'questions']))->assertOk()
            ->assertSee('Why does round robin starve long jobs?')->assertSee('What is an OS?')->assertSee('Week 3: Scheduling')
            ->assertSee('New question');
    }

    public function test_the_phone_tab_bar_is_home_modules_cards_ask_and_more_with_the_rest_in_a_sheet(): void
    {
        $html = $this->get(route('workspaces.show', $this->workspace))->assertOk()->getContent();
        $bar = substr($html, (int) strpos($html, 'class="app-tabbar"'), 5000);

        preg_match_all('/<(?:a|button)[^>]*class="tab-item"[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<span>([^<]+)<\/span>/s', $bar, $tabs);
        $this->assertSame(['Home', 'Modules', 'Cards', 'Ask', 'More'], $tabs[1]);
        // Ask opens the helper's sheet; More opens a sheet of the rest.
        $this->assertStringContainsString('aria-controls="ask-sheet"', $bar);
        $this->assertStringContainsString('<dialog id="more-sheet"', $bar);
        foreach (['Questions', 'Assignments', 'Progress', 'Notes &amp; files', 'Calendar', 'Settings'] as $item) {
            $this->assertStringContainsString($item, substr($bar, (int) strpos($bar, '<dialog id="more-sheet"')));
        }
        $this->assertStringContainsString('id="ask-sheet"', $html);
    }

    public function test_more_is_the_current_tab_on_a_page_it_holds(): void
    {
        $bar = fn (string $section) => (function ($html) {
            return substr($html, (int) strpos($html, 'aria-controls="more-sheet"') - 60, 200);
        })($this->get(route('workspaces.show', [$this->workspace, $section]))->getContent());

        $this->assertStringContainsString('data-current', $bar('progress'));
        $this->assertStringNotContainsString('data-current', $bar('modules'));
    }

    public function test_settings_is_one_page_with_four_parts_and_the_old_address_leads_to_the_first(): void
    {
        $this->get(route('settings'))->assertOk()->assertSee('<title>AI settings', false)->assertSeeInOrder(['AI', 'Appearance', 'Security', 'Your data'])
            ->assertSeeInOrder(['aria-current="page"', 'AI'], false);
        $this->get(route('settings', ['part' => 'appearance']))->assertOk()->assertSee('<title>Appearance', false)->assertSee('data-appearance-option', false);
        $this->get(route('settings', ['part' => 'security']))->assertOk()->assertSee('Two-step sign-in')->assertSee(route('two-factor.setup'), false);
        $this->get(route('settings', ['part' => 'data']))->assertOk()->assertSee('Your study record')->assertSee(route('journal.index'), false)->assertSee('Open your study record');
        // An unknown part is the first.
        $this->get(route('settings', ['part' => 'nonsense']))->assertOk()->assertSee('<title>AI settings', false);
        $this->get('/ai-engine')->assertStatus(301)->assertRedirect('/settings?part=ai');
    }

    public function test_outside_a_course_the_sidebar_has_courses_calendar_and_settings_and_the_journal_is_in_settings(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $sidebar = substr($html, (int) strpos($html, '<aside class="app-sidebar">'), 4000);

        $this->assertStringContainsString('title="Settings"', $sidebar);
        $this->assertStringContainsString('title="The calendar of every course"', $sidebar);
        $this->assertStringNotContainsString('title="Journal"', $sidebar);
        // The journal's own pages stay, and Settings marks itself current on them.
        $this->get(route('journal.index'))->assertOk()->assertSee('title="Settings"  aria-current="page"', false);
    }

    public function test_course_pages_live_at_courses_and_the_old_address_leads_to_them_with_its_query(): void
    {
        $id = $this->workspace;
        $this->assertSame(url("/courses/{$id}"), route('workspaces.show', $id));
        $this->assertSame(url("/courses/{$id}/progress"), route('workspaces.show', [$id, 'progress']));

        $this->get("/courses/{$id}/modules")->assertOk();
        $this->get("/workspaces/{$id}")->assertStatus(301)->assertRedirect("/courses/{$id}");
        $this->get("/workspaces/{$id}/modules?new=1")->assertStatus(301)->assertRedirect("/courses/{$id}/modules?new=1");
        $this->get("/workspaces/{$id}/notes/abc/export/pdf")->assertStatus(301)->assertRedirect("/courses/{$id}/notes/abc/export/pdf");
        $this->get('/workspaces')->assertStatus(301)->assertRedirect('/courses');
        $this->get('/courses')->assertStatus(301)->assertRedirect('/');
    }

    public function test_the_old_address_asks_a_visitor_who_is_not_signed_in_to_log_in_first(): void
    {
        auth()->logout();
        $this->get("/workspaces/{$this->workspace}/progress")->assertRedirect("/courses/{$this->workspace}/progress");
        $this->get("/courses/{$this->workspace}/progress")->assertRedirect(route('login'));
    }

    public function test_settings_is_for_a_signed_in_student(): void
    {
        auth()->logout();
        $this->get(route('settings'))->assertRedirect(route('login'));
    }
}
