<?php

namespace App\Engine\Skills;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Engine\Settings;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Input;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The skills, each one SKILL.md in resources/skills/<name> (the owner's ask, 2026-10-23): a name, a one-line
 * description of when it is for, an optional title for the screens, where it is used, and its body, the
 * instructions. The same format whether a skill is for ViStud's own chats or for the AI apps students connect
 * (App\Mcp\Plugins\PluginBuilder packages those).
 *
 * Each skill can be switched off: by an admin for everyone (the Skills page of the admin area), and by a student for
 * themselves (AI settings), for ViStud's own chats. Off, its instructions are left out of the chat altogether, so it
 * costs nothing; an outside app's skill switched off by an admin is left out of the plugin students download.
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
    /** The skills every ViStud chat carries, in order, unless switched off. */
    public const ALWAYS_ON = ['ui'];

    /** The most the always-on skills may weigh together, in tokens: they are in every call of every chat. */
    public const ALWAYS_ON_TOKENS = 300;

    /** Where a skill is used: ViStud's own chats, or the AI apps students connect (the default). */
    public const VISTUD = 'vistud';

    /** The platform setting holding the skills an admin switched off, as a JSON list of names. */
    public const PLATFORM_OFF = 'skills.off';

    /**
     * Every skill, by name.
     *
     * @return array<string, array{name: string, title: string, description: string, where: string, body: string}>
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

    /** @return array{name: string, title: string, description: string, where: string, body: string} */
    public static function read(string $name): array
    {
        $file = resource_path("skills/{$name}/SKILL.md");
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1 || ! is_file($file)) {
            throw new RuntimeException("There is no skill named {$name}.");
        }

        return self::parse($file);
    }

    /**
     * The always-on skills' instructions, for the end of a chat's fixed rules: those not switched off for everyone,
     * and with a student, not by them either. Empty when none is on.
     */
    public static function alwaysOn(?Principal $by = null): string
    {
        $off = [...self::platformOff(), ...($by !== null ? self::studentOff($by) : [])];

        return implode("\n\n", array_map(fn (string $name) => self::read($name)['body'], array_values(array_diff(self::ALWAYS_ON, $off))));
    }

    /** @return list<string> the skills an admin switched off for everyone */
    public static function platformOff(): array
    {
        return self::names(DB::table('platform_settings')->where('key', self::PLATFORM_OFF)->value('value'));
    }

    /** @return list<string> the skills this student switched off for themselves */
    public static function studentOff(Principal $by): array
    {
        return self::names(LearnerTables::query(Guard::learner($by), 'engine_settings')->value('skills_off'));
    }

    /**
     * The skills of ViStud's own chats a student may switch: those an admin left on.
     *
     * @return array<string, array{name: string, title: string, description: string, where: string, body: string, on: bool}>
     */
    public static function forStudent(Principal $by): array
    {
        $off = self::studentOff($by);
        $platformOff = self::platformOff();
        $skills = [];
        foreach (self::all() as $name => $skill) {
            if ($skill['where'] === self::VISTUD && ! in_array($name, $platformOff, true)) {
                $skills[$name] = $skill + ['on' => ! in_array($name, $off, true)];
            }
        }

        return $skills;
    }

    /** An admin switches a skill on or off for everyone; in the audit log. Needs a recent password confirmation. */
    public static function setForEveryone(Principal $by, string $name, bool $on): void
    {
        Guard::protectedAdmin($by);
        self::read($name);
        $off = array_values(array_diff(self::platformOff(), [$name]));
        if (! $on) {
            $off[] = $name;
        }
        DB::table('platform_settings')->upsert(
            [['key' => self::PLATFORM_OFF, 'value' => $off === [] ? null : json_encode($off), 'secret' => false, 'updated_by' => $by->userId, 'updated_at' => now()]],
            ['key'],
            ['value', 'secret', 'updated_by', 'updated_at'],
        );
        app(AuditLog::class)->record($by, AuditAction::SKILL_CHANGED, 'skill', $name, ['on' => $on]);
    }

    /** A student switches one of ViStud's chat skills on or off for themselves. */
    public static function setForStudent(Principal $by, string $name, bool $on): void
    {
        $scope = Guard::learner($by);
        Input::refuse(! isset(self::forStudent($by)[$name]) ? ['skill' => 'That skill isn\'t one you can switch.'] : []);
        $off = array_values(array_diff(self::studentOff($by), [$name]));
        if (! $on) {
            $off[] = $name;
        }
        $values = ['skills_off' => $off === [] ? null : json_encode($off), 'updated_at' => now()];
        if (LearnerTables::query($scope, 'engine_settings')->exists()) {
            LearnerTables::query($scope, 'engine_settings')->update($values);
        } else {
            // A first row, with the defaults App\Engine\Settings gives one.
            LearnerTables::insert($scope, 'engine_settings', $values + [
                'id' => Ids::new(), 'session_cap_micros' => Settings::DEFAULT_SESSION_CAP, 'month_cap_micros' => Settings::DEFAULT_MONTH_CAP,
                'no_training' => true, 'created_at' => now(),
            ]);
        }
    }

    /** @return list<string> */
    private static function names(mixed $json): array
    {
        $names = is_string($json) ? json_decode($json, true) : null;

        return is_array($names) ? array_values(array_filter($names, fn ($name) => is_string($name) && preg_match('/^[a-z0-9-]+$/', $name) === 1)) : [];
    }

    /** @return array{name: string, title: string, description: string, where: string, body: string} */
    private static function parse(string $file): array
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $m) !== 1) {
            throw new RuntimeException("{$file} must start with a name and a description.");
        }
        $head = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z]+): (.+)$/', $line, $pair) === 1) {
                $head[$pair[1]] = trim($pair[2]);
            }
        }
        if (preg_match('/^[a-z0-9-]+$/', $head['name'] ?? '') !== 1 || ($head['description'] ?? '') === '') {
            throw new RuntimeException("{$file} must start with a name and a description.");
        }

        return [
            'name' => $head['name'],
            'title' => $head['title'] ?? ucfirst(str_replace('-', ' ', $head['name'])),
            'description' => $head['description'],
            'where' => ($head['where'] ?? '') === self::VISTUD ? self::VISTUD : 'apps',
            'body' => trim($m[2]),
        ];
    }
}
