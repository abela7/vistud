<?php

namespace App\Engine\Skills;

use RuntimeException;

/**
 * The skills, each one SKILL.md in resources/skills/<name> (the owner's ask, 2026-10-23): a name, a one-line
 * description of when it is for, where it is used, and its body, the instructions. The same format whether a skill
 * is for ViStud's own chats or for the AI apps students connect (App\Mcp\Plugins\PluginBuilder packages those).
 *
 * How a skill is given to a model, so it stays cheap and safe:
 *  - an always-on skill (ALWAYS_ON) is short and goes after the fixed rules, word for word the same every call, so
 *    the service caches it (about a tenth of the price);
 *  - a skill for now and then will carry only its description line in every call, and its body when it is needed;
 *  - a skill never gives the model a way to run anything: the UI skill, for one, has it write small tags that
 *    App\Engine\ChatBlocks checks and draws from ViStud's own components, and anything not exactly right shows as
 *    plain words.
 */
final class Skills
{
    /** The skills every ViStud chat carries, in order. */
    public const ALWAYS_ON = ['ui'];

    /** The most the always-on skills may weigh together, in tokens: they are in every call of every chat. */
    public const ALWAYS_ON_TOKENS = 300;

    /** Where a skill is used: ViStud's own chats, or the AI apps students connect (the default). */
    public const VISTUD = 'vistud';

    /**
     * Every skill, by name.
     *
     * @return array<string, array{name: string, description: string, where: string, body: string}>
     */
    public static function all(): array
    {
        $skills = [];
        foreach (glob(resource_path('skills/*/SKILL.md')) ?: [] as $file) {
            $skill = self::parse($file);
            $skills[$skill['name']] = $skill;
        }
        ksort($skills);

        return $skills;
    }

    /** @return array{name: string, description: string, where: string, body: string} */
    public static function read(string $name): array
    {
        $file = resource_path("skills/{$name}/SKILL.md");
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1 || ! is_file($file)) {
            throw new RuntimeException("There is no skill named {$name}.");
        }

        return self::parse($file);
    }

    /** The always-on skills' instructions, for the end of a chat's fixed rules. */
    public static function alwaysOn(): string
    {
        return implode("\n\n", array_map(fn (string $name) => self::read($name)['body'], self::ALWAYS_ON));
    }

    /** @return array{name: string, description: string, where: string, body: string} */
    private static function parse(string $file): array
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
        if (preg_match('/\A---\nname: ([a-z0-9-]+)\ndescription: ([^\n]+)\n(?:where: ([a-z]+)\n)?---\n(.*)\z/s', $raw, $m) !== 1) {
            throw new RuntimeException("{$file} must start with a name and a description.");
        }

        return ['name' => $m[1], 'description' => $m[2], 'where' => $m[3] !== '' ? $m[3] : 'apps', 'body' => trim($m[4])];
    }
}
