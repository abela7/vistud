<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;

/** The numbers beside a module's tabs (docs/specs/vistud-2-blueprint.md §3.5.3): its topics, files, notes, open questions and study sessions. */
final class ModuleTabs
{
    public function __construct(private Questions $questions, private Sessions $sessions) {}

    /** @return array{topics: int, files: int, notes: int, questions: int, sessions: int} */
    public function counts(Principal $by, string $workspaceId, string $moduleId): array
    {
        $scope = Guard::learner($by);
        $in = fn (string $table) => LearnerTables::query($scope, $table)->where('workspace_id', $workspaceId)->where('module_id', $moduleId);

        return [
            'topics' => $in('topics')->whereNull('retired_at')->count(),
            'files' => $in('files')->whereNull('trashed_at')->count(),
            'notes' => $in('notes')->whereNull('trashed_at')->count(),
            'questions' => count(array_filter($this->questions->list($by, $workspaceId, $moduleId), fn (QuestionDetails $q) => $q->status !== 'answered')),
            'sessions' => count($this->sessions->forModule($by, $workspaceId, $moduleId)),
        ];
    }
}
