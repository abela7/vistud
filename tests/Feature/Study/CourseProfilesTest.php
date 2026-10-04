<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What a course is: its profile, the reader's proposal, and what the student ticks (docs/specs/vistud-2-blueprint.md §3.5.2). */
class CourseProfilesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private CourseProfiles $profiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->profiles = app(CourseProfiles::class);
    }

    /** @return array{about: string, outcomes: list<string>, assessment: list<array>, textbook: string, modules: list<array>} */
    private function reading(): array
    {
        return [
            'about' => 'Processes, memory and file systems.',
            'outcomes' => ['Explain scheduling policies'],
            'assessment' => [
                ['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12'],
                ['name' => 'Final', 'kind' => 'exam', 'weight' => 40, 'due_on' => null],
            ],
            'textbook' => 'Operating System Concepts',
            'modules' => [
                ['title' => 'Week 1: Introduction', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-20'],
                ['title' => 'Week 2: Processes', 'starts_on' => null, 'ends_on' => null],
                ['title' => 'Week 3: Threads', 'starts_on' => null, 'ends_on' => null],
            ],
        ];
    }

    public function test_a_course_without_a_profile_has_an_empty_one(): void
    {
        $profile = $this->profiles->get($this->by, $this->workspace);

        $this->assertFalse($profile->hasContent());
        $this->assertFalse($profile->hasSyllabus());
        $this->assertSame([], $profile->proposedModules);
        $this->assertSame('manual', $profile->source);
    }

    public function test_the_student_writes_it_and_each_part_is_checked(): void
    {
        $saved = $this->profiles->save($this->by, $this->workspace, [
            'about' => '  A course   on processes. ',
            'outcomes' => "Explain scheduling\n\n  Write a shell \n",
            'textbook' => 'Tanenbaum',
            'assessment' => [['name' => 'Midterm', 'kind' => 'exam', 'weight' => '30', 'due_on' => '2026-10-12']],
        ]);

        $this->assertSame('A course on processes.', $saved->about);
        $this->assertSame(['Explain scheduling', 'Write a shell'], $saved->outcomes);
        $this->assertSame([['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12', 'activity_id' => null]], $saved->assessment);
        $this->assertTrue($saved->hasContent());

        $bad = fn (array $input, string $field) => $this->assertSame([$field], array_keys($this->refusal(fn () => $this->profiles->save($this->by, $this->workspace, $input))));
        $bad(['about' => str_repeat('a', 1201)], 'about');
        $bad(['outcomes' => implode("\n", array_fill(0, 13, 'x'))], 'outcomes');
        $bad(['assessment' => [['name' => '', 'kind' => 'exam', 'weight' => '10']]], 'assessment.0');
        $bad(['assessment' => [['name' => 'Quiz', 'kind' => 'nonsense']]], 'assessment.0');
        $bad(['assessment' => [['name' => 'Quiz', 'kind' => 'quiz', 'weight' => '101']]], 'assessment.0');
        $bad(['assessment' => [['name' => 'Quiz', 'kind' => 'quiz', 'due_on' => '2026-13-45']]], 'assessment.0');
        // A refusal changes nothing.
        $this->assertSame('A course on processes.', $this->profiles->get($this->by, $this->workspace)->about);
    }

    public function test_an_emptied_row_is_dropped_and_an_unknown_assignment_id_is_forgotten(): void
    {
        $saved = $this->profiles->save($this->by, $this->workspace, ['assessment' => [
            ['name' => '', 'kind' => 'other', 'weight' => '', 'due_on' => ''],
            ['name' => 'Lab', 'kind' => 'lab', 'activity_id' => 'not-an-assignment'],
        ]]);

        $this->assertCount(1, $saved->assessment);
        $this->assertNull($saved->assessment[0]['activity_id']);
    }

    public function test_the_reader_s_findings_are_kept_for_review_and_nothing_is_added_yet(): void
    {
        $this->profiles->setSyllabus($this->by, $this->workspace, null, "Week 1: Intro\nWeek 2: Processes");
        $this->assertTrue($this->profiles->get($this->by, $this->workspace)->hasSyllabus());

        $this->profiles->applyReading($this->by, $this->workspace, $this->reading(), 'fake/quick');
        $profile = $this->profiles->get($this->by, $this->workspace);

        $this->assertSame('Processes, memory and file systems.', $profile->about);
        $this->assertSame('Operating System Concepts', $profile->textbook);
        $this->assertSame(['Week 1: Introduction', 'Week 2: Processes', 'Week 3: Threads'], array_column($profile->proposedModules, 'title'));
        $this->assertSame(['fake/quick', 'manual'], [$profile->model, $profile->source]);
        $this->assertNotNull($profile->builtAt);
        // The pasted syllabus has been read and is not kept.
        $this->assertFalse($profile->hasSyllabus());
        $this->assertSame([], app(Modules::class)->list($this->by, $this->workspace));
        $this->assertSame([], app(Activities::class)->list($this->by, $this->workspace));
    }

    public function test_what_is_ticked_is_added_in_order_with_an_assignment_for_each_dated_assessment_once(): void
    {
        $this->profiles->applyReading($this->by, $this->workspace, $this->reading(), 'fake/quick');

        $added = $this->profiles->addFromReading($this->by, $this->workspace, [2, 0], [0, 1]);

        $this->assertSame(['modules' => 2, 'assignments' => 1], $added);
        $modules = app(Modules::class)->list($this->by, $this->workspace);
        // In the order of the proposal, not of the ticks; dates as found.
        $this->assertSame(['Week 1: Introduction', 'Week 3: Threads'], array_map(fn ($m) => $m->title, $modules));
        $this->assertSame(['2026-09-14', '2026-09-20'], [$modules[0]->startsOn, $modules[0]->endsOn]);
        $tasks = app(Activities::class)->list($this->by, $this->workspace);
        $this->assertSame([['Midterm', 'exam', '2026-10-12']], array_map(fn ($t) => [$t->title, $t->kind, $t->dueOn], $tasks));

        // The module list is spent, and adding again never doubles the assignment.
        $profile = $this->profiles->get($this->by, $this->workspace);
        $this->assertSame([], $profile->proposedModules);
        $this->assertSame($tasks[0]->id, $profile->assessment[0]['activity_id']);
        $this->assertSame(['modules' => 0, 'assignments' => 0], $this->profiles->addFromReading($this->by, $this->workspace, [1], [0]));
        $this->assertCount(1, app(Activities::class)->list($this->by, $this->workspace));
    }

    public function test_a_proposal_can_be_dismissed(): void
    {
        $this->profiles->applyReading($this->by, $this->workspace, $this->reading(), 'fake/quick');
        $this->profiles->dismissProposal($this->by, $this->workspace);

        $this->assertSame([], $this->profiles->get($this->by, $this->workspace)->proposedModules);
        $this->assertSame('Processes, memory and file systems.', $this->profiles->get($this->by, $this->workspace)->about);
    }

    public function test_a_syllabus_is_a_file_of_the_course_or_pasted_text_and_nothing_else(): void
    {
        $this->assertSame('validation_failed', $this->refusalCode(fn () => $this->profiles->setSyllabus($this->by, $this->workspace, null, '  ')));

        $file = app(Files::class)->upload($this->by, 'workspace', $this->workspace, $this->temp("Week 1: Intro\n"), 'syllabus.txt');
        $this->profiles->setSyllabus($this->by, $this->workspace, $file->id, null);
        $this->assertSame(['file' => $file->id, 'text' => null], $this->profiles->syllabus($this->by, $this->workspace));

        // A pasted text replaces the file; both are cut at the limit.
        $this->profiles->setSyllabus($this->by, $this->workspace, null, str_repeat('x', CourseProfiles::MAX_SYLLABUS + 500));
        $syllabus = $this->profiles->syllabus($this->by, $this->workspace);
        $this->assertNull($syllabus['file']);
        $this->assertSame(CourseProfiles::MAX_SYLLABUS, mb_strlen((string) $syllabus['text']));

        // Another student's file, or a missing one, is not found.
        $bob = $this->principal($this->student());
        $theirs = app(Files::class)->upload($bob, 'workspace', app(Workspaces::class)->create($bob, ['name' => 'Chemistry'])->id, $this->temp("Secret\n"), 'theirs.txt');
        $this->expectNotFound(fn () => $this->profiles->setSyllabus($this->by, $this->workspace, $theirs->id, null));
    }

    public function test_another_students_course_is_not_found_and_a_deleted_course_takes_its_profile(): void
    {
        $this->profiles->save($this->by, $this->workspace, ['about' => 'Mine.']);
        $bob = $this->principal($this->student());

        $this->expectNotFound(fn () => $this->profiles->get($bob, $this->workspace));
        $this->expectNotFound(fn () => $this->profiles->save($bob, $this->workspace, ['about' => 'Hers.']));
        $this->expectNotFound(fn () => $this->profiles->addFromReading($bob, $this->workspace, [0]));

        app(Workspaces::class)->delete($this->by, $this->workspace);
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'course_profiles')->count());
    }

    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (Unprocessable $e) {
            return $e->details['fields'];
        }
        $this->fail('Expected a refusal.');
    }

    private function refusalCode(callable $call): string
    {
        try {
            $call();
        } catch (Unprocessable $e) {
            return $e->errorCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function expectNotFound(callable $call): void
    {
        try {
            $call();
        } catch (NotFound) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected not found.');
    }
}
