<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Livewire\Study\EngineSettings;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A student's AI engine page: their own key, their models, limits and consent (docs/specs/study-memory.md §6). */
class EngineSettingsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['vistud.engine.key' => '']);
        $this->app->instance(Engine::class, new Fake);
    }

    public function test_a_student_sets_the_chat_up_alone_key_models_limits_and_consent(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->actingAs($ada);

        // The way in: the Overview's menu and the account menu.
        $this->get(route('workspaces.show', $workspace->id))->assertOk()->assertSee(route('engine.settings'))->assertSeeInOrder(['Instructions for the AI', 'AI settings', 'Log time']);
        $this->get('/')->assertOk()->assertSee(route('engine.settings'));
        $this->get('/ai-engine')->assertOk()->assertSee('<title>AI settings', false)->assertSee('Not set up')->assertSee('openrouter.ai')->assertSee('Save and try')->assertDontSee(route('admin.engine'));

        $page = Livewire::test(EngineSettings::class)->assertSet('sessionCap', '2.00')->assertSet('monthCap', '20.00')->assertSet('consent', false)
            ->set('key', 'nope')->call('saveKey')->assertHasErrors(['key'])
            ->set('key', 'sk-or-v1-abcdefghijklmnopqrstuvwxyz')->call('saveKey')->assertHasNoErrors()->assertSet('key', '')
            ->assertSee('Your key is saved and works.')->assertSee('It works: the service offers 3 models.')->assertSee('Your key ending …wxyz')->assertSee('Replace your key')
            // The models are offered with their prices once a key works.
            ->assertSee('Fake tutor · $1.00 in · $5.00 out per million tokens');
        $this->assertSame('sk-or-v1-abcdefghijklmnopqrstuvwxyz', app(Settings::class)->key($by));

        $page->set('tutorModel', 'bad id!')->call('save')->assertHasErrors(['tutorModel']);
        $page->set('tutorModel', 'fake/plain')->assertSee('no look-ups: this model can\'t call tools')
            ->set('tutorModel', 'fake/tutor')->set('readerModel', 'fake/quick')->set('sessionCap', '1.5')->set('consent', true)
            ->call('save')->assertHasNoErrors()->assertSee('Your AI settings are saved.');
        $choices = app(Settings::class)->get($by);
        $this->assertSame(['fake/tutor', 'fake/quick', 1_500_000, true, true], [$choices->tutorModel, $choices->readerModel, $choices->sessionCapMicros, $choices->ready(), $choices->ownKey()]);

        $page->call('askRemove')->assertSee('Remove your key?')->call('removeKey')->assertSee('Your key is removed.')->assertSee('Not set up');
        $this->assertNull(app(Settings::class)->key($by));
    }

    public function test_with_the_owners_key_the_chat_works_already_and_an_admin_is_pointed_to_the_shared_set_up(): void
    {
        $admin = $this->admin();
        app(Setup::class)->setKey($this->principal($admin), 'sk-or-v1-owner-key-00000000000');
        $this->actingAs($this->student());
        Livewire::test(EngineSettings::class)->assertSee("Using the owner's", false)->assertSee('Paste your own to pay yourself')->assertDontSee(route('admin.engine'));

        app(Setup::class)->removeKey($this->principal($admin));
        $this->actingAs($admin);
        Livewire::test(EngineSettings::class)->assertSee('Not set up')->assertSee(route('admin.engine'));
    }

    public function test_the_language_is_chosen_on_the_page(): void
    {
        $ada = $this->student();
        $this->actingAs($ada);
        Livewire::test(EngineSettings::class)->assertSee('Your language')->assertSee('Afaan Oromo')
            ->set('tutorModel', 'fake/tutor')->set('language', '<script>')->call('save')->assertHasErrors(['language'])
            ->set('language', 'Amharic')->call('save')->assertHasNoErrors()->assertSet('language', 'Amharic');
        $this->assertSame('Amharic', app(Settings::class)->get($this->principal($ada))->language);

        // The tutor keeps the topics itself unless asked not to.
        Livewire::test(EngineSettings::class)->assertSet('askTopics', false)->assertSee('Ask me before adding or switching topics')
            ->set('askTopics', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(app(Settings::class)->get($this->principal($ada))->askTopics);
        $this->assertSame('Amharic', app(Settings::class)->get($this->principal($ada))->language);
    }

    public function test_each_role_has_a_field_with_one_line_and_the_trust_toggles_are_kept(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $this->actingAs($ada);
        app(Settings::class)->setKey($by, 'sk-or-v1-abcdefghijklmnopqrstuvwxyz');

        // The page's top is the template: Back, the title, one line of what the page is for.
        $this->get('/ai-engine')->assertOk()->assertSee('All courses')->assertSee('Your key, your models and what they cost.')->assertSee('section-header-context', false);

        $page = Livewire::test(EngineSettings::class)
            ->assertSee('Teaches in sessions.')->assertSee('Reads files, writes summaries and cards.')->assertSee('Quick edits and questions.')
            ->assertSee('Reader (optional)')->assertSee('Helper (optional)')
            ->assertSet('autoReadFiles', true)->assertSet('tutorMarksTopics', true)->assertSet('copyPasteAi', false)
            ->assertSee('Let the tutor mark topics')->assertSee('Read my files automatically')->assertSee('I use another AI by copy-paste');

        // A price replaces the role's line once a model is chosen; the toggles and the three models are kept.
        $page->set('tutorModel', 'fake/tutor')->set('readerModel', 'fake/quick')
            ->assertDontSee('Teaches in sessions.')->assertDontSee('Reads files, writes summaries and cards.')->assertSee('Quick edits and questions.');
        $page->set('helperModel', 'fake/plain')->assertSee('no look-ups: this model can\'t call tools')
            ->set('autoReadFiles', false)->set('tutorMarksTopics', false)->set('copyPasteAi', true)->set('consent', true)
            ->call('save')->assertHasNoErrors();
        $choices = app(Settings::class)->get($by);
        $this->assertSame(['fake/tutor', 'fake/quick', 'fake/plain', false, false, true], [$choices->tutorModel, $choices->readerModel, $choices->helperModel, $choices->autoReadFiles, $choices->tutorMarksTopics, $choices->copyPasteAi]);
        Livewire::test(EngineSettings::class)->assertSet('helperModel', 'fake/plain')->assertSet('autoReadFiles', false)->assertSet('tutorMarksTopics', false)->assertSet('copyPasteAi', true);
    }

    public function test_usage_this_month_shows_what_each_role_cost_and_the_chat_counts_as_the_tutors(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        $ada = $this->student();
        $by = $this->principal($ada);
        $this->actingAs($ada);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);

        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $module = app(Modules::class)->create($by, $workspace->id, ['title' => 'Week 2: SQL joins'])->id;
        $topic = app(Topics::class)->create($by, $workspace->id, 'Joins', $module)->id;
        $session = app(Sessions::class)->start($by, $workspace->id, $topic, $module);

        Livewire::test(EngineSettings::class)->assertSee('Usage this month')->assertSeeHtml('<dd data-usage-role="tutor">$0.00</dd>')->assertSee('$0.00 of $20.00, all three together');

        $engine->will(Fake::says('A join combines rows.', 30_000));
        app(SessionChat::class)->send($by, $session->id, 'What is a join?');
        $scope = LearnerScope::of($by);
        $job = fn (string $role, int $cost) => LearnerTables::insert($scope, 'engine_jobs', ['id' => Ids::new(), 'role' => $role, 'kind' => 'read_file', 'status' => 'done', 'cost_micros' => $cost, 'created_at' => '2026-10-02 10:00:00']);
        $job('reader', 120_000);
        $job('helper', 20_000);
        $job('reader', 9_000_000); // Last month's: the month is the student's own.
        LearnerTables::query($scope, 'engine_jobs')->where('cost_micros', 9_000_000)->update(['created_at' => '2026-09-20 10:00:00']);

        Livewire::test(EngineSettings::class)
            ->assertSeeHtml('<dd data-usage-role="tutor">$0.03</dd>')->assertSeeHtml('<dd data-usage-role="reader">$0.12</dd>')->assertSeeHtml('<dd data-usage-role="helper">$0.02</dd>')
            ->assertSee('$0.17 of $20.00, all three together')
            ->set('monthCap', '0')->call('save')->assertSee('No monthly limit');
    }
}
