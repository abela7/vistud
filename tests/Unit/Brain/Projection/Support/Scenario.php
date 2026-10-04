<?php

namespace Tests\Unit\Brain\Projection\Support;

use App\Brain\Journal\EntryFactory;
use App\Brain\Journal\EntryValidator;
use App\Brain\Journal\JournalEntry;
use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\Projector;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds a learner's journal for WP4 developer tests, one entry per call, in
 * position order. Every entry is checked against the ADR 0002 contract
 * (EntryValidator) as it is added, so the scenarios stay valid journals.
 *
 * Times are local to Europe/London, written "2026-10-15 14:12", unless an
 * explicit offset is given. Claims default to accepted.
 *
 * This is developer tooling for WP4. The independent acceptance tests (V1)
 * are separate and do not use it.
 */
final class Scenario
{
    public const LEARNER = 'L1';

    private const TZ = 'Europe/London';

    /** @var list<array> */
    private array $specs = [];

    public static function make(): self
    {
        return new self;
    }

    // ---- records and setup -------------------------------------------------

    public function topic(string $id, string $at = '2026-10-01 09:00', string $status = 'active'): self
    {
        return $this->defines("K-{$id}-".count($this->specs), 'topic', $id, $at, ['kind' => 'concept', 'aliases' => [], 'status' => $status]);
    }

    public function task(string $id, string $at = '2026-10-01 09:00'): self
    {
        return $this->add([
            'id' => "R-{$id}-".count($this->specs), 'kind' => 'record', 'actor' => $this->system(), 'occurred_at' => $this->time($at),
            'body' => ['record_type' => 'task', 'record_id' => $id, 'key' => "key/{$id}", 'revision' => 1, 'status' => 'active'],
        ]);
    }

    /** A new revision of an existing task record: same identity, new content (ADR 0002 §4). */
    public function reviseTask(string $id, int $revision, string $at): self
    {
        return $this->add([
            'id' => "R-{$id}-v{$revision}", 'kind' => 'record', 'actor' => $this->system(), 'occurred_at' => $this->time($at),
            'body' => ['record_type' => 'task', 'record_id' => $id, 'key' => "key/{$id}", 'revision' => $revision, 'content_hash' => "hash-{$revision}", 'status' => 'active'],
        ]);
    }

    public function activity(string $id, string $kind, string $startsAt, string $at = '2026-10-01 09:00'): self
    {
        return $this->add([
            'id' => $id, 'kind' => 'record', 'actor' => $this->learner(), 'occurred_at' => $this->time($at),
            'body' => ['record_type' => 'activity', 'record_id' => $id, 'kind' => $kind, 'title' => $id, 'starts_at' => $this->time($startsAt)],
        ]);
    }

    /** A source or extraction record: nothing the projection reads, but part of the journal. */
    public function record(string $id, string $type, string $at): self
    {
        return $this->add([
            'id' => $id, 'kind' => 'record', 'actor' => $this->system(), 'occurred_at' => $this->time($at),
            'body' => ['record_type' => $type, 'record_id' => $id],
        ]);
    }

    public function exercises(string $task, string ...$topics): self
    {
        foreach ($topics as $topic) {
            $this->relates("X-{$task}-{$topic}-".count($this->specs), "task:{$task}", "topic:{$topic}", 'exercises');
        }

        return $this;
    }

    public function partOf(string $child, string $parent): self
    {
        return $this->relates("P-{$child}-{$parent}", "topic:{$child}", "topic:{$parent}", 'part_of');
    }

    public function examRelevant(string $id, string $activity, string $topic, string $at = '2026-10-01 09:00'): self
    {
        return $this->claim($id, 'relates', ["activity:{$activity}", "topic:{$topic}"],
            ['relation' => 'emphasizes', 'qualifiers' => ['emphasis' => 'exam_relevant'], 'status' => 'active'],
            at: $at, derivedFrom: ['source:SRC#00:34:10']);
    }

    public function misconception(string $claimId, string $id, array $topics, string $at, string $state = 'accepted', string $method = 'interpreter'): self
    {
        return $this->claim($claimId, 'defines', ["misconception:{$id}"],
            ['entity_type' => 'misconception', 'status' => 'active', 'topics' => array_map(fn ($t) => "topic:{$t}", $topics)],
            method: $method, at: $at, state: $state);
    }

    public function question(string $claimId, string $id, string $at): self
    {
        return $this->claim($claimId, 'defines', ["question:{$id}"], ['entity_type' => 'question', 'status' => 'active'], method: 'rule', at: $at);
    }

    public function refersTo(string $claimId, string $ask, string $question, string $at, string $state = 'accepted'): self
    {
        return $this->claim($claimId, 'refers_to', ["event:{$ask}"], ['entity' => "question:{$question}"], method: 'rule', at: $at, state: $state);
    }

    public function stemsFrom(string $claimId, string $question, string $ref, string $at): self
    {
        return $this->claim($claimId, 'relates', ["question:{$question}", $ref], ['relation' => 'stems_from', 'status' => 'active'], method: 'rule', at: $at);
    }

    public function addresses(string $claimId, string $exposure, string $misconception, string $at): self
    {
        return $this->claim($claimId, 'relates', ["event:{$exposure}", "misconception:{$misconception}"], ['relation' => 'addresses', 'status' => 'active'], at: $at);
    }

    public function sameAs(string $claimId, string $type, string $a, string $b, string $at, string $state = 'accepted'): self
    {
        return $this->claim($claimId, 'same_as', ["{$type}:{$a}", "{$type}:{$b}"], ['survivor' => "{$type}:{$b}"], method: 'rule', at: $at, state: $state);
    }

    /** @param array<string, string> $assignments evidence ref => new entity id */
    public function splitsInto(string $claimId, string $type, string $a, array $into, array $assignments, string $at): self
    {
        return $this->claim($claimId, 'splits_into', ["{$type}:{$a}"], [
            'into' => array_map(fn ($id) => "{$type}:{$id}", $into),
            'assignments' => array_map(fn ($ref, $to) => ['ref' => $ref, 'to' => "{$type}:{$to}"], array_keys($assignments), $assignments),
        ], method: 'person', at: $at, actor: $this->learner());
    }

    // ---- observations ------------------------------------------------------

    /** @param array{format?: string, approach?: list<string>, by?: string, session?: string, responds_to?: string, precision?: string, until?: string, activity?: string, origin?: string} $o */
    public function exposure(string $id, string $at, array $about, array $o = []): self
    {
        $links = array_map(fn ($ref) => ['rel' => 'about', 'target' => $ref], $about);
        if (isset($o['responds_to'])) {
            $links[] = ['rel' => 'responds_to', 'target' => "event:{$o['responds_to']}"];
        }
        $by = $o['by'] ?? 'lecturer';

        return $this->add(array_filter([
            'id' => $id, 'kind' => 'exposure', 'actor' => $this->learner(), 'origin' => $o['origin'] ?? 'first_hand',
            'occurred_at' => $this->time($at), 'occurred_until' => isset($o['until']) ? $this->time($o['until']) : null,
            'precision' => $o['precision'] ?? null, 'session' => $o['session'] ?? null, 'activity' => $o['activity'] ?? null,
            'links' => $links,
            'body' => ['format' => $o['format'] ?? 'explanation', 'approach' => $o['approach'] ?? [], 'by' => ['type' => $by]],
            'content' => $by === 'chat_ai' ? ['summary' => 'An explanation.'] : null,
        ], fn ($v) => $v !== null));
    }

    /** @param array{form?: string, support?: string, setting?: string, session?: string, precision?: string, revision?: int, key_source?: string} $o */
    public function attempt(string $id, string $at, string $task, string $outcome, string $judgedBy = 'auto', array $o = []): self
    {
        $body = [
            'task' => $task, 'task_revision' => $o['revision'] ?? 1, 'form' => $o['form'] ?? 'apply', 'support' => $o['support'] ?? 'unaided',
            'setting' => $o['setting'] ?? 'practice', 'outcome' => $outcome, 'judged_by' => $judgedBy,
        ];
        if ($judgedBy === 'auto') {
            $body['checker'] = ['id' => 'checker', 'version' => 1, 'key_source' => $o['key_source'] ?? 'course_material'];
        }

        return $this->add(array_filter([
            'id' => $id, 'kind' => 'attempt', 'actor' => $this->learner(), 'origin' => 'first_hand',
            'occurred_at' => $this->time($at), 'precision' => $o['precision'] ?? null, 'session' => $o['session'] ?? null,
            'body' => $body, 'content' => ['answer' => 'An answer.'],
        ], fn ($v) => $v !== null));
    }

    public function ask(string $id, string $at, array $about = [], ?string $session = null): self
    {
        return $this->add(array_filter([
            'id' => $id, 'kind' => 'question', 'actor' => $this->learner(), 'origin' => 'first_hand', 'occurred_at' => $this->time($at),
            'session' => $session, 'links' => array_map(fn ($ref) => ['rel' => 'about', 'target' => $ref], $about),
            'content' => ['text' => 'A question.'],
        ], fn ($v) => $v !== null && $v !== []));
    }

    public function selfReport(string $id, string $at, string $stance, array $about, ?string $session = null, ?string $triggeredBy = null): self
    {
        $links = array_map(fn ($ref) => ['rel' => 'about', 'target' => $ref], $about);
        if ($triggeredBy !== null) {
            $links[] = ['rel' => 'triggered_by', 'target' => "event:{$triggeredBy}"];
        }

        return $this->add(array_filter([
            'id' => $id, 'kind' => 'self_report', 'actor' => $this->learner(), 'origin' => 'first_hand', 'occurred_at' => $this->time($at),
            'session' => $session, 'links' => $links,
            'body' => ['stance' => $stance],
        ], fn ($v) => $v !== null));
    }

    public function retract(string $id, string $target, string $at): self
    {
        return $this->add([
            'id' => $id, 'kind' => 'amendment', 'actor' => $this->learner(), 'occurred_at' => $this->time($at),
            'body' => ['action' => 'retract', 'targets' => ["event:{$target}"], 'reason' => 'wrong_capture'],
        ]);
    }

    // ---- claims ------------------------------------------------------------

    /**
     * A verdict on an attempt or exposure.
     *
     * @param  array{method?: string, derived_from?: list<string>, supersedes?: list<string>, state?: string}  $o
     */
    public function judges(string $id, string $target, array $value, string $at, array $o = []): self
    {
        return $this->claim($id, 'judges', ["event:{$target}"], $value,
            method: $o['method'] ?? 'interpreter', at: $at, state: $o['state'] ?? 'accepted',
            derivedFrom: $o['derived_from'] ?? [], supersedes: $o['supersedes'] ?? []);
    }

    /** A review by the learner: dispute, withdraw, accept or reject. */
    public function learnerReview(string $id, string $target, string $decision, string $at, array $value = []): self
    {
        return $this->claim($id, 'reviews', [$target], ['decision' => $decision, 'reason' => 'other'] + $value,
            method: 'person', at: $at, actor: $this->learner());
    }

    public function claim(
        string $id, string $type, array $targets, array $value, string $method = 'interpreter', string $at = '2026-10-01 09:00',
        string $state = 'accepted', array $derivedFrom = [], array $supersedes = [], ?array $actor = null,
    ): self {
        return $this->add([
            'id' => $id, 'kind' => 'claim', 'actor' => $actor ?? $this->system(), 'occurred_at' => $this->time($at),
            'body' => [
                'type' => $type, 'targets' => $targets, 'value' => $value,
                'confidence' => in_array($method, ['person', 'rule', 'auto'], true) ? null : 0.9,
                'method' => ['kind' => $method, 'id' => $method === 'person' ? self::LEARNER : $method, 'version' => '1'],
                'derived_from' => $derivedFrom, 'supersedes' => $supersedes, 'review' => ['state' => $state],
            ],
        ]);
    }

    private function defines(string $claimId, string $type, string $id, string $at, array $value): self
    {
        return $this->claim($claimId, 'defines', ["{$type}:{$id}"], ['entity_type' => $type] + $value, method: 'rule', at: $at);
    }

    private function relates(string $id, string $from, string $to, string $relation): self
    {
        return $this->claim($id, 'relates', [$from, $to], ['relation' => $relation, 'status' => 'active'], method: 'rule');
    }

    // ---- output ------------------------------------------------------------

    public function add(array $spec): self
    {
        $spec += ['tz' => self::TZ];
        EntryValidator::validate($spec);
        $this->specs[] = $spec;

        return $this;
    }

    /** @return list<array> */
    public function specs(): array
    {
        return $this->specs;
    }

    /** @return list<JournalEntry> positions 1, 2, 3… in the order added */
    public function entries(): array
    {
        return array_map(
            fn (array $spec, int $i) => EntryFactory::make($spec, self::LEARNER, $i + 1),
            $this->specs,
            array_keys($this->specs),
        );
    }

    public function position(string $id): int
    {
        foreach ($this->specs as $i => $spec) {
            if ($spec['id'] === $id) {
                return $i + 1;
            }
        }
        throw new \InvalidArgumentException("No entry {$id}.");
    }

    public function project(string $now, ?int $maxPosition = null, ?string $observedUntil = null): array
    {
        return (new Projector)->project($this->entries(), new ProjectionOptions(
            now: new DateTimeImmutable($this->time($now)),
            maxPosition: $maxPosition,
            observedUntil: $observedUntil === null ? null : new DateTimeImmutable($this->time($observedUntil)),
        ));
    }

    public function time(string $local): string
    {
        if (preg_match('/(Z|[+-]\d{2}:\d{2})$/', $local) === 1) {
            return $local;
        }

        return (new DateTimeImmutable($local, new DateTimeZone(self::TZ)))->format('Y-m-d\TH:i:sP');
    }

    private function learner(): array
    {
        return ['type' => 'learner', 'id' => self::LEARNER, 'channel' => 'web'];
    }

    private function system(): array
    {
        return ['type' => 'processor', 'id' => 'resolver', 'channel' => 'job'];
    }
}
