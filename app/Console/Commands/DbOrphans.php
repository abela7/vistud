<?php

namespace App\Console\Commands;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports (and with --prune deletes) learner rows whose parent course, session, module, folder, note, file, topic or
 * assignment is gone (docs/specs/mcp-connections-plan.md §4 M6.0). Prints nothing when clean.
 */
#[Signature('vistud:db:orphans {--prune : Delete the orphaned rows}')]
#[Description('Report (and with --prune delete) learner rows whose parent record is gone')]
class DbOrphans extends Command
{
    /** Child tables whose rows belong to a parent table by a required or optional foreign id. */
    private const PARENTS = [
        'engine_messages' => ['id', [['thread_id', 'engine_threads']]],
        'session_segments' => ['id', [['session_id', 'study_sessions']]],
        'note_versions' => ['id', [['note_id', 'notes']]],
        'file_digests' => ['id', [['file_id', 'files']]],
        'folder_briefs' => ['folder_id', [['folder_id', 'folders']]],
        'module_briefs' => ['module_id', [['module_id', 'modules']]],
        'topic_suggestions' => ['id', [['module_id', 'modules']]],
        'activity_items' => ['id', [['activity_id', 'activities'], ['workspace_id', 'workspaces']]],
        'activity_members' => ['id', [['activity_id', 'activities'], ['workspace_id', 'workspaces']]],
        'activity_sources' => ['id', [['activity_id', 'activities'], ['workspace_id', 'workspaces']]],
        'activity_understandings' => ['id', [['activity_id', 'activities'], ['workspace_id', 'workspaces']]],
        'activity_log' => ['id', [['activity_id', 'activities'], ['workspace_id', 'workspaces']]],
        'class_reviews' => ['id', [['class_session_id', 'class_sessions'], ['workspace_id', 'workspaces']]],
        'class_materials' => ['id', [['class_session_id', 'class_sessions'], ['workspace_id', 'workspaces']]],
        'class_transcripts' => ['id', [['class_session_id', 'class_sessions'], ['workspace_id', 'workspaces']]],
        'class_recordings' => ['id', [['class_session_id', 'class_sessions'], ['workspace_id', 'workspaces']]],
        'class_sessions' => ['id', [['workspace_id', 'workspaces']]],
        'quizzes' => ['id', [['workspace_id', 'workspaces']]],
        'flashcards' => ['id', [['workspace_id', 'workspaces']]],
        'study_sessions' => ['id', [['workspace_id', 'workspaces']]],
        'findings' => ['id', [['topic_id', 'topics'], ['workspace_id', 'workspaces']]],
        'questions' => ['id', [['workspace_id', 'workspaces']]],
        'topics' => ['id', [['workspace_id', 'workspaces']]],
        'links' => ['id', [['workspace_id', 'workspaces']]],
        'activities' => ['id', [['workspace_id', 'workspaces']]],
        'files' => ['id', [['workspace_id', 'workspaces']]],
        'notes' => ['id', [['workspace_id', 'workspaces']]],
        'folders' => ['id', [['workspace_id', 'workspaces']]],
        'modules' => ['id', [['workspace_id', 'workspaces']]],
        'course_profiles' => ['id', [['workspace_id', 'workspaces']]],
        'learner_profiles' => ['id', [['workspace_id', 'workspaces']]],
        'tutor_memories' => ['id', [['workspace_id', 'workspaces']]],
    ];

    public function handle(): int
    {
        $prune = (bool) $this->option('prune');
        $counts = [];

        foreach (DB::table('learners')->orderBy('id')->pluck('id') as $learnerId) {
            $scope = LearnerScope::forJob((string) $learnerId);

            $threadOrphans = PruneEngineThreads::orphanIds($scope);
            if ($threadOrphans !== []) {
                $counts['engine_threads'] = ($counts['engine_threads'] ?? 0) + count($threadOrphans);
                if ($prune) {
                    PruneEngineThreads::pruneFor($scope);
                }
            }

            foreach (self::PARENTS as $table => [$pk, $relations]) {
                $ids = self::orphanRowIds($scope, $table, $pk, $relations);
                if ($ids !== []) {
                    $counts[$table] = ($counts[$table] ?? 0) + count($ids);
                    if ($prune) {
                        LearnerTables::query($scope, $table)->whereIn($pk, $ids)->delete();
                    }
                }
            }
        }

        if ($counts === []) {
            return self::SUCCESS;
        }

        foreach ($counts as $table => $count) {
            $this->line("{$table}: {$count} orphaned ".($count === 1 ? 'row' : 'rows').($prune ? ' (pruned)' : ''));
        }

        return $prune ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $relations
     * @return list<string>
     */
    private static function orphanRowIds(LearnerScope $scope, string $table, string $pk, array $relations): array
    {
        $columns = array_values(array_unique([$pk, ...array_map(fn (array $rel) => $rel[0], $relations)]));
        $rows = LearnerTables::query($scope, $table)->get($columns);
        if ($rows->isEmpty()) {
            return [];
        }

        $parents = [];
        foreach ($relations as [, $parentTable]) {
            $parents[$parentTable] ??= array_flip(LearnerTables::query($scope, $parentTable)->pluck('id')->map('strval')->all());
        }

        $orphans = [];
        foreach ($rows as $row) {
            foreach ($relations as [$column, $parentTable]) {
                $value = $row->{$column} ?? null;
                if ($value !== null && ! isset($parents[$parentTable][(string) $value])) {
                    $orphans[] = (string) $row->{$pk};
                    break;
                }
            }
        }

        return $orphans;
    }
}
