<?php

namespace App\Mcp;

/**
 * Which company's chat app a connection belongs to, read from where its sign-in sends the student back
 * (docs/specs/mcp-connections-plan.md §3.2). Fixed at consent. A loopback address is a program on this computer
 * (for example Claude Code), never named as one.
 */
final class Provider
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI = 'openai';

    public const GOOGLE = 'google';

    public const LOCAL = 'local';

    public const OTHER = 'other';

    /** @var array<string, string> */
    public const NAMES = [
        self::ANTHROPIC => 'Anthropic (Claude)',
        self::OPENAI => 'OpenAI (ChatGPT)',
        self::GOOGLE => 'Google (Gemini)',
        self::LOCAL => 'A program on this computer',
        self::OTHER => 'Another app',
    ];

    public static function fromRedirect(string $uri): string
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return match (true) {
            $host === 'claude.ai' || str_ends_with($host, '.claude.ai') => self::ANTHROPIC,
            $host === 'chatgpt.com' || str_ends_with($host, '.chatgpt.com') => self::OPENAI,
            $host === 'antigravity.google' || str_ends_with($host, '.googleusercontent.com') || str_ends_with($host, '.google.com') => self::GOOGLE,
            in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) => self::LOCAL,
            default => self::OTHER,
        };
    }

    /** The company on its own, for a line such as "set by a chat app (Anthropic)". */
    public static function company(string $provider): string
    {
        return match ($provider) {
            self::ANTHROPIC => 'Anthropic',
            self::OPENAI => 'OpenAI',
            self::GOOGLE => 'Google',
            self::LOCAL => 'a program on this computer',
            default => 'another app',
        };
    }

    public static function name(string $provider): string
    {
        return self::NAMES[$provider] ?? self::NAMES[self::OTHER];
    }
}
