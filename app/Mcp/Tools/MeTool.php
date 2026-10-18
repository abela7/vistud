<?php

namespace App\Mcp\Tools;

use App\Identity\PrincipalFactory;
use App\Study\Workspaces;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Who the chat app is signed in as (docs/specs/mcp-connections-plan.md M6.1): a first name, a masked email and how
 * many courses there are, so the student can see it is their own account. This MCP-only tool names the student; the
 * study tools never do (docs/architecture/contracts.md).
 */
#[Name('me')]
#[Title('Who am I in ViStud')]
#[Description('Says which ViStud account this chat is signed in to (first name, masked email) and how many courses it has. Use it when the student asks who they are signed in as, or to check the connection works.')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsIdempotent(true)]
#[IsOpenWorld(false)]
final class MeTool extends Tool
{
    public function __construct(private PrincipalFactory $principals, private Workspaces $workspaces) {}

    public function handle(Request $request): Response
    {
        $by = $this->principals->forToken(request());
        $user = DB::table('users')->where('id', $by->userId)->first(['name', 'email']);
        $courses = count($this->workspaces->list($by));

        return Response::text(sprintf(
            'Signed in to ViStud as %s (%s). %d %s.',
            self::firstName((string) ($user->name ?? '')),
            self::masked((string) ($user->email ?? '')),
            $courses,
            $courses === 1 ? 'course' : 'courses',
        ));
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
