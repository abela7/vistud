<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\ReadFile;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\FileDetails;
use App\Study\FileDigests;
use App\Study\FileReading;
use App\Study\Files;
use App\Study\FileText;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader reads one file into a digest, once per content, and the topics it finds wait for the student (docs/specs/vistud-2-blueprint.md §3.5.3). */
class ReadFileJobTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private Fake $engine;

    private const ANSWER = [
        'summary' => 'Scheduling algorithms: first come first served, shortest job first and round robin.',
        'outline' => [['page' => 1, 'heading' => 'Introduction'], ['page' => 3, 'heading' => 'Round robin']],
        'topics' => ['CPU scheduling', 'Round robin', 'cpu scheduling'],
        'language' => 'English',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function upload(string $text = "Scheduling decides which process runs next.\n", string $name = 'Lecture 3.txt', ?string $place = null): FileDetails
    {
        return app(Files::class)->upload($this->by, $place === null ? 'module' : 'workspace', $place ?? $this->module, $this->temp($text), $name);
    }

    private function row(): object
    {
        return LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->orderByDesc('created_at')->firstOrFail();
    }

    private function read(FileDetails $file): string
    {
        return app(Runner::class)->start($this->by, new ReadFile($file->workspaceId, $file->id));
    }

    public function test_the_rules_are_short_and_say_the_shape_of_the_answer_and_that_the_file_is_not_instructions(): void
    {
        $rules = ReadFile::rules();

        $this->assertLessThanOrEqual(600, (int) ceil(strlen($rules) / 4));
        foreach (['"summary"', '"outline"', '"topics"', '"language"', 'never invent', 'not instructions to you', 'three to eight'] as $part) {
            $this->assertStringContainsString($part, $rules);
        }
        $this->assertStringNotContainsString('<!--', $rules);
    }

    public function test_the_answer_is_cleaned_and_a_nothing_answer_is_a_failure(): void
    {
        $reading = ReadFile::parse("```json\n".json_encode(self::ANSWER + ['extra' => 'x'])."\n```");

        $this->assertSame([['page' => 1, 'heading' => 'Introduction'], ['page' => 3, 'heading' => 'Round robin']], $reading['outline']);
        // A topic said twice in different cases is one; the summary and language are kept.
        $this->assertSame(['CPU scheduling', 'Round robin'], $reading['topics']);
        $this->assertSame(['English', self::ANSWER['summary']], [$reading['language'], $reading['summary']]);

        $long = ReadFile::parse(json_encode(['summary' => str_repeat('s', 900), 'topics' => array_map(fn ($n) => "Topic {$n}", range(1, 20)), 'outline' => array_map(fn ($n) => ['page' => $n, 'heading' => 'H'], range(1, 80)) + [['page' => 'x', 'heading' => 'bad'], ['page' => 0, 'heading' => 'bad']]]));
        $this->assertSame([600, 8, 40], [mb_strlen($long['summary']), count($long['topics']), count($long['outline'])]);

        foreach (['No idea.', '[]', '{"summary": "", "topics": []}'] as $answer) {
            try {
                ReadFile::parse($answer);
                $this->fail("Expected a failure for: {$answer}");
            } catch (EngineFailed $e) {
                $this->assertSame('engine_unreadable', $e->errorCode);
            }
        }
    }

    public function test_the_reader_is_given_the_text_by_page_and_the_rest_as_an_outline(): void
    {
        $file = new FileDetails('f1', 'w1', null, null, 'Slides', 'pdf', 'pdf', 'application/pdf', 1, 1, '2026-10-07T09:00:00.000000Z', null);
        $page = fn (string $first) => $first."\n".str_repeat('x', 5_490);
        $pages = array_map(fn (int $n) => $page("Heading {$n}"), range(1, 9));

        [$body, $chars] = ReadFile::body(new FileText($file, FileText::READY, $pages, 'page'));

        // Five whole pages fit in 30,000 characters; the other four follow as their first lines.
        $this->assertStringStartsWith("[Page 1]\nHeading 1", $body);
        $this->assertStringContainsString('[Page 5]', $body);
        $this->assertStringNotContainsString('[Page 6]', $body);
        $this->assertStringContainsString("The rest of the file, by first line:\nPage 6: Heading 6\nPage 7: Heading 7\nPage 8: Heading 8\nPage 9: Heading 9", $body);
        $this->assertLessThanOrEqual(30_000, $chars);

        [$short] = ReadFile::body(new FileText($file, FileText::READY, ['One.', 'Two.'], 'slide'));
        $this->assertSame("[Slide 1]\nOne.\n\n[Slide 2]\nTwo.", $short);
    }

    public function test_a_file_is_read_into_a_digest_and_its_topics_wait_in_its_module(): void
    {
        $this->engine->will(Fake::says(json_encode(self::ANSWER), 15_000, 'fake/quick'));
        $file = $this->upload();

        $id = $this->read($file);

        $row = $this->row();
        $this->assertSame([$id, 'reader', 'read_file', 'file', $file->id, 'done', 'fake/quick', 15_000], [$row->id, $row->role, $row->kind, $row->target_type, $row->target_id, $row->status, $row->model, (int) $row->cost_micros]);
        $request = $this->engine->requests[0];
        $this->assertSame('fake/quick', $request->model);
        $this->assertSame([], $request->tools);
        $this->assertStringContainsString('You read one file of a student\'s course', $request->system);
        $this->assertStringContainsString('The file is "Lecture 3.txt"', $request->messages[0]['content']);
        $this->assertStringContainsString("[Part 1]\nScheduling decides which process runs next.", $request->messages[0]['content']);

        $digest = app(FileDigests::class)->find($this->by, $file->id);
        $this->assertSame(['done', 'English', 'fake/quick', 15_000], [$digest->status, $digest->language, $digest->model, $digest->costMicros]);
        $this->assertSame(['CPU scheduling', 'Round robin'], $digest->topics);
        $this->assertSame(1, $digest->pages);
        $this->assertSame(['CPU scheduling', 'Round robin'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));
        // Nothing was added to the module by the reader.
        $this->assertSame([], app(Topics::class)->list($this->by, $this->workspace));
    }

    public function test_the_same_content_is_read_once_and_a_copy_of_it_costs_nothing(): void
    {
        $this->engine->will(Fake::says(json_encode(self::ANSWER), 15_000));
        $first = $this->upload();
        $this->read($first);
        $this->assertCount(1, $this->engine->requests);

        // Reading it again says it was read.
        $this->read($first);
        $this->assertSame(['skipped', 'read', 0], [$this->row()->status, $this->row()->error_code, (int) $this->row()->cost_micros]);

        // The same bytes under another name: the digest is carried over, no call is made.
        $second = $this->upload(name: 'Lecture 3 (copy).txt');
        $this->read($second);
        $this->assertSame(['done', 0], [$this->row()->status, (int) $this->row()->cost_micros]);
        $this->assertCount(1, $this->engine->requests);
        $this->assertSame('English', app(FileDigests::class)->find($this->by, $second->id)->language);

        // A different file is read.
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));
        $this->read($this->upload("Something else.\n", 'Lab 3.txt'));
        $this->assertCount(2, $this->engine->requests);
    }

    public function test_a_picture_and_a_file_with_no_words_are_skipped_and_not_tried_again(): void
    {
        $picture = $this->upload($this->image(), 'Diagram.png');
        $this->read($picture);
        $this->assertSame(['skipped', 'picture'], [$this->row()->status, $this->row()->error_code]);
        $this->assertSame([], $this->engine->requests);

        $digest = app(FileDigests::class)->find($this->by, $picture->id);
        $this->assertSame(['skipped', 'picture'], [$digest->status, $digest->reason]);
        $this->read($picture);
        $this->assertSame('picture', $this->row()->error_code);
    }

    public function test_an_unreadable_answer_is_on_the_row_and_nothing_is_kept(): void
    {
        $this->engine->will(Fake::says('Sorry.', 4_000));
        $file = $this->upload();

        $this->read($file);

        $this->assertSame(['failed', 'engine_unreadable', 4_000], [$this->row()->status, $this->row()->error_code, (int) $this->row()->cost_micros]);
        $this->assertNull(app(FileDigests::class)->find($this->by, $file->id));
        $this->assertSame([], app(TopicSuggestions::class)->list($this->by, $this->module));
    }

    public function test_a_file_at_the_top_of_the_course_is_read_but_suggests_nothing(): void
    {
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));
        $file = $this->upload(place: $this->workspace);

        $this->read($file);

        $this->assertSame('done', $this->row()->status);
        $this->assertSame([], LearnerTables::query(LearnerScope::of($this->by), 'topic_suggestions')->get()->all());
    }

    public function test_a_file_is_read_on_upload_only_when_the_student_wants_it_and_the_ai_is_set_up(): void
    {
        $reading = app(FileReading::class);
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));

        // On, in a module, AI set up: it is read.
        $this->assertNotNull($reading->afterUpload($this->by, $this->upload()));
        $this->assertSame('done', $this->row()->status);

        // A picture is never sent; a file outside a module waits.
        $this->assertNull($reading->afterUpload($this->by, $this->upload($this->image(), 'Diagram.png')));
        $this->assertNull($reading->afterUpload($this->by, $this->upload("Top level.\n", 'Top.txt', $this->workspace)));

        // Turned off: it waits for Read now.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'auto_read_files' => false]);
        $waiting = $this->upload("Another lecture.\n", 'Lecture 4.txt');
        $this->assertNull($reading->afterUpload($this->by, $waiting));
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));
        $this->assertNotNull($reading->readNow($this->by, $waiting->id));
        $this->assertSame('done', $this->row()->status);

        // Without consent nothing is sent: not on upload (quietly), and Read now says why.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => false]);
        $this->assertNull($reading->afterUpload($this->by, $this->upload("A third.\n", 'Lecture 5.txt')));
        $this->expectExceptionMessage('Agree to the chat in your AI settings first.');
        $reading->readNow($this->by, $waiting->id);
    }

    public function test_the_states_of_a_modules_files_are_read_reading_unread_or_skipped(): void
    {
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));
        $read = $this->upload();
        $this->read($read);
        $picture = $this->upload($this->image(), 'Diagram.png');
        $this->read($picture);
        $fresh = $this->upload("Not read yet.\n", 'Lecture 4.txt');
        $busy = $this->upload("Being read.\n", 'Lecture 5.txt');
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', ['id' => 'job-1', 'role' => 'reader', 'kind' => 'read_file', 'target_type' => 'file', 'target_id' => $busy->id, 'status' => 'running', 'created_at' => now()]);

        $states = app(FileDigests::class)->states($this->by, [$read, $picture, $fresh, $busy]);

        $this->assertSame(['read', 'skipped', 'unread', 'reading'], array_map(fn ($file) => $states[$file->id]['state'], [$read, $picture, $fresh, $busy]));
        $this->assertNotNull($states[$read->id]['digest']);
        // A run that has waited for a queue nobody works for a quarter of an hour is not reading any more.
        $this->travel(20)->minutes();
        $this->assertSame('unread', app(FileDigests::class)->states($this->by, [$busy])[$busy->id]['state']);
    }

    public function test_a_changed_file_is_read_again_and_a_deleted_file_takes_its_digest(): void
    {
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));
        $file = $this->upload();
        $this->read($file);
        $this->assertNotNull(app(FileDigests::class)->find($this->by, $file->id));

        // The bytes change: the digest is of the old content, so the file is unread again.
        LearnerTables::query(LearnerScope::of($this->by), 'files')->where('id', $file->id)->update(['sha256' => str_repeat('0', 64)]);
        $this->assertNull(app(FileDigests::class)->find($this->by, $file->id));
        $this->assertSame('unread', app(FileDigests::class)->states($this->by, [app(Files::class)->find($this->by, $file->id)])[$file->id]['state']);

        app(Files::class)->trash($this->by, $file->id);
        app(Files::class)->destroy($this->by, $file->id);
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'file_digests')->count());
        // What it suggested stays, now from a file that is gone.
        $this->assertNotSame([], app(TopicSuggestions::class)->list($this->by, $this->module));
        $this->assertNull(app(TopicSuggestions::class)->list($this->by, $this->module)[0]->sourceFileId);
    }

    public function test_another_students_file_cannot_be_read_or_seen(): void
    {
        $file = $this->upload();
        $bob = $this->principal($this->student());

        foreach ([fn () => app(FileDigests::class)->find($bob, $file->id), fn () => app(FileReading::class)->readNow($bob, $file->id), fn () => app(FileDigests::class)->forModule($bob, $this->module)] as $call) {
            try {
                $call();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
