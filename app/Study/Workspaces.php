<?php

namespace App\Study;

use App\Appearance\Theme;
use App\Brain\Writer\JournalWriter;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
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
    /** The icons a workspace can have (Lucide names, synced by scripts/sync-icons.mjs). */
    public const ICONS = [
        'book-open', 'microscope', 'sigma', 'languages', 'flask-conical', 'globe',
        'code', 'palette', 'calculator', 'music', 'landmark', 'brain',
    ];

    /**
     * A workspace's sections, in order: [key, label, icon]. The list is data
     * so that later workspace types can add their own.
     */
    public const SECTIONS = [
        ['overview', 'Overview', 'layout-grid'],
        ['modules', 'Modules', 'layers'],
        ['notes', 'Notes & files', 'file-text'],
        ['calendar', 'Calendar', 'calendar'],
        ['progress', 'Progress', 'trending-up'],
    ];

    public const MAX_NAME = 80;

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
     * @param  array{name?: mixed, code?: mixed, term?: mixed, starts_on?: mixed, ends_on?: mixed, colour?: mixed, icon?: mixed}  $input
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
                'type' => 'general',
                'position' => $position,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record($by, $scope, $id, 1, $fields, archived: false);
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
            $this->record($by, $scope, $id, $revision, $fields, archived: $row->archived_at !== null);
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
            $fields = array_intersect_key((array) $row, array_flip(['name', 'code', 'term', 'starts_on', 'ends_on', 'colour', 'icon']));
            $this->record($by, $scope, $id, $revision, $fields, $archived);
        });
    }

    private function lock(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'workspaces')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFound;
    }

    /** A new revision of the workspace's journal record, with its current details. */
    private function record(Principal $by, LearnerScope $scope, string $id, int $revision, array $fields, bool $archived): void
    {
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
                'status' => $archived ? 'archived' : 'active',
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * The fields a student sets, cleaned and checked. Refusals name the field
     * (Unprocessable validation_failed), so a screen can show them there.
     *
     * @return array{name: string, code: ?string, term: ?string, starts_on: ?string, ends_on: ?string, colour: string, icon: string}
     */
    private static function validated(array $input): array
    {
        $text = fn (string $key) => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim(preg_replace('/\s+/u', ' ', $input[$key])) : null;
        $date = function (string $key) use ($input): ?string {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : 'invalid';
        };

        $fields = [
            'name' => $text('name'),
            'code' => $text('code'),
            'term' => $text('term'),
            'starts_on' => $date('starts_on'),
            'ends_on' => $date('ends_on'),
            'colour' => $input['colour'] ?? 'blue',
            'icon' => $input['icon'] ?? 'book-open',
        ];

        $errors = array_filter([
            'name' => match (true) {
                $fields['name'] === null => 'Give the workspace a name.',
                mb_strlen($fields['name']) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
                default => null,
            },
            'code' => $fields['code'] !== null && mb_strlen($fields['code']) > 20 ? 'Keep the code to 20 characters.' : null,
            'term' => $fields['term'] !== null && mb_strlen($fields['term']) > 40 ? 'Keep the term to 40 characters.' : null,
            'starts_on' => $fields['starts_on'] === 'invalid' ? 'Enter a date.' : null,
            'ends_on' => match (true) {
                $fields['ends_on'] === 'invalid' => 'Enter a date.',
                $fields['ends_on'] !== null && $fields['starts_on'] !== null && $fields['starts_on'] !== 'invalid' && $fields['ends_on'] < $fields['starts_on'] => 'The end date is before the start date.',
                default => null,
            },
            'colour' => in_array($fields['colour'], Theme::CATEGORIES, true) ? null : 'Pick one of the colours.',
            'icon' => in_array($fields['icon'], self::ICONS, true) ? null : 'Pick one of the icons.',
        ]);

        if ($errors !== []) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => array_map(fn ($message) => [$message], $errors)]);
        }

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
        );
    }
}
