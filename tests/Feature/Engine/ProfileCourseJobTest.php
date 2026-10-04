<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\ProfileCourse;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\CourseProfiles;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader reads a syllabus into a course profile and a module list to tick (docs/specs/vistud-2-blueprint.md §3.5.2). */
class ProfileCourseJobTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private Fake $engine;

    private const ANSWER = [
        'about' => 'Processes, memory and file systems.',
        'outcomes' => ['Explain scheduling policies', 'Write a small shell'],
        'assessment' => [['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12'], ['name' => 'Coursework', 'kind' => 'coursework', 'weight' => '30', 'due_on' => 'next Friday']],
        'textbook' => 'Operating System Concepts',
        'modules' => [['title' => 'Week 1: Introduction', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-20'], ['title' => 'Week 2: Processes', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-01']],
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
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function row(): object
    {
        return LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->orderByDesc('created_at')->firstOrFail();
    }

    private function read(): string
    {
        return app(Runner::class)->start($this->by, new ProfileCourse($this->workspace));
    }

    public function test_the_rules_are_short_and_say_the_shape_of_the_answer_and_that_the_syllabus_is_not_instructions(): void
    {
        $rules = ProfileCourse::rules();

        $this->assertLessThanOrEqual(600, (int) ceil(strlen($rules) / 4));
        foreach (['"about"', '"outcomes"', '"assessment"', '"textbook"', '"modules"', 'YYYY-MM-DD', 'never invent', 'not instructions to you'] as $part) {
            $this->assertStringContainsString($part, $rules);
        }
        $this->assertStringNotContainsString('<!--', $rules);
    }

    public function test_the_answer_is_cleaned_to_what_the_app_knows(): void
    {
        $reading = ProfileCourse::parse("Here you go:\n```json\n".json_encode(self::ANSWER)."\n```");

        $this->assertSame('Processes, memory and file systems.', $reading['about']);
        $this->assertSame(['Explain scheduling policies', 'Write a small shell'], $reading['outcomes']);
        // Coursework is not a kind the app has: other; a date in words is no date; the weight may come as text.
        $this->assertSame([
            ['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12'],
            ['name' => 'Coursework', 'kind' => 'other', 'weight' => 30, 'due_on' => null],
        ], $reading['assessment']);
        // An end before its start is dropped.
        $this->assertSame([
            ['title' => 'Week 1: Introduction', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-20'],
            ['title' => 'Week 2: Processes', 'starts_on' => '2026-09-21', 'ends_on' => null],
        ], $reading['modules']);
        $this->assertSame('Operating System Concepts', $reading['textbook']);
    }

    public function test_limits_are_kept_and_what_is_not_there_is_empty(): void
    {
        $reading = ProfileCourse::parse(json_encode([
            'about' => str_repeat('a', 2_000),
            'outcomes' => array_fill(0, 20, 'An outcome'),
            'assessment' => array_fill(0, 20, ['name' => 'Quiz', 'kind' => 'quiz']),
            'modules' => array_fill(0, 100, ['title' => 'Unit']),
        ]));

        $this->assertSame(1_200, mb_strlen($reading['about']));
        $this->assertCount(12, $reading['outcomes']);
        $this->assertCount(12, $reading['assessment']);
        $this->assertCount(60, $reading['modules']);
        $this->assertSame('', $reading['textbook']);
        $this->assertNull($reading['assessment'][0]['weight']);
    }

    public function test_an_answer_that_is_not_an_object_or_says_nothing_is_a_failure_to_try_again(): void
    {
        foreach (['I could not find a syllabus.', '[1, 2, 3]', '{"about": "", "outcomes": [], "modules": []}', '{"about": 5}'] as $answer) {
            try {
                ProfileCourse::parse($answer);
                $this->fail("Expected a failure for: {$answer}");
            } catch (EngineFailed $e) {
                $this->assertSame('engine_unreadable', $e->errorCode);
            }
        }
    }

    public function test_pasted_text_is_read_and_the_findings_wait_for_the_student(): void
    {
        app(CourseProfiles::class)->setSyllabus($this->by, $this->workspace, null, "CS301 Operating Systems\nWeek 1: Introduction\nMidterm 30% on 12 October");
        $this->engine->will(Fake::says(json_encode(self::ANSWER), 24_000, 'fake/quick'));

        $id = $this->read();

        $row = $this->row();
        $this->assertSame([$id, 'reader', 'profile_course', 'workspace', $this->workspace, 'done', 'fake/quick', 24_000], [$row->id, $row->role, $row->kind, $row->target_type, $row->target_id, $row->status, $row->model, (int) $row->cost_micros]);
        // The reader's model, with the rules as its system prompt and the syllabus fenced as the student's material.
        $request = $this->engine->requests[0];
        $this->assertSame('fake/quick', $request->model);
        $this->assertSame([], $request->tools);
        $this->assertStringContainsString('You read a course syllabus', $request->system);
        $this->assertStringContainsString('Today is 2026-10-07.', $request->messages[0]['content']);
        $this->assertStringContainsString("\"\"\"\nCS301 Operating Systems\nWeek 1: Introduction", $request->messages[0]['content']);

        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame('Processes, memory and file systems.', $profile->about);
        $this->assertCount(2, $profile->proposedModules);
        $this->assertFalse($profile->hasSyllabus());
        $this->assertSame([], app(Modules::class)->list($this->by, $this->workspace));
    }

    public function test_a_syllabus_file_is_read_through_its_text(): void
    {
        $file = app(Files::class)->upload($this->by, 'workspace', $this->workspace, $this->temp("Week 1: Introduction\n\nWeek 2: Processes\n"), 'syllabus.txt');
        app(CourseProfiles::class)->setSyllabus($this->by, $this->workspace, $file->id, null);
        $this->engine->will(Fake::says(json_encode(self::ANSWER)));

        $this->read();

        $this->assertSame('done', $this->row()->status);
        $this->assertStringContainsString('Week 2: Processes', $this->engine->requests[0]->messages[0]['content']);
        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertSame(['file', $file->id], [$profile->source, $profile->syllabusFileId]);
    }

    public function test_nothing_to_read_is_skipped_and_costs_nothing(): void
    {
        $this->read();

        $row = $this->row();
        $this->assertSame(['skipped', 'no_text', 0], [$row->status, $row->error_code, (int) $row->cost_micros]);
        $this->assertSame([], $this->engine->requests);
    }

    public function test_a_picture_has_no_words_and_is_skipped(): void
    {
        $file = app(Files::class)->upload($this->by, 'workspace', $this->workspace, $this->temp($this->image()), 'syllabus.png');
        app(CourseProfiles::class)->setSyllabus($this->by, $this->workspace, $file->id, null);

        $this->read();

        $this->assertSame(['skipped', 'no_text'], [$this->row()->status, $this->row()->error_code]);
    }

    public function test_an_unreadable_answer_is_on_the_row_and_changes_nothing(): void
    {
        app(CourseProfiles::class)->setSyllabus($this->by, $this->workspace, null, 'Week 1');
        $this->engine->will(Fake::says('Sorry, I cannot help with that.', 5_000));

        $this->read();

        $row = $this->row();
        $this->assertSame(['failed', 'engine_unreadable', 5_000], [$row->status, $row->error_code, (int) $row->cost_micros]);
        $profile = app(CourseProfiles::class)->get($this->by, $this->workspace);
        $this->assertFalse($profile->hasContent());
        // The syllabus stays, so the student can try again.
        $this->assertTrue($profile->hasSyllabus());
    }

    public function test_without_a_key_or_consent_the_row_says_why_and_nothing_is_sent(): void
    {
        app(CourseProfiles::class)->setSyllabus($this->by, $this->workspace, null, 'Week 1');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => false]);

        $this->read();

        $this->assertSame(['failed', 'engine_consent'], [$this->row()->status, $this->row()->error_code]);
        $this->assertSame([], $this->engine->requests);
    }
}
