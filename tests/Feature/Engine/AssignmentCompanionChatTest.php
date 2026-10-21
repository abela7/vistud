<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Reply;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Tools\AssignmentPlanTool;
use App\Engine\Tools\Context;
use App\Engine\Usage;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Modules;
use App\Study\Plans;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class AssignmentCompanionChatTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspaceId;

    private string $moduleId;

    private ActivityDetails $assignment;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);

        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, [
            'tutor_model' => 'fake/tutor',
            'reader_model' => 'fake/quick',
            'fallback_model' => 'fake/plain',
            'consent' => true,
            'auto_read_files' => false,
        ]);

        $this->workspaceId = app(Workspaces::class)->create($this->by, ['name' => 'Computer Science'])->id;
        $this->moduleId = app(Modules::class)->create($this->by, $this->workspaceId, ['title' => 'Databases'])->id;
        $this->assignment = app(Activities::class)->create($this->by, $this->workspaceId, [
            'title' => 'Coursework 1: Relational Schema',
            'kind' => 'assignment',
            'module_id' => $this->moduleId,
            'due_on' => '2026-10-25',
            'due_time' => '17:00',
        ]);
    }

    private function chat(): SessionChat
    {
        return app(SessionChat::class);
    }

    public function test_a_turn_sends_companion_prompt_assignment_layer_and_only_assignment_tools(): void
    {
        // Add a source
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, [
            'kind' => 'brief',
            'title' => 'Brief doc',
            'text' => 'Design a 3NF schema for an airline.',
        ]);

        // Add an understanding
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $this->assignment->id,
            [
                'summary' => 'Airline schema design in 3NF.',
                'goal' => 'Create a normalized relational schema.',
                'confidence' => 'high',
                'deliverables' => [['text' => 'ER diagram', 'source' => 'S1']],
                'requirements' => [['text' => 'Must normalize to 3NF', 'source' => 'S1']],
                'constraints' => [],
                'criteria' => [['title' => '3NF normalization', 'weight' => '40%', 'source' => 'S1']],
                'teacher_expects' => [],
                'dates' => [],
                'modules' => [],
                'topics' => [],
                'best_practices' => [],
                'assumptions' => [],
                'risks' => [],
                'gaps' => [],
                'questions' => [['text' => 'Do we include views?']],
                'extra_sections' => [['title' => 'Data provided', 'items' => [['text' => 'Use airline.csv.', 'source' => 'S1']]]],
            ],
            changes: null,
            sources: ['S1' => ['type' => 'brief', 'id' => 'src-1', 'title' => 'Brief doc']],
            fingerprint: app(AssignmentSources::class)->fingerprint($this->by, $this->assignment->id),
            model: 'fake/tutor',
        );

        // Add a plan item
        app(Plans::class)->addPart($this->by, $this->assignment->id, 'Part 1: Schema Design', marks: 50);

        // Add a log entry
        app(AssignmentLog::class)->add($this->by, $this->assignment->id, 'understand', 'decision', 'Selected PostgreSQL.', byRole: 'student');

        $this->engine->will(Fake::says('I am ready to help you with your coursework.'));

        $reply = $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Hello companion');

        $this->assertSame('I am ready to help you with your coursework.', $reply->text);

        // Check thread isolation and purpose
        $thread = LearnerTables::query(Guard::learner($this->by), 'engine_threads')
            ->where('activity_id', $this->assignment->id)
            ->first();
        $this->assertNotNull($thread);
        $this->assertSame('assignment', $thread->purpose);

        // Check request sent to tutor model
        $req = $this->engine->last();
        $this->assertSame('fake/tutor', $req->model);

        // Companion prompt present
        $this->assertStringContainsStringIgnoringCase('You are the student\'s companion for this assignment', $req->system);
        $this->assertStringContainsString('never silently', $req->system);
        $this->assertStringContainsString('will do the work is a decision', $req->system);

        // Assignment layer present
        $this->assertStringContainsString('Coursework 1: Relational Schema', $req->system);
        $this->assertStringContainsString('Stage: understand', $req->system);
        $this->assertStringContainsString('Airline schema design in 3NF.', $req->system);
        $this->assertStringContainsString('Do we include views?', $req->system);
        $this->assertStringContainsString('Data provided:', $req->system);
        $this->assertStringContainsString('Use airline.csv. [S1]', $req->system);
        $this->assertStringContainsString('Part 1: Schema Design', $req->system);
        $this->assertStringContainsString('Selected PostgreSQL.', $req->system);

        // Tools offered: exactly ASSIGNMENT_TOOLS
        $toolNames = array_map(fn ($t) => $t['function']['name'], $req->tools);
        sort($toolNames);
        $expected = SessionChat::ASSIGNMENT_TOOLS;
        sort($expected);
        $this->assertSame($expected, $toolNames);
    }

    public function test_assignment_source_tool_reads_source_text(): void
    {
        $source = app(AssignmentSources::class)->add($this->by, $this->assignment->id, [
            'kind' => 'brief',
            'title' => 'Project Brief',
            'text' => 'Complete parts A and B.',
        ]);

        $this->engine->will(
            Fake::calls('assignment_source', ['source' => 'S1'], 'call_src'),
            function (Request $request) {
                $toolMsg = $request->messages[array_key_last($request->messages)];
                $this->assertSame('tool', $toolMsg['role']);
                $this->assertStringContainsString('Project Brief', $toolMsg['content']);
                $this->assertStringContainsString('Complete parts A and B.', $toolMsg['content']);

                return Fake::says('The brief says to complete parts A and B.');
            }
        );

        $reply = $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'What does source S1 say?');
        $this->assertStringContainsString('The brief says', $reply->text);
    }

    public function test_add_assignment_source_tool_requires_agreement_then_adds_source(): void
    {
        $this->engine->will(
            Fake::calls('add_assignment_source', [
                'kind' => 'feedback',
                'title' => 'Teacher note on ERD',
                'text' => 'The teacher said Chen notation is fine.',
            ], 'call_add_src'),
            function (Request $request) {
                $lastMsg = $request->messages[array_key_last($request->messages)];
                $this->assertSame('tool', $lastMsg['role']);
                $this->assertStringContainsString('Not done yet', $lastMsg['content']);
                $this->assertStringContainsString('Keep "Teacher note on ERD" as a source', $lastMsg['content']);

                return Fake::says('Should I keep this note as a source for your assignment?');
            }
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'The teacher said Chen notation is fine.');
        $sources = app(AssignmentSources::class)->list($this->by, $this->assignment->id);
        $this->assertCount(0, $sources);

        // Now student agrees
        $this->engine->will(
            Fake::calls('add_assignment_source', [
                'kind' => 'feedback',
                'title' => 'Teacher note on ERD',
                'text' => 'The teacher said Chen notation is fine.',
            ], 'call_add_src_confirmed'),
            Fake::says('I have recorded it as a source and started re-reading the assignment.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Yes, keep it.');
        $sources = app(AssignmentSources::class)->list($this->by, $this->assignment->id);
        $this->assertCount(1, $sources);
        $this->assertSame('Teacher note on ERD', $sources[0]->title);
    }

    public function test_log_progress_tool_writes_log_and_reports_effects(): void
    {
        $this->engine->will(
            Fake::calls('log_progress', [
                'stage' => 'plan',
                'kind' => 'decision',
                'text' => 'Selected BCNF over 3NF.',
            ], 'call_log'),
            Fake::says('Logged your decision.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'We decided to go with BCNF.');

        $logs = app(AssignmentLog::class)->list($this->by, $this->assignment->id);
        $this->assertCount(1, $logs);
        $this->assertSame('Selected BCNF over 3NF.', $logs[0]->text);
        $this->assertSame('tutor', $logs[0]->by);

        // Check effects in transcript
        $turns = $this->chat()->assignmentTranscript($this->by, $this->assignment->id);
        $lastTurn = end($turns);
        $this->assertNotEmpty($lastTurn['log_entries']);
        $this->assertSame('Selected BCNF over 3NF.', $lastTurn['log_entries'][0]['text']);
    }

    public function test_set_stage_tool_requires_agreement_then_updates_stage(): void
    {
        $this->engine->will(
            Fake::calls('set_stage', ['stage' => 'plan'], 'call_stage'),
            function (Request $request) {
                $lastMsg = $request->messages[array_key_last($request->messages)];
                $this->assertStringContainsString('Not done yet', $lastMsg['content']);
                $this->assertStringContainsString('Move the assignment to the "plan" stage?', $lastMsg['content']);

                return Fake::says('Shall we move to the plan stage?');
            }
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'I understand it now. Let\'s plan.');
        $this->assertSame('understand', app(Activities::class)->find($this->by, $this->assignment->id)->stage);

        // Student agrees
        $this->engine->will(
            Fake::calls('set_stage', ['stage' => 'plan'], 'call_stage_confirmed'),
            Fake::says('Moved to plan stage.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Yes, let\'s move to plan.');
        $this->assertSame('plan', app(Activities::class)->find($this->by, $this->assignment->id)->stage);
    }

    public function test_out_of_date_and_no_understanding_cases_inform_tutor(): void
    {
        // 1. No understanding
        $this->engine->will(Fake::says('You have not added what you were given yet.'));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'What is this assignment?');
        $this->assertStringContainsString('There is no stored understanding of this assignment yet', $this->engine->last()->system);

        // 2. Out of date understanding
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, [
            'kind' => 'brief',
            'title' => 'Brief v1',
            'text' => 'Original brief.',
        ]);
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $this->assignment->id,
            ['summary' => 'V1', 'confidence' => 'low'],
            changes: null,
            sources: ['S1' => ['type' => 'brief', 'id' => '1', 'title' => 'Brief v1']],
            fingerprint: 'old-fingerprint',
            model: 'fake/tutor',
        );

        $this->engine->will(Fake::says('The picture is out of date.'));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Tell me about the brief.');
        $this->assertStringContainsString('OUT OF DATE', $this->engine->last()->system);
        $this->assertStringContainsString('Read again', $this->engine->last()->system);
    }

    public function test_source_labels_stay_the_ones_the_understanding_cites(): void
    {
        $sources = app(AssignmentSources::class);
        $brief = $sources->add($this->by, $this->assignment->id, ['kind' => 'brief', 'title' => 'Brief doc', 'text' => 'Design the schema.']);
        $rubric = $sources->add($this->by, $this->assignment->id, ['kind' => 'rubric', 'title' => 'Rubric doc', 'text' => 'Normalization is 40 marks.']);
        // Read when the rubric was the only source: it is S1, the brief (added to the list earlier) came second.
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $this->assignment->id,
            [
                'summary' => 'A schema.',
                'confidence' => 'high',
                'criteria' => [['criterion' => 'Normalization', 'weight' => 40, 'looks_like' => 'Every table in 3NF', 'source' => 'S1']],
                'dates' => [['what' => 'Draft due', 'on' => '2026-10-20', 'source' => 'S2']],
            ],
            changes: null,
            sources: [
                'S1' => ['type' => 'source', 'id' => $rubric->id, 'title' => 'Rubric doc'],
                'S2' => ['type' => 'source', 'id' => $brief->id, 'title' => 'Brief doc'],
            ],
            fingerprint: $sources->fingerprint($this->by, $this->assignment->id),
            model: 'fake/tutor',
        );
        $sources->add($this->by, $this->assignment->id, ['kind' => 'email', 'title' => 'Late email', 'text' => 'Use PostgreSQL.']);

        $this->engine->will(
            Fake::calls('assignment_source', ['source' => 'S1'], 'call_src'),
            function (Request $request) {
                $this->assertStringContainsString('Normalization is 40 marks.', $request->messages[array_key_last($request->messages)]['content']);

                return Fake::says('S1 is the rubric.');
            }
        );
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'What is S1?');

        $system = $this->engine->last()->system;
        $this->assertMatchesRegularExpression('/- S1 · [^\n]*“Rubric doc”/', $system);
        $this->assertMatchesRegularExpression('/- S2 · [^\n]*“Brief doc”/', $system);
        $this->assertMatchesRegularExpression('/- S3 · [^\n]*“Late email”[^\n]*new: not in the understanding yet/', $system);
        $this->assertStringContainsString('- Normalization (40%) — full marks: Every table in 3NF [S1]', $system);
        $this->assertStringContainsString('- Draft due: 2026-10-20 [S2]', $system);
    }

    public function test_another_student_cannot_access_or_write_assignment_chat(): void
    {
        $other = $this->principal($this->student());

        $this->expectException(NotFound::class);
        $this->chat()->sendOnAssignment($other, $this->assignment->id, 'Hello?');
    }

    public function test_folding_works_on_long_assignment_chat(): void
    {
        config(['vistud.engine.fold_at' => 900, 'vistud.engine.keep_recent' => 2]);
        $long = str_repeat('Database normalization ensures minimal redundancy. ', 8);

        $this->engine->will(Fake::says($long), Fake::says($long));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Turn 1');
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Turn 2');

        // Third turn triggers fold with quick model
        $this->engine->will(
            Fake::says($long),
            function (Request $request) {
                $this->assertSame('fake/quick', $request->model);
                $this->assertStringContainsString('Student: Turn 1', $request->messages[0]['content']);
                $this->assertStringContainsString('Student: Turn 2', $request->messages[0]['content']);
                $this->assertStringNotContainsString('Student: Turn 3', $request->messages[0]['content']);

                return Fake::says('The student and tutor discussed normalization fundamentals.', 200, 'fake/quick');
            }
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Turn 3');

        $this->engine->will(Fake::says('Turn 4 response.'));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Turn 4');

        $last = $this->engine->last();
        $this->assertStringContainsString('## Earlier in this chat', $last->system);
        $this->assertStringContainsString('discussed normalization fundamentals', $last->system);

        $turns = $this->chat()->assignmentTranscript($this->by, $this->assignment->id);
        $this->assertTrue($turns[0]['folded']);
        $this->assertTrue($turns[1]['folded']);
    }

    public function test_clearing_or_deleting_the_chat_removes_its_turns_but_keeps_what_it_cost(): void
    {
        $scope = Guard::learner($this->by);
        $month = fn () => app(Usage::class)->monthTotal($scope, 'UTC');
        $this->engine->will(Fake::says('Hi.', 5_000));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Hello');
        $spent = $month();
        $this->assertGreaterThan(0, $spent);

        $this->chat()->clearAssignmentChat($this->by, $this->assignment->id);
        $this->assertSame([], $this->chat()->assignmentTranscript($this->by, $this->assignment->id));
        $this->assertSame($spent, $month());

        $this->engine->will(Fake::says('Again.', 5_000));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Hello again');
        $spent = $month();
        app(Activities::class)->delete($this->by, $this->assignment->id);
        $this->assertSame(0, LearnerTables::query($scope, 'engine_messages')->count());
        $this->assertSame(0, LearnerTables::query($scope, 'engine_threads')->count());
        $this->assertSame($spent, $month());
    }

    public function test_a_file_the_ai_could_not_read_yet_makes_its_understanding_out_of_date_once_it_can_be(): void
    {
        $sources = app(AssignmentSources::class);
        $keep = fn (array $file) => app(AssignmentUnderstandings::class)->keep(
            $this->by, $this->assignment->id, ['summary' => 'A schema.', 'confidence' => 'low'], null, ['F1' => $file],
            $sources->fingerprint($this->by, $this->assignment->id), 'fake/tutor',
        );
        $file = ['type' => 'file', 'id' => '01a00000-0000-7000-8000-000000000001', 'title' => 'brief.pdf'];

        $this->assertFalse($sources->outOfDate($this->by, $this->assignment->id, $keep($file)));
        // Unread while converting, and no longer converting (here: gone): read it again.
        $this->assertTrue($sources->outOfDate($this->by, $this->assignment->id, $keep($file + ['unread' => true])));
    }

    public function test_a_full_log_keeps_the_newest_entries(): void
    {
        $log = app(AssignmentLog::class);
        for ($i = 1; $i <= AssignmentLog::MAX_ENTRIES + 1; $i++) {
            $this->travel(1)->seconds();
            $log->add($this->by, $this->assignment->id, 'do', 'progress', "Entry {$i}");
        }

        $this->assertCount(AssignmentLog::MAX_ENTRIES, $log->ids($this->by, $this->assignment->id));
        $newest = $log->list($this->by, $this->assignment->id, limit: AssignmentLog::MAX_ENTRIES);
        $this->assertSame('Entry '.(AssignmentLog::MAX_ENTRIES + 1), $newest[0]->text);
        $this->assertSame('Entry 2', end($newest)->text);
    }

    public function test_the_tutor_logs_in_the_current_stage_when_it_names_none(): void
    {
        app(Activities::class)->setStage($this->by, $this->assignment->id, 'plan');
        $this->engine->will(Fake::calls('log_progress', ['kind' => 'decision', 'text' => 'Chose PostgreSQL.']), Fake::says('Logged.'));

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'We go with PostgreSQL.');

        $this->assertSame('plan', app(AssignmentLog::class)->list($this->by, $this->assignment->id)[0]->stage);
    }

    public function test_a_study_session_on_a_linked_topic_hears_what_the_assignment_asks(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspaceId, 'Normalization', $this->moduleId);
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $this->assignment->id,
            [
                'summary' => 'Design an airline schema in 3NF.',
                'confidence' => 'high',
                'requirements' => [['text' => 'Normalize every table to 3NF', 'source' => 'S1']],
                'criteria' => [['criterion' => 'Normalization', 'weight' => 40, 'source' => 'S1']],
                'topics' => [['topic' => 'Normalization', 'id' => $topic->id, 'why' => 'Part 2 is graded on 3NF', 'source' => 'S1']],
            ],
            changes: null,
            sources: [],
            fingerprint: 'any',
            model: 'fake/tutor',
        );
        $session = app(Sessions::class)->start($this->by, $this->workspaceId, $topic->id, $this->moduleId);

        $system = app(Stack::class)->build($this->by, $session, null, true)->system;

        $this->assertStringContainsString('## Assignments and exams', $system);
        $this->assertStringContainsString('- Coursework 1: Relational Schema (Assignment): Due 25 Oct, 17:00, 12 days left; stage: understand', $system);
        $this->assertStringContainsString("Coursework 1: Relational Schema, because it draws on the session's topic, Normalization: Part 2 is graded on 3NF.", $system);
        $this->assertStringContainsString('- Must: Normalize every table to 3NF [S1]', $system);
        $this->assertStringContainsString('- Marked on: Normalization (40%) [S1]', $system);
        $this->assertStringContainsString('On Coursework 1 you are asked to', $system);
    }

    public function test_a_session_unrelated_to_an_assignment_hears_only_its_deadline(): void
    {
        $other = app(Modules::class)->create($this->by, $this->workspaceId, ['title' => 'Networks'])->id;
        $session = app(Sessions::class)->start($this->by, $this->workspaceId, null, $other);

        $system = app(Stack::class)->build($this->by, $session, null, true)->system;

        $this->assertStringContainsString('- Coursework 1: Relational Schema (Assignment):', $system);
        $this->assertStringNotContainsString('What this study feeds', $system);
    }

    public function test_confirm_assignment_understanding_tool_confirms_latest_and_moves_to_plan(): void
    {
        $sources = app(AssignmentSources::class);
        $sources->add($this->by, $this->assignment->id, [
            'kind' => 'brief',
            'title' => 'Brief doc',
            'text' => 'Design a 3NF schema.',
        ]);
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $this->assignment->id,
            ['summary' => 'Airline 3NF schema.', 'confidence' => 'high'],
            changes: null,
            sources: ['S1' => ['type' => 'source', 'id' => '1', 'title' => 'Brief doc']],
            fingerprint: $sources->fingerprint($this->by, $this->assignment->id),
            model: 'fake/tutor',
        );

        $this->engine->will(
            Fake::calls('confirm_assignment_understanding', ['move_to_plan' => true], 'call_confirm'),
            Fake::says('Confirmed your understanding and moved to planning.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'This understanding is right.');

        $latest = app(AssignmentUnderstandings::class)->latest($this->by, $this->assignment->id);
        $this->assertNotNull($latest);
        $this->assertTrue($latest->confirmed());
        $this->assertSame('plan', app(Activities::class)->find($this->by, $this->assignment->id)->stage);

        $turns = $this->chat()->assignmentTranscript($this->by, $this->assignment->id);
        $lastTurn = end($turns);
        $this->assertNotEmpty($lastTurn['understandings_confirmed']);
        $this->assertSame(1, $lastTurn['understandings_confirmed'][0]['revision']);
    }

    public function test_save_assignment_plan_tool_requires_agreement_then_saves_sections_steps_criteria_and_tracking(): void
    {
        $planInput = [
            'tracking' => 'both',
            'replace' => true,
            'sections' => [
                [
                    'title' => 'Part 1: Conceptual ERD',
                    'marks' => 40,
                    'steps' => ['Identify entities and keys', 'Draw Chen ERD'],
                ],
                [
                    'title' => 'Part 2: 3NF Relations',
                    'marks' => 60,
                    'steps' => ['Write functional dependencies', 'Normalize to 3NF'],
                ],
            ],
            'criteria' => [
                ['title' => 'Correct 3NF decomposition', 'marks' => 60],
            ],
        ];

        $this->engine->will(
            Fake::calls('save_assignment_plan', $planInput, 'call_plan_1'),
            function (Request $request) {
                $lastMsg = $request->messages[array_key_last($request->messages)];
                $this->assertStringContainsString('Not done yet', $lastMsg['content']);
                $this->assertStringContainsString('both plan steps and marking criteria', $lastMsg['content']);

                return Fake::says('Here is the proposed plan and tracking method. Shall I save it?');
            }
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Let\'s build the plan.');
        $this->assertTrue(app(Plans::class)->get($this->by, $this->assignment->id)->empty());

        $this->engine->will(
            Fake::calls('save_assignment_plan', $planInput, 'call_plan_2'),
            Fake::says('Saved your plan and tracking method.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Yes, save that plan.');

        $plan = app(Plans::class)->get($this->by, $this->assignment->id);
        $this->assertCount(2, $plan->parts());
        $this->assertCount(2, $plan->steps($plan->parts()[0]->id));
        $this->assertCount(1, $plan->criteria());
        $this->assertSame('do', app(Activities::class)->find($this->by, $this->assignment->id)->stage);

        $logs = app(AssignmentLog::class)->list($this->by, $this->assignment->id);
        $this->assertStringContainsString('Tracking method: plan steps and marking criteria', $logs[0]->text);
    }

    public function test_update_assignment_plan_item_tool_marks_steps_and_criteria_as_work_progresses(): void
    {
        $plans = app(Plans::class);
        $part = $plans->addPart($this->by, $this->assignment->id, 'Part 1: Conceptual ERD', 40);
        $step = $plans->addStep($this->by, $this->assignment->id, 'Identify entities and keys', $part->id);
        $crit = $plans->addCriterion($this->by, $this->assignment->id, 'Correct 3NF decomposition', 60);

        $this->engine->will(
            Fake::calls('update_assignment_plan_item', [
                'item' => 'Identify entities',
                'state' => 'done',
            ], 'call_step_done'),
            Fake::calls('update_assignment_plan_item', [
                'item' => 'Correct 3NF',
                'state' => 'partly',
            ], 'call_crit_partly'),
            Fake::says('Marked that step as done and the 3NF criterion as partly met.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'I finished identifying the entities and keys.');

        $updatedPlan = $plans->get($this->by, $this->assignment->id);
        $this->assertSame('done', $updatedPlan->item($step->id)?->state);
        $this->assertSame('partly', $updatedPlan->item($crit->id)?->state);
        $this->assertSame('doing', app(Activities::class)->find($this->by, $this->assignment->id)->status);
    }

    public function test_write_note_and_persistent_memory_survive_clearing_the_assignment_chat(): void
    {
        $plans = app(Plans::class);
        $part = $plans->addPart($this->by, $this->assignment->id, 'Part 1: Schema', 50);
        $step1 = $plans->addStep($this->by, $this->assignment->id, 'Identify entities', $part->id);
        $plans->addStep($this->by, $this->assignment->id, 'Write SQL DDL', $part->id);
        $plans->setState($this->by, $step1->id, 'done');

        $this->engine->will(
            Fake::calls('write_note', [
                'title' => 'Schema Draft Notes',
                'text' => "## Entities\n\n- Flight\n- Passenger",
            ], 'call_write_note'),
            Fake::says('Saved your schema draft notes.')
        );

        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Save these entities in a note.');

        // Clear the chat completely
        $this->chat()->clearAssignmentChat($this->by, $this->assignment->id);
        $this->assertSame([], $this->chat()->assignmentTranscript($this->by, $this->assignment->id));

        // Start a brand-new conversation on the same assignment: standing context still has the step states and saved note
        $this->engine->will(Fake::says('Picking up right where we left off on Write SQL DDL.'));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Where are we?');

        $system = $this->engine->last()->system;
        $this->assertStringContainsString('[done] Identify entities', $system);
        $this->assertStringContainsString('[to do] Write SQL DDL', $system);
        $this->assertStringContainsString('“Schema Draft Notes”', $system);
    }

    public function test_study_session_hears_unassigned_open_assignment_and_saves_notes_to_it(): void
    {
        // Student creates an assignment before linking it to any module or topic
        $earlyAssignment = app(Activities::class)->create($this->by, $this->workspaceId, [
            'title' => 'Final Capstone Project',
            'kind' => 'project',
            'module_id' => null,
            'due_on' => '2026-11-15',
        ]);
        app(AssignmentUnderstandings::class)->keep(
            $this->by,
            $earlyAssignment->id,
            [
                'summary' => 'Build a distributed database prototype.',
                'confidence' => 'high',
                'requirements' => [['text' => 'Include replication and ACID transactions', 'source' => 'S1']],
            ],
            changes: null,
            sources: [],
            fingerprint: 'fp-early',
            model: 'fake/tutor',
        );

        $session = app(Sessions::class)->start($this->by, $this->workspaceId, null, $this->moduleId);
        $system = app(Stack::class)->build($this->by, $session, null, true)->system;

        // The study session tutor hears about the unassigned open assignment and its requirements
        $this->assertStringContainsString('Final Capstone Project, because it is an open course assignment not tied to a single module yet.', $system);
        $this->assertStringContainsString('Include replication and ACID transactions', $system);
        $this->assertStringContainsString('save_to_assignment', $system);

        // And the study session tutor can save useful study notes directly into the assignment's folder and log
        $this->engine->will(
            Fake::calls('save_to_assignment', [
                'assignment' => 'Final Capstone Project',
                'title' => 'ACID & Replication Notes',
                'text' => "## Key points for Capstone\n\n- Use write-ahead logging for durability.",
            ], 'call_save_to_assign'),
            Fake::says('Saved those notes directly to your Final Capstone Project.')
        );

        $this->chat()->send($this->by, $session->id, 'Save these ACID notes to my capstone project.');

        $companionSystem = app(Stack::class)->forAssignment(
            $this->by,
            app(Activities::class)->find($this->by, $earlyAssignment->id),
            null,
            true
        )->system;

        $this->assertStringContainsString('“ACID & Replication Notes”', $companionSystem);
        $this->assertStringContainsString('Saved study note "ACID & Replication Notes" to this assignment.', $companionSystem);
    }

    public function test_save_assignment_plan_tool_supports_pre_made_starters(): void
    {
        $companionSystem = app(Stack::class)->forAssignment($this->by, $this->assignment, null, true)->system;
        $this->assertStringContainsString('Pre-made plan starters available', $companionSystem);
        $this->assertStringContainsString('coding (Coding project: Plan → Build → Test → Hand in)', $companionSystem);

        $input = [
            'tracking' => 'steps',
            'starter' => 'coding',
        ];

        $this->engine->will(
            Fake::calls('save_assignment_plan', $input, 'call_starter_ask'),
            Fake::says('Should I start your plan from the Coding project template and track by plan steps?')
        );
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Use the coding project starter.');
        $this->assertTrue(app(Plans::class)->get($this->by, $this->assignment->id)->empty());

        $this->engine->will(
            Fake::calls('save_assignment_plan', $input, 'call_starter_ok'),
            Fake::says('Saved the Coding project plan.')
        );
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Yes, apply it.');

        $plan = app(Plans::class)->get($this->by, $this->assignment->id);
        $this->assertSame(['Plan', 'Build', 'Test', 'Hand in'], array_map(fn ($p) => $p->title, $plan->parts()));
        $this->assertSame('do', app(Activities::class)->find($this->by, $this->assignment->id)->stage);
    }

    /**
     * A research paper spans months and the student changes model. The next model is told the stage and the
     * plan as they are stored now. It is not told the previous model's private reasoning, and it is not told
     * the whole history: the standing layer keeps fifteen log lines and one level of plan steps.
     */
    public function test_a_later_model_is_told_the_stored_stage_and_plan_after_months_of_work(): void
    {
        $plans = app(Plans::class);
        $activities = app(Activities::class);
        $log = app(AssignmentLog::class);

        $part = $plans->addPart($this->by, $this->assignment->id, 'Literature review');
        $search = $plans->addStep($this->by, $this->assignment->id, 'Search CINAHL', $part->id);
        $screen = $plans->addStep($this->by, $this->assignment->id, 'Screen the 2024 reviews', $search->id);
        $appraise = $plans->addStep($this->by, $this->assignment->id, 'Appraise the rest', $search->id);
        $plans->addCriterion($this->by, $this->assignment->id, 'Critical appraisal', 20);
        $plans->addMilestone($this->by, $this->assignment->id, 'Ethics approval', '2026-12-01');

        $activities->setStage($this->by, $this->assignment->id, 'do');
        $plans->setState($this->by, $screen->id, 'done');
        $plans->setState($this->by, $appraise->id, 'todo');

        for ($i = 1; $i <= 20; $i++) {
            $this->travel(10)->days();
            $log->add($this->by, $this->assignment->id, 'do', 'decision', "Decision {$i}: kept the research question.");
        }

        $this->engine->will(new Reply(
            'We are still planning the search.',
            model: 'fake/tutor',
            reasoning: [['type' => 'reasoning.text', 'text' => 'SECRET-REASONING-MODEL-A']],
        ));
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'Where are we?', model: 'fake/tutor');

        $activities->setStage($this->by, $this->assignment->id, 'review');
        $activities->setStatus($this->by, $this->assignment->id, 'done');

        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/other', $request->model);
            // Marked done moves the stage to submit (Activities::setStatus): never "review, done".
            $this->assertStringContainsString('Stage: submit', $request->system);
            $this->assertStringContainsString('Status: done', $request->system);
            $this->assertStringContainsString('Literature review: in progress', $request->system);
            $this->assertStringContainsString('[in progress] Search CINAHL', $request->system);
            $this->assertStringContainsString('[not yet] Critical appraisal', $request->system);
            $this->assertStringContainsString('Ethics approval', $request->system);
            $this->assertStringContainsString('Decision 20: kept the research question.', $request->system);
            $this->assertStringContainsString('Decision 6: kept the research question.', $request->system);
            $this->assertStringNotContainsString('Decision 5: kept the research question.', $request->system);
            // Every level of steps is in the layer now, and the log says how to read further back.
            $this->assertStringContainsString('    - [done] Screen the 2024 reviews', $request->system);
            $this->assertStringContainsString('    - [to do] Appraise the rest', $request->system);
            $this->assertStringContainsString('log_page 2', $request->system);
            $this->assertStringContainsString('the chat is older: go by this', $request->system);

            $spoken = json_encode($request->messages);
            $this->assertStringContainsString('We are still planning the search.', $spoken);
            $this->assertStringNotContainsString('SECRET-REASONING-MODEL-A', $spoken);

            return Fake::says('The paper is in review.', model: 'fake/other');
        });
        $this->chat()->sendOnAssignment($this->by, $this->assignment->id, 'New model. What stage is this paper in, and what is still open?', model: 'fake/other');

        $fresh = $activities->find($this->by, $this->assignment->id);
        $this->assertSame('submit', $fresh->stage);
        $this->assertSame('done', $fresh->status);

        $tool = app(AssignmentPlanTool::class)->run(
            $this->by,
            new Context($this->workspaceId, $this->moduleId, null, 'UTC', activityId: $this->assignment->id),
            ['assignment' => ''],
        );
        $this->assertStringContainsString('Screen the 2024 reviews', $tool);
        $this->assertStringContainsString('Appraise the rest', $tool);
    }
}
