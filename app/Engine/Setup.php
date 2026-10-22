<?php

namespace App\Engine;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Study\Input;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The engine's set-up for the whole installation (docs/specs/study-memory.md §6), done by an admin on the
 * AI engine page of the admin area, so nobody has to open .env or a shell: the service's key (kept encrypted
 * with the app key, shown again only by its last four characters), the service's address, and the models
 * every student starts with. A key in .env (VISTUD_ENGINE_KEY) still works, for servers set up by hand, until
 * one is set here. Every change is in the audit log; the key itself never is.
 */
final class Setup
{
    public const KEY = 'engine.key';

    public const URL = 'engine.url';

    public const TUTOR = 'engine.tutor_model';

    public const READER = 'engine.reader_model';

    public const HELPER = 'engine.helper_model';

    /** The shared key for students without their own (the owner's ask, 2026-10-22): on or off, its own key, one model, a daily limit. */
    public const SHARED_ON = 'engine.shared_on';

    public const SHARED_KEY = 'engine.shared_key';

    public const SHARED_MODEL = 'engine.shared_model';

    public const SHARED_DAILY = 'engine.shared_daily_micros';

    public const DEFAULT_SHARED_DAILY = 100_000;

    public const MAX_SHARED_DAILY_DOLLARS = 10;

    private const MODEL_ID = '#^[a-z0-9][a-z0-9._:/-]{0,118}$#i';

    /** @var array<string, object>|null */
    private ?array $rows = null;

    public function __construct(private AuditLog $audit) {}

    // ---------- What the engine and the screens read ----------

    /** The key to call the service with: the one set here, else .env's; empty when there is none. */
    public function key(): string
    {
        $row = $this->rows()[self::KEY] ?? null;
        if ($row !== null && $row->value !== null) {
            try {
                return Crypt::decryptString($row->value);
            } catch (DecryptException) {
                // The app key changed since it was saved: it can't be read, so it counts as not set.
                return '';
            }
        }

        return (string) config('vistud.engine.key');
    }

    public function keySet(): bool
    {
        return $this->key() !== '';
    }

    public function url(): string
    {
        $value = $this->rows()[self::URL]->value ?? null;

        return is_string($value) && $value !== '' ? $value : (string) config('vistud.engine.url');
    }

    /** @return array{tutor: string, reader: string, helper: string} the models a student starts with, by role */
    public function defaultModels(): array
    {
        return [
            'tutor' => (string) ($this->rows()[self::TUTOR]->value ?? config('vistud.engine.tutor_model', '')),
            'reader' => (string) ($this->rows()[self::READER]->value ?? config('vistud.engine.reader_model', '')),
            'helper' => (string) ($this->rows()[self::HELPER]->value ?? config('vistud.engine.helper_model', '')),
        ];
    }

    /**
     * The shared key a student without their own may use, with the one model it runs and how much a student may
     * spend on it a day (millionths of a dollar); null while it is off or not fully set up. It is never the admin
     * key: a student on it can't reach the admin key's models or limits.
     *
     * @return array{key: string, model: string, daily_micros: int}|null
     */
    public function shared(): ?array
    {
        $rows = $this->rows();
        if (($rows[self::SHARED_ON]->value ?? null) !== '1') {
            return null;
        }
        $key = $this->sharedKey();
        $model = (string) ($rows[self::SHARED_MODEL]->value ?? '');
        $daily = (int) ($rows[self::SHARED_DAILY]->value ?? 0);

        return $key !== '' && $model !== '' && $daily > 0 && $key !== $this->key()
            ? ['key' => $key, 'model' => $model, 'daily_micros' => $daily]
            : null;
    }

    private function sharedKey(): string
    {
        $value = $this->rows()[self::SHARED_KEY]->value ?? null;
        if ($value === null) {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }

    // ---------- The admin's page ----------

    /**
     * @return array{key_set: bool, key_hint: ?string, key_from_env: bool, key_unreadable: bool, key_updated_at: ?string, url: string, tutor_model: string, reader_model: string, helper_model: string, shared_on: bool, shared_key_hint: ?string, shared_model: string, shared_daily: string, shared_working: bool}
     */
    public function status(Principal $by): array
    {
        Guard::admin($by);
        $row = $this->rows()[self::KEY] ?? null;
        $stored = $row !== null && $row->value !== null;
        $key = $this->key();
        $defaults = $this->defaultModels();

        return [
            'key_set' => $key !== '',
            'key_hint' => $key !== '' ? '…'.substr($key, -4) : null,
            'key_from_env' => $key !== '' && ! $stored,
            'key_unreadable' => $stored && $key === '',
            'key_updated_at' => $stored ? (string) $row->updated_at : null,
            'url' => $this->url(),
            'tutor_model' => $defaults['tutor'],
            'reader_model' => $defaults['reader'],
            'helper_model' => $defaults['helper'],
            'shared_on' => ($this->rows()[self::SHARED_ON]->value ?? null) === '1',
            'shared_key_hint' => ($shared = $this->sharedKey()) !== '' ? '…'.substr($shared, -4) : null,
            'shared_model' => (string) ($this->rows()[self::SHARED_MODEL]->value ?? ''),
            'shared_daily' => Choices::dollars((int) ($this->rows()[self::SHARED_DAILY]->value ?? self::DEFAULT_SHARED_DAILY)),
            'shared_working' => $this->shared() !== null,
        ];
    }

    /**
     * The shared key for students: on or off, its own key (empty keeps the saved one), the one model and the daily
     * limit per student in dollars. It can only be turned on with all three, and never with the admin key.
     *
     * @param  array<string, mixed>  $input  on, key, model, daily
     */
    public function setShared(Principal $by, array $input): void
    {
        Guard::protectedAdmin($by);
        $on = filter_var($input['on'] ?? false, FILTER_VALIDATE_BOOL);
        $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
        $model = is_string($input['model'] ?? null) ? trim($input['model']) : '';
        $daily = is_string($input['daily'] ?? null) ? trim(str_replace(['$', ','], '', $input['daily'])) : ($input['daily'] ?? '');
        $errors = [];
        if ($key !== '' && preg_match('/^\S{16,300}$/', $key) !== 1) {
            $errors['key'] = 'Paste the whole key, with no spaces.';
        } elseif ($key !== '' && $key === $this->key()) {
            $errors['key'] = 'Use a separate key for students, not the admin key, so you can give it its own spending limit.';
        }
        if ($model !== '' && (preg_match(self::MODEL_ID, $model) !== 1 || str_ends_with($model, ':batch'))) {
            $errors['model'] = 'Enter the model\'s id as the service names it, like "google/gemini-2.5-flash".';
        }
        if (! is_numeric($daily) || (float) $daily <= 0 || (float) $daily > self::MAX_SHARED_DAILY_DOLLARS) {
            $errors['daily'] = 'Enter an amount in dollars, more than 0 and up to '.self::MAX_SHARED_DAILY_DOLLARS.'.';
        }
        if ($on && $key === '' && $this->sharedKey() === '') {
            $errors['key'] = 'Paste a key for students first.';
        }
        if ($on && $model === '') {
            $errors['model'] = 'Choose the one model students will use.';
        }
        Input::refuse($errors);

        if ($key !== '') {
            $this->put(self::SHARED_KEY, Crypt::encryptString($key), true, $by);
        }
        $this->put(self::SHARED_MODEL, $model ?: null, false, $by);
        $this->put(self::SHARED_DAILY, (string) (int) round((float) $daily * 1_000_000), false, $by);
        $this->put(self::SHARED_ON, $on ? '1' : '0', false, $by);
        $this->audit->record($by, AuditAction::ENGINE_SHARED_CHANGED, 'engine', 'shared', array_filter([
            'on' => $on, 'daily_micros' => (int) round((float) $daily * 1_000_000), 'hint' => $key !== '' ? substr($key, -4) : null,
        ], fn ($v) => $v !== null));
    }

    /** Removes the students' key, which turns the shared key off. */
    public function removeSharedKey(Principal $by): void
    {
        Guard::protectedAdmin($by);
        DB::table('platform_settings')->whereIn('key', [self::SHARED_KEY])->delete();
        $this->put(self::SHARED_ON, '0', false, $by);
        $this->audit->record($by, AuditAction::ENGINE_SHARED_CHANGED, 'engine', 'shared', ['on' => false, 'key_removed' => true]);
    }

    /** Keeps the key, encrypted. The audit log gets its last four characters, never the key. */
    public function setKey(Principal $by, mixed $key): void
    {
        Guard::protectedAdmin($by);
        $key = is_string($key) ? trim($key) : '';
        Input::refuse(preg_match('/^\S{16,300}$/', $key) === 1 ? [] : ['key' => 'Paste the whole key, with no spaces (it starts with "sk-or-" on OpenRouter).']);
        $this->put(self::KEY, Crypt::encryptString($key), true, $by);
        $this->audit->record($by, AuditAction::ENGINE_KEY_SET, 'engine', 'key', ['hint' => substr($key, -4)]);
        Cache::forget('vistud.engine.models');
    }

    public function removeKey(Principal $by): void
    {
        Guard::protectedAdmin($by);
        DB::table('platform_settings')->where('key', self::KEY)->delete();
        $this->rows = null;
        $this->audit->record($by, AuditAction::ENGINE_KEY_REMOVED, 'engine', 'key');
        Cache::forget('vistud.engine.models');
    }

    /** @param array<string, mixed> $input url, tutor_model, reader_model, helper_model (empty for the built-in defaults) */
    public function setDefaults(Principal $by, array $input): void
    {
        Guard::protectedAdmin($by);
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        $errors = [];
        if ($url !== '' && (mb_strlen($url) > 200 || preg_match('#^https?://[^\s/]+#i', $url) !== 1)) {
            $errors['url'] = 'Enter the service\'s address, like https://openrouter.ai/api/v1.';
        }
        $models = [];
        foreach (['tutor_model', 'reader_model', 'helper_model'] as $field) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ($value !== '' && preg_match(self::MODEL_ID, $value) !== 1) {
                $errors[$field] = 'Enter the model\'s id as the service names it, like "openai/gpt-4.1-mini".';
            }
            $models[$field] = $value;
        }
        Input::refuse($errors);
        $this->put(self::URL, rtrim($url, '/') ?: null, false, $by);
        $this->put(self::TUTOR, $models['tutor_model'] ?: null, false, $by);
        $this->put(self::READER, $models['reader_model'] ?: null, false, $by);
        $this->put(self::HELPER, $models['helper_model'] ?: null, false, $by);
        $this->audit->record($by, AuditAction::ENGINE_DEFAULTS_CHANGED, 'engine', 'defaults');
        Cache::forget('vistud.engine.models');
    }

    /**
     * Asks the service for its models, with the key as it is now: proof the set-up works.
     *
     * @return array{ok: bool, message: string, models: int}
     */
    public function test(Principal $by): array
    {
        Guard::admin($by);
        if (! $this->keySet()) {
            return ['ok' => false, 'message' => 'No key is set yet.', 'models' => 0];
        }
        try {
            $count = count(app(Engine::class)->models($this->key()));
        } catch (EngineFailed $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'models' => 0];
        }

        return $count > 0
            ? ['ok' => true, 'message' => "It works: the service offers {$count} models.", 'models' => $count]
            : ['ok' => false, 'message' => 'The service answered but listed no models. Check its address.', 'models' => 0];
    }

    // ---------- Inside ----------

    /** @return array<string, object> */
    private function rows(): array
    {
        return $this->rows ??= DB::table('platform_settings')->get()->keyBy('key')->all();
    }

    private function put(string $key, ?string $value, bool $secret, Principal $by): void
    {
        DB::table('platform_settings')->upsert(
            [['key' => $key, 'value' => $value, 'secret' => $secret, 'updated_by' => $by->userId, 'updated_at' => now()]],
            ['key'],
            ['value', 'secret', 'updated_by', 'updated_at'],
        );
        $this->rows = null;
    }
}
