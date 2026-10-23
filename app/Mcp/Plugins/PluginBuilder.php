<?php

namespace App\Mcp\Plugins;

use App\Engine\Skills\Skills;
use App\Mcp\Servers\ViStudServer;
use ZipArchive;

/**
 * Builds the install packages for each chat app from ONE source, resources/skills (docs/specs/mcp-connections-plan.md
 * M6.4), so the rules never drift between Claude, ChatGPT and Gemini. A package holds only the public server address,
 * the skills and short instructions: no key, no token, nothing of any student's. It is stamped with the server's
 * contract version. `vistud:mcp:plugins` writes them to disk; the Connections screen serves the zips.
 */
final class PluginBuilder
{
    public const TARGETS = ['claude', 'openai', 'gemini'];

    public const NAME = 'vistud';

    private const LICENSE = "ViStud plugin. All rights reserved.\n\nYou may install this package to use ViStud with your own account. You may not redistribute or modify it without permission.\n";

    /** The address chat apps connect to. */
    public static function url(): string
    {
        return rtrim((string) (config('mcp.public_url') ?: config('app.url')), '/').'/mcp';
    }

    /**
     * Every file of one package, by its path inside the package folder.
     *
     * @return array<string, string>
     */
    public function files(string $target): array
    {
        $skills = $this->skills();
        $files = match ($target) {
            'claude' => $this->claude($skills),
            'openai' => $this->openai($skills),
            'gemini' => $this->gemini($skills),
            default => throw new \InvalidArgumentException("Unknown plugin target {$target}."),
        };
        ksort($files);

        return $files;
    }

    /** The package as a zip, in a temporary file the caller removes. */
    public function zip(string $target): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-plugin-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || throw new \RuntimeException('Could not make the zip.');
        foreach ($this->files($target) as $name => $content) {
            $zip->addFromString(self::NAME.'/'.$name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return array<string, array{description: string, body: string}> skill name => parts */
    public function skills(): array
    {
        // Not the skills for ViStud's own chats (App\Engine\Skills\Skills::VISTUD): an outside app can't use them.
        $skills = [];
        // Nor those an admin switched off for everyone.
        $off = Skills::platformOff();
        foreach (Skills::all() as $name => $skill) {
            if ($skill['where'] !== Skills::VISTUD && ! in_array($name, $off, true)) {
                $skills[$name] = ['description' => $skill['description'], 'body' => $skill['body']."\n"];
            }
        }

        return $skills;
    }

    /**
     * @param  array<string, array{description: string, body: string}>  $skills
     * @return array<string, string>
     */
    private function claude(array $skills): array
    {
        $files = [
            '.claude-plugin/plugin.json' => self::json(['name' => self::NAME, 'version' => ViStudServer::CONTRACT, 'description' => 'Study with your ViStud course in Claude: teach, quiz and save to your own study record.']),
            '.mcp.json' => self::json(['mcpServers' => [self::NAME => ['type' => 'http', 'url' => self::url()]]]),
            'README.md' => $this->readme('Claude', 'Upload this zip in Claude under Customize, then Plugins. Claude asks you to sign in to ViStud and to choose what it may do.'),
            'LICENSE' => self::LICENSE,
        ];
        foreach ($skills as $name => $skill) {
            $files["skills/{$name}/SKILL.md"] = self::skillFile($name, $skill);
        }

        return $files;
    }

    /**
     * @param  array<string, array{description: string, body: string}>  $skills
     * @return array<string, string>
     */
    private function openai(array $skills): array
    {
        $files = [
            'plugin.json' => self::json(['name' => self::NAME, 'version' => ViStudServer::CONTRACT, 'description' => 'Study with your ViStud course in ChatGPT: teach, quiz and save to your own study record.', 'mcp' => 'mcp.json', 'skills' => 'skills']),
            'mcp.json' => self::json(['servers' => [self::NAME => ['type' => 'streamable-http', 'url' => self::url(), 'auth' => 'oauth']]]),
            'agents/openai.yaml' => "name: ViStud\ndescription: Study with your ViStud course. Teach, quiz and save to your own study record.\nskills: skills\nmcp: mcp.json\n",
            'README.md' => $this->readme('ChatGPT', 'Add ViStud as a custom connector in ChatGPT with the address above, then sign in to ViStud when it asks. Whether ChatGPT loads the skills from a private plugin is not verified yet; the server sends the same rules either way.'),
            'LICENSE' => self::LICENSE,
        ];
        foreach ($skills as $name => $skill) {
            $files["skills/{$name}/SKILL.md"] = str_replace('Claude', 'the assistant', self::skillFile($name, $skill));
        }

        return $files;
    }

    /**
     * @param  array<string, array{description: string, body: string}>  $skills
     * @return array<string, string>
     */
    private function gemini(array $skills): array
    {
        $body = "---\nname: ".self::NAME."\ndescription: Use when the student wants to study, be quizzed, or hear where they stand in their ViStud course. Teaches from their own material and keeps what they learn in ViStud.\n---\n\n# ViStud (untested with Gemini)\n\nThis one skill joins the three ViStud skills. It has not been tried with Gemini yet.\n";
        foreach ($skills as $name => $skill) {
            $body .= "\n(Skill: {$name}. {$skill['description']})\n".str_replace("\n# ", "\n## ", "\n".$skill['body']);
        }

        return ['SKILL.md' => $body, 'README.md' => $this->readme('Gemini', 'Untested. Connect Gemini to the address above and add SKILL.md as its instructions.')];
    }

    private function readme(string $app, string $install): string
    {
        return "# ViStud for {$app}\n\nViStud is a study platform: your courses, notes, flashcards and progress. This package lets {$app} study with you from your own ViStud, teaching from your material and saving what you learn back to it.\n\n"
            .'Address: '.self::url()."\n\nInstall: {$install}\n\nIt holds no password or key. You sign in to ViStud yourself, choose what the app may do, and can undo what it did or disconnect it in ViStud under Settings, AI.\n\nPackage version ".ViStudServer::CONTRACT.".\n";
    }

    /** @param  array{description: string, body: string}  $skill */
    private static function skillFile(string $name, array $skill): string
    {
        return "---\nname: {$name}\ndescription: {$skill['description']}\n---\n{$skill['body']}";
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
