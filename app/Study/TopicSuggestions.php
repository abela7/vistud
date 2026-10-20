<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\ContentIndexes;
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

    /**
     * @param  ?list<string>  $folderIds  only those found in these folders' files (Folders::within: a folder and those inside it)
     * @return list<TopicSuggestionDetails> waiting for the student, oldest first
     */
    public function list(Principal $by, string $moduleId, ?array $folderIds = null): array
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);
        $list = $this->pending($scope, [$moduleId])[$moduleId] ?? [];

        return $folderIds === null ? $list : array_values(array_filter($list, fn (TopicSuggestionDetails $s) => $s->folderId !== null && in_array($s->folderId, $folderIds, true)));
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
     * out. A file in a folder of the module gives its folder (docs/specs/vistud-2-blueprint.md, Phase 9): added, the
     * topics go there. Returns how many are new.
     *
     * @param  list<string>  $names
     */
    public function suggest(Principal $by, string $moduleId, ?string $fileId, array $names, ?string $folderId = null, bool $force = false): int
    {
        $scope = Guard::learner($by);
        $module = $this->module($scope, $moduleId);
        if ($folderId === null && $fileId !== null) {
            $file = LearnerTables::query($scope, 'files')->where('id', $fileId)->first(['module_id', 'folder_id']);
            $folderId = $file !== null && $file->module_id === $moduleId ? $file->folder_id : null;
        }
        $knownTopics = [];
        foreach ($this->topics->list($by, $module->workspace_id) as $topic) {
            if ($topic->moduleId === $moduleId) {
                $knownTopics[self::key($topic->name)] = true;
            }
        }

        $new = 0;
        $seenInBatch = [];
        foreach ($names as $name) {
            $name = Input::text(['name' => $name], 'name');
            if ($name !== null) {
                $name = Topics::cleanMarkdown($name);
            }
            if ($name === null || $name === '' || mb_strlen($name) > Topics::MAX_NAME) {
                continue;
            }
            $key = self::key($name);
            if (isset($knownTopics[$key]) || isset($seenInBatch[$key])) {
                continue;
            }
            $seenInBatch[$key] = true;

            $existing = LearnerTables::query($scope, 'topic_suggestions')
                ->where('module_id', $moduleId)
                ->where('name_key', ContentIndexes::suggestion($scope, $name))
                ->first();

            if ($existing === null) {
                LearnerTables::insert($scope, 'topic_suggestions', [
                    'id' => Ids::new(),
                    'module_id' => $moduleId,
                    'folder_id' => $folderId,
                    'name' => $name,
                    'name_key' => $key,
                    'source_file_id' => $fileId,
                    'status' => 'suggested',
                    'created_at' => now(),
                ]);
                $new++;
            } elseif ($existing->status === 'suggested') {
                if ($folderId !== null && $existing->folder_id === null) {
                    LearnerTables::query($scope, 'topic_suggestions')
                        ->where('id', $existing->id)
                        ->update(['folder_id' => $folderId]);
                }
            } elseif ($force || ($folderId !== null && ($existing->folder_id === null || $existing->folder_id !== $folderId))) {
                // If explicitly requested (e.g. session study map generation) or this suggestion was previously
                // at module level, revive and assign to this session folder
                LearnerTables::query($scope, 'topic_suggestions')
                    ->where('id', $existing->id)
                    ->update([
                        'folder_id' => $folderId,
                        'source_file_id' => $fileId ?? $existing->source_file_id,
                        'status' => 'suggested',
                        'created_at' => now(),
                    ]);
                $new++;
            }
        }

        return $new;
    }

    /**
     * The main topics the AI chose for one part of a module, from all of its files at once (AppEngineJobsChooseTopics):
     * they take the place of what was waiting there, in the order to study them, each with why it matters, what it covers
     * and where it is taught. A topic the module has, or one the student added or said no to before, is left out. A part
     * is a folder of the module, or the module itself for files outside its folders ($folderId null). Returns how many
     * wait now.
     *
     * @param  list<array{name: string, why: string, note?: string, covers: list<string>, sources: list<array{file: string, pages: string}>}>  $topics
     */
    public function choose(Principal $by, string $moduleId, ?string $folderId, array $topics): int
    {
        $scope = Guard::learner($by);
        $module = $this->module($scope, $moduleId);
        // What is there already, by the name's core (case, plurals, punctuation and "the" aside): never chosen again.
        $known = [];
        foreach ($this->topics->list($by, $module->workspace_id) as $topic) {
            if ($topic->moduleId === $moduleId) {
                $known[self::core($topic->name)] = true;
            }
        }
        foreach (LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->get(['name', 'status', 'folder_id']) as $row) {
            // Said no to, or waiting in another part of the module.
            if ($row->status === 'dismissed' || ($row->status === 'suggested' && $row->folder_id !== $folderId)) {
                $known[self::core($row->name)] = true;
            }
        }

        $waiting = 0;
        DB::transaction(function () use ($scope, $moduleId, $folderId, $topics, $known, &$waiting) {
            // What waited in this part is replaced: the new choice was made from all of its files.
            $here = LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('status', 'suggested');
            ($folderId === null ? $here->whereNull('folder_id') : $here->where('folder_id', $folderId))->delete();

            $position = 0;
            foreach ($topics as $topic) {
                $name = Topics::cleanMarkdown(trim((string) $topic['name']));
                $key = self::key($name);
                $core = self::core($name);
                if ($name === '' || mb_strlen($name) > Topics::MAX_NAME || isset($known[$core])) {
                    continue;
                }
                $known[$core] = true;
                $fields = [
                    'reason' => $topic['why'] !== '' ? mb_substr($topic['why'], 0, 300) : null,
                    'note' => ($topic['note'] ?? '') !== '' ? mb_substr($topic['note'], 0, 500) : null,
                    'covers' => json_encode(array_values($topic['covers']), JSON_UNESCAPED_UNICODE),
                    'sources' => json_encode(array_values($topic['sources']), JSON_UNESCAPED_UNICODE),
                    'position' => ++$position,
                    'folder_id' => $folderId,
                    'source_file_id' => $topic['sources'][0]['file'] ?? null,
                ];
                $existing = LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('name_key', ContentIndexes::suggestion($scope, $name))->first();
                if ($existing === null) {
                    LearnerTables::insert($scope, 'topic_suggestions', $fields + ['id' => Ids::new(), 'module_id' => $moduleId, 'name' => $name, 'name_key' => $key, 'status' => 'suggested', 'created_at' => now()]);
                } elseif ($existing->status === 'suggested') {
                    // Waiting in another part of the module: it is this part's now.
                    LearnerTables::query($scope, 'topic_suggestions')->where('id', $existing->id)->update($fields);
                } else {
                    // Added or said no to before: not suggested again.
                    $position--;

                    continue;
                }
                $waiting++;
            }
        });

        return $waiting;
    }

    /**
     * The topics the student said no to in a module, so they aren't chosen again under another name.
     *
     * @return list<string>
     */
    public function refused(Principal $by, string $moduleId): array
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);

        $collator = new \Collator('root');
        $collator->setStrength(\Collator::PRIMARY);

        return LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('status', 'dismissed')->get(['id', 'name'])
            ->sort(fn ($left, $right) => $collator->compare($left->name, $right->name) ?: strcmp($left->id, $right->id))->pluck('name')->values()->all();
    }

    /** Adds every waiting suggestion of the module (or of some of its folders) as a topic, in order. Returns how many. */
    public function addAll(Principal $by, string $moduleId, ?array $folderIds = null): int
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);

        return $this->add($by, array_map(fn (TopicSuggestionDetails $s) => $s->id, $this->list($by, $moduleId, $folderIds)));
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
                // In the folder of the file it was found in, while that folder is in the module.
                $folder = $row->folder_id === null ? null : LearnerTables::query($scope, 'folders')->where('id', $row->folder_id)->where('module_id', $row->module_id)->value('id');
                $this->topics->create($by, $module->workspace_id, $row->name, $row->module_id, $folder);
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

    /** Renames a suggestion. */
    public function rename(Principal $by, string $id, string $newName): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        $name = Input::text(['name' => $newName], 'name');
        if ($name !== null) {
            $name = Topics::cleanMarkdown($name);
        }
        if ($name === null || $name === '' || mb_strlen($name) > Topics::MAX_NAME) {
            return;
        }
        LearnerTables::query($scope, 'topic_suggestions')
            ->where('id', $id)
            ->update([
                'name' => $name,
                'name_key' => self::key($name),
            ]);
    }

    public function dismissAll(Principal $by, string $moduleId, ?array $folderIds = null): void
    {
        $scope = Guard::learner($by);
        $this->module($scope, $moduleId);
        $query = LearnerTables::query($scope, 'topic_suggestions')->where('module_id', $moduleId)->where('status', 'suggested');
        if ($folderIds !== null) {
            $query->whereIn('folder_id', $folderIds === [] ? [''] : $folderIds);
        }
        $query->update(['status' => 'dismissed']);
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
        // In the order to study them where the AI gave one, then as they were found.
        $rows = LearnerTables::query($scope, 'topic_suggestions')->whereIn('module_id', $moduleIds)->where('status', 'suggested')
            ->orderByRaw('position is null')->orderBy('position')->orderBy('created_at')->orderBy('id')->get();
        $sourcesOf = fn (object $row): array => is_string($row->sources ?? null) ? array_values(array_filter((array) json_decode($row->sources, true), 'is_array')) : [];
        $fileIds = $rows->pluck('source_file_id')->filter()->all();
        foreach ($rows as $row) {
            foreach ($sourcesOf($row) as $source) {
                $fileIds[] = (string) ($source['file'] ?? '');
            }
        }
        $files = LearnerTables::query($scope, 'files')->whereIn('id', array_values(array_unique(array_filter($fileIds))))->whereNull('trashed_at')->get(['id', 'name', 'extension'])->keyBy('id');
        $by = [];
        foreach ($rows as $row) {
            $file = $files[$row->source_file_id] ?? null;
            $sources = [];
            foreach ($sourcesOf($row) as $source) {
                $at = $files[$source['file'] ?? ''] ?? null;
                if ($at !== null) {
                    $sources[] = ['fileId' => $at->id, 'name' => "{$at->name}.{$at->extension}", 'pages' => (string) ($source['pages'] ?? '')];
                }
            }
            $covers = is_string($row->covers ?? null) ? array_values(array_filter((array) json_decode($row->covers, true), 'is_string')) : [];
            $by[$row->module_id][] = new TopicSuggestionDetails($row->id, $row->module_id, $row->name, $file?->id, $file === null ? null : "{$file->name}.{$file->extension}", $row->folder_id ?? null,
                $row->reason ?? null, $covers, $sources, isset($row->position) ? (int) $row->position : null, $row->note ?? null);
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

    /**
     * A name's core, for telling twins apart from new topics: lower case, no punctuation, no "the", "a" or "an", and
     * each word without a plural's s ("The Graph Traversals" and "graph traversal" are one topic).
     */
    public static function core(string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)))) ?: [];
        $words = array_filter($words, fn (string $w) => ! in_array($w, ['the', 'a', 'an', 'and', 'of'], true));

        $one = fn (string $w): string => match (true) {
            mb_strlen($w) <= 3 => $w,
            str_ends_with($w, 'ies') => mb_substr($w, 0, -3).'y',                              // theories
            (bool) preg_match('/(ss|x|z|ch|sh)es$/u', $w) => mb_substr($w, 0, -2),             // processes, matches
            str_ends_with($w, 's') && ! preg_match('/(ss|us|is)$/u', $w) => mb_substr($w, 0, -1), // graphs, not status or analysis
            default => $w,
        };

        return implode(' ', array_map($one, $words));
    }

    private static function key(string $name): string
    {
        return mb_substr(mb_strtolower($name), 0, 120);
    }
}
