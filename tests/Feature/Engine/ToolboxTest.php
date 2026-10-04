<?php

namespace Tests\Feature\Engine;

use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Plans;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What the engine may look up in ViStud during a chat, as the student (docs/specs/study-memory.md §6). */
class ToolboxTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private string $week1;

    private string $joins;

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $modules = app(Modules::class);
        $this->week1 = $modules->create($this->by, $this->databases->id, ['title' => 'Week 1: Relational model', 'starts_on' => '2026-09-28', 'ends_on' => '2026-10-04'])->id;
        $week2 = $modules->create($this->by, $this->databases->id, ['title' => 'Week 2: SQL joins'])->id;
        $topics = app(Topics::class);
        $keys = $topics->create($this->by, $this->databases->id, 'Primary and foreign keys', $this->week1);
        $topics->report($this->by, $keys->id, 'understood');
        $this->joins = $topics->create($this->by, $this->databases->id, 'Joins', $week2)->id;
        $topics->report($this->by, $this->joins, 'confused');
        app(Findings::class)->add($this->by, $this->joins, ['text' => 'A left join keeps every row of the left table.'], 'ai');
        $questions = app(Questions::class);
        $stuck = $questions->ask($this->by, $this->databases->id, 'Why does a left join keep unmatched rows?', $this->joins, $week2);
        $questions->setStatus($this->by, $stuck->id, 'stuck');
        $done = $questions->ask($this->by, $this->databases->id, 'What is a key?', $keys->id, $this->week1);
        $questions->setStatus($this->by, $done->id, 'answered', 'A column that names a row.');
        $activities = app(Activities::class);
        $essay = $activities->create($this->by, $this->databases->id, ['title' => 'ER diagram for the library', 'kind' => 'assignment', 'due_on' => '2026-10-12', 'module_id' => $this->week1]);
        $plans = app(Plans::class);
        $research = $plans->addPart($this->by, $essay->id, 'Research', 40);
        $plans->addStep($this->by, $essay->id, 'Read the brief', $research->id);
        $plans->addCriterion($this->by, $essay->id, 'Correct notation', 20);
        $plans->addMilestone($this->by, $essay->id, 'Draft done', '2026-10-09');
        $activities->create($this->by, $this->databases->id, ['title' => 'Old lab', 'kind' => 'lab', 'due_on' => '2026-09-01']);
        $notes = app(Notes::class);
        $note = $notes->create($this->by, 'module', $this->week1, 'Lecture 3: joins');
        $notes->save($this->by, $note->id, ['base_version' => 1, 'save_id' => 'engine-save-1', 'client_id' => 'engine-tab-1', 'title' => 'Lecture 3: joins', 'doc' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'An inner join keeps only the rows that match on both sides. A cross join pairs everything.']]],
        ]]]);
        app(Flashcards::class)->add($this->by, $this->databases->id, $this->joins, 'What does a left join keep?', 'Every left row.');
        $sessions = app(Sessions::class);
        $earlier = $sessions->start($this->by, $this->databases->id, $this->joins, $week2);
        $sessions->toggleMaterial($this->by, $earlier->id, "note:{$note->id}");
        $sessions->setSummary($this->by, $earlier->id, 'We covered inner joins.');
        $sessions->setCheckpoint($this->by, $earlier->id, 'Stopped before outer joins.');
        $sessions->end($this->by, $earlier->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
        $now = $sessions->start($this->by, $this->databases->id, $this->joins, $week2);
        $this->context = new Context($this->databases->id, $week2, $now->id, 'UTC');
    }

    private function look(string $tool, array $input = []): string
    {
        return app(Toolbox::class)->run($this->by, $this->context, $tool, $input);
    }

    public function test_the_tools_are_described_in_the_openai_shape(): void
    {
        $definitions = app(Toolbox::class)->definitions();
        $this->assertCount(count(Toolbox::TOOLS), $definitions);
        foreach ($definitions as $definition) {
            $this->assertSame('function', $definition['type']);
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $definition['function']['name']);
            $this->assertNotEmpty($definition['function']['description']);
            $this->assertSame('object', $definition['function']['parameters']['type']);
        }
        $this->assertStringContainsString('"properties":{}', json_encode(app(Toolbox::class)->definitions()[0]));
    }

    public function test_the_course_overview_counts_what_matters_now(): void
    {
        $overview = json_decode($this->look('course_overview'), true);
        $this->assertSame('2026-10-05', $overview['today']);
        $this->assertSame(['number' => 1, 'title' => 'Week 1: Relational model', 'runs' => '2026-09-28 to 2026-10-04', 'topics' => ['understood' => 1], 'notes' => 1], $overview['modules'][0]);
        $this->assertSame(['number' => 2, 'title' => 'Week 2: SQL joins', 'current' => true, 'topics' => ['confused' => 1]], $overview['modules'][1]);
        // One question is answered; the old lab is late, so it is due too.
        $this->assertSame([1, 1, 2, 1], [$overview['open_questions'], $overview['stuck_questions'], $overview['due_in_14_days'], $overview['flashcards_due']]);
    }

    public function test_topics_questions_and_findings_are_read_with_their_status_and_sources(): void
    {
        $topics = json_decode($this->look('topics'), true);
        $this->assertSame(['Primary and foreign keys', 'understood'], [$topics[0]['topic'], $topics[0]['status']]);
        $this->assertSame(['Joins', 'confused', 'Week 2: SQL joins'], [$topics[1]['topic'], $topics[1]['status'], $topics[1]['module']]);
        $this->assertCount(1, json_decode($this->look('topics', ['module' => 'week 2']), true));
        $this->assertStringContainsString('no module called "Week 9"', $this->look('topics', ['module' => 'Week 9']));

        $open = json_decode($this->look('questions'), true);
        $this->assertSame([['Why does a left join keep unmatched rows?', 'stuck', 'Joins']], array_map(fn ($q) => [$q['question'], $q['status'], $q['topic']], $open));
        $all = json_decode($this->look('questions', ['which' => 'all']), true);
        $this->assertSame('A column that names a row.', $all[1]['answer']);
        // Narrowed to a topic or a module.
        $this->assertSame(['What is a key?'], array_column(json_decode($this->look('questions', ['which' => 'all', 'topic' => 'keys']), true), 'question'));
        $this->assertSame(['Why does a left join keep unmatched rows?'], array_column(json_decode($this->look('questions', ['module' => 'week 2']), true), 'question'));
        $this->assertStringContainsString('No open questions there.', $this->look('questions', ['topic' => 'keys']));
        $this->assertStringContainsString('no topic called "Monads"', $this->look('questions', ['topic' => 'Monads']));

        $findings = json_decode($this->look('findings', ['topic' => 'joins']), true);
        $this->assertSame(['Joins', 'A left join keeps every row of the left table.', 'a study session'], [$findings[0]['topic'], $findings[0]['finding'], $findings[0]['by']]);
        $this->assertStringContainsString('No findings kept on that topic', $this->look('findings', ['topic' => 'keys']));
    }

    public function test_assignments_their_plans_and_the_calendar_are_read(): void
    {
        $open = json_decode($this->look('assignments'), true);
        $this->assertSame(['Old lab', 'lab', 'to do', '2026-09-01'], [$open[0]['title'], $open[0]['kind'], $open[0]['status'], $open[0]['due']]);
        $this->assertSame(['ER diagram for the library', 'Week 1: Relational model', '0 of 1 done, 0%'], [$open[1]['title'], $open[1]['module'], $open[1]['plan']]);

        $plan = json_decode($this->look('assignment_plan', ['assignment' => 'ER diagram']), true);
        $this->assertSame(['Research', '40%', 'to do'], [$plan['sections'][0]['section'], $plan['sections'][0]['weight'], $plan['sections'][0]['state']]);
        $this->assertSame('Read the brief', $plan['sections'][0]['tasks'][0]['task']);
        $this->assertSame(['Correct notation', 20, 'not yet'], [$plan['criteria'][0]['criterion'], $plan['criteria'][0]['marks'], $plan['criteria'][0]['state']]);
        $this->assertSame(['Draft done', '2026-10-09'], [$plan['milestones'][0]['milestone'], $plan['milestones'][0]['on']]);
        $this->assertStringContainsString('has no plan yet', $this->look('assignment_plan', ['assignment' => 'Old lab']));
        $this->assertStringContainsString('Say which assignment', $this->look('assignment_plan'));

        $calendar = json_decode($this->look('calendar'), true);
        $this->assertSame(['2026-10-05', '2026-10-19'], [$calendar['from'], $calendar['to']]);
        $this->assertContains('Draft done', array_column($calendar['entries'], 'what'));
        $this->assertContains('ER diagram for the library', array_column($calendar['entries'], 'what'));
        $this->assertStringContainsString('Nothing on the calendar from 2027-01-01 to 2027-01-02', $this->look('calendar', ['from' => '2027-01-01', 'to' => '2027-01-02']));
    }

    public function test_notes_are_listed_read_and_searched_and_files_and_sessions_listed(): void
    {
        $notes = json_decode($this->look('notes'), true);
        $this->assertSame(['Lecture 3: joins', 'Week 1: Relational model', '2026-10-05'], [$notes[0]['note'], $notes[0]['module'], $notes[0]['changed']]);
        $this->assertSame("# Lecture 3: joins\n\nAn inner join keeps only the rows that match on both sides. A cross join pairs everything.", $this->look('read_note', ['note' => 'lecture 3']));
        $this->assertStringContainsString('no note called "Lecture 9"', $this->look('read_note', ['note' => 'Lecture 9']));
        $found = json_decode($this->look('search_notes', ['query' => 'cross join']), true);
        $this->assertSame('Lecture 3: joins', $found[0]['note']);
        $this->assertStringContainsString('cross join pairs everything', $found[0]['snippet']);
        $this->assertStringContainsString('No note mentions "monads"', $this->look('search_notes', ['query' => 'monads']));
        $this->assertStringContainsString('No files or links', $this->look('files'));

        $sessions = json_decode($this->look('earlier_sessions'), true);
        $this->assertCount(1, $sessions);
        $this->assertSame(['2026-10-05', 'Week 2: SQL joins', 'Joins', 'We covered inner joins.', 'Stopped before outer joins.', ['the note "Lecture 3: joins"']], [$sessions[0]['on'], $sessions[0]['module'], $sessions[0]['topic'], $sessions[0]['summary'], $sessions[0]['checkpoint'], $sessions[0]['used']]);
        // Narrowed to a module or a topic, for going over one before an exam.
        $this->assertCount(1, json_decode($this->look('earlier_sessions', ['module' => 'Week 2']), true));
        $this->assertCount(1, json_decode($this->look('earlier_sessions', ['topic' => 'Joins', 'limit' => 5]), true));
        $this->assertStringContainsString('no module called "Week 9"', $this->look('earlier_sessions', ['module' => 'Week 9']));
    }

    public function test_an_unknown_tool_is_named_and_another_students_things_are_not_found(): void
    {
        $this->assertStringContainsString('There is no tool called "delete_everything"', $this->look('delete_everything'));

        $other = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($other, ['name' => 'Theirs']);
        $context = new Context($theirs->id, null, null, 'UTC');
        // Ada's note by its id, from another student's course: not there.
        $noteId = app(Notes::class)->list($this->by, $this->databases->id)[0]->id;
        $this->assertStringContainsString('no note called', app(Toolbox::class)->run($other, $context, 'read_note', ['note' => $noteId]));
        $this->assertStringContainsString('No notes yet', app(Toolbox::class)->run($other, $context, 'notes'));
        // And Ada's workspace is not found for the other student at all.
        $this->assertStringContainsString('Not found', app(Toolbox::class)->run($other, new Context($this->databases->id, null, null, 'UTC'), 'topics'));
    }

    public function test_a_file_is_read_a_few_pages_at_a_time_with_where_each_page_is(): void
    {
        Storage::fake('local');
        $slide = fn (string $text) => "<p:sld><a:p><a:r><a:t>{$text}</a:t></a:r></a:p></p:sld>";
        $slides = [];
        foreach (range(1, 8) as $n) {
            $slides["ppt/slides/slide{$n}.xml"] = $slide("Slide {$n}: ".str_repeat('joins ', in_array($n, [6, 7], true) ? 2_000 : 5));
        }
        $files = app(Files::class);
        $files->upload($this->by, 'module', $this->week1, $this->temp($this->ooxml('ppt/presentation.xml', $slides)), 'Joins deck.pptx');
        $files->upload($this->by, 'module', $this->week1, $this->temp($this->image()), 'Whiteboard.png');

        $first = $this->look('read_file', ['file' => 'Joins deck']);
        $this->assertStringStartsWith('"Joins deck.pptx": slides 1-3 of 8.', $first);
        $this->assertStringContainsString("--- Slide 2 of 8 ---\nSlide 2: joins", $first);
        $this->assertStringNotContainsString('Slide 4 of 8', $first);

        // At most five at a time, and a long one stops the look-up early, saying where to go on.
        $this->assertStringStartsWith('"Joins deck.pptx": slides 2-6 of 8.', $this->look('read_file', ['file' => 'deck', 'pages' => '2-9']));
        $long = $this->look('read_file', ['file' => 'deck', 'pages' => '4-7']);
        $this->assertStringStartsWith('"Joins deck.pptx": slides 4-6 of 8.', $long);
        $this->assertStringContainsString('ask for slide 7 on', $long);
        $this->assertStringStartsWith('"Joins deck.pptx": slides 7-8 of 8.', $this->look('read_file', ['file' => 'deck', 'pages' => '7 to 20']));
        $this->assertStringContainsString('ask for slides 1 to 8', $this->look('read_file', ['file' => 'deck', 'pages' => '12']));
        $this->assertStringContainsString('is a picture', $this->look('read_file', ['file' => 'Whiteboard']));
        $this->assertStringContainsString('no file called "Lecture 9"', $this->look('read_file', ['file' => 'Lecture 9']));
    }

    public function test_flashcards_key_points_and_questions_go_straight_into_the_course_once_each(): void
    {
        $said = $this->look('make_flashcards', ['cards' => [
            ['front' => 'What does a right join keep?', 'back' => 'Every right row.', 'topic' => 'joins'],
            ['front' => 'What is a cross join?', 'back' => 'Every pair of rows.', 'topic' => 'Operating System Fundamentals'],
            ['front' => '   ', 'back' => 'Nothing'],
        ]]);
        $this->assertStringStartsWith('Saved 2 flashcards in Joins. 1 was empty.', $said);
        // A topic the course doesn't have goes to the session's topic: the tutor makes no topics of its own.
        $cards = collect(app(Flashcards::class)->list($this->by, $this->databases->id))->keyBy('front');
        $this->assertSame([$this->joins, 'ai', $this->context->sessionId], [$cards['What is a cross join?']->topicId, $cards['What is a cross join?']->author, $cards['What is a cross join?']->sessionId]);
        $this->assertNotContains('Operating System Fundamentals', array_column(app(Topics::class)->list($this->by, $this->databases->id), 'name'));
        $this->assertSame(['saved' => ['flashcard' => 2]], $this->context->effects->take());

        // The same card again is recognised.
        $this->assertStringStartsWith('Nothing new was saved. 1 was saved already in this session.', $this->look('make_flashcards', ['cards' => [['front' => 'What does a right join keep?', 'back' => 'Every right row.']]]));
        $this->assertSame([], $this->context->effects->take());

        $this->assertStringStartsWith('Saved 1 key point in Joins.', $this->look('save_key_points', ['points' => [['text' => 'A right join keeps every right row.']]]));
        $this->assertContains('A right join keeps every right row.', array_map(fn ($f) => $f->text, app(Findings::class)->byTopic($this->by, $this->databases->id)[$this->joins]));
        $this->assertStringStartsWith('Saved 1 question in Primary and foreign keys.', $this->look('add_questions', ['questions' => [['text' => 'Can a foreign key be NULL?', 'topic' => 'primary and foreign keys']]]));
        $this->assertContains('Can a foreign key be NULL?', array_map(fn ($q) => $q->text, app(Questions::class)->list($this->by, $this->databases->id)));
        $this->assertStringContainsString('Nothing to save', $this->look('add_questions', ['questions' => []]));

        // Without a session there is nothing to save into.
        $this->assertStringContainsString('needs a study session', app(Toolbox::class)->run($this->by, new Context($this->databases->id, null, null, 'UTC'), 'make_flashcards', ['cards' => [['front' => 'a', 'back' => 'b']]]));
    }

    public function test_the_tutor_writes_in_the_sessions_study_note_a_named_one_or_a_new_one(): void
    {
        $first = $this->look('write_note', ['text' => "## Joins\n\n- A **left** join keeps every left row.\n- Energy: \$E = mc^2\$"]);
        $this->assertStringStartsWith('Started the note "Study notes · Joins · Mon 5 Oct".', $first);
        $notes = app(Notes::class);
        $study = collect($notes->list($this->by, $this->databases->id))->first(fn ($n) => $n->title === 'Study notes · Joins · Mon 5 Oct');
        $this->assertSame($this->context->moduleId, $study->moduleId);
        $effects = $this->context->effects->take();
        $this->assertSame([[$study->id, 'Study notes · Joins · Mon 5 Oct', 1]], array_map(fn ($n) => [$n['id'], $n['title'], $n['version']], $effects['notes']));

        // The next write adds to the same note, at its end.
        $this->assertStringStartsWith('Added to the note "Study notes · Joins · Mon 5 Oct".', $this->look('write_note', ['text' => 'A right join keeps every right row.']));
        $text = NoteDoc::markdown($notes->open($this->by, $study->id)->doc);
        $this->assertStringContainsString("## Joins\n\n- A **left** join keeps every left row.", $text);
        $this->assertStringContainsString('$E = mc^2$', $text);
        $this->assertStringEndsWith('A right join keeps every right row.', trim($text));
        $this->assertSame(2, $this->context->effects->take()['notes'][0]['version']);

        // A note the student names, and a new one with a title (a title that's already a note's adds to it).
        $this->assertStringStartsWith('Added to the note "Lecture 3: joins".', $this->look('write_note', ['text' => 'Self joins next week.', 'note' => 'Lecture 3']));
        $this->assertStringEndsWith('Self joins next week.', trim(NoteDoc::markdown($notes->open($this->by, collect($notes->list($this->by, $this->databases->id))->firstWhere('title', 'Lecture 3: joins')->id)->doc)));
        $this->assertStringStartsWith('Started the note "Join cheat sheet".', $this->look('write_note', ['text' => '| Join | Keeps |'."\n".'|---|---|'."\n".'| left | all left |', 'title' => 'Join cheat sheet']));
        $this->assertStringStartsWith('Added to the note "Join cheat sheet".', $this->look('write_note', ['text' => 'More.', 'title' => 'join cheat sheet']));
        $this->assertStringContainsString('Nothing to write', $this->look('write_note', ['text' => '  ']));
        $this->assertStringContainsString('no note called "Lecture 9"', $this->look('write_note', ['text' => 'x', 'note' => 'Lecture 9']));
    }

    public function test_a_whole_file_can_be_seen_at_once_as_an_outline(): void
    {
        Storage::fake('local');
        $slides = [];
        foreach (['What is an OS?', 'Batch systems', 'Real-time systems'] as $i => $title) {
            $slides['ppt/slides/slide'.($i + 1).'.xml'] = "<p:sld><a:p><a:r><a:t>{$title}</a:t></a:r></a:p><a:p><a:r><a:t>Details of {$title}</a:t></a:r></a:p></p:sld>";
        }
        app(Files::class)->upload($this->by, 'module', $this->week1, $this->temp($this->ooxml('ppt/presentation.xml', $slides)), 'OS types.pptx');

        $outline = $this->look('read_file', ['file' => 'OS types', 'pages' => 'all']);
        $this->assertStringStartsWith('"OS types.pptx": an outline of its 3 slides', $outline);
        $this->assertStringEndsWith("Slide 1: What is an OS?\nSlide 2: Batch systems\nSlide 3: Real-time systems", $outline);
        $this->assertStringNotContainsString('Details of', $outline);
    }
}
