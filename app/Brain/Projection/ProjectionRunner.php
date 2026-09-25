<?php

namespace App\Brain\Projection;

use App\Brain\Store\JournalReader;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;

/**
 * Projects a learner's stored journal (ADR 0002 §7). The projection itself
 * is pure; this reads the entries and keeps the snapshot cache, which is
 * derived and may be emptied at any time.
 */
final class ProjectionRunner
{
    public function __construct(private JournalReader $reader, private Projector $projector = new Projector) {}

    public function project(LearnerScope $scope, ProjectionOptions $options): array
    {
        return $this->projector->project($this->reader->entries($scope, $options->maxPosition), $options);
    }

    /** Projects the current state and stores it as the learner's latest snapshot. */
    public function refresh(LearnerScope $scope, ProjectionOptions $options): array
    {
        $snapshot = $this->project($scope, $options);

        LearnerTables::query($scope, 'projection_snapshots')->where('rules_version', Rules::VERSION)->delete();
        LearnerTables::insert($scope, 'projection_snapshots', [
            'rules_version' => Rules::VERSION,
            'position' => $snapshot['position'],
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'computed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $snapshot;
    }

    /** The latest stored snapshot, or null. It may be behind the journal. */
    public function latest(LearnerScope $scope): ?array
    {
        $row = LearnerTables::query($scope, 'projection_snapshots')->where('rules_version', Rules::VERSION)->first();

        return $row === null ? null : json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
    }
}
