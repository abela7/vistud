<?php

namespace Tests\Feature\Engine;

use App\Audit\AuditAction;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\OpenRouter;
use App\Engine\Request;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The engine's set-up by an admin: the key kept encrypted, the service's address and the defaults (docs/specs/study-memory.md §6). */
class SetupTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_key_is_kept_encrypted_shown_by_its_last_four_characters_and_used_by_the_engine(): void
    {
        config(['vistud.engine.key' => '', 'vistud.engine.url' => 'https://engine.test/api/v1']);
        $admin = $this->principal($this->admin());
        $setup = app(Setup::class);
        $this->assertFalse($setup->keySet());
        $this->assertSame(['key_set' => false, 'key_hint' => null, 'key_from_env' => false, 'key_unreadable' => false], array_slice($setup->status($admin), 0, 4));

        $setup->setKey($admin, " sk-or-v1-abcdefghijklmnopqrstuvwxyz0123456789 \n");
        $raw = DB::table('platform_settings')->where('key', Setup::KEY)->value('value');
        $this->assertStringNotContainsString('sk-or-v1', $raw);
        $this->assertSame('sk-or-v1-abcdefghijklmnopqrstuvwxyz0123456789', Crypt::decryptString($raw));
        $this->assertSame('sk-or-v1-abcdefghijklmnopqrstuvwxyz0123456789', app(Setup::class)->key());
        $status = app(Setup::class)->status($admin);
        $this->assertSame([true, '…6789', false, false], [$status['key_set'], $status['key_hint'], $status['key_from_env'], $status['key_unreadable']]);
        $this->assertNotNull($status['key_updated_at']);

        // The audit log says it was set, by whom, with the last four characters only.
        $rows = $this->auditRows(AuditAction::ENGINE_KEY_SET);
        $this->assertCount(1, $rows);
        $this->assertSame(['hint' => '6789'], json_decode($rows[0]->metadata, true));
        $this->assertStringNotContainsString('abcdefgh', json_encode($rows[0]));

        // The engine calls the service with it.
        Http::fake(['engine.test/*' => Http::response(['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']]])]);
        app(OpenRouter::class)->reply(new Request('m', 's', []));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-or-v1-abcdefghijklmnopqrstuvwxyz0123456789'));

        app(Setup::class)->removeKey($admin);
        $this->assertFalse(app(Setup::class)->keySet());
        $this->assertCount(1, $this->auditRows(AuditAction::ENGINE_KEY_REMOVED));
    }

    public function test_a_key_in_env_still_works_until_one_is_set_here_and_an_unreadable_one_counts_as_not_set(): void
    {
        config(['vistud.engine.key' => 'sk-or-from-env-000000000000']);
        $admin = $this->principal($this->admin());
        $status = app(Setup::class)->status($admin);
        $this->assertSame([true, '…0000', true], [$status['key_set'], $status['key_hint'], $status['key_from_env']]);

        DB::table('platform_settings')->insert(['key' => Setup::KEY, 'value' => 'not-encrypted-at-all', 'secret' => true, 'updated_at' => now()]);
        $status = app(Setup::class)->status($admin);
        $this->assertSame([false, true], [$status['key_set'], $status['key_unreadable']]);
    }

    public function test_the_defaults_apply_to_students_and_are_checked(): void
    {
        $admin = $this->principal($this->admin());
        $setup = app(Setup::class);
        $setup->setDefaults($admin, ['url' => 'https://other.test/v1/', 'tutor_model' => 'openai/gpt-4.1-mini', 'reader_model' => '', 'helper_model' => '']);
        $this->assertSame('https://other.test/v1', app(Setup::class)->url());
        $this->assertSame(['tutor' => 'openai/gpt-4.1-mini', 'reader' => '', 'helper' => ''], app(Setup::class)->defaultModels());
        $this->assertSame('openai/gpt-4.1-mini', app(Settings::class)->get($this->principal($this->student()))->tutorModel);
        $this->assertCount(1, $this->auditRows(AuditAction::ENGINE_DEFAULTS_CHANGED));

        try {
            $setup->setDefaults($admin, ['url' => 'ftp://nope', 'tutor_model' => 'bad id!']);
            $this->fail('Should refuse.');
        } catch (Unprocessable $e) {
            $this->assertSame(['url', 'tutor_model'], array_keys($e->details['fields']));
        }
        try {
            $setup->setKey($admin, 'short');
            $this->fail('Should refuse.');
        } catch (Unprocessable $e) {
            $this->assertSame(['key'], array_keys($e->details['fields']));
        }
    }

    public function test_trying_the_set_up_reports_the_models_or_what_is_wrong(): void
    {
        $admin = $this->principal($this->admin());
        $this->app->instance(Engine::class, new Fake);
        $this->assertSame(['ok' => false, 'message' => 'No key is set yet.', 'models' => 0], app(Setup::class)->test($admin));
        app(Setup::class)->setKey($admin, 'sk-or-v1-abcdefghijklmnopqrstuvwxyz');
        $this->assertSame(['ok' => true, 'message' => 'It works: the service offers 3 models.', 'models' => 3], app(Setup::class)->test($admin));
    }

    public function test_only_an_admin_sets_it_up_and_only_with_a_fresh_password(): void
    {
        $student = $this->principal($this->student());
        $this->expectException(Forbidden::class);
        app(Setup::class)->setKey($student, 'sk-or-v1-abcdefghijklmnopqrstuvwxyz');
    }
}
