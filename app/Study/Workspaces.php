<?php

namespace App\Study;

use App\Appearance\CourseColour;
use App\Brain\Writer\JournalWriter;
use App\Engine\Spent;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * A student's workspaces: one per subject (docs/specs/workspaces.md). Every
 * method works on the principal's own learner stream only; another
 * student's workspace is exactly as missing as one that doesn't exist.
 *
 * Every change is also recorded in the student's journal as a new revision
 * of a `workspace` record (ADR 0002), so learning evidence can say which
 * workspace it belongs to.
 */
final class Workspaces
{
    /** The icons a course is shown first (Lucide names, synced by scripts/sync-icons.mjs); the rest are under More icons. */
    public const ICONS = [
        'book-open', 'microscope', 'sigma', 'languages', 'flask-conical', 'globe',
        'code', 'palette', 'calculator', 'music', 'landmark', 'brain',
    ];

    /** Every icon a course can have, in groups (the owner's ask, 2026-10-12: more icons). */
    public const ICON_GROUPS = [
        'Study' => ['graduation-cap', 'library', 'notebook', 'pencil-ruler', 'lightbulb', 'target', 'star', 'flag', 'trophy', 'puzzle', 'rocket'],
        'Maths and science' => ['pi', 'infinity', 'ruler', 'compass', 'atom', 'orbit', 'magnet', 'zap', 'test-tube', 'telescope', 'satellite', 'dna'],
        'Computing' => ['terminal', 'binary', 'cpu', 'database', 'server', 'network', 'cloud', 'bot', 'smartphone', 'wifi', 'bug', 'shield', 'lock', 'key', 'cog'],
        'Life and health' => ['leaf', 'sprout', 'tree-pine', 'fish', 'paw-print', 'bone', 'heart-pulse', 'stethoscope', 'pill', 'syringe', 'apple', 'baby', 'droplet', 'flame', 'sun', 'moon', 'earth'],
        'People and society' => ['scale', 'gavel', 'briefcase', 'handshake', 'users', 'chart-line', 'chart-pie', 'coins', 'store', 'building-2', 'newspaper', 'megaphone', 'map', 'mountain', 'plane', 'car'],
        'Languages and arts' => ['quote', 'scroll', 'feather', 'pen-tool', 'brush', 'camera', 'film', 'drama', 'piano', 'guitar', 'book-heart'],
        'Everyday' => ['dumbbell', 'gamepad-2', 'dice-5', 'chef-hat', 'utensils', 'shirt', 'gem', 'crown', 'heart', 'wrench', 'hammer'],
    ];

    /**
     * A workspace's sections: [key, label, icon]. The list is data so that later workspace types can add their own. Six are
     * doors of the course (NAV, in the order the sidebar shows them); the other two are pages reached from elsewhere: Notes &
     * files from Modules, the course's Calendar from Home's Coming up (docs/specs/vistud-2-blueprint.md §3.4).
     */
    public const SECTIONS = [
        ['overview', 'Home', 'layout-grid'],
        ['modules', 'Modules', 'layers'],
        ['flashcards', 'Cards', 'gallery-vertical-end'],
        ['questions', 'Questions', 'circle-help'],
        ['assignments', 'Assignments', 'clipboard-check'],
        ['progress', 'Progress', 'trending-up'],
        ['notes', 'Notes & files', 'file-text'],
        ['calendar', 'Calendar', 'calendar'],
    ];

    /** The sections the sidebar lists. */
    public const NAV = ['overview', 'modules', 'flashcards', 'questions', 'assignments', 'progress'];

    public const MAX_NAME = 80;

    /** Every icon a course can have: the first ones, then the groups. @return list<string> */
    public static function icons(): array
    {
        return array_values(array_unique([...self::ICONS, ...array_merge(...array_values(self::ICON_GROUPS))]));
    }

    public function __construct(private JournalWriter $journal) {}

    /** @return list<WorkspaceDetails> in the student's order; archived ones only when asked */
    public function list(Principal $by, bool $archived = false): array
    {
        $scope = Guard::learner($by);

        return LearnerTables::query($scope, 'workspaces')
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(self::details(...))
            ->all();
    }

    public function find(Principal $by, string $id): WorkspaceDetails
    {
        $row = LearnerTables::query(Guard::learner($by), 'workspaces')->where('id', $id)->first();

        return $row === null ? throw new NotFound : self::details($row);
    }

    /**
     * @param  array{name?: mixed, code?: mixed, term?: mixed, starts_on?: mixed, ends_on?: mixed, colour?: mixed, icon?: mixed, type?: mixed, project_tools?: mixed}  $input
     */
    public function create(Principal $by, array $input): WorkspaceDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($by, $scope, $fields, $id) {
            $position = (int) LearnerTables::query($scope, 'workspaces')->max('position') + 1;
            LearnerTables::insert($scope, 'workspaces', $fields + [
                'id' => $id,
                'type' => CourseTypes::NONE,
                'position' => $position,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record($by, $scope, $id, 1, $fields + ['type' => CourseTypes::NONE], archived: false);
        });

        return $this->find($by, $id);
    }

    public function update(Principal $by, string $id, array $input): WorkspaceDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);

        DB::transaction(function () use ($by, $scope, $id, $fields) {
            $row = $this->lock($scope, $id);
            $revision = $row->revision + 1;
            LearnerTables::query($scope, 'workspaces')->where('id', $id)->update($fields + ['revision' => $revision, 'updated_at' => now()]);
            $this->record($by, $scope, $id, $revision, $fields + ['type' => $row->type], archived: $row->archived_at !== null);
        });

        return $this->find($by, $id);
    }

    /** What kind of course it is (CourseTypes), or none (`general`): only that changes, as a new revision of its record. */
    public function setType(Principal $by, string $id, string $type): WorkspaceDetails
    {
        $scope = Guard::learner($by);
        Input::refuse($type === CourseTypes::NONE || CourseTypes::chosen($type) ? [] : ['type' => 'Pick one of the kinds of course.']);

        DB::transaction(function () use ($by, $scope, $id, $type) {
            $row = $this->lock($scope, $id);
            if ($row->type === $type) {
                return;
            }
            $revision = $row->revision + 1;
            LearnerTables::query($scope, 'workspaces')->where('id', $id)->update(['type' => $type, 'revision' => $revision, 'updated_at' => now()]);
            $fields = array_intersect_key((array) $row, array_flip(['name', 'code', 'term', 'starts_on', 'ends_on', 'colour', 'icon']));
            $this->record($by, $scope, $id, $revision, $fields + ['type' => $type], archived: $row->archived_at !== null);
        });

        return $this->find($by, $id);
    }

    /** Hides the workspace from the student's list. Nothing in it is deleted, and restore() brings it back. */
    public function archive(Principal $by, string $id): void
    {
        $this->setArchived($by, $id, true);
    }

    public function restore(Principal $by, string $id): void
    {
        $this->setArchived($by, $id, false);
    }

    /**
     * Deletes the workspace and everything inside it: modules, folders, notes,
     * files, flashcards, study sessions, its instructions and profiles, what the
     * tutor remembered for it, and the reader's and helper's runs for it.
     */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        // The stored files go only once the rows are gone for good: a failed delete keeps everything.
        $storageKeys = DB::transaction(function () use ($by, $scope, $id) {
            $row = $this->lock($scope, $id);
            $storageKeys = LearnerTables::query($scope, 'files')->where('workspace_id', $id)->pluck('storage_key')->all();

            // Tombstone notes before deleting
            $noteIds = LearnerTables::query($scope, 'notes')->where('workspace_id', $id)->pluck('id')->all();
            foreach ($noteIds as $noteId) {
                LearnerTables::insert($scope, 'content_tombstones', [
                    'entity_type' => 'note',
                    'entity_id' => $noteId,
                    'kind' => 'deleted',
                    'at' => now(),
                ]);
            }
            if ($noteIds !== []) {
                LearnerTables::query($scope, 'note_versions')->whereIn('note_id', $noteIds)->delete();
                LearnerTables::query($scope, 'notes')->whereIn('id', $noteIds)->delete();
            }

            // Delete module instructions, then modules
            $moduleIds = LearnerTables::query($scope, 'modules')->where('workspace_id', $id)->pluck('id')->all();
            if ($moduleIds !== []) {
                LearnerTables::query($scope, 'instructions')->whereIn('scope', array_map(fn ($mId) => "module:{$mId}", $moduleIds))->delete();
            }

            // Delete files (and what the AI read in them), folders, modules (and what the AI suggested for them)
            $fileIds = LearnerTables::query($scope, 'files')->where('workspace_id', $id)->pluck('id')->all();
            if ($fileIds !== []) {
                LearnerTables::query($scope, 'file_digests')->whereIn('file_id', $fileIds)->delete();
            }
            $folderIds = LearnerTables::query($scope, 'folders')->where('workspace_id', $id)->pluck('id')->all();
            if ($folderIds !== []) {
                LearnerTables::query($scope, 'folder_briefs')->whereIn('folder_id', $folderIds)->delete();
            }
            if ($moduleIds !== []) {
                LearnerTables::query($scope, 'topic_suggestions')->whereIn('module_id', $moduleIds)->delete();
                LearnerTables::query($scope, 'module_briefs')->whereIn('module_id', $moduleIds)->delete();
            }
            $audioPaths = LearnerTables::query($scope, 'class_recordings')->where('workspace_id', $id)->whereNotNull('path')->pluck('path')->all();
            $storageKeys = [...$storageKeys, ...$audioPaths];
            LearnerTables::query($scope, 'class_reviews')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'class_materials')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'class_transcripts')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'class_recordings')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'class_sessions')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'quizzes')->where('workspace_id', $id)->delete();
            TutorMemories::forgetCourse($scope, $id);
            LearnerTables::query($scope, 'files')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'folders')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'modules')->where('workspace_id', $id)->delete();

            // Delete study sessions, segments, flashcards
            $sessionIds = LearnerTables::query($scope, 'study_sessions')->where('workspace_id', $id)->pluck('id')->all();
            if ($sessionIds !== []) {
                LearnerTables::query($scope, 'session_segments')->whereIn('session_id', $sessionIds)->delete();
                LearnerTables::query($scope, 'study_sessions')->whereIn('id', $sessionIds)->delete();
            }
            LearnerTables::query($scope, 'flashcards')->where('workspace_id', $id)->delete();

            // Delete topics, questions, findings, links, activities, instructions
            LearnerTables::query($scope, 'topics')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'questions')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'findings')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'links')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activity_items')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activity_members')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activity_sources')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activity_understandings')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activity_log')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'activities')->where('workspace_id', $id)->delete();
            LearnerTables::query($scope, 'instructions')->where('scope', "workspace:{$id}")->delete();
            CourseProfiles::forget($scope, $id);
            LearnerProfiles::forget($scope, $id);
            // Its chats and AI runs go; what they cost stays in the month's usage.
            $threads = LearnerTables::query($scope, 'engine_threads')->where('workspace_id', $id);
            Spent::forgetMessages($scope, (clone $threads)->pluck('id')->all());
            $threads->delete();
            Spent::forgetJobs($scope, $id);

            // Delete course
            LearnerTables::query($scope, 'workspaces')->where('id', $id)->delete();

            $fields = array_intersect_key((array) $row, array_flip(['name', 'code', 'term', 'starts_on', 'ends_on', 'colour', 'icon', 'type']));
            $this->record($by, $scope, $id, $row->revision + 1, $fields, archived: false, deleted: true);

            return $storageKeys;
        });

        foreach ($storageKeys as $key) {
            Files::disk()->delete($key);
            FilePreviews::forget($key);
            FileTexts::forget($key);
        }
    }

    /**
     * Item counts for workspaces (modules, notes, files).
     *
     * @return array<string, array{modules: int, notes: int, files: int}>
     */
    public function counts(Principal $by): array
    {
        $scope = Guard::learner($by);

        $modules = LearnerTables::query($scope, 'modules')
            ->selectRaw('workspace_id, count(*) as total')
            ->groupBy('workspace_id')
            ->pluck('total', 'workspace_id')
            ->all();

        $notes = LearnerTables::query($scope, 'notes')
            ->whereNull('trashed_at')
            ->selectRaw('workspace_id, count(*) as total')
            ->groupBy('workspace_id')
            ->pluck('total', 'workspace_id')
            ->all();

        $files = LearnerTables::query($scope, 'files')
            ->whereNull('trashed_at')
            ->selectRaw('workspace_id, count(*) as total')
            ->groupBy('workspace_id')
            ->pluck('total', 'workspace_id')
            ->all();

        $workspaceIds = LearnerTables::query($scope, 'workspaces')->pluck('id');
        $counts = [];
        foreach ($workspaceIds as $id) {
            $counts[$id] = [
                'modules' => (int) ($modules[$id] ?? 0),
                'notes' => (int) ($notes[$id] ?? 0),
                'files' => (int) ($files[$id] ?? 0),
            ];
        }

        return $counts;
    }

    private function setArchived(Principal $by, string $id, bool $archived): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($by, $scope, $id, $archived) {
            $row = $this->lock($scope, $id);
            if (($row->archived_at !== null) === $archived) {
                return;
            }
            $revision = $row->revision + 1;
            $position = $archived ? $row->position : (int) LearnerTables::query($scope, 'workspaces')->whereNull('archived_at')->max('position') + 1;
            LearnerTables::query($scope, 'workspaces')->where('id', $id)->update([
                'archived_at' => $archived ? now() : null,
                'position' => $position,
                'revision' => $revision,
                'updated_at' => now(),
            ]);
            $fields = array_intersect_key((array) $row, array_flip(['name', 'code', 'term', 'starts_on', 'ends_on', 'colour', 'icon', 'type']));
            $this->record($by, $scope, $id, $revision, $fields, $archived);
        });
    }

    private function lock(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'workspaces')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFound;
    }

    /** A new revision of the workspace's journal record, with its current details. */
    private function record(Principal $by, LearnerScope $scope, string $id, int $revision, array $fields, bool $archived, bool $deleted = false): void
    {
        $status = match (true) {
            $deleted => 'deleted',
            $archived => 'archived',
            default => 'active',
        };
        $this->journal->append($scope, [
            'id' => Ids::new(),
            'kind' => 'record',
            'actor' => ['type' => 'learner', 'id' => $scope->learnerId, 'channel' => in_array($by->channel, ['web', 'api'], true) ? $by->channel : 'web'],
            'occurred_at' => now()->toIso8601String(),
            'body' => array_filter([
                'record_type' => 'workspace',
                'record_id' => $id,
                'revision' => $revision,
                'title' => $fields['name'],
                'code' => $fields['code'] ?? null,
                'term' => $fields['term'] ?? null,
                'starts_on' => $fields['starts_on'] ?? null,
                'ends_on' => $fields['ends_on'] ?? null,
                'colour' => $fields['colour'],
                // The kind of course (CourseTypes), so what is learnt in it knows its subject.
                'subject' => CourseTypes::chosen($fields['type'] ?? null) ? $fields['type'] : null,
                'status' => $status,
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * The fields a student sets, cleaned and checked. Refusals name the field
     * (Unprocessable validation_failed), so a screen can show them there.
     *
     * @return array{name: string, code: ?string, term: ?string, starts_on: ?string, ends_on: ?string, colour: string, icon: string, project_tools?: bool}
     */
    private static function validated(array $input): array
    {
        [$startsOn, $endsOn, $dateErrors] = Input::dates($input);
        $fields = [
            'name' => Input::text($input, 'name'),
            'code' => Input::text($input, 'code'),
            'term' => Input::text($input, 'term'),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            // A category, or a colour of the student's own (#rrggbb).
            'colour' => CourseColour::normalise($input['colour'] ?? 'blue') ?? '',
            'icon' => $input['icon'] ?? 'book-open',
        ];
        // Left as they are unless the input says (an update from somewhere that doesn't know of them must not change them).
        if (array_key_exists('type', $input)) {
            $fields['type'] = is_string($input['type']) && $input['type'] !== '' ? $input['type'] : CourseTypes::NONE;
        }
        if (array_key_exists('project_tools', $input)) {
            $fields['project_tools'] = filter_var($input['project_tools'], FILTER_VALIDATE_BOOLEAN);
        }

        Input::refuse(array_filter([
            'name' => match (true) {
                $fields['name'] === null => 'Give the course a name.',
                mb_strlen($fields['name']) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
                default => null,
            },
            'code' => $fields['code'] !== null && mb_strlen($fields['code']) > 20 ? 'Keep the code to 20 characters.' : null,
            'term' => $fields['term'] !== null && mb_strlen($fields['term']) > 40 ? 'Keep the term to 40 characters.' : null,
            ...$dateErrors,
            'colour' => $fields['colour'] !== '' ? null : 'Pick one of the colours, or a colour of your own.',
            'icon' => in_array($fields['icon'], self::icons(), true) ? null : 'Pick one of the icons.',
            'type' => isset($fields['type']) && $fields['type'] !== CourseTypes::NONE && ! CourseTypes::chosen($fields['type']) ? 'Pick one of the kinds of course.' : null,
        ]));

        return $fields;
    }

    private static function details(object $row): WorkspaceDetails
    {
        return new WorkspaceDetails(
            id: $row->id,
            name: $row->name,
            code: $row->code,
            term: $row->term,
            startsOn: $row->starts_on,
            endsOn: $row->ends_on,
            colour: $row->colour,
            icon: $row->icon,
            type: $row->type,
            position: (int) $row->position,
            archivedAt: $row->archived_at,
            projectTools: (bool) $row->project_tools,
        );
    }
}
