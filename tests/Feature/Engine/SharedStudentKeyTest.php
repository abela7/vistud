<?php

namespace Tests\Feature\Engine;

use App\Audit\AuditAction;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Model;
use App\Engine\Request;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Engine\SharedKeyPin;
use App\Engine\StudentKeyGate;
use App\Identity\AdmissionGate;
use App\Livewire\Admin\EngineSetup;
use App\Livewire\Study\EngineSettings;
use App\Livewire\Workspaces\TutorChat;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The shared key for students without their own (the owner's ask, 2026-10-22): off unless an admin turns it on with
 * its own key, free or very cheap models and a daily limit; a student on it runs only those models, up to the limit, and never the
 * admin key; their own key always wins.
 */
class SharedStudentKeyTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private string $adminKey;

    private string $studentsKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->adminKey = 'sk-or-admin-'.bin2hex(random_bytes(8));
        $this->studentsKey = 'sk-or-students-'.bin2hex(random_bytes(8));
        config(['vistud.engine.key' => $this->adminKey]);
        $this->app->instance(Engine::class, new Fake([
            new Model('fake/tutor', 'Fake tutor', 1.0, 5.0, 200_000, true, true, true),
            new Model('fake/quick', 'Fake quick', 0.1, 0.4, 32_000, true, false, false),
            new Model('fake/plain', 'Fake plain', 0.05, 0.2, 8_000, false, false, false),
            new Model('fake/new', 'Fake new', 0.2, 0.6, 64_000, true, false, false),
        ]));
    }

    public function test_it_is_off_until_an_admin_turns_it_on_with_a_key_a_model_and_a_daily_limit(): void
    {
        $student = $this->principal($this->student());
        $admin = $this->principal($this->admin());
        $this->assertSame('', app(Settings::class)->credential($student));
        $this->assertNull(app(Setup::class)->shared());

        $this->turnOn($admin);
        $this->assertSame(['key' => $this->studentsKey, 'model' => 'fake/quick', 'models' => ['fake/quick', 'fake/plain'], 'daily_micros' => 100_000], app(Setup::class)->shared());
        // The cheap ones the key can use, cheapest first; the dear one is not offered.
        $this->assertSame(['fake/plain', 'fake/quick', 'fake/new'], array_map(fn ($m) => $m->id, app(Setup::class)->cheapModels()));
        $raw = DB::table('platform_settings')->where('key', Setup::SHARED_KEY)->value('value');
        $this->assertStringNotContainsString($this->studentsKey, $raw);
        $this->assertSame($this->studentsKey, Crypt::decryptString($raw));
        $audit = DB::table('audit_log')->where('action', AuditAction::ENGINE_SHARED_CHANGED)->latest('id')->value('metadata');
        $this->assertStringNotContainsString($this->studentsKey, (string) $audit);

        // Turned off: the key is kept, students get nothing again.
        app(Setup::class)->setShared($admin, ['on' => false, 'models' => 'fake/quick', 'daily' => '0.10']);
        $this->assertNull(app(Setup::class)->shared());
        $this->assertSame('', app(Settings::class)->credential($student));
        $this->assertSame('…'.substr($this->studentsKey, -4), app(Setup::class)->status($admin)['shared_key_hint']);

        app(Setup::class)->removeSharedKey($admin);
        $this->assertSame([false, null], [app(Setup::class)->status($admin)['shared_on'], app(Setup::class)->status($admin)['shared_key_hint']]);
    }

    public function test_the_admin_key_a_dear_or_unknown_model_or_a_bad_limit_is_refused_and_a_password_check_is_needed(): void
    {
        $admin = $this->principal($this->admin());
        foreach ([
            [['on' => true, 'key' => $this->adminKey, 'models' => 'fake/quick', 'daily' => '0.10'], 'key'],
            [['on' => true, 'models' => 'fake/quick', 'daily' => '0.10'], 'key'],
            [['on' => true, 'key' => $this->studentsKey, 'models' => '', 'daily' => '0.10'], 'models'],
            [['on' => true, 'key' => $this->studentsKey, 'models' => 'fake/quick', 'daily' => '0'], 'daily'],
            [['on' => true, 'key' => $this->studentsKey, 'models' => 'fake/quick', 'daily' => '50'], 'daily'],
            [['on' => true, 'key' => $this->studentsKey, 'models' => 'fake/quick:batch', 'daily' => '0.10'], 'models'],
            // $1 in and $5 out a million tokens: too dear for the shared key.
            [['on' => true, 'key' => $this->studentsKey, 'models' => "fake/quick\nfake/tutor", 'daily' => '0.10'], 'models'],
            [['on' => true, 'key' => $this->studentsKey, 'models' => 'nobody/offers-this', 'daily' => '0.10'], 'models'],
        ] as [$input, $field]) {
            try {
                app(Setup::class)->setShared($admin, $input);
                $this->fail('Accepted: '.json_encode(array_diff_key($input, ['key' => 1])));
            } catch (Unprocessable $e) {
                $this->assertArrayHasKey($field, $e->details['fields']);
            }
        }
        $this->assertNull(app(Setup::class)->shared());

        $this->expectException(PasswordConfirmationRequired::class);
        app(Setup::class)->setShared($this->principal($this->admin(), confirmed: false), ['on' => true, 'key' => $this->studentsKey, 'models' => 'fake/quick', 'daily' => '0.10']);
    }

    public function test_a_student_without_a_key_gets_the_shared_key_and_chooses_among_its_models_and_their_own_key_wins(): void
    {
        $admin = $this->principal($this->admin());
        $this->turnOn($admin);
        $settings = app(Settings::class);
        $student = $this->principal($this->student());
        $settings->set($student, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/plain', 'fallback_model' => 'fake/plain', 'favorite_models' => ['fake/tutor'], 'consent' => true]);

        $this->assertSame($this->studentsKey, $settings->credential($student));
        $choices = $settings->get($student);
        // fake/tutor isn't one of the shared key's models: the default instead. fake/plain is, so it stays.
        $this->assertSame(['fake/quick', 'fake/plain', 'fake/quick', ''], [$choices->tutorModel, $choices->readerModel, $choices->helperModel, $choices->fallbackModel]);
        // Their chat favourite for their own key (fake/tutor) plays no part: every shared model, none outside the list.
        $this->assertSame(['fake/quick', 'fake/plain'], $choices->chatModels());
        $this->assertSame([$this->studentsKey, 'fake/quick'], array_slice($settings->ready($student, Role::Tutor), 1));
        // Recordings are long and costly: not on the shared key.
        $this->assertNull($settings->audioModel($student));

        // The admin keeps the admin key and their own models.
        $settings->set($admin, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->assertSame($this->adminKey, $settings->credential($admin));
        $this->assertSame('fake/tutor', $settings->get($admin)->tutorModel);

        // Their own key: theirs, with their own models, and no daily limit.
        $own = bin2hex(random_bytes(32));
        $settings->setKey($student, $own);
        $this->assertSame($own, $settings->credential($student));
        $this->assertNull($settings->shared($student));
        $this->assertSame('fake/tutor', $settings->get($student)->tutorModel);
        $this->spend($student, 5_000_000);
        $this->assertSame($own, $settings->ready($student, Role::Tutor)[1]);
    }

    public function test_a_call_on_the_shared_key_runs_only_its_models_whatever_the_chat_asked_for(): void
    {
        $this->turnOn($this->principal($this->admin()));
        $pin = new SharedKeyPin($inner = new Fake, app(Setup::class));

        $pin->reply(new Request('fake/tutor', 'S', [['role' => 'user', 'content' => 'Hi']], fallbacks: ['fake/plain', 'fake/tutor'], key: $this->studentsKey));
        $pin->stream(new Request('anthropic/claude-opus', 'S', [['role' => 'user', 'content' => 'Hi']], key: $this->studentsKey), fn () => null);
        $pin->reply(new Request('fake/plain', 'S', [['role' => 'user', 'content' => 'Hi']], key: $this->studentsKey));
        $pin->reply(new Request('fake/tutor', 'S', [['role' => 'user', 'content' => 'Hi']], fallbacks: ['fake/plain'], key: $this->adminKey));

        $this->assertSame(['fake/quick', 'fake/quick', 'fake/plain', 'fake/tutor'], array_map(fn (Request $r) => $r->model, $inner->requests));
        $this->assertSame([['fake/plain'], [], [], ['fake/plain']], array_map(fn (Request $r) => $r->fallbacks, $inner->requests));
        $this->assertInstanceOf(SharedKeyPin::class, (function () {
            $this->app->forgetInstance(Engine::class);

            return app(Engine::class);
        })());
    }

    public function test_the_daily_limit_stops_the_shared_key_for_the_rest_of_the_day_only(): void
    {
        $this->turnOn($this->principal($this->admin()));
        $settings = app(Settings::class);
        $student = $this->principal($this->student());
        $settings->set($student, ['consent' => true]);

        $this->spend($student, 60_000);
        $this->assertSame($this->studentsKey, $settings->ready($student, Role::Tutor)[1]);
        $this->spend($student, 40_000);
        foreach (Role::cases() as $role) {
            try {
                $settings->ready($student, $role);
                $this->fail('Over the daily limit, yet ready.');
            } catch (Unprocessable $e) {
                $this->assertSame('engine_daily', $e->errorCode);
            }
        }
        // Yesterday's use doesn't count today.
        LearnerTables::query(LearnerScope::of($student), 'engine_jobs')->update(['created_at' => now()->subDays(2)]);
        $this->assertSame($this->studentsKey, $settings->ready($student, Role::Tutor)[1]);
    }

    public function test_the_gate_accepts_the_shared_key_but_never_the_admin_key_for_a_student(): void
    {
        $this->assertTrue(app(AdmissionGate::class)->ownKeyOnly());
        $this->turnOn($this->principal($this->admin()));
        $this->assertTrue(app(AdmissionGate::class)->ownKeyOnly());
        $this->assertTrue(app(StudentKeyGate::class)->check());
        $this->artisan('vistud:gate:second-learner')->expectsOutputToContain('student uses own AI key, or the shared one with a daily limit: yes');

        // The students' key becomes the admin key: the shared key stops, so the admin key never reaches a student.
        config(['vistud.engine.key' => $this->studentsKey]);
        $this->assertNull(app(Setup::class)->shared());
        $this->assertSame('', app(Settings::class)->credential($this->principal($this->student())));
        $this->assertTrue(app(AdmissionGate::class)->ownKeyOnly());
    }

    public function test_the_admin_page_turns_it_on_and_the_student_sees_the_model_and_the_limit(): void
    {
        $this->withoutVite();
        $admin = $this->admin();
        $this->actingAs($admin)->withSession($this->confirmedSession());
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(EngineSetup::class)
            ->assertSee('Students without a key')
            ->set('sharedOn', true)->set('sharedModels', 'fake/quick')->set('sharedDaily', '0.25')->call('saveShared')->assertHasErrors(['sharedKey'])
            ->set('sharedKey', $this->studentsKey)->set('sharedModels', "fake/quick\nfake/tutor")->call('saveShared')->assertHasErrors(['sharedModels'])
            ->set('sharedModels', 'fake/quick')->call('saveShared')->assertHasNoErrors()
            ->assertSet('sharedKey', '')->assertSee('Students without a key now use the shared one.')->assertSee('…'.substr($this->studentsKey, -4))
            ->assertSee('Free and very cheap models this key can use (3)')->assertSee('fake/plain')
            ->call('addShared', 'fake/plain')->assertSet('sharedModels', "fake/quick\nfake/plain")->call('saveShared')->assertHasNoErrors();
        $this->assertSame(['fake/quick', 'fake/plain'], app(Setup::class)->shared()['models']);
        $this->assertSame(250_000, app(Setup::class)->shared()['daily_micros']);

        $student = $this->student();
        $this->actingAs($student);
        Livewire::test(EngineSettings::class)->assertSee('You\'re using the shared key, up to $0.25 a day, with 2 models to choose from.', false)
            ->assertSee('Choose which of its models does each job', false);
    }

    public function test_a_student_on_the_shared_key_gives_each_job_one_of_its_models_and_switches_them_on_and_off_for_the_chat(): void
    {
        $this->withoutVite();
        $this->turnOn($this->principal($this->admin()));
        $user = $this->student();
        $this->actingAs($user);
        $student = $this->principal($user);
        $settings = app(Settings::class);

        // Until they choose: the default for each job, and every shared model in the chat.
        $this->assertSame(['fake/quick', 'fake/plain'], $settings->get($student)->chatModels());
        Livewire::test(EngineSettings::class)
            ->assertSee('Choose which of its models does each job')->assertSee('In the chat')->assertDontSee('Popular recommendations')
            ->assertSet('tutorModel', 'fake/quick')
            ->set('tutorModel', 'fake/plain')->set('readerModel', 'fake/quick')->set('helperModel', 'fake/plain')
            ->set('favoriteModels', [])->call('save')->assertHasNoErrors();
        $choices = $settings->get($student);
        $this->assertSame(['fake/plain', 'fake/quick', 'fake/plain'], [$choices->tutorModel, $choices->readerModel, $choices->helperModel]);
        // Everything else switched off: the tutor alone, and it stays that way (not back to all of them).
        $this->assertSame(['fake/plain'], $choices->chatModels());

        // The admin adds a model and drops one: the student has it in the chat and the job lists at once, without
        // saving anything, and the one they switched off stays off.
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => true, 'models' => "fake/plain\nfake/quick\nfake/new", 'daily' => '0.10']);
        // A new request reads the admin's list again (one Settings keeps what it read).
        $this->assertSame(['fake/plain', 'fake/new'], app(Settings::class)->get($student)->chatModels());
        Livewire::test(EngineSettings::class)->assertSee('Fake new')->assertSet('favoriteModels', ['fake/plain', 'fake/new']);
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => true, 'models' => "fake/new\nfake/quick", 'daily' => '0.10']);
        $choices = app(Settings::class)->get($student);
        // Their tutor (fake/plain) is gone: the admin's default instead, at once.
        $this->assertSame(['fake/new', 'fake/quick', 'fake/new'], [$choices->tutorModel, $choices->readerModel, $choices->helperModel]);
        $this->assertSame(['fake/new'], $choices->chatModels());

        // Switched back on.
        Livewire::test(EngineSettings::class)->set('favoriteModels', ['fake/new', 'fake/quick'])->call('save')->assertHasNoErrors();
        $this->assertSame(['fake/new', 'fake/quick'], app(Settings::class)->get($student)->chatModels());
    }

    public function test_a_student_on_the_shared_key_starts_the_ai_from_the_chat_in_one_click(): void
    {
        $this->withoutVite();
        $this->turnOn($this->principal($this->admin()));
        $user = $this->student();
        $this->actingAs($user);
        $student = $this->principal($user);
        app(Settings::class)->set($student, ['tutor_model' => 'fake/plain', 'language' => 'Amharic']);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(TutorChat::class, ['vistud' => true])
            ->assertSee('Start the AI')->assertSee('to the model chosen for you (fake/plain)')->assertDontSee('Set up your AI first')
            ->call('agreeToAi')
            ->assertDontSee('Start the AI')->assertSee('Ask me anything about your study');
        $choices = app(Settings::class)->get($student);
        // Only the consent changed: their model and language are as they were.
        $this->assertNotNull($choices->consentedAt);
        $this->assertSame(['fake/plain', 'Amharic'], [$choices->tutorModel, $choices->language]);

        // Without the shared key, the chat still sends them to set up their own.
        $other = $this->student();
        $this->actingAs($other);
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => false, 'models' => 'fake/quick', 'daily' => '0.10']);
        Livewire::test(TutorChat::class, ['vistud' => true])->assertSee('Set up your AI first')->assertDontSee('Agree and start');
    }

    public function test_the_list_of_cheap_models_narrows_by_words_price_pictures_and_tools(): void
    {
        $models = [
            new Model('google/gemini-flash-lite', 'Gemini Flash Lite', 0.1, 0.4, 1_000_000, true, true, true),
            new Model('meta/llama-free:free', 'Llama Free', 0.0, 0.0, 128_000, false, false, false),
            new Model('deepseek/chat', 'DeepSeek Chat', 0.3, 0.9, 64_000, true, false, false),
        ];
        $ids = fn (array $found) => array_map(fn (Model $m) => $m->id, $found);
        $this->assertSame(['google/gemini-flash-lite', 'meta/llama-free:free', 'deepseek/chat'], $ids(EngineSetup::filterModels($models, '', '1', false, false)));
        $this->assertSame(['meta/llama-free:free'], $ids(EngineSetup::filterModels($models, '', 'free', false, false)));
        $this->assertSame(['google/gemini-flash-lite', 'meta/llama-free:free'], $ids(EngineSetup::filterModels($models, '', '0.5', false, false)));
        $this->assertSame(['google/gemini-flash-lite'], $ids(EngineSetup::filterModels($models, 'GEMINI lite', '1', false, false)));
        $this->assertSame(['google/gemini-flash-lite'], $ids(EngineSetup::filterModels($models, '', '1', true, false)));
        $this->assertSame(['google/gemini-flash-lite', 'deepseek/chat'], $ids(EngineSetup::filterModels($models, '', '1', false, true)));
        $this->assertSame([], $ids(EngineSetup::filterModels($models, 'claude', '1', false, false)));
    }

    public function test_a_password_check_while_saving_comes_back_to_the_ai_engine_page(): void
    {
        $this->withoutVite();
        $this->actingAs($this->admin())->withSession(['auth.password_confirmed_at' => now()->getTimestamp() - 3600]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(EngineSetup::class)
            ->set('sharedKey', $this->studentsKey)->set('sharedModels', 'fake/quick')->set('sharedDaily', '0.10')->call('saveShared')
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(route('admin.engine', ['confirmed' => 1]), session('url.intended'));
        $this->assertNull(app(Setup::class)->status($this->principal($this->admin()))['shared_key_hint']);

        Livewire::withQueryParams(['confirmed' => 1])->test(EngineSetup::class)->assertSee('Password confirmed. Paste the key again and press Save');
    }

    private function turnOn($admin): void
    {
        app(Setup::class)->setShared($admin, ['on' => true, 'key' => $this->studentsKey, 'models' => "fake/quick\nfake/plain", 'daily' => '0.10']);
    }

    private function spend($student, int $micros): void
    {
        $course = app(Workspaces::class)->create($student, ['name' => 'Synthetic']);
        LearnerTables::insert(LearnerScope::of($student), 'engine_jobs', [
            'id' => Ids::new(), 'workspace_id' => $course->id, 'role' => Role::Helper->value, 'kind' => 'probe',
            'status' => 'done', 'cost_micros' => $micros, 'created_at' => now(),
        ]);
    }
}
