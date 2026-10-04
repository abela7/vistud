<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Input;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Each student's engine settings (docs/specs/study-memory.md §6, docs/specs/vistud-2-blueprint.md §3.6): which
 * model plays each role (the tutor that teaches, the reader that reads files, the helper that does quick jobs),
 * which to try when the tutor's fails, how much a session and a month may cost, whether their words may be used
 * for training (no, unless they say so), the toggles they trust the AI with, and their consent to the chat
 * sending their study material to the service. The owner's defaults (config) apply until they choose. The key is
 * the owner's and lives in .env.
 */
final class Settings
{
    public const DEFAULT_SESSION_CAP = 2_000_000;

    public const DEFAULT_MONTH_CAP = 20_000_000;

    public const MAX_CAP_DOLLARS = 1000;

    private const MODEL_ID = '#^[a-z0-9][a-z0-9._:/-]{0,118}$#i';

    public function __construct(private Setup $setup, private Models $models) {}

    public function get(Principal $by): Choices
    {
        $row = LearnerTables::query(Guard::learner($by), 'engine_settings')->first();
        $defaults = $this->setup->defaultModels();
        $key = self::decrypt($row);

        return new Choices(
            tutorModel: (string) ($row->tutor_model ?? $defaults['tutor']),
            readerModel: (string) ($row->reader_model ?? $defaults['reader']),
            helperModel: (string) ($row->helper_model ?? $defaults['helper']),
            fallbackModel: (string) ($row->fallback_model ?? ''),
            sessionCapMicros: (int) ($row->session_cap_micros ?? self::DEFAULT_SESSION_CAP),
            monthCapMicros: (int) ($row->month_cap_micros ?? self::DEFAULT_MONTH_CAP),
            noTraining: $row === null || (bool) $row->no_training,
            consentedAt: $row?->consented_at === null ? null : (string) $row->consented_at,
            ownKeyHint: $key === null ? null : '…'.substr($key, -4),
            keyUpdatedAt: $key === null || $row?->key_updated_at === null ? null : (string) $row->key_updated_at,
            language: isset($row->language) && $row->language !== '' ? (string) $row->language : null,
            askTopics: (bool) ($row->ask_topics ?? false),
            autoReadFiles: (bool) ($row->auto_read_files ?? true),
            tutorMarksTopics: (bool) ($row->tutor_marks_topics ?? true),
            copyPasteAi: (bool) ($row->copy_paste_ai ?? false),
        );
    }

    /** The student's own key for the service, or null without one (or when it can't be read any more). */
    public function key(Principal $by): ?string
    {
        return self::decrypt(LearnerTables::query(Guard::learner($by), 'engine_settings')->first());
    }

    /**
     * The student's choices, their key and the model for $role, once a call may be made: a key (their own, or the
     * one set up for everyone), a model for the role and the student's consent.
     *
     * @return array{0: Choices, 1: ?string, 2: string}
     *
     * @throws Unprocessable
     */
    public function ready(Principal $by, Role $role): array
    {
        $choices = $this->get($by);
        $key = $this->key($by);
        if ($key === null && ! $this->setup->keySet()) {
            throw new Unprocessable('engine_key', 'Add your OpenRouter key in your AI engine settings first.');
        }
        $model = $choices->modelFor($role);
        if ($model === '') {
            throw new Unprocessable('engine_model', 'Choose a model in your AI engine settings first.');
        }
        if ($choices->consentedAt === null) {
            throw new Unprocessable('engine_consent', 'Agree to the chat in your AI engine settings first.');
        }

        return [$choices, $key, $model];
    }

    /** Whether this student's chats can reach the service at all: their own key, or the one set up for everyone. */
    public function keyAvailable(Principal $by): bool
    {
        return $this->key($by) !== null || $this->setup->keySet();
    }

    /** Keeps the student's own key, encrypted. */
    public function setKey(Principal $by, mixed $key): Choices
    {
        $scope = Guard::learner($by);
        $key = is_string($key) ? trim($key) : '';
        Input::refuse(preg_match('/^\S{16,300}$/', $key) === 1 ? [] : ['key' => 'Paste the whole key, with no spaces (it starts with "sk-or-" on OpenRouter).']);
        $this->ensureRow($scope);
        LearnerTables::query($scope, 'engine_settings')->update(['key_encrypted' => Crypt::encryptString($key), 'key_updated_at' => now(), 'updated_at' => now()]);

        return $this->get($by);
    }

    public function removeKey(Principal $by): Choices
    {
        LearnerTables::query(Guard::learner($by), 'engine_settings')->update(['key_encrypted' => null, 'key_updated_at' => null, 'updated_at' => now()]);

        return $this->get($by);
    }

    /**
     * Asks the service for its models with the student's key (or the one set up for everyone): proof it works.
     *
     * @return array{ok: bool, message: string, models: int}
     */
    public function test(Principal $by): array
    {
        $key = $this->key($by);
        if ($key === null && ! $this->setup->keySet()) {
            return ['ok' => false, 'message' => 'No key yet. Paste your OpenRouter key above.', 'models' => 0];
        }
        try {
            $count = count(app(Engine::class)->models($key));
        } catch (EngineFailed $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'models' => 0];
        }
        if ($count > 0) {
            Cache::forget('vistud.engine.models');
        }

        return $count > 0
            ? ['ok' => true, 'message' => "It works: the service offers {$count} models.", 'models' => $count]
            : ['ok' => false, 'message' => 'The service answered but listed no models.', 'models' => 0];
    }

    /**
     * @param  array<string, mixed>  $input  tutor_model, reader_model, helper_model, fallback_model (ids or empty), session_cap
     *                                       and month_cap (dollars, "2.50"), no_training and consent (booleans); language,
     *                                       ask_topics, auto_read_files, tutor_marks_topics and copy_paste_ai are kept as
     *                                       they are when left out
     */
    public function set(Principal $by, array $input): Choices
    {
        $scope = Guard::learner($by);
        $errors = [];
        $models = [];
        foreach (['tutor_model', 'reader_model', 'helper_model', 'fallback_model'] as $key) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '' && preg_match(self::MODEL_ID, $value) !== 1) {
                $errors[$key] = 'Enter the model\'s id as the service names it, like "openai/gpt-4.1-mini".';
            } elseif (str_ends_with($value, ':batch')) {
                $errors[$key] = 'That is the batch version, which answers hours later. Choose the one without ":batch".';
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
        $existing = LearnerTables::query($scope, 'engine_settings')->first();
        // The language, when given: a name like "Amharic" or "Afaan Oromo"; empty for the one the student writes in.
        $language = array_key_exists('language', $input) ? (is_string($input['language']) ? trim($input['language']) : '') : (string) ($existing?->language ?? '');
        if ($language !== '' && preg_match("/^\\p{L}[\\p{L}\\p{M} ()'’-]{0,39}$/u", $language) !== 1) {
            $errors['language'] = 'Write the language\'s name, like "Amharic".';
        }
        Input::refuse($errors);

        $consent = filter_var($input['consent'] ?? ($existing?->consented_at !== null), FILTER_VALIDATE_BOOL);
        $values = [
            'tutor_model' => $models['tutor_model'],
            'reader_model' => $models['reader_model'],
            'helper_model' => $models['helper_model'],
            'fallback_model' => $models['fallback_model'],
            'session_cap_micros' => $caps['session_cap'],
            'month_cap_micros' => $caps['month_cap'],
            'no_training' => filter_var($input['no_training'] ?? true, FILTER_VALIDATE_BOOL),
            'language' => $language === '' ? null : $language,
            'ask_topics' => filter_var($input['ask_topics'] ?? ($existing?->ask_topics ?? false), FILTER_VALIDATE_BOOL),
            'auto_read_files' => filter_var($input['auto_read_files'] ?? ($existing?->auto_read_files ?? true), FILTER_VALIDATE_BOOL),
            'tutor_marks_topics' => filter_var($input['tutor_marks_topics'] ?? ($existing?->tutor_marks_topics ?? true), FILTER_VALIDATE_BOOL),
            'copy_paste_ai' => filter_var($input['copy_paste_ai'] ?? ($existing?->copy_paste_ai ?? false), FILTER_VALIDATE_BOOL),
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

    /** A row with the defaults, so a key can be kept before anything else is chosen. */
    private function ensureRow(LearnerScope $scope): void
    {
        if (! LearnerTables::query($scope, 'engine_settings')->exists()) {
            LearnerTables::insert($scope, 'engine_settings', [
                'id' => Ids::new(), 'session_cap_micros' => self::DEFAULT_SESSION_CAP, 'month_cap_micros' => self::DEFAULT_MONTH_CAP,
                'no_training' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private static function decrypt(?object $row): ?string
    {
        if ($row === null || $row->key_encrypted === null) {
            return null;
        }
        try {
            return Crypt::decryptString($row->key_encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
