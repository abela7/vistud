<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Brain\Writer\JournalWriter;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Topics, statuses and questions on the journal (docs/specs/study-memory.md §3). */
class TopicsTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private Topics $topics;

    private Questions $questions;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->topics = app(Topics::class);
        $this->questions = app(Questions::class);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1: Relational model']);
    }

    public function test_a_topic_is_a_journal_entity_and_starts_with_no_contact(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, '  Joins ', $this->week1->id);
        $normal = $this->topics->create($this->by, $this->databases->id, 'Normalisation');

        $this->assertSame(['Joins', $this->week1->id, 'not_started', 'not_started', 'no contact yet'], [$joins->name, $joins->moduleId, $joins->label, $joins->shown(), $joins->evidence()]);
        $this->assertSame([null, 2], [$normal->moduleId, $normal->position]);

        $claims = array_filter($this->entries(), fn ($e) => $e->kind->value === 'claim' && $e->body['type'] === 'defines');
        $this->assertSame(['topic', 'person', 'accepted'], [
            end($claims)->body['value']['entity_type'], end($claims)->body['method']['kind'], end($claims)->body['review']['state'],
        ]);

        $this->assertThrows(fn () => $this->topics->create($this->by, $this->databases->id, ' '), Unprocessable::class);
        $other = app(Workspaces::class)->create($this->by, ['name' => 'Maths']);
        $this->assertThrows(fn () => $this->topics->create($this->by, $other->id, 'Sets', $this->week1->id), NotFound::class);
    }

    public function test_the_students_word_becomes_evidence_and_the_rules_answer_honestly(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins');

        $this->topics->report($this->by, $joins->id, 'covered');
        $covered = $this->topics->find($this->by, $joins->id);
        $this->assertSame(['covered', 'introduced', 'covered'], [$covered->status, $covered->label, $covered->shown()]);
        $this->assertNotNull($covered->lastContact);

        $this->topics->report($this->by, $joins->id, 'understood');
        $understood = $this->topics->find($this->by, $joins->id);
        // The student says understood; the rules say: seen, not practised yet.
        $this->assertSame(['understood', 'introduced', ['claimed_only']], [$understood->shown(), $understood->label, $understood->flags]);
        $this->assertSame('seen, not practised · not practised yet', $understood->evidence());

        $this->topics->report($this->by, $joins->id, 'confused');
        $this->assertSame('confused', $this->topics->find($this->by, $joins->id)->shown());

        $kinds = array_map(fn ($e) => $e->kind->value.(isset($e->body['stance']) ? ':'.$e->body['stance'] : ''), $this->entries());
        $this->assertSame(['exposure', 'self_report:confident', 'self_report:confused'], array_slice($kinds, -3));
        $this->assertThrows(fn () => $this->topics->report($this->by, $joins->id, 'mastered'), Unprocessable::class);
    }

    public function test_mastered_is_earned_from_practice_not_claimed(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins');
        $scope = $this->learnerScopeOf($this->ada);

        // Two unaided, correctly checked apply tasks across two sessions, then explained in the learner's own words (ADR 0002 §7).
        $entries = [
            $this->taskRecord('R1', 'TK-1', 'LAB/q1'), $this->exercises('K2', 'TK-1', $joins->id),
            $this->taskRecord('R2', 'TK-2', 'LAB/q2'), $this->exercises('K3', 'TK-2', $joins->id),
            $this->attempt($scope, 'A1', 'TK-1', 'correct', '2026-10-01T10:00:00+01:00'),
            $this->attempt($scope, 'A2', 'TK-2', 'correct', '2026-10-03T10:00:00+01:00'),
            $this->attempt($scope, 'A3', 'TK-1', 'correct', '2026-10-06T10:00:00+01:00', ['body' => ['form' => 'explain']]),
            $this->interpreterJudges('J1', 'A3', ['own_words' => true], '2026-10-06T10:05:00+01:00'),
        ];
        app(JournalWriter::class)->appendBatch($scope, $entries);

        $this->assertSame(['secure', 'mastered'], [$this->topics->find($this->by, $joins->id)->label, $this->topics->find($this->by, $joins->id)->shown()]);
    }

    public function test_topics_are_renamed_moved_reordered_and_retired(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins');
        $normal = $this->topics->create($this->by, $this->databases->id, 'Normalisation', $this->week1->id);

        $this->topics->rename($this->by, $joins->id, 'SQL joins');
        $this->topics->move($this->by, $joins->id, $this->week1->id);
        $this->topics->reorder($this->by, $normal->id, 0);
        $this->assertSame([['Normalisation', $this->week1->id], ['SQL joins', $this->week1->id]], array_map(fn ($t) => [$t->name, $t->moduleId], $this->topics->list($this->by, $this->databases->id)));

        $this->topics->move($this->by, $joins->id, null);
        $question = $this->questions->ask($this->by, $this->databases->id, 'Why is a left join different?', $joins->id);
        $this->topics->retire($this->by, $joins->id);
        $this->assertSame(['Normalisation'], array_map(fn ($t) => $t->name, $this->topics->list($this->by, $this->databases->id)));
        $this->assertNull($this->questions->find($this->by, $question->id)->topicId);
        $this->assertThrows(fn () => $this->topics->find($this->by, $joins->id), NotFound::class);
    }

    public function test_questions_open_get_understood_and_reopen_through_the_journal(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins');

        $question = $this->questions->ask($this->by, $this->databases->id, ' Why is a left join   different from an inner join? ', $joins->id);
        $this->assertSame(['Why is a left join different from an inner join?', 'open', 'open', false], [$question->text, $question->state, $question->shown(), $question->askTeacher]);

        $this->questions->setAskTeacher($this->by, $question->id, true);
        $this->questions->resolve($this->by, $question->id);
        $resolved = $this->questions->find($this->by, $question->id);
        $this->assertSame(['resolved_learner_confirmed', 'understood', true], [$resolved->state, $resolved->shown(), $resolved->askTeacher]);
        // The explanation that answered it counts as contact with the topic.
        $this->assertSame('introduced', $this->topics->find($this->by, $joins->id)->label);

        $this->questions->reopen($this->by, $question->id);
        $reopened = $this->questions->find($this->by, $question->id);
        $this->assertSame('open', $reopened->shown());
        $this->assertContains('reopened', $reopened->flags);

        $this->questions->retire($this->by, $question->id);
        $this->assertSame([], $this->questions->list($this->by, $this->databases->id));
        $this->assertThrows(fn () => $this->questions->ask($this->by, $this->databases->id, ''), Unprocessable::class);
    }

    public function test_a_question_is_pending_stuck_or_answered_in_its_module_and_session(): void
    {
        $week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1']);
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins', $week1->id);
        $session = app(Sessions::class)->start($this->by, $this->databases->id);

        // Its module is the topic's, and it belongs to the open session.
        $question = $this->questions->ask($this->by, $this->databases->id, 'What does NULL mean in a join?', $joins->id);
        $this->assertSame([$week1->id, 'pending', $session->id], [$question->moduleId, $question->status, $question->sessionId]);
        $loose = $this->questions->ask($this->by, $this->databases->id, 'What is a key?', null, $week1->id);
        $this->assertSame([$week1->id, null], [$loose->moduleId, $loose->topicId]);
        $this->assertCount(2, $this->questions->list($this->by, $this->databases->id, $week1->id));
        $this->assertCount(2, $this->questions->list($this->by, $this->databases->id, null, $session->id));

        $stuck = $this->questions->setStatus($this->by, $question->id, 'stuck');
        $this->assertSame(['stuck', 'open'], [$stuck->status, $stuck->shown()]);
        $answered = $this->questions->setStatus($this->by, $question->id, 'answered', '  No value: the row had no match.  ');
        $this->assertSame(['answered', 'No value: the row had no match.', 'understood'], [$answered->status, $answered->answer, $answered->shown()]);
        $this->assertStringStartsWith('resolved', $answered->state);
        $again = $this->questions->setStatus($this->by, $question->id, 'pending');
        $this->assertSame(['pending', 'No value: the row had no match.'], [$again->status, $again->answer]);
        $this->assertContains('reopened', $again->flags);

        $this->assertSame('What does NULL mean in a left join?', $this->questions->update($this->by, $question->id, 'What does NULL mean in a left join?')->text);
        $this->assertThrows(fn () => $this->questions->setStatus($this->by, $question->id, 'maybe'), Unprocessable::class);
        $this->assertThrows(fn () => $this->questions->setStatus($this->by, $question->id, 'answered', str_repeat('a', 2001)), Unprocessable::class);
        $this->assertThrows(fn () => $this->questions->ask($this->by, $this->databases->id, 'Q', null, 'no-such-module'), NotFound::class);
    }

    public function test_another_students_topics_and_questions_are_missing(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $topic = $this->topics->create($bob, $theirs->id, 'Secret');
        $question = $this->questions->ask($bob, $theirs->id, 'Their question', $topic->id);

        foreach ([
            fn () => $this->topics->list($this->by, $theirs->id),
            fn () => $this->topics->find($this->by, $topic->id),
            fn () => $this->topics->report($this->by, $topic->id, 'understood'),
            fn () => $this->topics->create($this->by, $this->databases->id, 'Mine', 'no-such-module'),
            fn () => $this->questions->find($this->by, $question->id),
            fn () => $this->questions->resolve($this->by, $question->id),
            fn () => $this->questions->ask($this->by, $this->databases->id, 'Mine', $topic->id),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFound::class);
        }
        $this->assertSame('Secret', $this->topics->find($bob, $topic->id)->name);
    }

    public function test_the_student_sets_a_status_and_the_tutor_marks_one_that_can_be_undone_but_never_over_the_students(): void
    {
        $joins = $this->topics->create($this->by, $this->databases->id, 'Joins', $this->week1->id);
        $keys = $this->topics->create($this->by, $this->databases->id, 'Keys', $this->week1->id);
        $this->assertSame([null, null, null], [$joins->status, $joins->statusBy, $joins->statusAt]);
        $this->assertFalse($joins->byTutor());

        // The student's word is theirs.
        $this->topics->report($this->by, $keys->id, 'understood');
        $mine = $this->topics->find($this->by, $keys->id);
        $this->assertSame(['understood', 'student', false], [$mine->status, $mine->statusBy, $mine->byTutor()]);
        $this->assertNotNull($mine->statusAt);

        // The tutor's word on a topic with none: shown as the tutor's, and not evidence in the journal.
        $before = count($this->entries());
        $undo = $this->topics->mark($this->by, $joins->id, 'confused');
        $marked = $this->topics->find($this->by, $joins->id);
        $this->assertSame(['status' => null, 'by' => null, 'at' => null], $undo);
        $this->assertSame(['confused', 'tutor', true, 'confused'], [$marked->status, $marked->statusBy, $marked->byTutor(), $marked->shown()]);
        $this->assertSame($before, count($this->entries()));

        // Never over the student's own, and nothing changes when it already says that.
        $this->assertNull($this->topics->mark($this->by, $keys->id, 'confused'));
        $this->assertNull($this->topics->mark($this->by, $joins->id, 'confused'));
        $this->assertSame('understood', $this->topics->find($this->by, $keys->id)->status);

        // The tutor can change its own word, and undo goes back to what was before each.
        $second = $this->topics->mark($this->by, $joins->id, 'understood');
        $this->assertSame('confused', $second['status']);
        $this->topics->unmark($this->by, $joins->id, $second);
        $this->assertSame(['confused', 'tutor'], [$this->topics->find($this->by, $joins->id)->status, $this->topics->find($this->by, $joins->id)->statusBy]);
        $this->topics->unmark($this->by, $joins->id, $undo);
        $cleared = $this->topics->find($this->by, $joins->id);
        $this->assertSame([null, null, null], [$cleared->status, $cleared->statusBy, $cleared->statusAt]);

        // Once the student has said something, the tutor's undo leaves their word alone.
        $this->topics->mark($this->by, $joins->id, 'covered');
        $this->topics->report($this->by, $joins->id, 'understood');
        $this->topics->unmark($this->by, $joins->id, $undo);
        $this->assertSame(['understood', 'student'], [$this->topics->find($this->by, $joins->id)->status, $this->topics->find($this->by, $joins->id)->statusBy]);

        // Only the three statuses, and only the student's own topics.
        $this->assertThrows(fn () => $this->topics->mark($this->by, $joins->id, 'mastered'), Unprocessable::class);
        $this->assertThrows(fn () => $this->topics->mark($this->principal($this->student()), $joins->id, 'covered'), NotFound::class);
    }

    private function entries(): array
    {
        return app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
    }
}
