<?php

namespace App\Engine;

use App\Identity\RoleStore;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\ContentCipher;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Input;
use Illuminate\Support\Facades\Cache;

/**
 * Each student's engine settings (docs/specs/study-memory.md §6, docs/specs/vistud-2-blueprint.md §3.6): which
 * model plays each role (the tutor that teaches, the reader that reads files, the helper that does quick jobs),
 * which to try when the tutor's fails, how much a session and a month may cost, whether their words may be used
 * for training (no, unless they say so), the toggles they trust the AI with, and their consent to the chat
 * sending their study material to the service. The owner's defaults (config) apply until they choose. The key is
 * private to the student. Installation credentials are reserved for admin accounts.
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
        $key = $this->decrypt(Guard::learner($by), $row);
        $rawFavorites = $row->favorite_models ?? null;
        $favoriteModels = is_string($rawFavorites) ? (json_decode($rawFavorites, true) ?: []) : (is_array($rawFavorites) ? $rawFavorites : []);

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
            tutorRemembers: (bool) ($row->tutor_remembers ?? true),
            copyPasteAi: (bool) ($row->copy_paste_ai ?? false),
            favoriteModels: $favoriteModels,
            provider: $key === null ? null : self::providerOf($row),
        );
    }

    /**
     * The student's own key, as the engine takes it (App\Engine\Credential: its provider and the secret), or null
     * without one (or when it can't be read any more).
     */
    public function key(Principal $by): ?string
    {
        $row = LearnerTables::query(Guard::learner($by), 'engine_settings')->first();
        $secret = $this->decrypt(Guard::learner($by), $row);

        return $secret === null ? null : Credential::pack(self::providerOf($row), $secret);
    }

    /**
     * The student's choices, their key and the model for $role, once a call may be made: a key (their own, or the
     * installation key for an admin account), a model for the role and the student's consent.
     *
     * @return array{0: Choices, 1: ?string, 2: string}
     *
     * @throws Unprocessable
     */
    public function ready(Principal $by, Role $role): array
    {
        $choices = $this->get($by);
        $key = $this->credential($by);
        if ($key === '') {
            throw new Unprocessable('engine_key', 'Add your key in your AI settings first.');
        }
        $model = $choices->modelFor($role);
        if ($model === '') {
            throw new Unprocessable('engine_model', 'Choose a model in your AI settings first.');
        }
        if ($choices->consentedAt === null) {
            throw new Unprocessable('engine_consent', 'Agree to the chat in your AI settings first.');
        }

        return [$choices, $key, $model];
    }

    /** Whether this student's chats can reach the service at all: their own key, or the installation key for an admin account. */
    public function keyAvailable(Principal $by): bool
    {
        return $this->credential($by) !== '';
    }

    /**
     * Keeps the student's own key, encrypted, with whose it is (the owner's ask, 2026-10-12). $provider says where it is
     * from: `openrouter` (one key, every model), a provider's id (App\Engine\Providers), or `own` to find out: each
     * provider the key may belong to is asked for its models, most likely first (by how its keys look), and the first
     * that takes it has it. With a provider's own key, the tutor gets that provider's strongest model and the reader and
     * the helper its quick one, among the models the key can use; back on OpenRouter, the models are the owner's again.
     *
     * @throws Unprocessable a key that isn't one, or that no provider took
     */
    public function setKey(Principal $by, mixed $key, string $provider = Providers::OPENROUTER): Choices
    {
        $scope = Guard::learner($by);
        $key = is_string($key) ? trim($key) : '';
        Input::refuse(preg_match('/^\S{16,300}$/', $key) === 1 ? [] : ['key' => 'Paste the whole key, with no spaces.']);
        Input::refuse($provider === 'own' || Providers::known($provider) ? [] : ['provider' => 'Choose where the key is from.']);

        [$provider, $models] = $this->whose($key, $provider);
        $this->ensureRow($scope);
        if (Providers::direct($provider)) {
            $quick = $this->answering($provider, $key, 'quick', $models);
            $tutor = $this->answering($provider, $key, 'tutor', $models);
        }
        $roles = Providers::direct($provider)
            ? ['tutor_model' => $tutor, 'reader_model' => $quick, 'helper_model' => $quick, 'fallback_model' => $quick !== $tutor ? $quick : null, 'favorite_models' => null]
            : (self::providerOf(LearnerTables::query($scope, 'engine_settings')->first()) !== Providers::OPENROUTER ? ['tutor_model' => null, 'reader_model' => null, 'helper_model' => null, 'fallback_model' => null, 'favorite_models' => null] : []);
        LearnerTables::query($scope, 'engine_settings')->update($roles + [
            'key_encrypted' => $this->seal($scope, LearnerTables::query($scope, 'engine_settings')->first(), $key), 'key_provider' => $provider === Providers::OPENROUTER ? null : $provider,
            'key_updated_at' => now(), 'updated_at' => now(),
        ]);

        return $this->get($by);
    }

    /**
     * The model for a role that answers with this key: a provider can list a model its plan won't run (Google's free
     * plan lists Gemini Pro with a quota of none), so the provider's choices are asked in turn, a word each, and the
     * first that answers has the role. A refused key or an empty account answers no better further down the list; with
     * none answering, the first choice is kept and trying the models says why.
     *
     * @param  list<string>  $offered
     */
    private function answering(string $provider, string $key, string $role, array $offered): string
    {
        foreach (array_slice(Providers::choices($provider, $role, $offered), 0, 3) as $model) {
            try {
                app(Engine::class)->reply(new Request($model, 'Answer in one word.', [['role' => 'user', 'content' => 'Say OK.']], maxTokens: 400, key: Credential::pack($provider, $key)));

                return $model;
            } catch (EngineFailed $e) {
                if (in_array($e->errorCode, ['engine_bad_key', 'engine_credit', 'engine_unreachable', 'engine_not_set_up'], true)) {
                    break;
                }
            }
        }

        return Providers::pick($provider, $role, $offered);
    }

    /**
     * Whose a key is, and the models it can use there: the provider named, or (`own`) the first of those it may belong
     * to that takes it. A key for OpenRouter is kept as it is; its models are checked when the student tries it.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws Unprocessable
     */
    private function whose(string $key, string $provider): array
    {
        if ($provider === Providers::OPENROUTER) {
            return [Providers::OPENROUTER, []];
        }
        $tried = $provider === 'own' ? array_values(array_filter(Providers::candidates($key), fn (string $id) => $id !== Providers::OPENROUTER || str_starts_with($key, 'sk-or-'))) : [$provider];
        foreach ($tried as $id) {
            try {
                $models = array_map(fn (Model $m) => $m->id, app(Engine::class)->models(Credential::pack($id, $key)));
            } catch (EngineFailed) {
                continue;
            }
            if ($models !== []) {
                return [$id, $models];
            }
        }
        Input::refuse(['key' => $provider === 'own'
            ? 'None of the providers took this key. Check that it is whole and active, or choose its provider.'
            : Providers::name($provider).' didn\'t take this key. Check that it is whole and active, and that it is a '.Providers::name($provider).' key.']);

        return [$provider, []];
    }

    public function removeKey(Principal $by): Choices
    {
        $scope = Guard::learner($by);
        $row = LearnerTables::query($scope, 'engine_settings')->first();
        // A provider's models mean nothing without its key: the owner's are the ones again.
        $roles = self::providerOf($row) !== Providers::OPENROUTER ? ['tutor_model' => null, 'reader_model' => null, 'helper_model' => null, 'fallback_model' => null, 'favorite_models' => null] : [];
        LearnerTables::query($scope, 'engine_settings')->update($roles + ['key_encrypted' => null, 'key_provider' => null, 'key_updated_at' => null, 'updated_at' => now()]);

        return $this->get($by);
    }

    /** Whose the stored key is: a provider's id, or OpenRouter's. */
    private static function providerOf(?object $row): string
    {
        $provider = $row?->key_provider ?? null;

        return is_string($provider) && Providers::known($provider) ? $provider : Providers::OPENROUTER;
    }

    /**
     * Asks the service for its models with the student's key (or the installation key for an admin account): proof it works.
     *
     * @return array{ok: bool, message: string, models: int}
     */
    public function test(Principal $by): array
    {
        $key = $this->credential($by);
        if ($key === '') {
            return ['ok' => false, 'message' => 'No key yet. Paste your key above.', 'models' => 0];
        }
        try {
            $count = count(app(Engine::class)->models($key));
        } catch (EngineFailed $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'models' => 0];
        }
        if ($count > 0) {
            Cache::forget(Models::cacheKey($key));
        }

        return $count > 0
            ? ['ok' => true, 'message' => 'It works: '.Providers::name(Credential::provider($key)).' offers '.$count.' models.', 'models' => $count]
            : ['ok' => false, 'message' => 'The service answered but listed no models.', 'models' => 0];
    }

    /**
     * Sends one message to one model with the student's own key and nothing else (no fallback, no study material), so
     * they see whether the model answers before a session leans on it (the owner's ask, 2026-10-12): a provider can list
     * a model its plan won't run. Only with their own key: the installation key for an admin account isn't for trying things out.
     *
     * @return array{ok: bool, model: string, reply: string, message: string, ms: int, cost: ?int}
     */
    public function tryModel(Principal $by, string $model, string $words = 'Reply with one word: OK.'): array
    {
        $model = trim($model);
        $words = mb_substr(trim($words), 0, 500);
        $result = fn (bool $ok, string $message, string $reply = '', int $ms = 0, ?int $cost = null) => ['ok' => $ok, 'model' => $model, 'reply' => $reply, 'message' => $message, 'ms' => $ms, 'cost' => $cost];
        $key = $this->key($by);
        if ($key === null) {
            return $result(false, 'Add your own key first: trying a model uses it.');
        }
        if ($model === '' || preg_match(self::MODEL_ID, $model) !== 1) {
            return $result(false, 'Choose a model first.');
        }
        if ($words === '') {
            return $result(false, 'Write something first.');
        }
        $started = hrtime(true);
        try {
            $reply = app(Engine::class)->reply(new Request($model, 'You are being tried out from a settings page. Answer in a sentence or two.', [['role' => 'user', 'content' => $words]],
                maxTokens: 800, noTraining: $this->get($by)->noTraining, key: $key));
        } catch (EngineFailed $e) {
            return $result(false, $e->getMessage(), ms: (int) ((hrtime(true) - $started) / 1_000_000));
        }
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $text = trim($reply->text);

        return $result(true, $text === '' ? 'It answered, but with no words (it may have spent them all thinking).' : 'It answered.', $text, $ms, $reply->costMicros);
    }

    /**
     * @param  array<string, mixed>  $input  tutor_model, reader_model, helper_model, fallback_model (ids or empty), session_cap
     *                                       and month_cap (dollars, "2.50"), no_training and consent (booleans); language,
     *                                       ask_topics, auto_read_files, tutor_marks_topics, tutor_remembers and copy_paste_ai are kept as
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
        $favs = $input['favorite_models'] ?? ($existing?->favorite_models ? json_decode($existing->favorite_models, true) : []);
        $validFavs = [];
        if (is_array($favs)) {
            $validFavs = array_values(array_unique(array_filter(array_map('trim', $favs), fn ($m) => is_string($m) && $m !== '' && preg_match(self::MODEL_ID, $m) === 1)));
        }
        $values = [
            'tutor_model' => $models['tutor_model'],
            'reader_model' => $models['reader_model'],
            'helper_model' => $models['helper_model'],
            'fallback_model' => $models['fallback_model'],
            'favorite_models' => $validFavs === [] ? null : json_encode($validFavs),
            'session_cap_micros' => $caps['session_cap'],
            'month_cap_micros' => $caps['month_cap'],
            'no_training' => filter_var($input['no_training'] ?? true, FILTER_VALIDATE_BOOL),
            'language' => $language === '' ? null : $language,
            'ask_topics' => filter_var($input['ask_topics'] ?? ($existing?->ask_topics ?? false), FILTER_VALIDATE_BOOL),
            'auto_read_files' => filter_var($input['auto_read_files'] ?? ($existing?->auto_read_files ?? true), FILTER_VALIDATE_BOOL),
            'tutor_marks_topics' => filter_var($input['tutor_marks_topics'] ?? ($existing?->tutor_marks_topics ?? true), FILTER_VALIDATE_BOOL),
            'tutor_remembers' => filter_var($input['tutor_remembers'] ?? ($existing?->tutor_remembers ?? true), FILTER_VALIDATE_BOOL),
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

    /** Explicit credential for every learner call. Empty never means installation fallback. */
    public function credential(Principal $by): string
    {
        $own = $this->key($by);
        if ($own !== null) {
            return $own;
        }

        return $by->userId !== null && app(RoleStore::class)->has($by->userId, \App\Platform\Access\Role::Admin)
            ? $this->setup->key() : '';
    }

    /** Audio through the existing chat adapter; unsupported providers fail before processing bytes. */
    public function audioModel(Principal $by): ?string
    {
        $key = $this->credential($by);
        if ($key === '') {
            return null;
        }

        return match (Credential::provider($key)) {
            Providers::OPENROUTER => (string) config('vistud.engine.audio_model', 'google/gemini-2.5-flash'),
            'google' => str_starts_with($this->get($by)->readerModel, 'gemini-') ? $this->get($by)->readerModel : 'gemini-2.5-flash',
            'openai' => 'gpt-audio',
            default => null,
        };
    }

    public function seal(LearnerScope $scope, object $row, string $secret): string
    {
        return 'vsk1:'.app(ContentCipher::class)->encrypt($scope, 'engine_settings', (array) $row, 'key_encrypted', $secret);
    }

    private function decrypt(LearnerScope $scope, ?object $row): ?string
    {
        if ($row === null || $row->key_encrypted === null) {
            return null;
        }
        if (! str_starts_with($row->key_encrypted, 'vsk1:')) {
            throw new Unprocessable('engine_key', 'Your saved key needs the offline key conversion.');
        }

        return app(ContentCipher::class)->decrypt($scope, 'engine_settings', (array) $row, 'key_encrypted', substr($row->key_encrypted, 5));
    }
}
