<?php

namespace App\Engine;

/**
 * The services a student's key can belong to (the owner's ask, 2026-10-12): OpenRouter, one key for every model, or a
 * provider's own key (OpenAI, Anthropic, Google, Mistral and the others in config/ai_providers.php). Each speaks the
 * OpenAI chat format at its own address, with a few differences kept here as data: how its keys look, what its
 * models cost (for a provider that doesn't say with each answer), which of its models suit the tutor and which the
 * quick jobs. The data is the owner's to change, as providers rename their models.
 */
final class Providers
{
    public const OPENROUTER = 'openrouter';

    /** @return array<string, array<string, mixed>> id => the provider */
    public static function all(): array
    {
        return (array) config('ai_providers.providers', []);
    }

    public static function known(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    public static function name(string $id): string
    {
        return (string) (self::all()[$id]['name'] ?? $id);
    }

    /** The address of its OpenAI-format API; null for OpenRouter, whose address the owner sets up (App\Engine\Setup). */
    public static function url(string $id): ?string
    {
        return $id === self::OPENROUTER ? null : (self::all()[$id]['url'] ?? null);
    }

    /** Whether a key of this provider is the provider's own, not OpenRouter's. */
    public static function direct(string $id): bool
    {
        return $id !== self::OPENROUTER && self::known($id);
    }

    /**
     * One of its quirks (config ai_providers: `max_tokens_field`, `stream_usage`, `headers`, `key_header`,
     * `models_url`, `skip`, `thought_signatures`), or the default.
     */
    public static function quirk(string $id, string $name, mixed $default = null): mixed
    {
        return self::all()[$id][$name] ?? $default;
    }

    /**
     * The providers a key may belong to, most likely first: those whose keys look like it (a prefix only theirs comes
     * before a prefix several share), then those whose keys have no look of their own. Each is asked in turn, and the
     * first that takes the key has it.
     *
     * @return list<string>
     */
    public static function candidates(string $secret): array
    {
        $matched = [];
        $shapeless = [];
        foreach (self::all() as $id => $provider) {
            $prefixes = array_values(array_filter((array) ($provider['prefix'] ?? []), fn ($p) => is_string($p) && $p !== ''));
            $pattern = $provider['pattern'] ?? null;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($secret, $prefix)) {
                    $matched[$id] = max($matched[$id] ?? 0, strlen($prefix));
                }
            }
            if (is_string($pattern) && preg_match($pattern, $secret) === 1) {
                $matched[$id] = max($matched[$id] ?? 0, 1);
            }
            if ($prefixes === [] && $id !== self::OPENROUTER) {
                $shapeless[] = $id;
            }
        }
        // The longest prefix first: "sk-or-" is OpenRouter's before "sk-" is anyone's.
        arsort($matched);
        $ids = array_keys($matched);

        // A key with a prefix only one provider uses is that provider's; with none of its own, the others are asked.
        return array_values(array_unique([...$ids, ...($ids !== [] && (self::all()[$ids[0]]['prefix_unique'] ?? false) ? [] : $shapeless)]));
    }

    /**
     * The models suggested to a student choosing what to pay for (config ai_providers `recommended`), by kind: the
     * smartest, the best value and the cheapest. Only models that call tools are suggested, as the tutor looks things
     * up; each has its provider's name, its prices, about what a study session costs on it, and where to pay for its API.
     *
     * @return array<string, list<array{kind: string, provider: string, providerName: string, model: string, name: string, why: string, in: ?float, out: ?float, session: ?float, images: bool, url: string}>>
     */
    public static function recommended(): array
    {
        $session = (array) config('ai_providers.session_tokens', []);
        $out = ['smartest' => [], 'value' => [], 'cheapest' => []];
        foreach ((array) config('ai_providers.recommended', []) as $pick) {
            $known = self::model((string) ($pick['provider'] ?? ''), (string) ($pick['model'] ?? ''));
            $url = self::all()[$pick['provider'] ?? '']['keys_url'] ?? null;
            if ($known === null || ! ($known['tools'] ?? true) || ! self::quirk((string) $pick['provider'], 'tools', true) || ! is_string($url) || ! isset($out[$pick['kind'] ?? ''])) {
                continue;
            }
            $in = isset($known['in']) ? (float) $known['in'] : null;
            $price = isset($known['out']) ? (float) $known['out'] : null;
            $out[$pick['kind']][] = [
                'kind' => $pick['kind'], 'provider' => $pick['provider'], 'providerName' => self::name($pick['provider']), 'model' => $pick['model'],
                'name' => (string) ($known['name'] ?? $pick['model']), 'why' => (string) ($pick['why'] ?? ''), 'in' => $in, 'out' => $price,
                'session' => $in !== null && $price !== null ? ((int) ($session['in'] ?? 0) * $in + (int) ($session['out'] ?? 0) * $price) / 1_000_000 : null,
                'images' => (bool) ($known['images'] ?? false), 'url' => $url,
            ];
        }

        return array_filter($out);
    }

    /**
     * What is known of one of its models: its name, prices (dollars per million tokens), context, whether it calls
     * tools and sees pictures. Null for a model the data doesn't name.
     *
     * @return ?array{name?: string, in?: float, out?: float, context?: int, tools?: bool, images?: bool}
     */
    public static function model(string $id, string $model): ?array
    {
        $models = (array) (self::all()[$id]['models'] ?? []);

        return isset($models[$model]) && is_array($models[$model]) ? $models[$model] : null;
    }

    /**
     * The model for a role (`tutor`: its strongest; `quick`: fast and cheap, for the reader and the helper) among those
     * the key can use: the first of the provider's choices that is there, else the first offered.
     *
     * @param  list<string>  $offered  the models the key can use
     */
    public static function pick(string $id, string $role, array $offered): string
    {
        return self::choices($id, $role, $offered)[0] ?? $offered[0] ?? (string) ((self::all()[$id]['roles'][$role] ?? [''])[0] ?? '');
    }

    /**
     * The provider's choices for a role that the key can use, best first; for the tutor, its quick ones after (a plan
     * that won't run the strongest model still teaches with a quick one).
     *
     * @param  list<string>  $offered
     * @return list<string>
     */
    public static function choices(string $id, string $role, array $offered): array
    {
        $roles = (array) (self::all()[$id]['roles'] ?? []);
        $wanted = [...(array) ($roles[$role] ?? []), ...($role === 'tutor' ? (array) ($roles['quick'] ?? []) : [])];

        return array_values(array_unique(array_filter($wanted, fn ($m) => in_array($m, $offered, true))));
    }
}
