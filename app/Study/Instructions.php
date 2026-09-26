<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;

/**
 * What the assistant should know before a study session starts
 * (docs/specs/study-memory.md §3), written once instead of in every chat:
 * about the student (`me`, for every course), for a workspace, and for a
 * module. A session sends all three that apply. The student's own only.
 */
final class Instructions
{
    public const MAX_TEXT = 2000;

    /** The text for `me`, `workspace:{id}` or `module:{id}`; empty when none is written. */
    public function get(Principal $by, string $scope): string
    {
        $learner = Guard::learner($by);
        $this->check($learner, $scope);

        return (string) LearnerTables::query($learner, 'instructions')->where('scope', $scope)->value('text');
    }

    /**
     * Everything a session in this workspace (and module) starts from.
     *
     * @return array{me: string, workspace: string, module: string}
     */
    public function forSession(Principal $by, string $workspaceId, ?string $moduleId = null): array
    {
        return [
            'me' => $this->get($by, 'me'),
            'workspace' => $this->get($by, "workspace:{$workspaceId}"),
            'module' => $moduleId === null ? '' : $this->get($by, "module:{$moduleId}"),
        ];
    }

    /** Writes the instructions for $scope; empty text removes them. */
    public function set(Principal $by, string $scope, mixed $text): void
    {
        $learner = Guard::learner($by);
        $this->check($learner, $scope);
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        Input::refuse(mb_strlen($text) > self::MAX_TEXT ? ['text' => 'Keep it to '.self::MAX_TEXT.' characters.'] : []);

        $query = LearnerTables::query($learner, 'instructions')->where('scope', $scope);
        if ($text === '') {
            $query->delete();

            return;
        }
        if ($query->exists()) {
            $query->update(['text' => $text, 'updated_at' => now()]);

            return;
        }
        LearnerTables::insert($learner, 'instructions', ['id' => Ids::new(), 'scope' => $scope, 'text' => $text, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** `me`, or a workspace or module the student owns; anything else is 404. */
    private function check(LearnerScope $learner, string $scope): void
    {
        [$type, $id] = array_pad(explode(':', $scope, 2), 2, '');
        $ok = match ($type) {
            'me' => $id === '',
            'workspace' => LearnerTables::query($learner, 'workspaces')->where('id', $id)->exists(),
            'module' => LearnerTables::query($learner, 'modules')->where('id', $id)->exists(),
            default => false,
        };
        if (! $ok) {
            throw new NotFound;
        }
    }
}
