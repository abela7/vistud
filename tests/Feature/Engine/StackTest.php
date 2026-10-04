<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Facts;
use App\Engine\Context\Stack;
use App\Engine\Context\Tokens;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\CourseProfiles;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Instructions;
use App\Study\LearnerProfiles;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The tutor's standing context, in layers with budgets (docs/specs/vistud-2-blueprint.md §3.6.2). */
class StackTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week2;

    private string $week3;

    private string $joins;

    private string $keys;

    private SessionDetails $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases', 'code' => 'CS301', 'term' => 'Autumn 2026'])->id;
        $this->week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-11'])->id;
        $this->week3 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3: Normal forms'])->id;
        $topics = app(Topics::class);
        $this->joins = $topics->create($this->by, $this->workspace, 'Joins', $this->week2)->id;
        $this->keys = $topics->create($this->by, $this->workspace, 'Keys', $this->week2)->id;
        $topics->create($this->by, $this->workspace, '1NF', $this->week3);
        $topics->report($this->by, $this->joins, 'confused');
        $this->session = app(Sessions::class)->start($this->by, $this->workspace, $this->joins, $this->week2);
    }

    private function stack(): Stack
    {
        return app(Stack::class);
    }

    public function test_the_layers_come_in_order_and_the_report_says_what_each_weighs(): void
    {
        app(Instructions::class)->set($this->by, 'me', 'Second year. I get lost when there are many new words.');
        app(Instructions::class)->set($this->by, "workspace:{$this->workspace}", 'Use C examples.');
        app(Instructions::class)->set($this->by, "module:{$this->week2}", 'Joins are on the midterm.');
        app(Sessions::class)->setCheckpoint($this->by, $this->session->id, 'Stopped at slide 7 of 18, before outer joins.');
        $session = app(Sessions::class)->find($this->by, $this->session->id);

        $built = $this->stack()->build($this->by, $session, 'We covered inner joins.', true);

        $positions = array_map(fn (string $needle) => strpos($built->system, $needle), [
            "# You are the student's tutor", '## The course: Databases', '## The student', '## The module: Week 2: SQL joins', '## This session', '## Earlier in this chat',
        ]);
        $this->assertSame(0, $positions[0]);
        $this->assertSame($positions, array_values(array_filter($positions, fn ($p) => $p !== false)), 'every layer is there');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the layers are in order, the changing ones last');

        // What is in them: the course, the student's own words, the module and its topics, the session.
        foreach (['CS301 · Autumn 2026', 'Instructions for this course, in the student\'s words: Use C examples.', 'About you, in their words: Second year. I get lost when there are many new words.',
            'Instructions for this module, in the student\'s words: Joins are on the midterm.', '(5 Oct – 11 Oct)', '- Joins: confused', '- Keys: not started',
            'Topic now: Joins (the student says confused;', 'Where this session last stood (your last checkpoint): Stopped at slide 7 of 18, before outer joins. Pick up from here.',
            "How to teach:\n- Explain first", 'We covered inner joins.'] as $expected) {
            $this->assertStringContainsString($expected, $built->system);
        }
        // Another module's topics are not in this module's layer.
        $this->assertStringNotContainsString('1NF', $built->system);

        // The report: layer, name, weight, budget, cuts: in order, the tools and the chat having none of their own.
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_column($built->report, 'layer'));
        $this->assertSame(['Rules', 'Tools', 'Course', 'Student', 'Module', 'Session', 'Chat so far'], array_column($built->report, 'name'));
        $this->assertSame([2_500, null, 600, 250, 700, 300, null], array_column($built->report, 'budget'));
        $this->assertSame([], $built->cuts());
        foreach ($built->report as $layer) {
            $this->assertGreaterThan(0, $layer['tokens'], "layer {$layer['layer']} weighs something");
        }
        $this->assertSame(array_sum(array_column($built->report, 'tokens')), $built->tokens());
        $this->assertSame(count(app(Toolbox::class)->definitions()), count($built->tools));
    }

    public function test_the_course_profile_and_how_the_student_likes_to_learn_fill_the_course_and_student_layers(): void
    {
        app(CourseProfiles::class)->save($this->by, $this->workspace, [
            'about' => 'Relational databases: modelling, SQL and transactions.',
            'outcomes' => "Write joins\nNormalise a schema",
            'textbook' => 'Database System Concepts',
            'assessment' => [['name' => 'Midterm', 'kind' => 'exam', 'weight' => 30, 'due_on' => '2026-10-12'], ['name' => 'Final', 'kind' => 'exam', 'weight' => 40, 'due_on' => '2026-12-14']],
        ]);
        app(LearnerProfiles::class)->save($this->by, $this->workspace, ['explain' => ['examples', 'diagrams'], 'pace' => 'small', 'check' => 'often', 'goal' => 'top', 'note' => 'Use C.']);
        app(Instructions::class)->set($this->by, "workspace:{$this->workspace}", 'Teach slide by slide.');

        $built = $this->stack()->build($this->by, app(Sessions::class)->find($this->by, $this->session->id), null, true);

        // The course: what it is, what it should teach and how it is assessed, with the student's own words last.
        $this->assertStringContainsString("## The course: Databases\nCS301 · Autumn 2026\nAbout: Relational databases: modelling, SQL and transactions.\nTextbook: Database System Concepts\nOutcomes:\n- Write joins\n- Normalise a schema\nAssessment:\n- Midterm 30 % (12 Oct)\n- Final 40 % (14 Dec)\nInstructions for this course, in the student's words: Teach slide by slide.", $built->system);
        // The student: one line of how they like to learn, in short phrases, after what they wrote about themselves.
        $this->assertStringContainsString("About you, in their words: nothing written yet.\nHow they like to learn in this course: Likes: examples first, diagrams · Pace: small steps · Check: often · Goal: top marks · Also: Use C..", $built->system);
        foreach ($built->report as $layer) {
            if (in_array($layer['layer'], [2, 3], true)) {
                $this->assertSame(0, $layer['cut']);
                $this->assertLessThanOrEqual($layer['budget'], $layer['tokens']);
            }
        }
        // Both are in the stable front of the prompt: the same for every session of the course.
        $this->assertStringContainsString('Assessment:', $built->stable);
        $this->assertStringContainsString('How they like to learn', $built->stable);
    }

    public function test_the_module_layer_names_its_files_questions_key_points_and_where_the_last_session_stopped(): void
    {
        $upload = fn (string $text, string $name) => app(Files::class)->upload($this->by, 'module', $this->week2, $this->tempFile($text), $name);
        $lecture = $upload("Joins.\n", 'Lecture 2.txt');
        $upload("Not read.\n", 'Lab 2.txt');
        app(FileDigests::class)->keep($this->by, $lecture->id, ['summary' => 'Joins.', 'outline' => [], 'topics' => ['Inner join', 'Left join'], 'language' => 'English'], 12, 900, 'fake/quick');
        $questions = app(Questions::class);
        $questions->ask($this->by, $this->workspace, 'What is a self join?', $this->joins, $this->week2);
        $stuck = $questions->ask($this->by, $this->workspace, 'Why does a left join keep unmatched rows?', $this->joins, $this->week2);
        $questions->setStatus($this->by, $stuck->id, 'stuck');
        app(Findings::class)->add($this->by, $this->joins, ['text' => 'A left join keeps every row of the left table.']);
        app(Sessions::class)->setCheckpoint($this->by, $this->session->id, 'Stopped before outer joins.');
        $session = app(Sessions::class)->find($this->by, $this->session->id);

        $built = $this->stack()->build($this->by, $session, null, true);

        // After the topics: where it stopped, then what it holds, the open questions (stuck first) and the key points.
        $this->assertStringContainsString("## The module: Week 2: SQL joins (5 Oct – 11 Oct)\nTopics, with what the student says of each:\n- Joins: confused\n- Keys: not started\nLast time: Stopped before outer joins.\nFiles:\n- Lecture 2.txt (12 pages: Inner join, Left join)\n- Lab 2.txt (not read yet)\nOpen questions:\n- \"Why does a left join keep unmatched rows?\" (stuck)\n- \"What is a self join?\"\nKey points:\n- A left join keeps every row of the left table.", $built->system);
        $module = array_values(array_filter($built->report, fn (array $layer) => $layer['layer'] === 4))[0];
        $this->assertSame(0, $module['cut']);
        $this->assertLessThanOrEqual(Stack::BUDGETS[4], $module['tokens']);

        // The brief is kept, and it changes only when something in the module does.
        $kept = fn () => DB::table('module_briefs')->where('module_id', $this->week2)->first();
        $first = $kept();
        $this->stack()->build($this->by, $session, null, true);
        $this->assertSame($first->built_at, $kept()->built_at);
        $this->travel(5)->minutes();
        $questions->setStatus($this->by, $stuck->id, 'answered', 'Because it keeps the left side.');
        $this->stack()->build($this->by, $session, null, true);
        $this->assertNotSame($first->fingerprint, $kept()->fingerprint);
        $this->assertStringNotContainsString('Why does a left join', $this->stack()->build($this->by, $session, null, true)->system);
    }

    public function test_a_long_profile_is_cut_a_line_at_a_time_and_a_course_with_none_is_as_before(): void
    {
        $plain = $this->stack()->build($this->by, app(Sessions::class)->find($this->by, $this->session->id), null, true);
        $this->assertStringNotContainsString('About:', $plain->system);
        $this->assertStringNotContainsString('Outcomes:', $plain->system);
        $this->assertStringNotContainsString('How they like to learn', $plain->system);

        app(CourseProfiles::class)->save($this->by, $this->workspace, [
            'about' => 'Relational databases.',
            'outcomes' => implode("\n", array_map(fn (int $n) => "Outcome {$n}: ".str_repeat('word ', 36), range(1, 12))),
            'assessment' => array_map(fn (int $n) => ['name' => "Piece of coursework {$n} ".str_repeat('x', 40), 'kind' => 'assignment', 'weight' => 5, 'due_on' => '2026-11-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)], range(1, 12)),
        ]);
        $built = $this->stack()->build($this->by, app(Sessions::class)->find($this->by, $this->session->id), null, true);

        $course = array_values(array_filter($built->report, fn (array $layer) => $layer['layer'] === 2))[0];
        $this->assertGreaterThan(0, $course['cut']);
        $this->assertLessThanOrEqual(Stack::BUDGETS[2], $course['tokens']);
        $this->assertStringContainsString('(cut: ', $built->system);
        // What the student wrote is never what is cut.
        $this->assertStringContainsString('About: Relational databases.', $built->system);
    }

    public function test_the_tutors_rules_stay_within_their_budget_for_a_model_with_tools_and_one_without(): void
    {
        foreach ([true, false] as $withTools) {
            $rules = Stack::rules($withTools);
            $this->assertLessThanOrEqual(Stack::BUDGETS[0], Tokens::of($rules), 'The rules fit their budget.');
            $this->assertStringStartsWith("# You are the student's tutor", $rules);
            // The markers are gone, and every heading has a blank line before it.
            $this->assertStringNotContainsString('<!--', $rules);
            $this->assertDoesNotMatchRegularExpression('/[^\n]\n#{1,3} /', $rules);
        }

        // Only a model that can use tools is told about them; only one that can't is given marks for what to keep.
        $with = Stack::rules(true);
        $plain = Stack::rules(false);
        $this->assertStringContainsString('## Your tools in this chat', $with);
        $this->assertStringContainsString('(add_topics, set_topic)', $with);
        $this->assertStringContainsString('Key points, questions and flashcards are saved with the tools, not marks.', $with);
        $this->assertStringNotContainsString('<flashcard topic=', $with);
        $this->assertStringNotContainsString('## Your tools in this chat', $plain);
        $this->assertStringContainsString('<flashcard topic="Topic name"><front>The question</front><back>The answer</back></flashcard>', $plain);
        $this->assertStringContainsString('<finding topic="Topic name">', $plain);

        // What both keep: the role, the order of rules, the path, the marks ViStud reads, the diagrams, the ending.
        foreach ([$with, $plain] as $rules) {
            foreach (['## Your job', '## When instructions clash', '## The path of a session', '## Words the student can use', '```mermaid', 'Write formulas in $…$ inside a line or $$…$$', '<checkpoint>', '<status topic="Topic name" proposed="confused">', '<attempt topic="Topic name" form="apply" support="unaided" result="partial">', '<asked>', '<right>', '<fix>', '<summary>', '## Ending', 'Don\'t write graded work'] as $needle) {
                $this->assertStringContainsString($needle, $rules);
            }
        }
    }

    public function test_the_front_of_the_prompt_is_the_same_for_every_session_of_a_course(): void
    {
        $sessions = app(Sessions::class);
        $first = $this->stack()->build($this->by, $sessions->find($this->by, $this->session->id), null, true);
        $sessions->setSummary($this->by, $this->session->id, 'A summary that changes it.');
        $sessions->end($this->by, $this->session->id);

        // Another session: another module, another topic, a Pomodoro clock, another teaching choice.
        $next = $sessions->start($this->by, $this->workspace, null, $this->week3, ['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4], ['method' => 'socratic', 'check_ins' => 'none', 'quiz' => 'exam', 'pace' => 'section']);
        $second = $this->stack()->build($this->by, $sessions->find($this->by, $next->id), 'The chat so far.', true);

        $this->assertNotSame($first->system, $second->system);
        $this->assertSame($first->stable, $second->stable);
        $this->assertTrue(str_starts_with($first->system, $first->stable) && str_starts_with($second->system, $second->stable));
        $this->assertStringContainsString('## The course: Databases', $first->stable);
        $this->assertStringContainsString('## The student', $first->stable);
        $this->assertStringNotContainsString('## The module', $first->stable);
        $this->assertStringNotContainsString('## This session', $first->stable);
        // The tools are the same too, word for word.
        $this->assertSame(json_encode($first->tools), json_encode($second->tools));
        $this->assertStringContainsString('Pomodoro, 25-minute focus periods', $second->system);
        $this->assertStringContainsString('Check questions: none', $second->system);

        // What is the student's changes it, and only it: their words, their language, how they want topics kept.
        app(Instructions::class)->set($this->by, 'me', 'Explain with pictures.');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'language' => 'Amharic', 'ask_topics' => true]);
        $changed = $this->stack()->build($this->by, $sessions->find($this->by, $next->id), null, true);
        $this->assertNotSame($first->stable, $changed->stable);
        $this->assertStringContainsString('About you, in their words: Explain with pictures.', $changed->stable);
        $this->assertStringContainsString("## Language\n\nThe student chose to be taught in Amharic.", $changed->stable);
        $this->assertStringContainsString('Topics: ask before you add or switch them; propose, and do it once they agree.', $changed->stable);
    }

    public function test_what_does_not_fit_is_cut_from_the_end_and_said_but_the_students_own_words_never_are(): void
    {
        $topics = array_map(fn (int $i) => ['name' => "Topic number {$i} of the module", 'status' => 'not started'], range(1, 300));
        $words = str_repeat('Every word here is the student\'s own and stays. ', 40);
        $facts = new Facts(
            courseName: 'Databases', courseInstructions: $words, aboutYou: $words, moduleTitle: 'Week 2', moduleInstructions: $words, topics: $topics,
            topicNow: 'Joins', topicPractice: 'the student says confused; practice: seen', teaching: ['Explain first.'], checkpoint: 'Slide 7.', summary: str_repeat('A long summary. ', 100),
        );

        $built = $this->stack()->compose($facts, true);

        // The topics were cut (a line at a time, in order), and the layer says so.
        $this->assertSame([4, 5], array_keys($built->cuts()));
        $this->assertGreaterThan(200, $built->cuts()[4]);
        $this->assertMatchesRegularExpression('/\(cut: \d+ more lines\)/', $built->system);
        $this->assertStringContainsString('- Topic number 1 of the module: not started', $built->system);
        $this->assertStringNotContainsString('- Topic number 300 of the module', $built->system);

        // The student's words are whole in all three places, however long, even past their layers' budgets.
        $this->assertSame(3, substr_count($built->system, trim($words)));
        $report = array_column($built->report, null, 'layer');
        $this->assertGreaterThan($report[3]['budget'], $report[3]['tokens']);
        $this->assertSame([0, 0], [$report[2]['cut'], $report[3]['cut']]);

        // The session layer: what must stay does, the summary that doesn't fit is cut and counted.
        $this->assertSame(1, $report[5]['cut']);
        $this->assertStringContainsString('Where this session last stood (your last checkpoint): Slide 7.', $built->system);
        $this->assertStringNotContainsString('Summary so far: A long summary.', $built->system);
        $this->assertStringContainsString('(cut: 1 more line)', $built->system);

        // The same facts always give the same words.
        $this->assertSame($built->system, $this->stack()->compose($facts, true)->system);
    }

    public function test_a_module_with_many_topics_still_fits_its_layer_and_a_short_one_loses_nothing(): void
    {
        $facts = fn (int $n) => new Facts(courseName: 'Databases', moduleTitle: 'Week 2', topics: array_map(fn (int $i) => ['name' => "Topic {$i}", 'status' => 'understood'], range(1, $n)), topicNow: null);

        $short = $this->stack()->compose($facts(8), true);
        $this->assertSame([], $short->cuts());
        $this->assertStringContainsString('- Topic 8: understood', $short->system);

        $long = $this->stack()->compose($facts(400), true);
        $layer = array_column($long->report, null, 'layer')[4];
        // Within its budget, give or take the line that says what was cut.
        $this->assertLessThanOrEqual(Stack::BUDGETS[4] + 10, $layer['tokens']);
        $this->assertGreaterThan(0, $layer['cut']);
    }

    public function test_a_model_that_cant_use_tools_gets_the_chosen_notes_text_and_marks_instead_of_tools(): void
    {
        $note = app(Notes::class)->create($this->by, 'module', $this->week2, 'Lecture 3: joins');
        app(Notes::class)->save($this->by, $note->id, ['base_version' => 1, 'save_id' => 'stack-save-1', 'client_id' => 'stack-tab-1', 'title' => 'Lecture 3: joins', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A left join keeps every left row.']]]]]]);
        app(Sessions::class)->toggleMaterial($this->by, $this->session->id, "note:{$note->id}");
        $session = app(Sessions::class)->find($this->by, $this->session->id);

        $with = $this->stack()->build($this->by, $session, null, true);
        $this->assertStringContainsString('Material: the student\'s note "Lecture 3: joins", read it with read_note.', $with->system);
        $this->assertStringNotContainsString('A left join keeps every left row.', $with->system);

        $plain = $this->stack()->build($this->by, $session, null, false);
        $this->assertSame([], $plain->tools);
        $this->assertStringContainsString('Material: the student\'s note "Lecture 3: joins", its text is below.', $plain->system);
        $this->assertStringContainsString("## The student's note: Lecture 3: joins\n\nA left join keeps every left row.", $plain->system);
        $this->assertStringNotContainsString('## Your tools in this chat', $plain->system);
        $this->assertStringNotContainsString('Topics: keep them yourself', $plain->system, 'no topic tools, no topic line');
        $this->assertStringContainsString('<flashcard topic="Topic name">', $plain->system);
    }

    public function test_a_session_without_a_topic_or_a_module_says_so_and_nothing_of_the_briefing_comes_along(): void
    {
        $sessions = app(Sessions::class);
        $sessions->end($this->by, $this->session->id);
        $bare = $sessions->start($this->by, $this->workspace);

        $built = $this->stack()->build($this->by, $sessions->find($this->by, $bare->id), null, true);

        $this->assertStringContainsString('Topic now: none chosen. Propose one and ask whether to take it.', $built->system);
        $this->assertStringNotContainsString('## The module', $built->system);
        $this->assertStringContainsString('About you, in their words: nothing written yet.', $built->system);
        $this->assertSame([], array_filter($built->cuts()));
        // The briefing for a pasted chat is another thing: none of its sections are sent here.
        foreach (['# Briefing', 'Earlier sessions', 'Due soon', 'Still confusing', 'All topics in this course'] as $heading) {
            $this->assertStringNotContainsString($heading, $built->system);
        }
        // About 5,600: the rules (2,400), the nineteen tools (2,950) and a few lines. The briefing alone could be 15,000.
        $this->assertLessThan(6_500, $built->tokens(), 'The standing context before the chat stays small.');
    }

    public function test_tokens_are_counted_by_bytes_on_the_safe_side(): void
    {
        $this->assertSame(0, Tokens::of(''));
        $this->assertSame(1, Tokens::of('abcd'));
        $this->assertSame(2, Tokens::of('abcde'));
        $this->assertGreaterThan(Tokens::of('abcdefghij'), Tokens::of('አማርኛ ቋንቋ'));
    }

    /** A temporary file holding $text, removed when the test ends. */
    private function tempFile(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-stack-');
        file_put_contents($path, $text);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
