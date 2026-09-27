<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Briefings;
use App\Study\Findings;
use App\Study\Instructions;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A study session's briefing, and the teaching choices behind it (docs/specs/study-memory.md §4.3). */
class BriefingsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases', 'code' => 'CS204']);
        $this->week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1: Relational model']);
    }

    public function test_the_prompt_is_filled_with_the_teaching_choices_and_its_comment_is_left_out(): void
    {
        $prompt = Tutoring::prompt(['method' => 'socratic', 'check_ins' => 'end', 'quiz' => 'exam', 'pace' => 'section']);

        $this->assertStringStartsWith('# You are the student\'s tutor', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringNotContainsString('Built from', $prompt);
        foreach (['method' => 'socratic', 'check_ins' => 'end', 'quiz' => 'exam', 'pace' => 'section'] as $key => $value) {
            $this->assertStringContainsString(Tutoring::CHOICES[$key][$value][1], $prompt);
        }
        // A clear role and path: who is who, what wins, the steps, the student's words, the unclear moments.
        $this->assertSeeInOrderText(['## Your role', '## Words used here', '## When instructions clash', '## How to teach in this session', '## How to use the briefing',
            '## The path of a session', '1. **Open**', '2. **Teach**', '3. **Checkpoint**', '4. **Close**',
            '## Words the student can use', '**next**', '**save that**', '**wrap up**', '## When things are unclear', '## Always', '## Marks', 'not in a code block', '## Ending'], $prompt);
        // The marks ViStud reads back are in the prompt.
        foreach (['<finding topic=', '<question topic=', '<flashcard topic=', '<attempt topic=', '<asked>', '<answer>', '<checkpoint>', '<summary>', '<status topic='] as $mark) {
            $this->assertStringContainsString($mark, $prompt);
        }

        $this->assertSame(Tutoring::DEFAULTS, Tutoring::validated([]));
        $this->assertThrows(fn () => Tutoring::validated(['method' => 'lecture']), Unprocessable::class);
        $this->assertSame('Socratic · a quiz at the end · exam level questions · a section at a time', Tutoring::summary(['method' => 'socratic', 'check_ins' => 'end', 'quiz' => 'exam', 'pace' => 'section']));
    }

    public function test_the_briefing_says_who_the_student_is_where_they_stand_and_what_this_session_is(): void
    {
        $topics = app(Topics::class);
        $joins = $topics->create($this->by, $this->databases->id, 'Joins', $this->week1->id);
        $keys = $topics->create($this->by, $this->databases->id, 'Primary and foreign keys', $this->week1->id);
        $normal = $topics->create($this->by, $this->databases->id, 'Normalisation');
        $topics->report($this->by, $joins->id, 'understood');
        $topics->report($this->by, $keys->id, 'confused');
        $note = $this->note('Lecture 3: joins', [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Left join']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Keeps every row of the '], ['type' => 'text', 'text' => 'left', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' table.']]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Unmatched rows get NULLs']]]]],
            ]],
        ]);
        app(Findings::class)->add($this->by, $joins->id, ['text' => 'A left join keeps every row of the left table.', 'source' => "note:{$note->id}", 'locator' => 'slide 12']);
        app(Findings::class)->add($this->by, $normal->id, ['text' => '3NF: no non-key column depends on another non-key column.']);
        $question = app(Questions::class)->ask($this->by, $this->databases->id, 'Why does a left join keep unmatched rows?', $joins->id);
        app(Questions::class)->setAskTeacher($this->by, $question->id, true);
        app(Activities::class)->create($this->by, $this->databases->id, ['kind' => 'assignment', 'title' => 'ER diagram', 'due_on' => '2026-10-07', 'module_id' => $this->week1->id]);
        app(Activities::class)->create($this->by, $this->databases->id, ['kind' => 'exam', 'title' => 'Final exam', 'due_on' => '2026-12-15']);
        app(Instructions::class)->set($this->by, 'me', 'Second-year student. Use everyday examples.');
        app(Instructions::class)->set($this->by, "workspace:{$this->databases->id}", 'Use PostgreSQL syntax.');
        $sessions = app(Sessions::class);
        $sessions->log($this->by, $this->databases->id, ['date' => '2026-10-04', 'time' => '14:00', 'minutes' => 90, 'topic_id' => $normal->id], 'UTC');
        $session = $sessions->start($this->by, $this->databases->id, $joins->id, null,
            ['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4, 'auto' => true],
            ['method' => 'socratic', 'check_ins' => 'section', 'quiz' => 'exam', 'pace' => 'slide']);
        $sessions->toggleMaterial($this->by, $session->id, "note:{$note->id}");

        $briefing = app(Briefings::class)->forSession($this->by, $session->id);
        $text = $briefing->markdown;

        $this->assertSame('Joins · Databases', $briefing->title);
        $this->assertFalse($briefing->trimmed);
        $this->assertStringContainsString(Tutoring::CHOICES['method']['socratic'][1], $text);
        $this->assertStringNotContainsString($this->ada->name, $text);
        $this->assertStringNotContainsString($this->ada->email, $text);
        $order = [
            '# You are the student\'s tutor', '# Briefing',
            '## About the student', 'Second-year student. Use everyday examples.',
            '## The course: Databases', 'Course code CS204', 'Use PostgreSQL syntax.',
            '## The module: Week 1: Relational model', 'No instructions for this module.',
            '## This session', '- Started: Mon 5 Oct 2026, 09:00', '- Topic: Joins. The student says: understood. Evidence: no contact yet · not practised yet.',
            '- Clock: Pomodoro, 25-minute focus periods with 5-minute breaks (15 minutes after every 4).',
            '- Teaching: Socratic · checks after every section · exam level questions · one slide at a time.',
            '## What the student has recorded about Joins', '- A left join keeps every row of the left table. (from Lecture 3: joins, slide 12)',
            '## Still confusing', '- Primary and foreign keys (Week 1: Relational model)',
            '## Open questions', '- Why does a left join keep unmatched rows? (Joins; for the teacher)',
            '## Due soon', '- ER diagram (assignment; Week 1: Relational model): due Wed 7 Oct',
            '## Earlier sessions', '- Sun 4 Oct: Normalisation, 1 h 30 min',
            '## Material for this session', '- The student\'s note "Lecture 3: joins": its text follows.',
            '## All topics in this course', '### Week 1: Relational model', '- Joins: understood (evidence: no contact yet · not practised yet)', '### Not in a module', '- Normalisation: not started',
            '## Other things the student has recorded', '- Normalisation: 3NF: no non-key column depends on another non-key column.',
            '## The student\'s note: Lecture 3: joins', '## Left join', 'Keeps every row of the left table.', '- Unmatched rows get NULLs',
        ];
        $this->assertSeeInOrderText($order, $text);
        // Not due within two weeks.
        $this->assertStringNotContainsString('Final exam', $text);
    }

    public function test_with_no_material_chosen_the_briefing_lists_what_the_module_holds(): void
    {
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins', $this->week1->id);
        $this->note('Lecture 3: joins', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Private words']]]]);
        $session = app(Sessions::class)->start($this->by, $this->databases->id, $joins->id);

        $text = app(Briefings::class)->forSession($this->by, $session->id)->markdown;

        $this->assertSeeInOrderText(['## Material in ViStud', 'Nothing chosen for this session. In Week 1: Relational model', '- Note: Lecture 3: joins'], $text);
        $this->assertStringNotContainsString('Private words', $text);
        $this->assertStringContainsString(Tutoring::CHOICES['method']['explain'][1], $text);
        $this->assertStringContainsString('- Clock: free.', $text);
    }

    public function test_a_large_course_is_cut_to_the_budget_and_says_what_was_left_out(): void
    {
        $topics = app(Topics::class);
        for ($i = 1; $i <= 700; $i++) {
            $topics->create($this->by, $this->databases->id, "Topic number {$i} with a fairly long name to fill the briefing up quickly");
        }
        $long = str_repeat('A long paragraph of lecture notes that goes on and on. ', 400);
        $note = $this->note('Huge note', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $long]]]]);
        $session = app(Sessions::class)->start($this->by, $this->databases->id);
        app(Sessions::class)->toggleMaterial($this->by, $session->id, "note:{$note->id}");

        $briefing = app(Briefings::class)->forSession($this->by, $session->id);

        $this->assertTrue($briefing->trimmed);
        $this->assertLessThanOrEqual(Briefings::BUDGET, $briefing->characters());
        $this->assertMatchesRegularExpression('/- … and \d+ more, left out to keep this briefing short\./', $briefing->markdown);
        // The session and the prompt are always whole.
        $this->assertStringContainsString('## This session', $briefing->markdown);
        $this->assertStringContainsString('## Ending', $briefing->markdown);
        $this->assertStringContainsString('- Topic: none chosen.', $briefing->markdown);
    }

    public function test_teaching_and_material_change_during_a_session_and_come_back_next_time(): void
    {
        $sessions = app(Sessions::class);
        $session = $sessions->start($this->by, $this->databases->id, tutoring: ['method' => 'steps']);
        $this->assertSame(['method' => 'steps'] + Tutoring::DEFAULTS, $sessions->find($this->by, $session->id)->tutoring);

        $sessions->setTutoring($this->by, $session->id, ['method' => 'summary', 'check_ins' => 'none', 'quiz' => 'easy', 'pace' => 'section']);
        $this->assertSame('summary', $sessions->find($this->by, $session->id)->tutoring['method']);
        $this->assertThrows(fn () => $sessions->setTutoring($this->by, $session->id, ['pace' => 'fast']), Unprocessable::class);

        $note = $this->note('Lecture 3', []);
        $sessions->toggleMaterial($this->by, $session->id, "note:{$note->id}");
        $this->assertTrue($sessions->find($this->by, $session->id)->uses("note:{$note->id}"));
        $sessions->toggleMaterial($this->by, $session->id, "note:{$note->id}");
        $this->assertSame([], $sessions->find($this->by, $session->id)->material);
        $this->assertThrows(fn () => $sessions->toggleMaterial($this->by, $session->id, 'note:missing'), NotFound::class);
        $this->assertThrows(fn () => $sessions->toggleMaterial($this->by, $session->id, 'topic:x'), NotFound::class);

        $sessions->end($this->by, $session->id);
        $this->assertSame(['pomodoro' => null, 'tutoring' => ['method' => 'summary', 'check_ins' => 'none', 'quiz' => 'easy', 'pace' => 'section']], $sessions->lastChoices($this->by, $this->databases->id));
    }

    public function test_another_students_session_has_no_briefing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $session = app(Sessions::class)->start($this->principal($bob), $theirs->id);
        $note = $this->note('Mine', []);

        $this->assertThrows(fn () => app(Briefings::class)->forSession($this->by, $session->id), NotFound::class);
        $this->assertThrows(fn () => app(Sessions::class)->toggleMaterial($this->principal($bob), $session->id, "note:{$note->id}"), NotFound::class);
    }

    public function test_a_note_becomes_markdown(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Joins']]],
            ['type' => 'orderedList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inner']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Left']]]]],
            ]],
            ['type' => 'taskList', 'content' => [
                ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Read chapter 3']]]]],
            ]],
            ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted']]]]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => "SELECT *\nFROM a"]]],
        ]];

        $this->assertSame("# Joins\n\n1. Inner\n2. Left\n\n- [x] Read chapter 3\n\n> Quoted\n\n```\nSELECT *\nFROM a\n```", NoteDoc::markdown($doc));
    }

    private function note(string $title, array $content)
    {
        $note = app(Notes::class)->create($this->by, 'module', $this->week1->id, $title);
        app(Notes::class)->save($this->by, $note->id, [
            'base_version' => 1, 'save_id' => 'save-'.bin2hex(random_bytes(6)), 'client_id' => 'client-00000001',
            'title' => $title, 'doc' => ['type' => 'doc', 'content' => $content === [] ? [['type' => 'paragraph']] : $content],
        ]);

        return $note;
    }

    private function assertSeeInOrderText(array $needles, string $haystack): void
    {
        $position = 0;
        foreach ($needles as $needle) {
            $found = mb_strpos($haystack, $needle, $position);
            $this->assertNotFalse($found, "\"{$needle}\" after position {$position} in:\n".$haystack);
            $position = $found + mb_strlen($needle);
        }
    }
}
