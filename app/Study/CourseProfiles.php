<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a course is (docs/specs/vistud-2-blueprint.md §3.5.2, §3.7): about it, what it should teach, how it is
 * assessed, its textbook, and the modules the reader found in a syllabus until the student has ticked which to add.
 * Written by the student, or read by the reader from a syllabus (App\Engine\Jobs\ProfileCourse); either way it is the
 * student's to edit. One row per course, created when first needed. The tutor reads it as its course layer. Only the
 * student's own courses: another student's is 404, like a missing one.
 */
final class CourseProfiles
{
    public const MAX_ABOUT = 1200;

    public const MAX_OUTCOMES = 12;

    public const MAX_OUTCOME = 200;

    public const MAX_ASSESSMENTS = 12;

    public const MAX_NAME = 80;

    public const MAX_TEXTBOOK = 200;

    public const MAX_MODULES = 60;

    /** The most of a syllabus the reader is given, in characters. */
    public const MAX_SYLLABUS = 40_000;

    public function __construct(private Modules $modules, private Activities $activities) {}

    public function get(Principal $by, string $workspaceId): CourseProfileDetails
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return self::details($workspaceId, $this->row($scope, $workspaceId));
    }

    /**
     * The student's own edits: what the course is about, what it should teach, how it is assessed, its textbook.
     * Refusals name the field. Assessment rows keep the assignment made from them, so adding again never doubles.
     *
     * @param  array{about?: mixed, outcomes?: mixed, assessment?: mixed, textbook?: mixed}  $input
     */
    public function save(Principal $by, string $workspaceId, array $input): CourseProfileDetails
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $fields = $this->validated($scope, $input);

        DB::transaction(function () use ($scope, $workspaceId, $fields) {
            $this->write($scope, $workspaceId, [
                'about' => $fields['about'], 'outcomes' => json_encode($fields['outcomes']),
                'assessment' => json_encode($fields['assessment']), 'textbook' => $fields['textbook'],
            ]);
        });

        return $this->get($by, $workspaceId);
    }

    /**
     * Keeps the syllabus to read: a file of the course (the student's own), or the text they pasted. A new one
     * replaces the old; the reader's earlier proposal stays until the new reading replaces it.
     */
    public function setSyllabus(Principal $by, string $workspaceId, ?string $fileId, ?string $text): void
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $text = $text === null ? null : trim(str_replace("\r\n", "\n", $text));
        Input::refuse(match (true) {
            $fileId === null && ($text === null || $text === '') => ['syllabus' => 'Drop a file or paste the text.'],
            default => [],
        });
        if ($fileId !== null) {
            $file = LearnerTables::query($scope, 'files')->where('id', $fileId)->where('workspace_id', $workspaceId)->whereNull('trashed_at')->first() ?? throw new NotFound;
            $fileId = $file->id;
        }
        $this->write($scope, $workspaceId, [
            'syllabus_file_id' => $fileId,
            'syllabus_text' => $fileId === null ? mb_substr((string) $text, 0, self::MAX_SYLLABUS) : null,
        ]);
    }

    /**
     * The syllabus the reader is to read, if it still has one: its file's id and the pasted text.
     *
     * @return array{file: ?string, text: ?string}
     */
    public function syllabus(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $row = $this->row($scope, $workspaceId);

        return ['file' => $row?->syllabus_file_id, 'text' => $row?->syllabus_text === null || $row->syllabus_text === '' ? null : (string) $row->syllabus_text];
    }

    /**
     * What the reader found, kept for the student to review: about, outcomes, assessment, textbook, and the modules
     * proposed. $reading is what App\Engine\Jobs\ProfileCourse::parse returned.
     *
     * @param  array{about: string, outcomes: list<string>, assessment: list<array>, textbook: string, modules: list<array>}  $reading
     */
    public function applyReading(Principal $by, string $workspaceId, array $reading, string $model): void
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $row = $this->row($scope, $workspaceId);
        $this->write($scope, $workspaceId, [
            'about' => $reading['about'] !== '' ? $reading['about'] : ($row?->about),
            'outcomes' => json_encode($reading['outcomes']),
            'assessment' => json_encode(array_map(fn (array $item) => $item + ['activity_id' => null], $reading['assessment'])),
            'textbook' => $reading['textbook'] !== '' ? $reading['textbook'] : ($row?->textbook),
            'proposed_modules' => json_encode($reading['modules']),
            'source' => $row?->syllabus_file_id !== null ? 'file' : 'manual',
            'model' => mb_substr($model, 0, 120),
            'built_at' => now(),
            // A pasted syllabus has been read: it is not kept.
            'syllabus_text' => null,
        ]);
    }

    /**
     * Adds what the student ticked from the reader's proposal: the modules, in the order found, and an assignment
     * for each ticked assessment that has a date and hasn't one yet. The proposal's module list is spent.
     *
     * @param  list<int>  $moduleIndexes  positions in the proposed modules
     * @param  list<int>  $assessmentIndexes  positions in the assessment
     * @return array{modules: int, assignments: int}
     */
    public function addFromReading(Principal $by, string $workspaceId, array $moduleIndexes, array $assessmentIndexes = []): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $row = $this->row($scope, $workspaceId);
        $proposed = self::decode($row?->proposed_modules);
        $assessment = self::decode($row?->assessment);

        // In the order the syllabus lists them, whatever order they were ticked in.
        $moduleIndexes = array_values(array_unique(array_map('intval', $moduleIndexes)));
        sort($moduleIndexes);
        $modules = 0;
        foreach ($moduleIndexes as $index) {
            if (! isset($proposed[$index])) {
                continue;
            }
            $this->modules->create($by, $workspaceId, $proposed[$index]);
            $modules++;
        }
        $assignments = 0;
        foreach (array_values(array_unique(array_map('intval', $assessmentIndexes))) as $index) {
            $item = $assessment[$index] ?? null;
            if ($item === null || ($item['due_on'] ?? null) === null || ($item['activity_id'] ?? null) !== null) {
                continue;
            }
            $made = $this->activities->create($by, $workspaceId, ['kind' => $item['kind'], 'title' => $item['name'], 'due_on' => $item['due_on']]);
            $assessment[$index]['activity_id'] = $made->id;
            $assignments++;
        }
        $this->write($scope, $workspaceId, ['proposed_modules' => null, 'assessment' => json_encode($assessment)]);

        return ['modules' => $modules, 'assignments' => $assignments];
    }

    /** The student wants none of the proposed modules. */
    public function dismissProposal(Principal $by, string $workspaceId): void
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        if ($this->row($scope, $workspaceId) !== null) {
            $this->write($scope, $workspaceId, ['proposed_modules' => null]);
        }
    }

    /** Everything the course's profile holds goes with the course (called by Workspaces::delete inside its transaction). */
    public static function forget(LearnerScope $scope, string $workspaceId): void
    {
        LearnerTables::query($scope, 'course_profiles')->where('workspace_id', $workspaceId)->delete();
    }

    // ---------- Inside ----------

    private function row(LearnerScope $scope, string $workspaceId): ?object
    {
        return LearnerTables::query($scope, 'course_profiles')->where('workspace_id', $workspaceId)->first();
    }

    /** @param array<string, mixed> $values */
    private function write(LearnerScope $scope, string $workspaceId, array $values): void
    {
        $query = LearnerTables::query($scope, 'course_profiles')->where('workspace_id', $workspaceId);
        if ($query->exists()) {
            $query->update($values + ['updated_at' => now()]);

            return;
        }
        LearnerTables::insert($scope, 'course_profiles', $values + [
            'id' => Ids::new(), 'workspace_id' => $workspaceId, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{about: ?string, outcomes: list<string>, assessment: list<array>, textbook: ?string}
     */
    private function validated(LearnerScope $scope, array $input): array
    {
        $errors = [];
        $about = Input::text($input, 'about');
        $textbook = Input::text($input, 'textbook');
        if ($about !== null && mb_strlen($about) > self::MAX_ABOUT) {
            $errors['about'] = 'Keep it to '.self::MAX_ABOUT.' characters.';
        }
        if ($textbook !== null && mb_strlen($textbook) > self::MAX_TEXTBOOK) {
            $errors['textbook'] = 'Keep it to '.self::MAX_TEXTBOOK.' characters.';
        }

        $lines = $input['outcomes'] ?? [];
        $lines = is_string($lines) ? preg_split('/\R/u', $lines) : (is_array($lines) ? $lines : []);
        $outcomes = array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim((string) preg_replace('/\s+/u', ' ', $line)) : '', $lines), fn ($line) => $line !== ''));
        if (count($outcomes) > self::MAX_OUTCOMES) {
            $errors['outcomes'] = 'Keep it to '.self::MAX_OUTCOMES.' lines.';
        } elseif (array_filter($outcomes, fn ($line) => mb_strlen($line) > self::MAX_OUTCOME) !== []) {
            $errors['outcomes'] = 'Keep each line to '.self::MAX_OUTCOME.' characters.';
        }

        $assessment = [];
        $rows = is_array($input['assessment'] ?? null) ? $input['assessment'] : [];
        if (count($rows) > self::MAX_ASSESSMENTS) {
            $errors['assessment'] = 'Keep it to '.self::MAX_ASSESSMENTS.' items.';
        }
        foreach (array_slice(array_values($rows), 0, self::MAX_ASSESSMENTS) as $position => $item) {
            $item = is_array($item) ? $item : [];
            $name = Input::text($item, 'name');
            $kind = $item['kind'] ?? 'other';
            $weight = $item['weight'] ?? null;
            $due = $item['due_on'] ?? null;
            $activity = $item['activity_id'] ?? null;
            if ($name === null && ($due === null || $due === '') && ($weight === null || $weight === '')) {
                continue;
            }
            $problem = match (true) {
                $name === null => 'Name it.',
                mb_strlen($name) > self::MAX_NAME => 'Keep the name to '.self::MAX_NAME.' characters.',
                ! is_string($kind) || ! isset(Activities::KINDS[$kind]) => 'Pick one of the kinds.',
                $weight !== null && $weight !== '' && (filter_var($weight, FILTER_VALIDATE_INT) === false || (int) $weight < 0 || (int) $weight > 100) => 'The weight is a number from 0 to 100.',
                $due !== null && $due !== '' && ! self::isDate($due) => 'Enter a date.',
                default => null,
            };
            if ($problem !== null) {
                $errors["assessment.{$position}"] = $problem;

                continue;
            }
            $assessment[] = [
                'name' => $name, 'kind' => $kind,
                'weight' => $weight === null || $weight === '' ? null : (int) $weight,
                'due_on' => $due === null || $due === '' ? null : $due,
                'activity_id' => is_string($activity) && LearnerTables::query($scope, 'activities')->where('id', $activity)->exists() ? $activity : null,
            ];
        }
        Input::refuse($errors);

        return ['about' => $about, 'outcomes' => $outcomes, 'assessment' => $assessment, 'textbook' => $textbook];
    }

    private static function isDate(mixed $value): bool
    {
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @return list<array<string, mixed>> */
    private static function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? array_values($value) : [];
    }

    private static function details(string $workspaceId, ?object $row): CourseProfileDetails
    {
        if ($row === null) {
            return new CourseProfileDetails($workspaceId);
        }

        return new CourseProfileDetails(
            workspaceId: $workspaceId,
            about: (string) $row->about,
            outcomes: array_map('strval', self::decode($row->outcomes)),
            assessment: array_map(fn (array $item) => [
                'name' => (string) ($item['name'] ?? ''), 'kind' => (string) ($item['kind'] ?? 'other'),
                'weight' => isset($item['weight']) ? (int) $item['weight'] : null,
                'due_on' => $item['due_on'] ?? null, 'activity_id' => $item['activity_id'] ?? null,
            ], self::decode($row->assessment)),
            textbook: (string) $row->textbook,
            syllabusFileId: $row->syllabus_file_id,
            hasSyllabusText: $row->syllabus_text !== null && $row->syllabus_text !== '',
            proposedModules: array_map(fn (array $item) => [
                'title' => (string) ($item['title'] ?? ''), 'starts_on' => $item['starts_on'] ?? null, 'ends_on' => $item['ends_on'] ?? null,
            ], self::decode($row->proposed_modules)),
            source: (string) $row->source,
            model: $row->model,
            builtAt: $row->built_at,
            updatedAt: $row->updated_at,
        );
    }
}
