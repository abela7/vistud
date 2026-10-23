<?php

namespace Tests\Feature\Engine;

use App\Audit\AuditAction;
use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Skills\Skills;
use App\Engine\VistudAi;
use App\Livewire\Admin\SkillsAdmin;
use App\Livewire\Study\SkillSwitches;
use App\Mcp\Plugins\PluginBuilder;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Skills switched on and off (the owner's ask, 2026-10-23): by an admin for everyone, by a student for themselves.
 * Off, a skill's instructions are left out of the chat altogether; an outside app's, out of the plugin.
 */
class SkillSwitchesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private string $ui;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Engine::class, new Fake);
        $this->ui = Skills::read('ui')['body'];
    }

    public function test_a_student_who_turns_a_skill_off_gets_none_of_its_instructions_in_any_chat(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $other = $this->principal($this->student());
        $course = app(Workspaces::class)->create($by, ['name' => 'Physics']);
        $session = app(Sessions::class)->start($by, $course->id);
        $assignment = app(Activities::class)->create($by, $course->id, ['title' => 'Lab report 1', 'kind' => 'assignment', 'due_on' => '2026-11-15']);
        $prompts = fn () => [
            'session' => app(Stack::class)->build($by, $session, null, true)->system,
            'assignment' => app(Stack::class)->forAssignment($by, $assignment, null, true)->system,
            'vistud' => app(VistudAi::class)->system($by),
        ];

        foreach ($prompts() as $where => $prompt) {
            $this->assertStringContainsString($this->ui, $prompt, $where);
        }
        $this->assertTrue(Skills::forStudent($by)['ui']['on']);

        Skills::setForStudent($by, 'ui', false);
        foreach ($prompts() as $where => $prompt) {
            $this->assertStringNotContainsString('Showing things in the chat', $prompt, $where);
        }
        $this->assertFalse(Skills::forStudent($by)['ui']['on']);
        // Only for them.
        $this->assertStringContainsString($this->ui, app(VistudAi::class)->system($other));

        Skills::setForStudent($by, 'ui', true);
        $this->assertStringContainsString($this->ui, app(VistudAi::class)->system($by));
        // A skill of the outside apps, or none, isn't theirs to switch.
        $this->assertThrows(fn () => Skills::setForStudent($by, 'quiz-me', false), Unprocessable::class);
        $this->assertThrows(fn () => Skills::setForStudent($by, 'no-such-skill', false), Unprocessable::class);
    }

    public function test_an_admin_turns_a_skill_off_for_everyone_and_students_cant_turn_it_back_on(): void
    {
        $admin = $this->principal($this->admin());
        $by = $this->principal($this->student());

        Skills::setForEveryone($admin, 'ui', false);
        $this->assertStringNotContainsString('Showing things in the chat', app(VistudAi::class)->system($by));
        $this->assertStringNotContainsString('Showing things in the chat', Stack::rules(true));
        $this->assertArrayNotHasKey('ui', Skills::forStudent($by));
        $this->assertThrows(fn () => Skills::setForStudent($by, 'ui', true), Unprocessable::class);
        $this->assertSame(['skill', 'ui', ['on' => false]], [
            DB::table('audit_log')->where('action', AuditAction::SKILL_CHANGED)->value('target_type'),
            DB::table('audit_log')->where('action', AuditAction::SKILL_CHANGED)->value('target_id'),
            json_decode((string) DB::table('audit_log')->where('action', AuditAction::SKILL_CHANGED)->value('metadata'), true),
        ]);

        // An outside app's skill turned off is left out of the plugin students download.
        Skills::setForEveryone($admin, 'quiz-me', false);
        $this->assertArrayNotHasKey('quiz-me', app(PluginBuilder::class)->skills());
        $this->assertArrayHasKey('study-session', app(PluginBuilder::class)->skills());

        Skills::setForEveryone($admin, 'ui', true);
        Skills::setForEveryone($admin, 'quiz-me', true);
        $this->assertStringContainsString($this->ui, app(VistudAi::class)->system($by));
        $this->assertArrayHasKey('quiz-me', app(PluginBuilder::class)->skills());

        // A student can't, and an admin needs a recent password.
        $this->assertThrows(fn () => Skills::setForEveryone($by, 'ui', false));
        $this->expectException(PasswordConfirmationRequired::class);
        Skills::setForEveryone($this->principal($this->admin(), confirmed: false), 'ui', false);
    }

    public function test_the_admin_page_lists_every_skill_and_switches_it(): void
    {
        $this->withoutVite();
        $this->actingAs($this->admin())->withSession($this->confirmedSession())->get(route('admin.skills'))->assertOk()
            ->assertSee('Cards and buttons in replies')->assertSee('Quiz me')->assertSee('In the plugin students download', false);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(SkillsAdmin::class)->call('toggle', 'ui')->assertSee('Cards and buttons in replies is off for everyone.')->assertSee('Turn on');
        $this->assertSame(['ui'], Skills::platformOff());
        Livewire::test(SkillsAdmin::class)->call('toggle', 'ui');
        $this->assertSame([], Skills::platformOff());

        $this->actingAs($this->student())->get(route('admin.skills'))->assertForbidden();
    }

    public function test_a_students_switch_in_ai_settings_saves_at_once(): void
    {
        $this->withoutVite();
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);

        Livewire::test(SkillSwitches::class)->assertSee('Cards and buttons in replies')->assertSee('1 of 1 on')->assertDontSee('Quiz me')
            ->call('toggle', 'ui')->assertSee('0 of 1 on');
        $this->assertSame(['ui'], Skills::studentOff($by));
        $this->get(route('settings', ['part' => 'ai']))->assertOk()->assertSee('Skills');
    }
}
