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

    /** @return list<TopicDetails> in order, with the derived state of each */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'topics')->where('workspace_id', $workspaceId)->whereNull('retired_at')
            ->orderBy('position')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $derived = $this->memory->snapshot($scope)['topics'];

        return $rows->map(fn ($row) => self::details($row, $derived[$row->id] ?? null))->all();
    }

    public function find(Principal $by, string $id): TopicDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return self::details($row, $this->memory->snapshot($scope)['topics'][$id] ?? null);
    }

    public function create(Principal $by, string $workspaceId, mixed $name, ?string $moduleId = null): TopicDetails
    {
        $scope = Guard::learner($by);
        $name = self::validatedName($name);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $moduleId, $name, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            $moduleId = $this->moduleIn($scope, $workspaceId, $moduleId);
            $this->memory->append($scope, [
                Memory::claim($scope, $by, 'defines', ["topic:{$id}"], ['entity_type' => 'topic', 'status' => 'active', 'kind' => 'concept', 'aliases' => []], ['label' => $name]),
            ], $workspaceId);
            LearnerTables::insert($scope, 'topics', [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'name' => $name,
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

    /** Puts the topic in a module of its workspace, or (null) in none. */
    public function move(Principal $by, string $id, ?string $moduleId): void
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        $moduleId = $this->moduleIn($scope, $row->workspace_id, $moduleId);
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['module_id' => $moduleId, 'updated_at' => now()]);
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
        LearnerTables::query($scope, 'topics')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
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
        });
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
        );
    }
}
