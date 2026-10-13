<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The assignment's activity log (the owner's ask, 2026-10-13): decisions, progress, advice, feedback and risks
 * recorded throughout the assignment journey, kept so that any model starts from the same log.
 */
final class AssignmentLog
{
    public const KINDS = [
        'decision' => 'Decision',
        'progress' => 'Progress',
        'advice' => 'Advice',
        'feedback' => 'Feedback',
        'risk' => 'Risk',
    ];

    public const MAX_TEXT = 500;

    public const MAX_ENTRIES = 300;

    public function __construct(private Activities $activities) {}

    /** @return list<AssignmentLogEntry> newest first */
    public function list(Principal $by, string $activityId, int $limit = 50): array
    {
        $scope = Guard::learner($by);
        $this->activities->find($by, $activityId);

        return LearnerTables::query($scope, 'activity_log')
            ->where('activity_id', $activityId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => self::details($row))
            ->all();
    }

    /** @return list<string> all log entry IDs for an assignment */
    public function ids(Principal $by, string $activityId): array
    {
        $scope = Guard::learner($by);
        $this->activities->find($by, $activityId);

        return LearnerTables::query($scope, 'activity_log')
            ->where('activity_id', $activityId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function add(Principal $by, string $activityId, string $stage, string $kind, string $text, string $byRole = 'tutor'): AssignmentLogEntry
    {
        $scope = Guard::learner($by);
        $text = trim($text);
        $byRole = strtolower(trim($byRole));
        $stage = strtolower(trim($stage));
        $kind = strtolower(trim($kind));

        Input::refuse(array_filter([
            'stage' => in_array($stage, Activities::STAGES, true) ? null : 'Unknown stage.',
            'kind' => array_key_exists($kind, self::KINDS) ? null : 'Choose what kind of entry this is.',
            'by' => in_array($byRole, ['tutor', 'student'], true) ? null : 'Unknown author.',
            'text' => match (true) {
                $text === '' => 'Write what happened or what was decided.',
                mb_strlen($text) > self::MAX_TEXT => 'Keep an entry to '.self::MAX_TEXT.' characters.',
                default => null,
            },
        ]));

        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $stage, $kind, $text, $byRole, $id) {
            $assignment = LearnerTables::query($scope, 'activities')->where('id', $activityId)->lockForUpdate()->first() ?? throw new NotFound;
            // Full: the oldest go, so the tutor (which can't delete) never stops logging; the context reads the newest.
            $over = LearnerTables::query($scope, 'activity_log')->where('activity_id', $activityId)->count() - self::MAX_ENTRIES + 1;
            if ($over > 0) {
                $oldest = LearnerTables::query($scope, 'activity_log')->where('activity_id', $activityId)
                    ->orderBy('created_at')->orderBy('id')->limit($over)->pluck('id')->all();
                LearnerTables::query($scope, 'activity_log')->whereIn('id', $oldest)->delete();
            }

            LearnerTables::insert($scope, 'activity_log', [
                'id' => $id,
                'workspace_id' => $assignment->workspace_id,
                'activity_id' => $activityId,
                'stage' => $stage,
                'kind' => $kind,
                'text' => $text,
                'by' => $byRole,
                'created_at' => now(),
            ]);
        });

        $row = LearnerTables::query($scope, 'activity_log')->where('id', $id)->first() ?? throw new NotFound;

        return self::details($row);
    }

    /** Deletes one log entry. */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        LearnerTables::query($scope, 'activity_log')->where('id', $id)->delete();
    }

    private static function details(object $row): AssignmentLogEntry
    {
        return new AssignmentLogEntry(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            activityId: (string) $row->activity_id,
            stage: (string) $row->stage,
            kind: (string) $row->kind,
            text: (string) $row->text,
            by: (string) $row->by,
            createdAt: (string) $row->created_at,
        );
    }
}
