<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;

/**
 * How a student likes to learn in a course (docs/specs/vistud-2-blueprint.md §3.5.2): four short questions, all
 * optional. They ask how the student likes things *explained*, which the tutor can act on, and never put them in a
 * learning-style box. The answers reach the tutor as one line, and they are the defaults for how a session teaches
 * (App\Study\Tutoring), which a session can still override. One row per course, made when first saved. The
 * student's own courses only: another student's is 404.
 */
final class LearnerProfiles
{
    public const MAX_NOTE = 300;

    /** How the student likes things explained (several allowed): key => [label, how the tutor is told]. */
    public const EXPLAIN = [
        'examples' => ['Examples first', 'examples first'],
        'theory' => ['Theory first', 'theory first'],
        'analogies' => ['Analogies', 'analogies'],
        'diagrams' => ['Diagrams', 'diagrams'],
    ];

    public const PACE = [
        'small' => ['Small steps', 'small steps'],
        'normal' => ['Normal', 'normal'],
        'fast' => ['Fast', 'fast'],
    ];

    public const CHECK = [
        'often' => ['Often', 'often'],
        'end' => ['At the end', 'at the end'],
        'rarely' => ['Rarely', 'rarely'],
    ];

    public const GOAL = [
        'pass' => ['Pass', 'pass'],
        'top' => ['Top marks', 'top marks'],
        'deep' => ['Understand deeply', 'understand deeply'],
        'coursework' => ['Finish the coursework', 'finish the coursework'],
    ];

    public function get(Principal $by, string $workspaceId): LearnerProfileDetails
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return self::details($workspaceId, $this->row($scope, $workspaceId));
    }

    /**
     * @param  array{explain?: mixed, pace?: mixed, check?: mixed, goal?: mixed, note?: mixed}  $input
     */
    public function save(Principal $by, string $workspaceId, array $input): LearnerProfileDetails
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $explain = $input['explain'] ?? [];
        $explain = is_array($explain) ? array_values(array_unique(array_filter($explain, 'is_string'))) : [];
        $note = Input::text($input, 'note');
        $errors = [];
        if (array_diff($explain, array_keys(self::EXPLAIN)) !== []) {
            $errors['explain'] = 'Choose from the options.';
        }
        $single = [];
        foreach (['pace' => self::PACE, 'check' => self::CHECK, 'goal' => self::GOAL] as $key => $options) {
            $value = $input[$key] ?? null;
            $value = $value === '' ? null : $value;
            if ($value !== null && (! is_string($value) || ! isset($options[$value]))) {
                $errors[$key] = 'Choose one of the options.';
            }
            $single[$key] = is_string($value) ? $value : null;
        }
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            $errors['note'] = 'Keep it to '.self::MAX_NOTE.' characters.';
        }
        Input::refuse($errors);

        // The order the options are listed in, whatever order they were picked in.
        $explain = array_values(array_intersect(array_keys(self::EXPLAIN), $explain));
        $values = ['preferences' => json_encode(['explain' => $explain] + $single), 'note' => $note];
        $query = LearnerTables::query($scope, 'learner_profiles')->where('workspace_id', $workspaceId);
        if ($query->exists()) {
            $query->update($values + ['updated_at' => now()]);
        } else {
            LearnerTables::insert($scope, 'learner_profiles', $values + ['id' => Ids::new(), 'workspace_id' => $workspaceId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this->get($by, $workspaceId);
    }

    /**
     * What the tutor is told, one short line each: "Likes: examples first, diagrams", "Pace: small steps"…, and what
     * the student added in their own words.
     *
     * @return list<string>
     */
    public static function lines(LearnerProfileDetails $profile): array
    {
        return array_values(array_filter([
            $profile->explain === [] ? null : 'Likes: '.implode(', ', array_map(fn (string $key) => self::EXPLAIN[$key][1], $profile->explain)),
            $profile->pace === null ? null : 'Pace: '.self::PACE[$profile->pace][1],
            $profile->check === null ? null : 'Check: '.self::CHECK[$profile->check][1],
            $profile->goal === null ? null : 'Goal: '.self::GOAL[$profile->goal][1],
            $profile->note === '' ? null : 'Also: '.$profile->note,
        ]));
    }

    /**
     * How a session teaches when the student hasn't chosen otherwise: their answers as App\Study\Tutoring's four
     * choices, or null when they haven't answered (the plain defaults then apply).
     *
     * @return ?array{method: string, check_ins: string, quiz: string, pace: string}
     */
    public static function teaching(LearnerProfileDetails $profile): ?array
    {
        if (! $profile->answered()) {
            return null;
        }

        return [
            'method' => match (true) {
                $profile->pace === 'small' => 'steps',
                in_array('theory', $profile->explain, true) => 'summary',
                default => Tutoring::DEFAULTS['method'],
            },
            'check_ins' => ['often' => 'section', 'end' => 'end', 'rarely' => 'none'][$profile->check] ?? Tutoring::DEFAULTS['check_ins'],
            'quiz' => $profile->goal === 'top' ? 'exam' : Tutoring::DEFAULTS['quiz'],
            'pace' => $profile->pace === 'fast' ? 'section' : Tutoring::DEFAULTS['pace'],
        ];
    }

    /** The profile goes with the course (called by Workspaces::delete inside its transaction). */
    public static function forget(LearnerScope $scope, string $workspaceId): void
    {
        LearnerTables::query($scope, 'learner_profiles')->where('workspace_id', $workspaceId)->delete();
    }

    private function row(LearnerScope $scope, string $workspaceId): ?object
    {
        return LearnerTables::query($scope, 'learner_profiles')->where('workspace_id', $workspaceId)->first();
    }

    private static function details(string $workspaceId, ?object $row): LearnerProfileDetails
    {
        if ($row === null) {
            return new LearnerProfileDetails($workspaceId);
        }
        $stored = json_decode((string) $row->preferences, true);
        $stored = is_array($stored) ? $stored : [];
        $one = fn (string $key, array $options) => is_string($stored[$key] ?? null) && isset($options[$stored[$key]]) ? $stored[$key] : null;

        return new LearnerProfileDetails(
            workspaceId: $workspaceId,
            explain: array_values(array_filter(is_array($stored['explain'] ?? null) ? $stored['explain'] : [], fn ($key) => is_string($key) && isset(self::EXPLAIN[$key]))),
            pace: $one('pace', self::PACE),
            check: $one('check', self::CHECK),
            goal: $one('goal', self::GOAL),
            note: (string) $row->note,
            updatedAt: $row->updated_at,
        );
    }
}
