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

    // ---------- The admin's page ----------

    /**
     * @return array{key_set: bool, key_hint: ?string, key_from_env: bool, key_unreadable: bool, key_updated_at: ?string, url: string, tutor_model: string, reader_model: string, helper_model: string}
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
        ];
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
            $count = count(app(Engine::class)->models());
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
