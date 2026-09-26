<?php

namespace App\Study;

use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\ProjectionRunner;
use App\Brain\Writer\JournalWriter;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Ids;
use DateTimeImmutable;

/**
 * The tracker's door to the journal (docs/specs/study-memory.md S2): the
 * student's clicks become journal events, and the screens read the state
 * the rules derive from them. The projection is recomputed on read; the
 * pilot's journals are small, and a cache would only add a way to be stale.
 */
final class Memory
{
    public function __construct(private JournalWriter $journal, private ProjectionRunner $projections) {}

    /**
     * Appends the events in order, as one batch. Given the workspace they
     * belong to, they carry the id of the study session open in it, so the
     * rules can tell sessions apart (ADR 0002 §7).
     */
    public function append(LearnerScope $scope, array $specs, ?string $workspaceId = null): void
    {
        $session = $workspaceId === null ? null : Sessions::openIn($scope, $workspaceId);
        if ($session !== null) {
            $specs = array_map(fn (array $spec) => $spec + ['session' => $session], $specs);
        }
        $this->journal->appendBatch($scope, $specs);
    }

    /** The derived state now: topics, questions, misconceptions, profile (ADR 0002 §7). */
    public function snapshot(LearnerScope $scope): array
    {
        return $this->projections->project($scope, new ProjectionOptions(new DateTimeImmutable));
    }

    /** The learner as the actor of an event written from a request. */
    public static function actor(LearnerScope $scope, Principal $by): array
    {
        return ['type' => 'learner', 'id' => $scope->learnerId, 'channel' => in_array($by->channel, ['web', 'api'], true) ? $by->channel : 'web'];
    }

    /** A claim the student makes about their own material (a person's claim is accepted as it is). */
    public static function claim(LearnerScope $scope, Principal $by, string $type, array $targets, array $value, array $content = [], ?string $at = null): array
    {
        return array_filter([
            'id' => Ids::new(),
            'kind' => 'claim',
            'actor' => self::actor($scope, $by),
            'occurred_at' => $at ?? self::now(),
            'body' => [
                'type' => $type,
                'targets' => $targets,
                'value' => $value,
                'confidence' => null,
                'method' => ['kind' => 'person', 'id' => $scope->learnerId, 'version' => '1'],
                'review' => ['state' => 'accepted'],
            ],
            'content' => $content,
        ], fn ($v) => $v !== []);
    }

    /** Something the student observed first-hand: an exposure, a question, a self-report. */
    public static function observation(LearnerScope $scope, Principal $by, string $kind, array $body, array $links = [], array $content = [], ?string $at = null): array
    {
        return array_filter([
            'id' => Ids::new(),
            'kind' => $kind,
            'actor' => self::actor($scope, $by),
            'origin' => 'first_hand',
            'occurred_at' => $at ?? self::now(),
            'body' => $body,
            'links' => $links,
            'content' => $content,
        ], fn ($v) => $v !== []);
    }

    /** Now, to the microsecond, so events written moments apart keep their order. */
    public static function now(): string
    {
        return now()->format('Y-m-d\TH:i:s.uP');
    }
}
