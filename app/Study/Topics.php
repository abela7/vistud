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
 * A workspace's topics: the spine of the course (docs/specs/study-memory.md
 * §3). A topic is a `defines` claim in the journal; its module, order and
 * the student's latest word are organisation, kept here. "Covered" is an
 * exposure, "understood" and "confused" are self-reports, and the label
 * beside them is what the rules derive from all the evidence (ADR 0002 §7).
 * Mastered is earned, never set. The student's own stream only.
 */
final class Topics
{
    public const MAX_NAME = 120;

    public const STATUSES = ['covered', 'understood', 'confused'];

    public function __construct(private Memory $memory) {}

    /**
     * @param  ?array  $snapshot  the derived state, when the caller has taken it already (Memory::snapshot): reading the journal is the costly part
     * @return list<TopicDetails> in order, with the derived state of each
     */
    public function list(Principal $by, string $workspaceId, ?array $snapshot = null): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'topics')->where('workspace_id', $workspaceId)->whereNull('retired_at')
            ->orderBy('position')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $derived = ($snapshot ?? $this->memory->snapshot($scope))['topics'];

        return $rows->map(fn ($row) => self::details($row, $derived[$row->id] ?? null))->all();
    }

    public function find(Principal $by, string $id): TopicDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return self::details($row, $this->memory->snapshot($scope)['topics'][$id] ?? null);
    }

    /**
     * A new topic, in a module and (docs/specs/vistud-2-blueprint.md, Phase 9) in one of its folders, or in neither. A
     * folder is in its own module: given one, the topic is in that module whatever module is given.
     */
    public function create(Principal $by, string $workspaceId, mixed $name, ?string $moduleId = null, ?string $folderId = null): TopicDetails
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $moduleId, $folderId, $name, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            [$moduleId, $folderId] = $this->placeIn($scope, $workspaceId, $moduleId, $folderId);
            $this->memory->append($scope, [
                Memory::claim($scope, $by, 'defines', ["topic:{$id}"], ['entity_type' => 'topic', 'status' => 'active', 'kind' => 'concept', 'aliases' => []], ['label' => $name]),
            ], $workspaceId);
            LearnerTables::insert($scope, 'topics', [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'folder_id' => $folderId, 'name' => $name,
                'position' => (int) LearnerTables::query($scope, 'topics')->where('workspace_id', $workspaceId)->max('position') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    public function rename(Principal $by, string $id, mixed $name): void
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
    }

    /** Puts the topic in a module of its workspace, or (null) in none; and in one of the module's folders, or none. */
    public function move(Principal $by, string $id, ?string $moduleId, ?string $folderId = null): void
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        [$moduleId, $folderId] = $this->placeIn($scope, $row->workspace_id, $moduleId, $folderId);
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['module_id' => $moduleId, 'folder_id' => $folderId, 'updated_at' => now()]);
        // Its cards go with it, and so do its questions' folder.
        LearnerTables::query($scope, 'flashcards')->where('topic_id', $id)->update(['module_id' => $moduleId, 'folder_id' => $folderId, 'updated_at' => now()]);
        LearnerTables::query($scope, 'questions')->where('topic_id', $id)->update(['folder_id' => $folderId]);
    }

    /** Moves the topic to $position (0 is first) among its workspace's topics. */
    public function reorder(Principal $by, string $id, int $position): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $position) {
            $row = $this->row($scope, $id, lock: true);
            $ids = LearnerTables::query($scope, 'topics')->where('workspace_id', $row->workspace_id)->whereNull('retired_at')
                ->orderBy('position')->orderBy('id')->pluck('id')->all();
            $ids = array_values(array_diff($ids, [$id]));
            array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);
            foreach ($ids as $index => $topicId) {
                LearnerTables::query($scope, 'topics')->where('id', $topicId)->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * The student's word on a topic: covered (a lecture or reading reached
     * them), understood, or confused. Each is a journal event; the row keeps
     * the latest so lists don't replay the journal for it.
     */
    public function report(Principal $by, string $id, string $status): void
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($status, self::STATUSES, true) ? [] : ['status' => 'Unknown status.']);
        $row = $this->row($scope, $id);

        $about = [['rel' => 'about', 'target' => "topic:{$id}"]];
        $this->memory->append($scope, [match ($status) {
            'covered' => Memory::observation($scope, $by, 'exposure', ['format' => 'lecture'], $about),
            'understood' => Memory::observation($scope, $by, 'self_report', ['stance' => 'confident'], $about),
            'confused' => Memory::observation($scope, $by, 'self_report', ['stance' => 'confused'], $about),
        }], $row->workspace_id);
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['status' => $status, 'status_by' => 'student', 'status_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The tutor's word on a topic (docs/specs/vistud-2-blueprint.md §3.8): shown as the tutor's, and never over the
     * student's own, which always wins. Only the row changes: the journal holds what the student reported and did, so
     * a status the tutor set is not evidence. Returns what it was before (for undo), or null when nothing changed: the
     * student's word stood, or the topic already had that status.
     *
     * @return ?array{status: ?string, by: ?string, at: ?string}
     */
    public function mark(Principal $by, string $id, string $status): ?array
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($status, self::STATUSES, true) ? [] : ['status' => 'Unknown status.']);
        $row = $this->row($scope, $id);
        if ($row->status === $status || ($row->status !== null && $row->status_by !== 'tutor')) {
            return null;
        }
        $before = ['status' => $row->status, 'by' => $row->status_by, 'at' => $row->status_at];
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['status' => $status, 'status_by' => 'tutor', 'status_at' => now(), 'updated_at' => now()]);

        return $before;
    }

    /**
     * Puts a topic's status back as it was before the tutor marked it ($before is what mark() returned), unless the
     * student has said something about it since.
     *
     * @param  array{status: ?string, by: ?string, at: ?string}  $before
     */
    public function unmark(Principal $by, string $id, array $before): void
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        if ($row->status_by !== 'tutor') {
            return;
        }
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['status' => $before['status'], 'status_by' => $before['status'] === null ? null : $before['by'], 'status_at' => $before['status'] === null ? null : $before['at'], 'updated_at' => now()]);
    }

    /** Retires the topic: it leaves the screens, and its evidence stays in the journal. */
    public function retire(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->row($scope, $id, lock: true);
            $this->memory->append($scope, [
                Memory::claim($scope, $by, 'defines', ["topic:{$id}"], ['entity_type' => 'topic', 'status' => 'retired', 'kind' => 'concept', 'aliases' => []], ['label' => $row->name]),
            ], $row->workspace_id);
            LearnerTables::query($scope, 'topics')->where('id', $id)->update(['retired_at' => now(), 'updated_at' => now()]);
            LearnerTables::query($scope, 'questions')->where('topic_id', $id)->update(['topic_id' => null]);
            LearnerTables::query($scope, 'quizzes')->where('topic_id', $id)->update(['topic_id' => null]);
        });
    }

    /**
     * A topic's module and folder, each of the workspace (404 otherwise): the folder's module when a folder is given,
     * else the module given; null for none.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function placeIn(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $folderId): array
    {
        if ($folderId === null || $folderId === '') {
            return [$this->moduleIn($scope, $workspaceId, $moduleId), null];
        }
        $folder = LearnerTables::query($scope, 'folders')->where('id', $folderId)->where('workspace_id', $workspaceId)->first() ?? throw new NotFound;

        return [$folder->module_id, $folder->id];
    }

    /** The module's id when it belongs to the workspace; null for none; 404 otherwise. */
    private function moduleIn(LearnerScope $scope, string $workspaceId, ?string $moduleId): ?string
    {
        if ($moduleId === null || $moduleId === '') {
            return null;
        }
        $module = LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->first() ?? throw new NotFound;

        return $module->id;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'topics')->where('id', $id)->whereNull('retired_at');

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    private static function validatedName(mixed $name): string
    {
        $name = Input::text(['name' => $name], 'name');
        Input::refuse(array_filter(['name' => match (true) {
            $name === null => 'Give the topic a name.',
            mb_strlen($name) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
            default => null,
        }]));

        return $name;
    }

    private static function details(object $row, ?array $derived): TopicDetails
    {
        return new TopicDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->name, $row->status, (int) $row->position,
            $derived['label'] ?? 'not_started', $derived['flags'] ?? [], $derived['facts']['last_contact'] ?? null,
            $row->status === null ? null : ($row->status_by ?? 'student'), $row->status === null || $row->status_at === null ? null : (string) $row->status_at,
            $row->folder_id ?? null,
        );
    }
}
