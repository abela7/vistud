<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;

/**
 * The module layer of the tutor's context (docs/specs/vistud-2-blueprint.md §3.6.2, §3.7): for a module, its files with a line
 * on each (what the reader found in them), its open questions (stuck first), its key points and where the last session
 * stopped, each short and few. It is kept in `module_briefs` by a fingerprint of what it is made of, so it is the same word
 * for word until something in the module changes, and what the tutor was told can be read back. The student's own
 * modules only: another student's is 404.
 */
final class ModuleBriefs
{
    public const QUESTIONS = 5;

    public const KEY_POINTS = 8;

    private const LINE = 110;

    public function __construct(
        private Files $files,
        private FileDigests $digests,
        private Questions $questions,
        private Findings $findings,
        private Topics $topics,
        private Sessions $sessions,
        private Folders $folders,
    ) {}

    public function for(Principal $by, string $moduleId): ModuleBrief
    {
        $scope = Guard::learner($by);
        $module = LearnerTables::query($scope, 'modules')->where('id', $moduleId)->first() ?? throw new NotFound;
        $brief = $this->build($by, $module->workspace_id, $moduleId);

        $fingerprint = sha1((string) json_encode($brief->toArray()));
        $kept = LearnerTables::query($scope, 'module_briefs')->where('module_id', $moduleId)->first();
        if ($kept === null) {
            LearnerTables::insert($scope, 'module_briefs', ['module_id' => $moduleId, 'text' => json_encode($brief->toArray()), 'fingerprint' => $fingerprint, 'built_at' => now()]);
        } elseif ($kept->fingerprint !== $fingerprint) {
            LearnerTables::query($scope, 'module_briefs')->where('module_id', $moduleId)->update(['text' => json_encode($brief->toArray()), 'fingerprint' => $fingerprint, 'built_at' => now()]);
        }

        return $brief;
    }

    /**
     * The same for a folder (docs/specs/vistud-2-blueprint.md, Phase 9): what is in it and in the folders inside it, its
     * questions and key points, and where the last session in it stopped. Kept in `folder_briefs`.
     */
    public function forFolder(Principal $by, string $folderId): ModuleBrief
    {
        $scope = Guard::learner($by);
        $folder = LearnerTables::query($scope, 'folders')->where('id', $folderId)->first() ?? throw new NotFound;
        $brief = $this->build($by, $folder->workspace_id, $folder->module_id, $this->folders->within($by, $folderId));

        $fingerprint = sha1((string) json_encode($brief->toArray()));
        $kept = LearnerTables::query($scope, 'folder_briefs')->where('folder_id', $folderId)->first();
        if ($kept === null) {
            LearnerTables::insert($scope, 'folder_briefs', ['folder_id' => $folderId, 'text' => json_encode($brief->toArray()), 'fingerprint' => $fingerprint, 'built_at' => now()]);
        } elseif ($kept->fingerprint !== $fingerprint) {
            LearnerTables::query($scope, 'folder_briefs')->where('folder_id', $folderId)->update(['text' => json_encode($brief->toArray()), 'fingerprint' => $fingerprint, 'built_at' => now()]);
        }

        return $brief;
    }

    /** @param ?list<string> $folderIds a folder and those inside it, for a folder's brief; null for the whole module */
    private function build(Principal $by, string $workspaceId, ?string $moduleId, ?array $folderIds = null): ModuleBrief
    {
        $here = fn (?string $itemModule, ?string $itemFolder) => $folderIds === null
            ? $itemModule === $moduleId
            : $itemFolder !== null && in_array($itemFolder, $folderIds, true);
        $files = array_values(array_filter($this->files->list($by, $workspaceId), fn (FileDetails $file) => $here($file->moduleId, $file->folderId)));
        $states = $this->digests->states($by, $files);
        $fileLines = [];
        foreach ($files as $file) {
            $digest = $states[$file->id]['digest'] ?? null;
            $what = match (true) {
                $digest?->read() === true => $digest->pages.' '.($file->kind === 'slides' ? 'slides' : 'pages').($digest->topics !== [] ? ': '.implode(', ', $digest->topics) : ($digest->summary !== '' ? ': '.self::cut($digest->summary) : '')),
                $file->kind === 'image' => 'picture',
                $digest !== null => 'nothing to read',
                default => 'not read yet',
            };
            $fileLines[] = "{$file->fileName()} ({$what})";
        }

        $asked = $folderIds === null ? $this->questions->list($by, $workspaceId, $moduleId) : $this->questions->list($by, $workspaceId, folderIds: $folderIds);
        $open = array_values(array_filter($asked, fn (QuestionDetails $q) => $q->status !== 'answered'));
        usort($open, fn (QuestionDetails $a, QuestionDetails $b) => ($b->status === 'stuck') <=> ($a->status === 'stuck'));
        $questionLines = array_map(fn (QuestionDetails $q) => '"'.self::cut($q->text).'"'.($q->status === 'stuck' ? ' (stuck)' : ''), array_slice($open, 0, self::QUESTIONS));

        $points = [];
        $byTopic = $this->findings->byTopic($by, $workspaceId);
        foreach ($this->topics->list($by, $workspaceId) as $topic) {
            if ($here($topic->moduleId, $topic->folderId)) {
                foreach ($byTopic[$topic->id] ?? [] as $finding) {
                    $points[] = self::cut($finding->text);
                }
            }
        }

        $last = null;
        $sessions = $folderIds === null ? $this->sessions->forModule($by, $workspaceId, (string) $moduleId) : $this->sessions->forFolder($by, $workspaceId, $folderIds);
        foreach ($sessions as $session) {
            if ($session->checkpoint !== null && trim($session->checkpoint) !== '') {
                $last = self::cut($session->checkpoint, 160);
                break;
            }
        }

        return new ModuleBrief($fileLines, $questionLines, array_slice($points, 0, self::KEY_POINTS), $last);
    }

    private static function cut(string $text, int $limit = self::LINE): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}
