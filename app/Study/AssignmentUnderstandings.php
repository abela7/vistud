<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the AI understands of an assignment (the owner's ask, 2026-10-13: "the AI should understand the assignment in
 * detail… every model change needs to have the same understanding"), kept so that every model, and every later step
 * (planning, tracking, review, submission), starts from the same written picture instead of its own guess. Written by
 * the tutor's model from all the assignment's sources (App\Engine\Jobs\UnderstandAssignment), one revision at a time:
 * a revision is never changed, a new one says what changed and why. Every point names the source it came from, or says
 * it is the AI's own reading; the modules and topics it links are the course's own. The student reads it, says it is
 * right, or corrects it (a correction is a source, App\Study\AssignmentSources). The student's own stream only.
 */
final class AssignmentUnderstandings
{
    /** The most assignment-specific sections and items the AI may add beyond the standard understanding. */
    public const EXTRA_SECTIONS = 3;

    public const EXTRA_ITEMS = 12;

    /** The parts that are lists, with the most items each keeps and the fields of an item: name => [max, [field => limit]]. */
    public const LISTS = [
        'deliverables' => [12, ['what' => 300, 'format' => 200, 'length' => 120]],
        'requirements' => [25, ['text' => 300]],
        'constraints' => [15, ['text' => 300]],
        'criteria' => [20, ['criterion' => 300, 'looks_like' => 500]],
        'teacher_expects' => [15, ['text' => 300]],
        'best_practices' => [12, ['text' => 300]],
        'dates' => [12, ['what' => 200]],
        'modules' => [12, ['module' => 200, 'why' => 300]],
        'topics' => [20, ['topic' => 200, 'why' => 300]],
        'assumptions' => [12, ['text' => 300]],
        'risks' => [12, ['text' => 300]],
        'gaps' => [12, ['text' => 300]],
        'questions' => [12, ['text' => 300]],
    ];

    public const CONFIDENCE = ['high', 'medium', 'low'];

    /** The revisions the history shows. */
    public const HISTORY = 20;

    public function __construct(private Activities $activities) {}

    public function latest(Principal $by, string $activityId): ?AssignmentUnderstanding
    {
        $this->activities->find($by, $activityId);
        $row = LearnerTables::query(Guard::learner($by), 'activity_understandings')->where('activity_id', $activityId)->orderByDesc('revision')->first();

        return $row === null ? null : self::details($row);
    }

    /** @return list<AssignmentUnderstanding> newest first */
    public function history(Principal $by, string $activityId): array
    {
        $this->activities->find($by, $activityId);

        return LearnerTables::query(Guard::learner($by), 'activity_understandings')->where('activity_id', $activityId)
            ->orderByDesc('revision')->limit(self::HISTORY)->get()->map(fn ($row) => self::details($row))->all();
    }

    /**
     * Keeps a new revision: the body as clean() makes it, what changed, the sources it cited and the fingerprint of what
     * it was made from.
     *
     * @param  array<string, mixed>  $body  as clean() gives it
     * @param  array<string, array{type: string, id: string, title: string, unread?: bool}>  $sources  unread: a file still
     *                                                                                                 being converted
     */
    public function keep(Principal $by, string $activityId, array $body, ?string $changes, array $sources, string $fingerprint, ?string $model): AssignmentUnderstanding
    {
        $scope = Guard::learner($by);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $body, $changes, $sources, $fingerprint, $model, $id) {
            $assignment = LearnerTables::query($scope, 'activities')->where('id', $activityId)->lockForUpdate()->first() ?? throw new NotFound;
            $revision = (int) LearnerTables::query($scope, 'activity_understandings')->where('activity_id', $activityId)->max('revision') + 1;
            LearnerTables::insert($scope, 'activity_understandings', [
                'id' => $id, 'workspace_id' => $assignment->workspace_id, 'activity_id' => $activityId, 'revision' => $revision,
                'body' => json_encode($body), 'changes' => $changes === null || trim($changes) === '' ? null : mb_substr(trim($changes), 0, 1500),
                'sources' => json_encode($sources), 'fingerprint' => $fingerprint, 'model' => $model === null ? null : mb_substr($model, 0, 120),
                'created_at' => now(),
            ]);
        });

        return self::details(LearnerTables::query($scope, 'activity_understandings')->where('id', $id)->first());
    }

    /** The student says the latest revision is right. */
    public function confirm(Principal $by, string $activityId): void
    {
        $latest = $this->latest($by, $activityId) ?? throw new NotFound;
        LearnerTables::query(Guard::learner($by), 'activity_understandings')->where('id', $latest->id)->update(['confirmed_at' => now()]);
    }

    /**
     * What a model's answer says, kept only as the app knows it: each part within its limits, every item with the source
     * it cites (a label it was given, "course" or "inferred"; anything else is "inferred"), dates as dates, weights from
     * 1 to 100, and only the modules and topics the course has (by their names as the course has them, with their ids).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $labels  the source labels the model was given (S1, F2…)
     * @param  list<array{id: string, title: string}>  $modules
     * @param  list<array{id: string, name: string}>  $topics
     * @return array<string, mixed>
     */
    public static function clean(array $data, array $labels, array $modules, array $topics): array
    {
        $text = fn (mixed $value, int $limit) => is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $limit) : '';
        $source = fn (mixed $value) => is_string($value) && (in_array(trim($value), $labels, true) || in_array(trim($value), ['course', 'inferred'], true)) ? trim($value) : 'inferred';
        $date = function (mixed $value): ?string {
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', trim($value)) : false;

            return $parsed !== false && $parsed->format('Y-m-d') === trim((string) $value) ? trim($value) : null;
        };
        $byTitle = [];
        foreach ($modules as $module) {
            $byTitle[mb_strtolower($module['title'])] = $module;
        }
        $byName = [];
        foreach ($topics as $topic) {
            $byName[mb_strtolower($topic['name'])] = $topic;
        }

        $out = [
            'summary' => $text($data['summary'] ?? null, 1200),
            'goal' => $text($data['goal'] ?? null, 600),
            'confidence' => in_array($data['confidence'] ?? null, self::CONFIDENCE, true) ? $data['confidence'] : 'low',
        ];
        foreach (self::LISTS as $part => [$max, $fields]) {
            $items = [];
            foreach (is_array($data[$part] ?? null) ? array_values($data[$part]) : [] as $item) {
                // A plain line is an item with only its main field.
                $item = is_string($item) ? [array_key_first($fields) => $item] : $item;
                if (! is_array($item) || count($items) >= $max) {
                    continue;
                }
                $clean = [];
                foreach ($fields as $field => $limit) {
                    $clean[$field] = $text($item[$field] ?? null, $limit);
                }
                if ($clean[array_key_first($fields)] === '') {
                    continue;
                }
                $clean['source'] = $source($item['source'] ?? null);
                if (in_array($part, ['deliverables', 'dates'], true)) {
                    $clean['on'] = $date($item['on'] ?? $item['due'] ?? null);
                }
                if ($part === 'criteria') {
                    $weight = filter_var($item['weight'] ?? null, FILTER_VALIDATE_INT);
                    $clean['weight'] = $weight !== false && $weight >= 1 && $weight <= 100 ? $weight : null;
                }
                if ($part === 'modules') {
                    $known = $byTitle[mb_strtolower($clean['module'])] ?? null;
                    if ($known === null) {
                        continue;
                    }
                    [$clean['module'], $clean['id']] = [$known['title'], $known['id']];
                }
                if ($part === 'topics') {
                    $known = $byName[mb_strtolower($clean['topic'])] ?? null;
                    if ($known === null) {
                        continue;
                    }
                    [$clean['topic'], $clean['id']] = [$known['name'], $known['id']];
                }
                $items[] = $clean;
            }
            $out[$part] = $items;
        }

        $reserved = array_flip([
            'what to hand in', 'must do', 'limits', 'what it is marked on', 'what the teacher wants to see', 'dates',
            'ask your teacher', 'missing or unclear', 'topics it draws on', 'modules it draws on', 'risks',
            'good practice', 'how the ai reads it',
        ]);
        $seen = [];
        $out['extra_sections'] = [];
        foreach (is_array($data['extra_sections'] ?? null) ? array_values($data['extra_sections']) : [] as $section) {
            if (! is_array($section) || count($out['extra_sections']) >= self::EXTRA_SECTIONS) {
                continue;
            }
            $title = $text($section['title'] ?? null, 80);
            $key = mb_strtolower($title);
            if ($title === '' || isset($reserved[$key]) || isset($seen[$key])) {
                continue;
            }
            $items = [];
            foreach (is_array($section['items'] ?? null) ? array_values($section['items']) : [] as $item) {
                $item = is_string($item) ? ['text' => $item] : $item;
                if (! is_array($item) || count($items) >= self::EXTRA_ITEMS) {
                    continue;
                }
                $itemText = $text($item['text'] ?? null, 300);
                if ($itemText !== '') {
                    $items[] = ['text' => $itemText, 'source' => $source($item['source'] ?? null)];
                }
            }
            if ($items !== []) {
                $seen[$key] = true;
                $out['extra_sections'][] = ['title' => $title, 'items' => $items];
            }
        }

        return $out;
    }

    /** Whether an answer says anything at all: a summary, a goal, or one item in any part. */
    public static function saysSomething(array $body): bool
    {
        if (($body['summary'] ?? '') !== '' || ($body['goal'] ?? '') !== '') {
            return true;
        }
        foreach (array_keys(self::LISTS) as $part) {
            if (($body[$part] ?? []) !== []) {
                return true;
            }
        }

        return ($body['extra_sections'] ?? []) !== [];
    }

    private static function details(object $row): AssignmentUnderstanding
    {
        $json = fn (mixed $value) => is_string($value) ? (json_decode($value, true) ?: []) : [];

        return new AssignmentUnderstanding(
            (string) $row->id, (string) $row->activity_id, (int) $row->revision, $json($row->body), $row->changes === null ? null : (string) $row->changes,
            $json($row->sources), (string) $row->fingerprint, $row->model === null ? null : (string) $row->model,
            $row->confirmed_at === null ? null : (string) $row->confirmed_at, (string) $row->created_at,
        );
    }
}
