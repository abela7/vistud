<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;

/** The numbers beside a module's tabs: topics, files, notes, open questions, study sessions, and classes. */
final class ModuleTabs
{
    public function __construct(private Questions $questions, private Sessions $sessions) {}

    /** @return array{folders: int, files: int, notes: int, topics: int, questions: int, sessions: int, classes: int} */
    public function counts(Principal $by, string $workspaceId, string $moduleId): array
    {
        $scope = Guard::learner($by);
        $in = fn (string $table) => LearnerTables::query($scope, $table)->where('workspace_id', $workspaceId)->where('module_id', $moduleId);

        return [
            'folders' => LearnerTables::query($scope, 'folders')->where('workspace_id', $workspaceId)->where('module_id', $moduleId)->whereNull('parent_id')->count(),
            'files' => $in('files')->whereNull('trashed_at')->count(),
            'notes' => $in('notes')->whereNull('trashed_at')->count(),
            'topics' => $in('topics')->whereNull('retired_at')->count(),
            'questions' => count(array_filter($this->questions->list($by, $workspaceId, $moduleId), fn (QuestionDetails $q) => $q->open())),
            'sessions' => count($this->sessions->forModule($by, $workspaceId, $moduleId)),
            'classes' => $in('class_sessions')->count(),
        ];
    }
}
