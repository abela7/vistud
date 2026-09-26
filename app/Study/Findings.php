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
 * Findings (docs/specs/study-memory.md §3): the short "must know" lines a
 * student keeps about a topic, like "A left join keeps every row of the left
 * table". Each can say where it came from, a note or a file and where in it,
 * and who wrote it: the student, or the assistant during a session. They are
 * the student's material, not learning state, so they are plain rows. The
 * student's own stream only.
 */
final class Findings
{
    public const MAX_TEXT = 500;

    public const MAX_LOCATOR = 60;

    public const AUTHORS = ['student', 'ai'];

    /** @return array<string, list<FindingDetails>> topic id => its findings, oldest first; retired topics' findings are left out */
    public function byTopic(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $live = LearnerTables::query($scope, 'topics')->where('workspace_id', $workspaceId)->whereNull('retired_at')->pluck('id')->all();
        $rows = LearnerTables::query($scope, 'findings')->where('workspace_id', $workspaceId)->whereIn('topic_id', $live)
            ->orderBy('created_at')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->sourceNames($scope, $workspaceId);
        $result = [];
        foreach ($rows as $row) {
            $result[$row->topic_id][] = self::details($row, $names);
        }

        return $result;
    }

    public function find(Principal $by, string $id): FindingDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return self::details($row, $this->sourceNames($scope, $row->workspace_id));
    }

    /**
     * Pins a finding to a topic.
     *
     * @param  array{text?: mixed, source?: mixed, locator?: mixed}  $input  source is `note:{id}`, `file:{id}` or empty
     */
    public function add(Principal $by, string $topicId, array $input, string $author = 'student'): FindingDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $topicId, $fields, $author, $id) {
            $topic = LearnerTables::query($scope, 'topics')->where('id', $topicId)->whereNull('retired_at')->lockForUpdate()->first() ?? throw new NotFound;
            LearnerTables::insert($scope, 'findings', $this->withSource($scope, $topic->workspace_id, $fields) + [
                'id' => $id, 'workspace_id' => $topic->workspace_id, 'topic_id' => $topic->id,
                'author' => in_array($author, self::AUTHORS, true) ? $author : 'student',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    /** @param array{text?: mixed, source?: mixed, locator?: mixed} $input */
    public function update(Principal $by, string $id, array $input): FindingDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $row = $this->row($scope, $id);
        LearnerTables::query($scope, 'findings')->where('id', $id)
            ->update($this->withSource($scope, $row->workspace_id, $fields) + ['updated_at' => now()]);

        return $this->find($by, $id);
    }

    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'findings')->where('id', $id)->delete();
    }

    /** The notes and files a finding can cite, as `note:{id}` / `file:{id}` => name, notes first. */
    public function sources(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return $this->sourceNames($scope, $workspaceId);
    }

    /** The fields with the source checked: a note or file of the same workspace, not in the trash. */
    private function withSource(LearnerScope $scope, string $workspaceId, array $fields): array
    {
        [$type, $sourceId] = $fields['source'];
        unset($fields['source']);
        if ($type !== null) {
            $exists = LearnerTables::query($scope, $type === 'note' ? 'notes' : 'files')
                ->where('id', $sourceId)->where('workspace_id', $workspaceId)->whereNull('trashed_at')->exists();
            Input::refuse($exists ? [] : ['source' => 'Choose a note or file of this course.']);
        }

        return ['text' => $fields['text'], 'source_type' => $type, 'source_id' => $type === null ? null : $sourceId, 'locator' => $type === null ? null : $fields['locator']];
    }

    /** @return array<string, string> */
    private function sourceNames(LearnerScope $scope, string $workspaceId): array
    {
        $names = [];
        foreach (LearnerTables::query($scope, 'notes')->where('workspace_id', $workspaceId)->whereNull('trashed_at')->orderBy('title')->get(['id', 'title']) as $note) {
            $names["note:{$note->id}"] = $note->title === '' ? 'Untitled note' : $note->title;
        }
        foreach (LearnerTables::query($scope, 'files')->where('workspace_id', $workspaceId)->whereNull('trashed_at')->orderBy('name')->get(['id', 'name', 'extension']) as $file) {
            $names["file:{$file->id}"] = "{$file->name}.{$file->extension}";
        }

        return $names;
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'findings')->where('id', $id)->first() ?? throw new NotFound;
    }

    /** @return array{text: string, locator: ?string, source: array{0: ?string, 1: ?string}} */
    private static function validated(array $input): array
    {
        $text = Input::text($input, 'text');
        $locator = Input::text($input, 'locator');
        $source = is_string($input['source'] ?? null) ? $input['source'] : '';
        [$type, $sourceId] = array_pad(explode(':', $source, 2), 2, '');

        Input::refuse(array_filter([
            'text' => match (true) {
                $text === null => 'Write what you need to know.',
                mb_strlen($text) > self::MAX_TEXT => 'Keep it to '.self::MAX_TEXT.' characters.',
                default => null,
            },
            'source' => $source === '' || (in_array($type, ['note', 'file'], true) && $sourceId !== '') ? null : 'Choose a note or file of this course.',
            'locator' => $locator !== null && mb_strlen($locator) > self::MAX_LOCATOR ? 'Keep it to '.self::MAX_LOCATOR.' characters.' : null,
        ]));

        return ['text' => $text, 'locator' => $locator, 'source' => $source === '' ? [null, null] : [$type, $sourceId]];
    }

    private static function details(object $row, array $names): FindingDetails
    {
        $source = $row->source_type === null ? null : "{$row->source_type}:{$row->source_id}";

        return new FindingDetails(
            $row->id, $row->workspace_id, $row->topic_id, $row->text, $row->author,
            $source, $source === null ? null : ($names[$source] ?? null), $row->locator,
        );
    }
}
