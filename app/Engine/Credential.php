<?php

namespace App\Engine;

/**
 * A student's key as the app carries it between the settings and the engine: the provider it belongs to and the
 * secret, written "provider:secret" (the owner's ask, 2026-10-12: OpenRouter, or a provider's own key). A key kept
 * before providers were told apart has no provider in front, and is OpenRouter's. Provider ids are lower-case words
 * (App\Engine\Providers), and no provider's key starts with one followed by a colon, so the two never mix. Never
 * logged, never shown: a screen shows only its last four characters.
 */
final class Credential
{
    public static function pack(string $provider, string $secret): string
    {
        return $provider === Providers::OPENROUTER ? $secret : "{$provider}:{$secret}";
    }

    /**
     * The provider and the secret of a key; null (the key set up for everyone) is OpenRouter's, at the address the
     * owner set up.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function unpack(?string $key): array
    {
        if ($key !== null && preg_match('/^([a-z][a-z0-9_]{1,23}):(.+)$/s', $key, $match) === 1 && Providers::known($match[1])) {
            return [$match[1], $match[2]];
        }

        return [Providers::OPENROUTER, $key];
    }

    public static function provider(?string $key): string
    {
        return self::unpack($key)[0];
    }
}
