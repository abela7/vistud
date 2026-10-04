<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The topics the reader found in a module's files, until the student acts (docs/specs/vistud-2-blueprint.md §3.5.3):
 * *3 new topics found in Lecture 3.pdf · Add all · Pick*. A module is suggested a topic once: a name it already has as a
 * topic, or has been suggested before (added or dismissed), is not suggested again, whatever a file says. Nothing here
 * changes the module until the student adds one. The student's own modules only: another student's is 404.
 */
final class TopicSuggestions
{
    public function __construct(private Topics $topics) {}

    /** @return list<TopicSuggestionDetails> waiting for the student, oldest first */
    public function list(Principal $by, string $moduleId): array
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);

        return $this->pending($scope, [$moduleId])[$moduleId] ?? [];
    }

    /**
     * Where suggestions wait, for a course: by module, how many and the file of the first.
     *
     * @return array<string, array{count: int, from: ?string}>
     */
    public function waiting(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $ids = LearnerTables::query($scope, 'modules')->where('workspace_id', $workspaceId)->pluck('id')->all();
        $waiting = [];
        foreach ($this->pending($scope, $ids) as $moduleId => $list) {
            $waiting[$moduleId] = ['count' => count($list), 'from' => $list[0]->sourceName];
        }

        return $waiting;
    }

    /**
     * Notes the topics the reader found in a file of the module. Those the module has, or has had suggested, are left
     * out. Returns how many are new.
     *
     * @param  list<string>  $names
     */
    public function suggest(Principal $by, string $moduleId, ?string $fileId, array $names): int
    {
        $scope = Guard::learner($by);
        $module = $this->module($scope, $moduleId);
        $known = [];
        foreach ($this->topics->list($by, $module->workspace_id) as $topic) {
            if ($topic->moduleId === $moduleId) {
                $known[self::key($topic->name)] = true;
            }
        }
        foreach (LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->pluck('name_key') as $key) {
            $known[$key] = true;
        }
        $new = 0;
        foreach ($names as $name) {
            $name = Input::text(['name' => $name], 'name');
            if ($name === null || mb_strlen($name) > Topics::MAX_NAME || isset($known[self::key($name)])) {
                continue;
            }
            $known[self::key($name)] = true;
            LearnerTables::insert($scope, 'topic_suggestions', [
                'id' => Ids::new(), 'module_id' => $moduleId, 'name' => $name, 'name_key' => self::key($name),
                'source_file_id' => $fileId, 'status' => 'suggested', 'created_at' => now(),
            ]);
            $new++;
        }

        return $new;
    }

    /** Adds every waiting suggestion of the module as a topic, in order. Returns how many. */
    public function addAll(Principal $by, string $moduleId): int
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);

        return $this->add($by, array_map(fn (TopicSuggestionDetails $s) => $s->id, $this->list($by, $moduleId)));
    }

    /**
     * Adds the chosen suggestions as topics of their modules, in the order they were found. Those already acted on are
     * left alone. Returns how many were added.
     *
     * @param  string|list<string>  $ids
     */
    public function add(Principal $by, string|array $ids): int
    {
        $scope = Guard::learner($by);
        $rows = LearnerTables::query($scope, 'topic_suggestions')->whereIn('id', (array) $ids)->where('status', 'suggested')->orderBy('created_at')->orderBy('id')->get();
        if ($rows->isEmpty() && (array) $ids !== []) {
            // Not the student's, or all acted on: only a suggestion that isn't theirs is "not found".
            LearnerTables::query($scope, 'topic_suggestions')->whereIn('id', (array) $ids)->exists() || throw new NotFound;
        }
        $added = 0;
        DB::transaction(function () use ($by, $scope, $rows, &$added) {
            foreach ($rows as $row) {
                $module = $this->module($scope, $row->module_id);
                $this->topics->create($by, $module->workspace_id, $row->name, $row->module_id);
                LearnerTables::query($scope, 'topic_suggestions')->where('id', $row->id)->update(['status' => 'added']);
                $added++;
            }
        });

        return $added;
    }

    /** The student doesn't want this one: it stays out, and is not suggested again. */
    public function dismiss(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'topic_suggestions')->where('id', $id)->where('status', 'suggested')->update(['status' => 'dismissed']);
    }

    public function dismissAll(Principal $by, string $moduleId): void
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);
        LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('status', 'suggested')->update(['status' => 'dismissed']);
    }

    // ---------- Inside ----------

    /**
     * @param  list<string>  $moduleIds
     * @return array<string, list<TopicSuggestionDetails>>
     */
    private function pending(LearnerScope $scope, array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }
        $rows = LearnerTables::query($scope, 'topic_suggestions')->whereIn('module_id', $moduleIds)->where('status', 'suggested')->orderBy('created_at')->orderBy('id')->get();
        $files = LearnerTables::query($scope, 'files')->whereIn('id', $rows->pluck('source_file_id')->filter()->all())->get(['id', 'name', 'extension'])->keyBy('id');
        $by = [];
        foreach ($rows as $row) {
            $file = $files[$row->source_file_id] ?? null;
            $by[$row->module_id][] = new TopicSuggestionDetails($row->id, $row->module_id, $row->name, $file?->id, $file === null ? null : "{$file->name}.{$file->extension}");
        }

        return $by;
    }

    private function module(LearnerScope $scope, string $moduleId): object
    {
        return LearnerTables::query($scope, 'modules')->where('id', $moduleId)->first() ?? throw new NotFound;
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'topic_suggestions')->where('id', $id)->first() ?? throw new NotFound;
    }

    private static function key(string $name): string
    {
        return mb_substr(mb_strtolower($name), 0, 120);
    }
}
