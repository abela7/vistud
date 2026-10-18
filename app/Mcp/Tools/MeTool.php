<?php

namespace App\Mcp\Tools;

use App\Platform\Access\Principal;
use App\Study\Workspaces;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Who the chat app is signed in as (docs/specs/mcp-connections-plan.md M6.1): a first name, a masked email and how
 * many courses there are, so the student can see it is their own account. This MCP-only tool names the student; the
 * study tools never do (docs/architecture/contracts.md).
 */
#[Description('Says which ViStud account this chat is signed in to (first name, masked email) and how many courses it has. Use it when the student asks who they are signed in as, or to check the connection works.')]
final class MeTool extends McpTool
{
    protected function info(): string
    {
        return 'me';
    }

    protected function answer(Principal $by, array $arguments): array
    {
        $user = request()->user('api');
        $courses = count(Container::getInstance()->make(Workspaces::class)->list($by));

        return [sprintf(
            'Signed in to ViStud as %s (%s). %d %s.',
            self::firstName((string) ($user->name ?? '')),
            self::masked((string) ($user->email ?? '')),
            $courses,
            $courses === 1 ? 'course' : 'courses',
        ), null, null, null];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    private static function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0]);

        return $first !== '' ? mb_substr($first, 0, 40) : 'a student';
    }

    /** "a••••@example.com" */
    public static function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return 'no email';
        }

        return mb_substr($local, 0, 1).'••••@'.$domain;
    }
}
