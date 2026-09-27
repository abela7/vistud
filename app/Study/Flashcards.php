<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Flashcards (docs/specs/study-memory.md §4.4): a front and a back, pinned to
 * a topic, written by the student or saved from a study session. Reviewing
 * them comes with the flashcard step. The student's own stream only.
 */
final class Flashcards
{
    public const MAX_FRONT = 500;

    public const MAX_BACK = 1000;

    /** @return list<object{id: string, topic_id: ?string, front: string, back: string, author: string}> newest first */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'flashcards')->where('workspace_id', $workspaceId)->whereNull('retired_at')
            ->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'topic_id', 'front', 'back', 'author', 'session_id'])->all();
    }

    public function add(Principal $by, string $workspaceId, ?string $topicId, mixed $front, mixed $back, string $author = 'student', ?string $sessionId = null): string
    {
        $scope = Guard::learner($by);
        $front = Input::text(['v' => $front], 'v');
        $back = Input::text(['v' => $back], 'v');
        Input::refuse(array_filter([
            'front' => $front === null ? 'Write the front.' : (mb_strlen($front) > self::MAX_FRONT ? 'Keep it to '.self::MAX_FRONT.' characters.' : null),
            'back' => $back === null ? 'Write the back.' : (mb_strlen($back) > self::MAX_BACK ? 'Keep it to '.self::MAX_BACK.' characters.' : null),
        ]));
        $id = Ids::new();

        DB::transaction(function () use ($scope, $workspaceId, $topicId, $front, $back, $author, $sessionId, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            if ($topicId !== null) {
                LearnerTables::query($scope, 'topics')->where('id', $topicId)->where('workspace_id', $workspaceId)->whereNull('retired_at')->exists() || throw new NotFound;
            }
            LearnerTables::insert($scope, 'flashcards', [
                'id' => $id, 'workspace_id' => $workspaceId, 'topic_id' => $topicId, 'front' => $front, 'back' => $back,
                'author' => in_array($author, ['student', 'ai'], true) ? $author : 'student', 'session_id' => $sessionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }

    public function retire(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        LearnerTables::query($scope, 'flashcards')->where('id', $id)->whereNull('retired_at')->exists() || throw new NotFound;
        LearnerTables::query($scope, 'flashcards')->where('id', $id)->update(['retired_at' => now(), 'updated_at' => now()]);
    }
}
