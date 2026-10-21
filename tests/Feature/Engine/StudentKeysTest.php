<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\OpenRouter;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Jobs\Job;
use App\Engine\Jobs\Runner;
use App\Engine\Models;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\StudentKeyGate;
use App\Platform\Access\Principal;
use App\Platform\Access\LearnerScope;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class StudentKeysTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_effective_credentials_follow_database_admin_roles_including_mcp_and_never_fall_back_for_a_student(): void
    {
        $installation = bin2hex(random_bytes(32));
        config(['vistud.engine.key' => $installation]);
        $settings = app(Settings::class);
        $a = $this->principal($this->student());
        $b = $this->principal($this->student());
        $admin = $this->principal($this->admin());
        $mcpAdmin = new Principal($admin->userId, $admin->learnerId, [\App\Platform\Access\Role::Student], true, null, 'mcp');
        $this->assertSame('', $settings->credential($a));
        $this->assertFalse($settings->keyAvailable($a));
        $this->assertSame($installation, $settings->credential($admin));
        $this->assertSame($installation, $settings->credential($mcpAdmin));
        $this->app->instance(Engine::class, $engine = new Fake);
        $settings->set($a, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        foreach (Role::cases() as $role) {
            try { $settings->ready($a, $role); $this->fail('Missing key accepted'); }
            catch (Unprocessable $e) { $this->assertSame('engine_key', $e->errorCode); }
        }
        $this->assertSame([], app(Models::class)->all(key: $settings->credential($a)));
        $secret = bin2hex(random_bytes(32));
        $settings->setKey($a, $secret);
        $this->assertSame($secret, $settings->ready($a, Role::Tutor)[1]);
        $this->assertSame('', $settings->credential($b));
        $this->assertTrue(app(StudentKeyGate::class)->check());
        foreach ([[$a, $secret], [$admin, $installation]] as [$actor, $expected]) {
            $settings->set($actor, ['tutor_model' => 'fake/tutor', 'consent' => true]);
            app(Runner::class)->run($actor, Role::Tutor, 'key_probe', null, null, null,
                fn ($run) => $run->ask('Synthetic', 'Synthetic', 20)->whole());
            $this->assertSame($expected, $engine->last()->key);
        }
        $engine->requests = [];
        $settings->removeKey($a);
        $this->assertSame('', $settings->credential($a));
        $this->assertSame([], $engine->requests);
    }

    public function test_every_concrete_engine_job_refuses_before_its_handler_and_no_secret_is_sent(): void
    {
        config(['vistud.engine.key' => bin2hex(random_bytes(32))]);
        $this->app->instance(Engine::class, $engine = new Fake);
        $by = $this->principal($this->student());
        $course = app(\App\Study\Workspaces::class)->create($by, ['name' => 'Synthetic']);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $count = 0;
        foreach (glob(app_path('Engine/Jobs/*.php')) as $file) {
            $class = 'App\\Engine\\Jobs\\'.basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if (! $reflection->isSubclassOf(Job::class) || $reflection->isAbstract()) { continue; }
            $job = $reflection->newInstanceWithoutConstructor();
            $id = Ids::new();
            \App\Platform\Database\LearnerTables::insert(LearnerScope::of($by), 'engine_jobs', [
                'id' => $id, 'workspace_id' => $course->id, 'role' => $job->role()->value, 'kind' => $job->kind(),
                'status' => 'queued', 'created_at' => now(),
            ]);
            app(Runner::class)->execute($id, $by, $job);
            $this->assertSame('engine_key', app(Runner::class)->status($by, $id)['error_code'], $class);
            $count++;
        }
        $this->assertGreaterThanOrEqual(10, $count);
        $this->assertSame([], $engine->requests);
    }

    public function test_saved_secrets_are_bound_to_learner_row_and_column_and_legacy_conversion_resumes_and_reverses(): void
    {
        $settings = app(Settings::class);
        $a = $this->principal($this->student());
        $b = $this->principal($this->student());
        $secret = bin2hex(random_bytes(32));
        $settings->setKey($a, $secret);
        $settings->setKey($b, bin2hex(random_bytes(32)));
        $raw = DB::table('engine_settings')->where('learner_id', $a->learnerId)->value('key_encrypted');
        $this->assertStringStartsWith('vsk1:', $raw);
        $this->assertStringNotContainsString($secret, $raw);
        DB::table('engine_settings')->where('learner_id', $b->learnerId)->update(['key_encrypted' => $raw]);
        $this->assertThrows(fn () => $settings->key($b), \RuntimeException::class);
        DB::table('engine_settings')->where('learner_id', $b->learnerId)->update(['key_encrypted' => null]);
        $legacy = Crypt::encryptString($secret);
        DB::table('engine_settings')->where('learner_id', $a->learnerId)->update(['key_encrypted' => $legacy]);
        $this->assertThrows(fn () => $settings->key($a), Unprocessable::class);
        $this->artisan('vistud:encrypt:ai-keys --dry-run')->expectsOutput('convert=1 verified=0 failed=0')->assertSuccessful();
        $this->assertSame($legacy, DB::table('engine_settings')->where('learner_id', $a->learnerId)->value('key_encrypted'));
        app()->maintenanceMode()->activate([]);
        try {
            $this->artisan('vistud:encrypt:ai-keys')->assertSuccessful();
            $this->artisan('vistud:encrypt:ai-keys')->expectsOutput('convert=0 verified=1 failed=0')->assertSuccessful();
            $this->assertSame($secret, $settings->key($a));
            $this->artisan('vistud:encrypt:ai-keys --decrypt')->assertSuccessful();
            $this->assertSame($secret, Crypt::decryptString(DB::table('engine_settings')->where('learner_id', $a->learnerId)->value('key_encrypted')));
            $this->artisan('vistud:encrypt:ai-keys')->assertSuccessful();
        } finally { app()->maintenanceMode()->deactivate(); }
    }

    public function test_audio_model_uses_the_credentials_provider_or_refuses_before_processing(): void
    {
        $settings = app(Settings::class);
        $by = $this->principal($this->student());
        $settings->setKey($by, bin2hex(random_bytes(32)));
        $this->assertNull($settings->audioModel($this->principal($this->student())));
        $this->assertSame(config('vistud.engine.audio_model'), $settings->audioModel($by));
        $scope = LearnerScope::of($by);
        foreach (['google' => 'gemini-2.5-flash', 'openai' => 'gpt-audio', 'anthropic' => null] as $provider => $model) {
            \App\Platform\Database\LearnerTables::query($scope, 'engine_settings')->update(['key_provider' => $provider, 'reader_model' => 'gemini-2.5-flash']);
            $this->assertSame($model, $settings->audioModel($by));
        }
    }

    public function test_a_student_with_no_key_never_sends_the_installation_key(): void
    {
        $installation = 'sk-or-install-'.bin2hex(random_bytes(8));
        config(['vistud.engine.key' => $installation]);
        $by = $this->principal($this->student());
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'helper_model' => 'fake/quick', 'consent' => true]);
        $this->app->instance(Engine::class, $engine = new Fake);
        $course = app(\App\Study\Workspaces::class)->create($by, ['name' => 'Synthetic']);
        $session = app(\App\Study\Sessions::class)->start($by, $course->id);

        foreach ([
            fn () => app(SessionChat::class)->send($by, $session->id, 'Hello'),
            fn () => app(\App\Engine\VistudAi::class)->send($by, \App\Platform\Ids::new(), 'Hello'),
            fn () => app(\App\Engine\Helper::class)->quick($by, 'Explain this.', 'Processes', workspaceId: $course->id),
            fn () => app(\App\Engine\CourseGuide::class)->turn($by, $course->id, [], 'Hello'),
        ] as $call) {
            try {
                $call();
            } catch (Unprocessable $e) {
                $this->assertSame('engine_key', $e->errorCode);
            }
        }
        foreach ($engine->requests as $request) {
            $this->assertNotSame($installation, $request->key);
            $this->assertStringNotContainsString($installation, (string) $request->key);
        }
        $this->assertNotContains($installation, $engine->modelKeys);

        $mcp = new Principal($by->userId, $by->learnerId, [\App\Platform\Access\Role::Student], true, null, 'mcp');
        $this->assertSame('', app(Settings::class)->credential($mcp));
        try {
            app(\App\Engine\Jobs\Runner::class)->run($mcp, \App\Engine\Role::Reader, 'mcp_key_probe', $course->id, 'course', $course->id, fn ($run) => $run->ask('Synthetic', 'Synthetic', 20));
            $this->fail('An MCP reader job ran without a key.');
        } catch (Unprocessable $e) {
            $this->assertSame('engine_key', $e->errorCode);
        }

        \Illuminate\Support\Facades\Http::fake();
        $this->app->forgetInstance(Engine::class);
        try {
            app(OpenRouter::class)->reply(new Request('fake/tutor', 'Synthetic', [['role' => 'user', 'content' => 'Hi']]));
            $this->fail('OpenRouter filled in the installation key.');
        } catch (EngineFailed $e) {
            $this->assertSame('engine_key', $e->errorCode);
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertTrue(app(StudentKeyGate::class)->check());
    }
}
