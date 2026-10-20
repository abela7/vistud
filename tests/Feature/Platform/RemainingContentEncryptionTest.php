<?php

namespace Tests\Feature\Platform;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\ContentColumns;
use App\Platform\Database\ContentIndexes;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\CourseProfiles;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Owner connection is required for verified maintenance conversion. All data is synthetic. */
class RemainingContentEncryptionTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql_owner');

        return $app;
    }

    public function test_every_s2b_column_round_trips_and_its_backfill_is_read_only_resumable_and_reversible(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        $marker = 'private-'.bin2hex(random_bytes(12)).' 中文 %_';
        $legacy = [];
        foreach (ContentColumns::S2B as $table => $fields) {
            $row = [];
            foreach (Schema::getColumns($table) as $column) {
                if ($column['auto_increment'] || $column['nullable'] || $column['default'] !== null) {
                    continue;
                }
                $name = $column['name'];
                $row[$name] = match (true) {
                    $name === 'learner_id' => $scope->learnerId,
                    $name === 'id', str_ends_with($name, '_id') => Ids::new(),
                    $column['type_name'] === 'date' => '2026-10-20',
                    in_array($column['type_name'], ['datetime', 'timestamp']) => '2026-10-20 12:00:00',
                    str_contains($column['type_name'], 'int') => 1,
                    $column['type_name'] === 'json' => '[]',
                    default => 'test',
                };
            }
            foreach ($fields as $field => $original) {
                $row[$field] = str_starts_with($original, 'json') ? json_encode(['text' => $marker], JSON_UNESCAPED_UNICODE) : $marker;
            }
            LearnerTables::insert($scope, $table, $row);
            $where = array_intersect_key($row, array_flip(ContentColumns::primary($table)));
            $raw = DB::table($table)->where($where)->first();
            $read = LearnerTables::query($scope, $table)->where($where);
            foreach ($fields as $field => $original) {
                $this->assertStringNotContainsString($marker, $raw->$field, $table.'.'.$field);
                $this->assertSame($row[$field], (clone $read)->value($field), $table.'.'.$field);
                $this->assertSame([$row[$field]], (clone $read)->pluck($field)->all());
                $this->assertSame('longtext', collect(Schema::getColumns($table))->firstWhere('name', $field)['type_name']);
            }
            $plain = array_intersect_key($row, $fields) + ['content_version' => 0];
            if ($table === 'topic_suggestions') {
                $plain['name_key'] = ContentIndexes::normalizedSuggestion($row['name']);
                $this->assertSame(ContentIndexes::suggestion($scope, $row['name']), $raw->name_key);
            }
            DB::table($table)->where($where)->update($plain);
            $legacy[$table] = [$where, $plain];
        }
        $before = [];
        foreach ($legacy as $table => [$where]) {
            $before[$table] = DB::table($table)->where($where)->first();
        }
        $this->artisan('vistud:encrypt:backfill', ['--phase' => 'S2b', '--dry-run' => true])
            ->expectsOutputToContain('learners=1, changed=0, blocked=0')->assertSuccessful();
        foreach ($before as $table => $row) {
            $this->assertEquals($row, DB::table($table)->first());
        }
        $options = ['--phase' => 'S2b', '--batch' => 1];
        $this->artisan('vistud:encrypt:backfill', $options + ['--limit' => 1])
            ->expectsOutputToContain('changed=1, blocked=0, stopped')->assertSuccessful();
        $this->assertSame(1, DB::table('workspaces')->value('content_version'));
        $this->assertSame(0, DB::table('modules')->value('content_version'));
        $this->artisan('vistud:encrypt:backfill', $options)->assertSuccessful();
        $encrypted = [];
        foreach ($legacy as $table => [$where, $plain]) {
            $encrypted[$table] = DB::table($table)->where($where)->first();
            foreach (ContentColumns::fields($table) as $field) {
                $this->assertSame($plain[$field], LearnerTables::query($scope, $table)->where($where)->value($field));
            }
        }
        $this->artisan('vistud:encrypt:backfill', $options)->expectsOutputToContain('changed=0, blocked=0')->assertSuccessful();
        $this->artisan('vistud:encrypt:backfill', $options + ['--verify' => true])->assertSuccessful();
        foreach ($encrypted as $table => $row) {
            $this->assertEquals($row, DB::table($table)->first());
        }
        $this->artisan('vistud:encrypt:backfill', $options + ['--decrypt' => true])->assertSuccessful();
        foreach ($before as $table => $row) {
            $this->assertEquals($row, DB::table($table)->first());
        }
        $this->artisan('vistud:encrypt:backfill', ['--phase' => 'S2a', '--table' => ['workspaces']])->assertFailed();
        $this->artisan('vistud:encrypt:backfill', ['--phase' => 'unknown'])->assertFailed();
    }

    public function test_suggestion_hashes_preserve_deduplication_renames_uniqueness_and_learner_separation(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        $other = LearnerScope::of($this->principal($this->student()));
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $module = app(Modules::class)->create($by, $workspace->id, ['title' => 'Module']);
        $suggestions = app(TopicSuggestions::class);
        $this->assertSame(1, $suggestions->suggest($by, $module->id, null, ['Private topic', 'PRIVATE TOPIC']));
        $first = $suggestions->list($by, $module->id)[0];
        $raw = DB::table('topic_suggestions')->where('id', $first->id)->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $raw->name_key);
        $this->assertNotSame(ContentIndexes::suggestion($other, 'Private topic'), $raw->name_key);
        $suggestions->rename($by, $first->id, 'Renamed topic');
        $this->assertSame(0, $suggestions->suggest($by, $module->id, null, ['RENAMED TOPIC']));
        $this->assertSame(1, $suggestions->suggest($by, $module->id, null, ['Private topic']));
        $renamed = DB::table('topic_suggestions')->where('id', $first->id)->first();
        $this->assertNotSame($raw->name, $renamed->name);
        $this->assertSame(ContentIndexes::suggestion($scope, 'Renamed topic'), $renamed->name_key);
        $copy = (array) LearnerTables::query($scope, 'topic_suggestions')->where('id', $first->id)->first();
        $copy['id'] = Ids::new();
        $this->assertThrows(fn () => LearnerTables::insert($scope, 'topic_suggestions', $copy), UniqueConstraintViolationException::class);
        DB::table('topic_suggestions')->where('id', $first->id)->update(['name_key' => str_repeat('0', 64)]);
        $this->artisan('vistud:encrypt:backfill', ['--phase' => 'S2b', '--table' => ['topic_suggestions'], '--verify' => true])->assertFailed();
    }

    public function test_file_digest_reuse_decrypts_and_reencrypts_under_the_new_row_identity(): void
    {
        Storage::fake('local');
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $files = app(Files::class);
        $a = $files->upload($by, 'workspace', $workspace->id, $this->temp('Same bytes'), 'A.txt');
        $b = $files->upload($by, 'workspace', $workspace->id, $this->temp('Same bytes'), 'B.txt');
        $digests = app(FileDigests::class);
        $digests->keep($by, $a->id, ['summary' => 'Private summary', 'outline' => [['page' => 1, 'heading' => 'Private heading']], 'topics' => ['Private topic'], 'language' => 'English'], 1, 10, null);
        $copy = $digests->reuse($by, $b);
        $this->assertSame('Private summary', $copy->summary);
        $rawA = DB::table('file_digests')->where('file_id', $a->id)->first();
        $rawB = DB::table('file_digests')->where('file_id', $b->id)->first();
        foreach (ContentColumns::fields('file_digests') as $field) {
            $this->assertNotSame($rawA->$field, $rawB->$field);
        }
        $this->assertSame('Private heading', $digests->find($by, $b->id)->outline[0]['heading']);
    }

    public function test_a_maximum_syllabus_and_long_names_fit_with_null_and_empty_values_preserved(): void
    {
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => str_repeat('文', 80)]);
        $profiles = app(CourseProfiles::class);
        $text = str_repeat('文', CourseProfiles::MAX_SYLLABUS);
        $profiles->setSyllabus($by, $workspace->id, null, $text);
        $this->assertSame($text, $profiles->syllabus($by, $workspace->id)['text']);
        $raw = DB::table('course_profiles')->where('workspace_id', $workspace->id)->first();
        $this->assertGreaterThan(strlen($text), strlen($raw->syllabus_text));
        $this->assertSame(str_repeat('文', 80), app(Workspaces::class)->find($by, $workspace->id)->name);
        $scope = LearnerScope::of($by);
        LearnerTables::query($scope, 'workspaces')->where('id', $workspace->id)->update(['code' => '', 'term' => null]);
        $this->assertSame('', LearnerTables::query($scope, 'workspaces')->where('id', $workspace->id)->value('code'));
        $this->assertNull(LearnerTables::query($scope, 'workspaces')->where('id', $workspace->id)->value('term'));
    }

    public function test_files_and_suggestions_survive_folder_moves_trash_and_restore_with_new_ciphertext(): void
    {
        Storage::fake('local');
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $a = app(Modules::class)->create($by, $workspace->id, ['title' => 'A']);
        $b = app(Modules::class)->create($by, $workspace->id, ['title' => 'B']);
        $folder = app(Folders::class)->create($by, 'module', $a->id, 'Private folder');
        $files = app(Files::class);
        $file = $files->upload($by, 'folder', $folder->id, $this->temp('Bytes'), 'Private file.txt');
        $suggestions = app(TopicSuggestions::class);
        $suggestions->suggest($by, $a->id, $file->id, ['Private topic']);
        $id = $suggestions->list($by, $a->id)[0]->id;
        $before = DB::table('topic_suggestions')->where('id', $id)->first();
        $name = fn () => DB::table('files')->where('id', $file->id)->value('name');
        $original = $name();
        app(Folders::class)->move($by, $folder->id, 'module', $b->id);
        $after = DB::table('topic_suggestions')->where('id', $id)->first();
        $this->assertSame($b->id, $after->module_id);
        $this->assertSame($before->name_key, $after->name_key);
        $this->assertNotSame($before->name, $after->name);
        $this->assertSame('Private topic', $suggestions->list($by, $b->id)[0]->name);
        $this->assertSame(0, $suggestions->suggest($by, $b->id, null, ['PRIVATE TOPIC']));
        $this->assertNotSame($original, $name());
        $files->trash($by, $file->id);
        $trashed = $name();
        $files->restore($by, $file->id);
        $this->assertNotSame($trashed, $name());
        $this->assertSame('Private file.txt', $files->find($by, $file->id)->fileName());
    }

    public function test_source_file_names_are_sorted_after_decryption(): void
    {
        Storage::fake('local');
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        foreach (['Zebra', 'apple', 'École'] as $name) {
            app(Files::class)->upload($by, 'workspace', $workspace->id, $this->temp('Bytes'), $name.'.txt');
        }
        $this->assertSame(['apple.txt', 'École.txt', 'Zebra.txt'], array_values(app(Findings::class)->sources($by, $workspace->id)));
    }

    public function test_historical_migrations_have_self_contained_frozen_manifests(): void
    {
        foreach (['100000' => ContentColumns::S2A, '110000' => ContentColumns::S2B] as $time => $expected) {
            $path = database_path('migrations/2026_10_21_'.$time.'_encrypt_'.($time === 100000 ? 'core' : 'remaining').'_learner_text.php');
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('ContentColumns', $source);
            $migration = require $path;
            $this->assertSame($expected, (new \ReflectionClass($migration))->getConstant('COLUMNS'));
        }
    }
}
