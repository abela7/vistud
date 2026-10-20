<?php

declare(strict_types=1);

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * A class the student sat in a module: a lecture, tutorial, lab, or seminar.
 * This is the record the class page is built on. Recording, transcription,
 * and the tutor's check are later steps on the same row.
 */
final class ClassSessions
{
    public const MAX_TITLE = 200;

    public const MAX_TEACHER = 120;

    /** Created, and not recorded yet. */
    public const NEW = 'new';

    /** At least one part of the recording is kept. */
    public const RECORDED = 'recorded';

    /** @var array<string, string> */
    public const KINDS = [
        'lecture' => 'Lecture',
        'tutorial' => 'Tutorial',
        'lab' => 'Lab',
        'seminar' => 'Seminar',
    ];

    /**
     * @param  array<string, mixed>  $input  title, kind, held_on, teacher
     */
    public function create(Principal $by, string $workspaceId, string $moduleId, array $input): ClassSessionDetails
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $module = LearnerTables::query($scope, 'modules')->where('id', $moduleId)->first() ?? throw new NotFound;
        $module->workspace_id === $workspaceId || throw new NotFound;

        $title = Input::text($input, 'title');
        $kind = Input::text($input, 'kind') ?? 'lecture';
        $teacher = Input::text($input, 'teacher');
        $heldOn = $this->day($input['held_on'] ?? null, $scope);
        Input::refuse(array_filter([
            'title' => $title === null ? 'Give the class a name.' : (mb_strlen($title) > self::MAX_TITLE ? 'Use '.self::MAX_TITLE.' characters or fewer.' : null),
            'kind' => isset(self::KINDS[$kind]) ? null : 'Choose lecture, tutorial, lab, or seminar.',
            'held_on' => $heldOn === false ? 'Choose the day this class was held.' : null,
            'teacher' => $teacher !== null && mb_strlen($teacher) > self::MAX_TEACHER ? 'Use '.self::MAX_TEACHER.' characters or fewer.' : null,
        ]));
        if ($title === null || ! is_string($heldOn)) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.');
        }

        $id = Ids::new();
        LearnerTables::insert($scope, 'class_sessions', [
            'id' => $id,
            'workspace_id' => $workspaceId,
            'module_id' => $moduleId,
            'kind' => $kind,
            'title' => $title,
            'held_on' => $heldOn,
            'teacher' => $teacher,
            'language' => 'en',
            'status' => self::NEW,
            'keep_audio' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($by, $id);
    }

    public function find(Principal $by, string $id): ClassSessionDetails
    {
        $scope = Guard::learner($by);

        return self::details($this->row($scope, $id));
    }

    /** @return list<ClassSessionDetails> newest day first */
    public function forModule(Principal $by, string $workspaceId, string $moduleId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'class_sessions')
            ->where('workspace_id', $workspaceId)
            ->where('module_id', $moduleId)
            ->orderByDesc('held_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $row) => self::details($row))
            ->all();
    }

    /** Today in the student's time zone, as Y-m-d. */
    public function today(Principal $by): string
    {
        return now(self::zone(Guard::learner($by)))->toDateString();
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'class_sessions')->where('id', $id)->first() ?? throw new NotFound;
    }

    /** A Y-m-d day, today when none was given, or false when the day is not a date. */
    private function day(mixed $value, LearnerScope $scope): string|false
    {
        if ($value === null || $value === '') {
            return now(self::zone($scope))->toDateString();
        }
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : false;
    }

    /** Keeps the class's transcript note and lecture notes (App\Engine\Jobs\TranscribeClass). */
    public function keepTranscript(Principal $by, string $id, string $transcriptNoteId, string $lectureNotesId): void
    {
        $scope = Guard::learner($by);
        $this->find($by, $id);
        LearnerTables::query($scope, 'class_sessions')->where('id', $id)->update(['transcript_note_id' => $transcriptNoteId, 'lecture_notes_id' => $lectureNotesId, 'updated_at' => now()]);
    }

    /** Which of the module's files this class is checked against (ticked by the student); the note comes when the check has run. */
    public function keepCheckFiles(Principal $by, string $id, array $fileIds): void
    {
        $scope = Guard::learner($by);
        $this->find($by, $id);
        LearnerTables::query($scope, 'class_sessions')->where('id', $id)->update(['check_files' => json_encode(array_values(array_unique(array_map('strval', $fileIds)))), 'updated_at' => now()]);
    }

    /** Keeps the note a check wrote (App\Engine\Jobs\ClassCheck). */
    public function keepCheck(Principal $by, string $id, array $fileIds, string $noteId): void
    {
        $scope = Guard::learner($by);
        $this->find($by, $id);
        LearnerTables::query($scope, 'class_sessions')->where('id', $id)->update(['check_files' => json_encode(array_values($fileIds)), 'check_note_id' => $noteId, 'updated_at' => now()]);
    }

    /** @return list<string> */
    private static function ids(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : $json;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private static function zone(LearnerScope $scope): string
    {
        $zone = DB::table('learners')->where('id', $scope->learnerId)->value('timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    private static function details(object $row): ClassSessionDetails
    {
        return new ClassSessionDetails(
            id: $row->id,
            workspaceId: $row->workspace_id,
            moduleId: $row->module_id,
            kind: $row->kind,
            title: $row->title,
            heldOn: substr((string) $row->held_on, 0, 10),
            teacher: $row->teacher,
            status: $row->status,
            createdAt: (string) $row->created_at,
            consentAt: $row->consent_at !== null ? (string) $row->consent_at : null,
            durationSeconds: (int) $row->duration_seconds,
            noteId: $row->note_id !== null ? (string) $row->note_id : null,
            transcriptNoteId: ($row->transcript_note_id ?? null) !== null ? (string) $row->transcript_note_id : null,
            lectureNotesId: ($row->lecture_notes_id ?? null) !== null ? (string) $row->lecture_notes_id : null,
            checkFiles: self::ids($row->check_files ?? null),
            checkNoteId: ($row->check_note_id ?? null) !== null ? (string) $row->check_note_id : null,
        );
    }
}
