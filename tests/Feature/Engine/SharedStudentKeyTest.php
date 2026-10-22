<?php

namespace Tests\Feature\Engine;

use App\Audit\AuditAction;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Engine\SharedKeyPin;
use App\Engine\StudentKeyGate;
use App\Identity\AdmissionGate;
use App\Livewire\Admin\EngineSetup;
use App\Livewire\Study\EngineSettings;
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
        $this->app->instance(Engine::class, new Fake);
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
        $this->assertSame(['fake/plain', 'fake/quick'], array_map(fn ($m) => $m->id, app(Setup::class)->cheapModels()));
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
            ->assertSee('Free and very cheap models this key can use (2)')->assertSee('fake/plain')
            ->call('addShared', 'fake/plain')->assertSet('sharedModels', "fake/quick\nfake/plain")->call('saveShared')->assertHasNoErrors();
        $this->assertSame(['fake/quick', 'fake/plain'], app(Setup::class)->shared()['models']);
        $this->assertSame(250_000, app(Setup::class)->shared()['daily_micros']);

        $student = $this->student();
        $this->actingAs($student);
        Livewire::test(EngineSettings::class)->assertSee('You\'re using the shared key, up to $0.25 a day, with 2 models to choose from.', false)
            ->assertSee('On the shared key you can choose: fake/quick, fake/plain.', false);
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
