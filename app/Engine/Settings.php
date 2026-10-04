<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Input;

/**
 * Each student's engine settings (docs/specs/study-memory.md §6): which model tutors them, which does small
 * jobs, which to try when the first fails, how much a session and a month may cost, whether their words may be
 * used for training (no, unless they say so), and their consent to the chat sending their study material to
 * the service. The owner's defaults (config) apply until they choose. The key is the owner's and lives in .env.
 */
final class Settings
{
    public const DEFAULT_SESSION_CAP = 2_000_000;

    public const DEFAULT_MONTH_CAP = 20_000_000;

    public const MAX_CAP_DOLLARS = 1000;

    private const MODEL_ID = '#^[a-z0-9][a-z0-9._:/-]{0,118}$#i';

    public function __construct(private Setup $setup) {}

    public function get(Principal $by): Choices
    {
        $row = LearnerTables::query(Guard::learner($by), 'engine_settings')->first();
        $defaults = $this->setup->defaultModels();

        return new Choices(
            tutorModel: (string) ($row->tutor_model ?? $defaults['tutor']),
            quickModel: (string) ($row->quick_model ?? $defaults['quick']),
            fallbackModel: (string) ($row->fallback_model ?? ''),
            sessionCapMicros: (int) ($row->session_cap_micros ?? self::DEFAULT_SESSION_CAP),
            monthCapMicros: (int) ($row->month_cap_micros ?? self::DEFAULT_MONTH_CAP),
            noTraining: $row === null || (bool) $row->no_training,
            consentedAt: $row?->consented_at === null ? null : (string) $row->consented_at,
        );
    }

    /**
     * @param  array<string, mixed>  $input  tutor_model, quick_model, fallback_model (ids or empty), session_cap and month_cap
     *                                       (dollars, "2.50"), no_training and consent (booleans)
     */
    public function set(Principal $by, array $input): Choices
    {
        $scope = Guard::learner($by);
        $errors = [];
        $models = [];
        foreach (['tutor_model', 'quick_model', 'fallback_model'] as $key) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '' && preg_match(self::MODEL_ID, $value) !== 1) {
                $errors[$key] = 'Enter the model\'s id as the service names it, like "openai/gpt-4.1-mini".';
            }
            $models[$key] = $value === '' ? null : $value;
        }
        $caps = [];
        foreach (['session_cap' => self::DEFAULT_SESSION_CAP, 'month_cap' => self::DEFAULT_MONTH_CAP] as $key => $default) {
            $value = $input[$key] ?? null;
            $value = is_string($value) ? trim(str_replace(['$', ','], '', $value)) : $value;
            if ($value === null || $value === '') {
                $caps[$key] = $default;
            } elseif (! is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_CAP_DOLLARS) {
                $errors[$key] = 'Enter an amount in dollars, from 0 to '.self::MAX_CAP_DOLLARS.'.';
            } else {
                $caps[$key] = (int) round((float) $value * 1_000_000);
            }
        }
        Input::refuse($errors);

        $existing = LearnerTables::query($scope, 'engine_settings')->first();
        $consent = filter_var($input['consent'] ?? ($existing?->consented_at !== null), FILTER_VALIDATE_BOOL);
        $values = [
            'tutor_model' => $models['tutor_model'],
            'quick_model' => $models['quick_model'],
            'fallback_model' => $models['fallback_model'],
            'session_cap_micros' => $caps['session_cap'],
            'month_cap_micros' => $caps['month_cap'],
            'no_training' => filter_var($input['no_training'] ?? true, FILTER_VALIDATE_BOOL),
            'consented_at' => $consent ? ($existing?->consented_at ?? now()) : null,
            'updated_at' => now(),
        ];
        if ($existing === null) {
            LearnerTables::insert($scope, 'engine_settings', $values + ['id' => Ids::new(), 'created_at' => now()]);
        } else {
            LearnerTables::query($scope, 'engine_settings')->where('id', $existing->id)->update($values);
        }

        return $this->get($by);
    }
}
