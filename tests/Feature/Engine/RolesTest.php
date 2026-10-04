<?php

namespace Tests\Feature\Engine;

use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The three AI roles and the settings kept for them (docs/specs/vistud-2-blueprint.md §3.6.1, §3.6.6). */
class RolesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_each_role_has_a_model_and_an_empty_one_uses_the_next_one_up(): void
    {
        config(['vistud.engine.tutor_model' => 'a/tutor', 'vistud.engine.reader_model' => '', 'vistud.engine.helper_model' => '']);
        $by = $this->principal($this->student());
        $settings = app(Settings::class);
        $models = fn () => array_map(fn (Role $role) => $settings->get($by)->modelFor($role), Role::cases());

        // Only a tutor: it plays all three.
        $this->assertSame(['a/tutor', 'a/tutor', 'a/tutor'], $models());

        // A reader: the helper uses it, before the tutor.
        $settings->set($by, ['tutor_model' => 'a/tutor', 'reader_model' => 'b/reader']);
        $this->assertSame(['a/tutor', 'b/reader', 'b/reader'], $models());

        // A helper of its own.
        $settings->set($by, ['tutor_model' => 'a/tutor', 'reader_model' => 'b/reader', 'helper_model' => 'c/helper']);
        $this->assertSame(['a/tutor', 'b/reader', 'c/helper'], $models());

        // Without a reader the helper keeps its own, and the reader goes back to the tutor.
        $settings->set($by, ['tutor_model' => 'a/tutor', 'reader_model' => '', 'helper_model' => 'c/helper']);
        $this->assertSame(['a/tutor', 'a/tutor', 'c/helper'], $models());
    }

    public function test_the_roles_are_named_for_the_settings_page(): void
    {
        $this->assertSame(['tutor', 'reader', 'helper'], array_map(fn (Role $role) => $role->value, Role::cases()));
        $this->assertSame(['Tutor', 'Reader', 'Helper'], array_map(fn (Role $role) => $role->label(), Role::cases()));
        foreach (Role::cases() as $role) {
            $this->assertLessThanOrEqual(60, mb_strlen($role->does()), "{$role->label()}'s line is a hint: one line.");
        }
    }

    public function test_the_owners_defaults_cover_all_three_roles_and_a_students_own_choice_wins(): void
    {
        $setup = app(Setup::class);
        $setup->setDefaults($this->principal($this->admin()), ['tutor_model' => 'a/tutor', 'reader_model' => 'b/reader', 'helper_model' => 'c/helper']);
        $this->assertSame(['tutor' => 'a/tutor', 'reader' => 'b/reader', 'helper' => 'c/helper'], $setup->defaultModels());

        $by = $this->principal($this->student());
        $settings = app(Settings::class);
        $choices = $settings->get($by);
        $this->assertSame(['a/tutor', 'b/reader', 'c/helper'], [$choices->modelFor(Role::Tutor), $choices->modelFor(Role::Reader), $choices->modelFor(Role::Helper)]);

        // A role the student leaves empty keeps the owner's default (not the tutor's): the defaults apply until they choose.
        $settings->set($by, ['tutor_model' => 'x/tutor', 'reader_model' => '', 'helper_model' => 'z/helper']);
        $choices = $settings->get($by);
        $this->assertSame(['x/tutor', 'b/reader', 'z/helper'], [$choices->modelFor(Role::Tutor), $choices->modelFor(Role::Reader), $choices->modelFor(Role::Helper)]);
    }

    public function test_the_environment_defaults_are_read_and_the_old_quick_model_still_sets_the_readers(): void
    {
        $this->assertSame('', (string) config('vistud.engine.helper_model'));
        $this->assertSame(['reader_model', 'helper_model'], array_values(array_intersect(['reader_model', 'helper_model', 'quick_model'], array_keys(config('vistud.engine')))));
        $this->assertFalse(array_key_exists('quick_model', config('vistud.engine')));
    }

    public function test_the_trust_toggles_start_as_the_blueprint_says_and_are_kept_until_changed(): void
    {
        $by = $this->principal($this->student());
        $settings = app(Settings::class);
        $toggles = fn () => [$settings->get($by)->askTopics, $settings->get($by)->autoReadFiles, $settings->get($by)->tutorMarksTopics, $settings->get($by)->copyPasteAi];

        // Ask before topics: off. Read files by themselves: on. The tutor marks topics: on. Copy-paste: off.
        $this->assertSame([false, true, true, false], $toggles());

        $settings->set($by, ['tutor_model' => 'a/tutor', 'auto_read_files' => false, 'copy_paste_ai' => '1']);
        $this->assertSame([false, false, true, true], $toggles());

        // Saving other choices leaves them as they are.
        $settings->set($by, ['tutor_model' => 'b/tutor', 'language' => 'Amharic']);
        $this->assertSame([false, false, true, true], $toggles());

        $settings->set($by, ['ask_topics' => true, 'tutor_marks_topics' => false, 'auto_read_files' => true, 'copy_paste_ai' => false]);
        $this->assertSame([true, true, false, false], $toggles());
    }

    public function test_the_helper_and_reader_ids_are_checked_like_the_tutors(): void
    {
        $by = $this->principal($this->student());
        foreach (['reader_model' => 'not a model!', 'helper_model' => 'google/gemini:batch'] as $field => $bad) {
            try {
                app(Settings::class)->set($by, [$field => $bad]);
                $this->fail("{$field} should be refused.");
            } catch (Unprocessable $e) {
                $this->assertSame([$field], array_keys($e->details['fields']));
            }
        }
    }

    public function test_a_call_needs_a_key_a_model_for_the_role_and_consent(): void
    {
        config(['vistud.engine.key' => '', 'vistud.engine.tutor_model' => '']);
        $by = $this->principal($this->student());
        $settings = app(Settings::class);

        $refusal = function (Role $role) use ($by, $settings): string {
            try {
                $settings->ready($by, $role);
            } catch (Unprocessable $e) {
                return $e->errorCode;
            }

            return 'ready';
        };

        $this->assertSame('engine_key', $refusal(Role::Helper));
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->assertSame('engine_model', $refusal(Role::Tutor));
        $settings->set($by, ['helper_model' => 'c/helper']);
        // The helper has a model of its own, the reader and the tutor still have none.
        $this->assertSame(['engine_consent', 'engine_model', 'engine_model'], [$refusal(Role::Helper), $refusal(Role::Reader), $refusal(Role::Tutor)]);
        $settings->set($by, ['helper_model' => 'c/helper', 'consent' => true]);
        $this->assertSame('ready', $refusal(Role::Helper));
        [$choices, $key, $model] = $settings->ready($by, Role::Helper);
        $this->assertSame(['c/helper', null], [$model, $key]);
        $this->assertTrue($choices->consentedAt !== null);
    }

    public function test_the_schema_has_the_new_columns_and_the_defaults_carry_over_from_the_quick_model(): void
    {
        $this->assertTrue(Schema::hasColumns('engine_settings', ['reader_model', 'helper_model', 'auto_read_files', 'tutor_marks_topics', 'copy_paste_ai']));
        $this->assertFalse(Schema::hasColumn('engine_settings', 'quick_model'));

        // The owner's default moves with its value; one already set for the reader is the one kept.
        $migration = require base_path('database/migrations/2026_10_06_100000_make_engine_roles_and_trust_settings.php');
        DB::table('platform_settings')->insert(['key' => 'engine.quick_model', 'value' => 'old/quick', 'secret' => false, 'updated_at' => now()]);
        $migration->carryOwnerDefault();
        $this->assertSame('old/quick', DB::table('platform_settings')->where('key', 'engine.reader_model')->value('value'));
        $this->assertFalse(DB::table('platform_settings')->where('key', 'engine.quick_model')->exists());

        DB::table('platform_settings')->insert(['key' => 'engine.quick_model', 'value' => 'older/quick', 'secret' => false, 'updated_at' => now()]);
        $migration->carryOwnerDefault();
        $this->assertSame('old/quick', DB::table('platform_settings')->where('key', 'engine.reader_model')->value('value'));
        $this->assertFalse(DB::table('platform_settings')->where('key', 'engine.quick_model')->exists());
    }
}
