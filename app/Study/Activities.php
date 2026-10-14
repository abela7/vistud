<?php

namespace App\Study;

use App\Brain\Journal\Vocabulary;
use App\Engine\Spent;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Assignments and tasks (docs/specs/study-memory.md §3): an assignment, a
 * quiz, an exam, a lab, a problem set or any other thing to do, with a
 * deadline (a day, and a time on it in the student's own time zone) and
 * where the student is with it (to do, doing, done). An assignment keeps its
 * files in a folder of its own (the owner's review, 2026-10-02), made with it
 * in its module or at the workspace's top level; a plain to-do gets one when
 * a file is first added. The folder is named after the assignment, moves
 * with it, and stays when the assignment is deleted: files are never lost
 * with it. Each change is also a new revision of the activity's `activity`
 * record in the journal (ADR 0002), so evidence can name it and the calendar
 * can show it. The student's own stream only.
 */
final class Activities
{
    public const MAX_TITLE = 200;

    public const STATUSES = ['todo', 'doing', 'done'];

    public const STAGES = ['understand', 'plan', 'do', 'review', 'submit'];

    /** The kinds a student picks from, in plain words; lectures come with the calendar. */
    public const KINDS = [
        'assignment' => 'Assignment',
        'project' => 'Project',
        'quiz' => 'Quiz',
        'exam' => 'Exam',
        'lab' => 'Lab',
        'problem_set' => 'Problem set',
        'other' => 'To-do',
    ];

    public function __construct(private Memory $memory, private Folders $folders) {}

    /** @return list<ActivityDetails> what's still open first, soonest due first; then what's done, latest first */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $zone = self::zone($scope);
        $rows = LearnerTables::query($scope, 'activities')->where('workspace_id', $workspaceId)->get()->map(fn ($row) => self::details($row, $zone))->all();

        usort($rows, fn (ActivityDetails $a, ActivityDetails $b) => ($a->status === 'done') <=> ($b->status === 'done')
            ?: ($a->status === 'done'
                ? $b->updatedAt <=> $a->updatedAt
                : [$a->dueOn === null, $a->dueOn, $a->dueTime ?? '24:00', $a->createdAt] <=> [$b->dueOn === null, $b->dueOn, $b->dueTime ?? '24:00', $b->createdAt]));

        return $rows;
    }

    public function find(Principal $by, string $id): ActivityDetails
    {
        $scope = Guard::learner($by);

        return self::details($this->row($scope, $id), self::zone($scope));
    }

    /**
     * The assignment whose folder this is, or a folder inside it.
     * Null when the folder belongs to no assignment.
     */
    public function forFolder(Principal $by, string $folderId): ?ActivityDetails
    {
        $scope = Guard::learner($by);
        $zone = self::zone($scope);
        $seen = [];
        $current = $folderId;

        while (! isset($seen[$current]) && count($seen) < 20) {
            $seen[$current] = true;
            $row = LearnerTables::query($scope, 'activities')->where('folder_id', $current)->first();
            if ($row !== null) {
                return self::details($row, $zone);
            }
            $parent = LearnerTables::query($scope, 'folders')->where('id', $current)->value('parent_id');
            if (! is_string($parent) || $parent === '') {
                return null;
            }
            $current = $parent;
        }

        return null;
    }

    /** @param array{kind?: mixed, title?: mixed, due_on?: mixed, due_time?: mixed, module_id?: mixed} $input */
    public function create(Principal $by, string $workspaceId, array $input): ActivityDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $by, $workspaceId, $fields, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            $fields['module_id'] = $this->moduleIn($scope, $workspaceId, $fields['module_id']);
            // Its own folder for its files; a plain to-do gets one when a file is first added (folder()).
            $folder = $fields['kind'] === 'other' ? null : $this->newFolder($by, $workspaceId, $fields['module_id'], $fields['title']);
            LearnerTables::insert($scope, 'activities', $fields + [
                'id' => $id, 'workspace_id' => $workspaceId, 'folder_id' => $folder, 'status' => 'todo', 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($scope, $by, $this->row($scope, $id, lock: true));
        });

        return $this->find($by, $id);
    }

    /** @param array{kind?: mixed, title?: mixed, due_on?: mixed, due_time?: mixed, module_id?: mixed} $input */
    public function update(Principal $by, string $id, array $input): ActivityDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);

        DB::transaction(function () use ($scope, $by, $id, $fields) {
            $row = $this->row($scope, $id, lock: true);
            $fields['module_id'] = $this->moduleIn($scope, $row->workspace_id, $fields['module_id']);
            // Its folder keeps its name (unless the student named it otherwise) and goes where it goes.
            $folder = $this->folderRow($scope, $row);
            if ($folder !== null) {
                if ($fields['title'] !== $row->title && $folder->name === self::folderName($row->title)) {
                    $this->folders->rename($by, $folder->id, self::folderName($fields['title']));
                }
                if ($fields['module_id'] !== $row->module_id) {
                    $this->folders->move($by, $folder->id, $fields['module_id'] === null ? 'workspace' : 'module', $fields['module_id'] ?? $row->workspace_id);
                }
            }
            $this->change($scope, $by, $row, $fields);
        });

        return $this->find($by, $id);
    }

    /** Its folder, for its files: the one it has, or a new one when it has none (a to-do, or the folder was deleted). */
    public function folder(Principal $by, string $id): FolderDetails
    {
        $scope = Guard::learner($by);

        $folderId = DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->row($scope, $id, lock: true);
            $folder = $this->folderRow($scope, $row);
            if ($folder !== null) {
                return $folder->id;
            }
            $folderId = $this->newFolder($by, $row->workspace_id, $row->module_id, $row->title);
            LearnerTables::query($scope, 'activities')->where('id', $id)->update(['folder_id' => $folderId]);

            return $folderId;
        });

        return $this->folders->find($by, $folderId);
    }

    /** To do, doing or done. */
    public function setStatus(Principal $by, string $id, string $status): void
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($status, self::STATUSES, true) ? [] : ['status' => 'Unknown status.']);

        DB::transaction(function () use ($scope, $by, $id, $status) {
            $row = $this->row($scope, $id, lock: true);
            if ($row->status !== $status) {
                $this->change($scope, $by, $row, ['status' => $status]);
            }
        });
    }

    /** The model that reads this assignment; null goes back to the tutor's model. The caller checks the model is one on offer. */
    public function setAiModel(Principal $by, string $id, ?string $model): void
    {
        $scope = Guard::learner($by);
        $model = $model === null ? null : trim($model);
        $model = $model === '' ? null : mb_substr((string) $model, 0, 120);

        DB::transaction(function () use ($scope, $id, $model) {
            $row = $this->row($scope, $id, lock: true);
            if (($row->ai_model ?? null) !== $model) {
                LearnerTables::query($scope, 'activities')->where('id', $id)->update(['ai_model' => $model, 'updated_at' => now()]);
            }
        });
    }

    /** The study companion stage: understand, plan, do, review, submit (never written to journal). */
    public function setStage(Principal $by, string $id, string $stage): void
    {
        $scope = Guard::learner($by);
        Input::refuse(in_array($stage, self::STAGES, true) ? [] : ['stage' => 'Unknown stage.']);

        DB::transaction(function () use ($scope, $id, $stage) {
            $row = $this->row($scope, $id, lock: true);
            if (($row->stage ?? 'understand') !== $stage) {
                LearnerTables::query($scope, 'activities')->where('id', $id)->update(['stage' => $stage, 'updated_at' => now()]);
            }
        });
    }

    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $workspaceId = '';
        $folderRoots = [];

        DB::transaction(function () use ($scope, $by, $id, &$workspaceId, &$folderRoots): void {
            $row = $this->row($scope, $id, lock: true);
            $workspaceId = (string) $row->workspace_id;
            $sectionFolders = LearnerTables::query($scope, 'activity_items')->where('activity_id', $id)->whereNotNull('folder_id')->pluck('folder_id')->all();
            $folderRoots = array_values(array_unique(array_filter([
                is_string($row->folder_id) ? $row->folder_id : null,
                ...array_map('strval', $sectionFolders),
            ])));

            LearnerTables::query($scope, 'activity_items')->where('activity_id', $id)->delete();
            LearnerTables::query($scope, 'activity_members')->where('activity_id', $id)->delete();
            LearnerTables::query($scope, 'activity_sources')->where('activity_id', $id)->delete();
            LearnerTables::query($scope, 'activity_understandings')->where('activity_id', $id)->delete();
            LearnerTables::query($scope, 'activity_log')->where('activity_id', $id)->delete();
            $threads = LearnerTables::query($scope, 'engine_threads')->where('activity_id', $id);
            Spent::forgetMessages($scope, (clone $threads)->pluck('id')->all());
            $threads->delete();
            Spent::forgetTargetJobs($scope, 'activity', $id);
            CourseProfiles::forgetActivity($scope, $workspaceId, $id);
            if ($row->module_id !== null) {
                LearnerTables::query($scope, 'module_briefs')->where('module_id', $row->module_id)->delete();
            }
            LearnerTables::query($scope, 'activities')->where('id', $id)->delete();
            $row->revision++;
            $this->record($scope, $by, $row, deleted: true);
        });

        $this->folders->purgeSubtrees($scope, $workspaceId, $folderRoots);
    }

    private function change(LearnerScope $scope, Principal $by, object $row, array $fields): void
    {
        LearnerTables::query($scope, 'activities')->where('id', $row->id)->update($fields + ['revision' => $row->revision + 1, 'updated_at' => now()]);
        $this->record($scope, $by, $this->row($scope, $row->id, lock: true));
    }

    /** A new revision of the activity's journal record, with its current details. */
    private function record(LearnerScope $scope, Principal $by, object $row, bool $deleted = false): void
    {
        $this->memory->append($scope, [[
            'id' => Ids::new(),
            'kind' => 'record',
            'actor' => Memory::actor($scope, $by),
            'occurred_at' => Memory::now(),
            'body' => array_filter([
                'record_type' => 'activity',
                'record_id' => $row->id,
                'revision' => (int) $row->revision,
                'workspace' => $row->workspace_id,
                'module' => $row->module_id,
                'kind' => $row->kind,
                'title' => $row->title,
                'due_on' => $row->due_on,
                'due_time' => $row->due_time === null ? null : substr((string) $row->due_time, 0, 5),
                'progress' => $row->status,
                'status' => $deleted ? 'deleted' : 'active',
            ], fn ($value) => $value !== null),
        ]], $row->workspace_id);
    }

    /** A new folder named after the assignment, in its module or at the workspace's top level. */
    private function newFolder(Principal $by, string $workspaceId, ?string $moduleId, string $title): string
    {
        return $this->folders->create($by, $moduleId === null ? 'workspace' : 'module', $moduleId ?? $workspaceId, self::folderName($title))->id;
    }

    /** Its folder, when it has one that still exists. */
    private function folderRow(LearnerScope $scope, object $row): ?object
    {
        return $row->folder_id === null ? null : LearnerTables::query($scope, 'folders')->where('id', $row->folder_id)->first();
    }

    private static function folderName(string $title): string
    {
        return mb_substr($title, 0, Folders::MAX_NAME);
    }

    /** The student's time zone, for the time on a deadline. */
    private static function zone(LearnerScope $scope): string
    {
        $zone = DB::table('learners')->where('id', $scope->learnerId)->value('timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    private function moduleIn(LearnerScope $scope, string $workspaceId, ?string $moduleId): ?string
    {
        if ($moduleId === null) {
            return null;
        }

        return (LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->first() ?? throw new NotFound)->id;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'activities')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    /** @return array{kind: string, title: string, due_on: ?string, due_time: ?string, module_id: ?string} */
    private static function validated(array $input): array
    {
        $title = Input::text($input, 'title');
        $kind = $input['kind'] ?? 'assignment';
        $due = $input['due_on'] ?? null;
        $due = $due === '' ? null : $due;
        $parsed = is_string($due) ? DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
        $time = $input['due_time'] ?? null;
        $time = $time === '' ? null : $time;
        $module = $input['module_id'] ?? null;

        Input::refuse(array_filter([
            'title' => match (true) {
                $title === null => 'Say what it is.',
                mb_strlen($title) > self::MAX_TITLE => 'Keep it to '.self::MAX_TITLE.' characters.',
                default => null,
            },
            'kind' => in_array($kind, Vocabulary::ACTIVITY_KINDS, true) ? null : 'Choose what kind it is.',
            'due_on' => $due === null || ($parsed !== false && $parsed->format('Y-m-d') === $due) ? null : 'Enter a date.',
            'due_time' => match (true) {
                $time === null => null,
                ! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1 => 'Enter a time, like 23:59.',
                $due === null => 'Choose the day first.',
                default => null,
            },
        ]));

        return ['kind' => $kind, 'title' => $title, 'due_on' => $due, 'due_time' => $time, 'module_id' => is_string($module) && $module !== '' ? $module : null];
    }

    private static function details(object $row, string $zone): ActivityDetails
    {
        return new ActivityDetails(
            $row->id, $row->workspace_id, $row->module_id, $row->kind, $row->title, $row->due_on, $row->status,
            (string) $row->created_at, (string) $row->updated_at,
            dueTime: $row->due_time === null ? null : substr((string) $row->due_time, 0, 5),
            folderId: $row->folder_id,
            zone: $zone,
            stage: $row->stage ?? 'understand',
            aiModel: $row->ai_model ?? null,
        );
    }
}
