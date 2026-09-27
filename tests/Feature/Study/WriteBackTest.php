<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Briefings;
use App\Study\Capture;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The end-of-session write-back: the tutor's marks, reviewed and saved (docs/specs/study-memory.md §4.4). */
class WriteBackTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private const CHAT = <<<'CHAT'
        Tutor: **Slide 3 of 12 · Left joins**
        A LEFT JOIN keeps every row of the left table.
        `<finding topic="Joins">A LEFT JOIN keeps every row of the left table, even when age<18 has no match.</finding>`

        Student: why do the unmatched rows get NULLs?
        Tutor: Because there is nothing to fill them with.
        <question topic="joins">Why are unmatched columns NULL rather than empty?</question>
        <flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every row of the left table.</back></flashcard>
        <attempt topic="Joins" form="apply" support="unaided" result="correct"><asked>Which rows does customers LEFT JOIN orders return?</asked><answer>All customers, with NULLs for those without orders.</answer></attempt>
        <attempt topic="Keys" form="recall" support="hinted" result="partial"><asked>What makes a foreign key?</asked><answer>A column pointing to another table.</answer></attempt>
        <checkpoint>Slide 3 of 12. Covered inner and left joins.</checkpoint>
        <checkpoint>Slide 7 of 12. Covered joins; next is self joins.</checkpoint>
        <summary>We covered inner and left joins. The student applied left joins well; NULLs were confusing at first.</summary>
        <status topic="Joins" proposed="understood">Answered the apply question right, unaided.</status>
        CHAT;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    private TopicDetails $joins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1']);
        $this->joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins', $this->week1->id);
    }

    public function test_marks_are_read_from_a_messy_paste_without_repeats(): void
    {
        $items = Capture::parse(self::CHAT."\n".str_replace('<', '&lt;', self::CHAT));

        $this->assertSame(['finding', 'question', 'flashcard', 'attempt', 'attempt', 'checkpoint', 'checkpoint', 'summary', 'status'], array_column($items, 'kind'));
        $this->assertSame('A LEFT JOIN keeps every row of the left table, even when age<18 has no match.', $items[0]['text']);
        $this->assertSame(['Keys', 'recall', 'hinted', 'partial'], [$items[4]['topic'], $items[4]['form'], $items[4]['support'], $items[4]['result']]);
        $this->assertSame([], Capture::parse('Nothing marked here. <finding topic="x"></finding> <status topic="Joins" proposed="mastered">no</status>'));
    }

    public function test_the_review_matches_topics_keeps_the_last_checkpoint_and_leaves_statuses_unticked(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->databases->id, $this->joins->id);
        $items = app(WriteBack::class)->review($this->by, $session->id, self::CHAT);

        $this->assertSame(['finding', 'question', 'flashcard', 'attempt', 'attempt', 'checkpoint', 'summary', 'status'], array_column($items, 'kind'));
        $this->assertSame([$this->joins->id, $this->joins->id, 'new'], [$items[0]['topic_id'], $items[1]['topic_id'], $items[4]['topic_id']]);
        $this->assertSame('Keys', $items[4]['topic_name']);
        $this->assertSame('Slide 7 of 12. Covered joins; next is self joins.', $items[5]['text']);
        $this->assertSame([true, true, true, true, true, true, true, false], array_column($items, 'include'));
    }

    public function test_saving_puts_everything_where_it_belongs_and_answers_become_evidence(): void
    {
        $sessions = app(Sessions::class);
        $session = $sessions->start($this->by, $this->databases->id, $this->joins->id);
        $this->travel(30)->minutes();
        $sessions->end($this->by, $session->id);
        $items = app(WriteBack::class)->review($this->by, $session->id, self::CHAT);
        $items[7]['include'] = true;
        $items[0]['text'] = 'A LEFT JOIN keeps every left row.';

        $result = app(WriteBack::class)->apply($this->by, $session->id, $items);

        $this->assertSame(['finding' => 1, 'question' => 1, 'flashcard' => 1, 'attempt' => 2, 'checkpoint' => 1, 'summary' => 1, 'status' => 1], $result['saved']);
        $this->assertSame([], $result['failed']);
        $findings = app(Findings::class)->byTopic($this->by, $this->databases->id)[$this->joins->id];
        $this->assertSame(['A LEFT JOIN keeps every left row.', 'ai'], [$findings[0]->text, $findings[0]->author]);
        $this->assertSame('Why are unmatched columns NULL rather than empty?', app(Questions::class)->list($this->by, $this->databases->id)[0]->text);
        $this->assertSame('What does a LEFT JOIN keep?', app(Flashcards::class)->list($this->by, $this->databases->id)[0]->front);
        $topics = collect(app(Topics::class)->list($this->by, $this->databases->id))->keyBy('name');
        $this->assertSame([$this->week1->id, 'understood'], [$topics['Keys']->moduleId, $topics['Joins']->status]);

        // The answers are attempts on tasks that exercise their topics, in this session, at its end.
        $entries = app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
        $attempts = array_values(array_filter($entries, fn ($e) => $e->kind->value === 'attempt'));
        $this->assertSame([['apply', 'unaided', 'correct', 'ai', 'chat'], ['recall', 'hinted', 'partial', 'ai', 'chat']],
            array_map(fn ($e) => [$e->body['form'], $e->body['support'], $e->body['outcome'], $e->body['judged_by'], $e->body['setting']], $attempts));
        $this->assertSame([$session->id, $session->id], array_map(fn ($e) => $e->sessionId, $attempts));
        $exercises = array_values(array_filter($entries, fn ($e) => $e->kind->value === 'claim' && $e->body['type'] === 'relates'));
        $this->assertContains(['task:'.$attempts[0]->body['task'], 'topic:'.$this->joins->id], array_map(fn ($e) => $e->body['targets'], $exercises));
        // The flashcard is a task of its own, exercising its topic too.
        $card = app(Flashcards::class)->list($this->by, $this->databases->id)[0];
        $this->assertContains(['task:card-'.$card->id, 'topic:'.$card->topicId], array_map(fn ($e) => $e->body['targets'], $exercises));
        $this->assertSame([$session->id, 'ai'], [$card->sessionId, $card->author]);
        // The rules now see practice on Joins, not only the student's word.
        $this->assertNotContains('claimed_only', $topics['Joins']->flags);
        $this->assertNotSame('not_started', $topics['Joins']->label);

        // The summary and checkpoint stay on the session, and the next briefing carries them.
        $ended = $sessions->find($this->by, $session->id);
        $this->assertSame(['We covered inner and left joins. The student applied left joins well; NULLs were confusing at first.', 'Slide 7 of 12. Covered joins; next is self joins.'], [$ended->summary, $ended->checkpoint]);
        $next = $sessions->start($this->by, $this->databases->id, $this->joins->id);
        $this->assertStringContainsString('The tutor\'s summary: We covered inner and left joins.', app(Briefings::class)->forSession($this->by, $next->id)->markdown);
    }

    public function test_a_session_note_gathers_what_was_saved_and_grows_with_the_next_paste(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->databases->id, $this->joins->id);
        $writeBack = app(WriteBack::class);
        $writeBack->apply($this->by, $session->id, $writeBack->review($this->by, $session->id, self::CHAT));

        $notes = app(Notes::class)->list($this->by, $this->databases->id);
        $this->assertCount(1, $notes);
        $this->assertSame(['Session: Joins · Mon 5 Oct', $this->week1->id], [$notes[0]->title, $notes[0]->moduleId]);
        $text = NoteDoc::markdown(app(Notes::class)->open($this->by, $notes[0]->id)->doc);
        foreach (['### Summary', '### Key points', 'even when age<18 has no match. (Joins)', '### Questions', '### Flashcards', 'What does a LEFT JOIN keep? → Every row of the left table.', '### Answers', 'Right: Which rows does customers LEFT JOIN orders return?', 'Partly right: What makes a foreign key?', '### Where it stands'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // The same chat again: everything saved is recognised, nothing is ticked. The status wasn't ticked, so it wasn't saved.
        $again = $writeBack->review($this->by, $session->id, self::CHAT);
        $this->assertSame([true, true, true, true, true, true, true, false], array_column($again, 'saved'));
        $this->assertSame([false], array_values(array_unique(array_column($again, 'include'))));

        // A new mark later goes into the same note.
        $writeBack->apply($this->by, $session->id, $writeBack->review($this->by, $session->id, '<finding topic="Joins">A RIGHT JOIN is a LEFT JOIN turned round.</finding>'));
        $this->assertCount(1, app(Notes::class)->list($this->by, $this->databases->id));
        $this->assertStringContainsString('A RIGHT JOIN is a LEFT JOIN turned round.', NoteDoc::markdown(app(Notes::class)->open($this->by, $notes[0]->id)->doc));
    }

    public function test_the_same_question_asked_again_is_the_same_task(): void
    {
        $sessions = app(Sessions::class);
        $first = $sessions->start($this->by, $this->databases->id, $this->joins->id);
        $writeBack = app(WriteBack::class);
        $mark = '<attempt topic="Joins" form="apply" result="incorrect"><asked>Which rows does a LEFT JOIN return?</asked><answer>Only matches</answer></attempt>';
        $writeBack->apply($this->by, $first->id, $writeBack->review($this->by, $first->id, $mark), note: false);
        $sessions->end($this->by, $first->id);
        $this->travel(2)->days();
        $second = $sessions->start($this->by, $this->databases->id, $this->joins->id);
        $writeBack->apply($this->by, $second->id, $writeBack->review($this->by, $second->id, str_replace(['incorrect', 'Only matches'], ['correct', 'All left rows'], $mark).' <attempt topic="Joins" result="correct"><asked>which rows does a left join return</asked><answer>All left rows</answer></attempt>'), note: false);

        $entries = app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
        $attempts = array_values(array_filter($entries, fn ($e) => $e->kind->value === 'attempt'));
        $this->assertCount(2, $attempts, 'The same answer to the same question, written twice, is one attempt.');
        $this->assertSame($attempts[0]->body['task'], $attempts[1]->body['task']);
        $this->assertSame([$first->id, $second->id], array_map(fn ($e) => $e->sessionId, $attempts));
        $this->assertCount(1, array_filter($entries, fn ($e) => $e->kind->value === 'record' && $e->body['record_type'] === 'task'));
        $this->assertCount(1, array_filter($entries, fn ($e) => $e->kind->value === 'claim' && $e->body['type'] === 'relates'));
    }

    public function test_bad_items_are_reported_and_the_rest_saved(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->databases->id, $this->joins->id);
        $items = app(WriteBack::class)->review($this->by, $session->id, self::CHAT);
        $items[0]['text'] = '';
        $items[1]['topic_id'] = 'someone-elses-topic';

        $result = app(WriteBack::class)->apply($this->by, $session->id, $items, note: false);

        $this->assertSame([0, 1], array_column($result['failed'], 0));
        $this->assertArrayNotHasKey('finding', $result['saved']);
        $this->assertSame(1, $result['saved']['flashcard']);
    }
}
