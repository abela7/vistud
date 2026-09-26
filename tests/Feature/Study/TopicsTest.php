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

    private function entries(): array
    {
        return app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
    }
}
