<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpPrincipal;
use App\Identity\PrincipalFactory;
use App\Mcp\Calls;
use App\Mcp\ToolInfo;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * What every ViStud MCP tool shares (docs/specs/mcp-connections-plan.md §3.1, §3.4): it acts as the student behind
 * the token, refuses a tool above the connection's level, turns a refusal into an MCP error the app can see
 * (`isError`), and records one row in `mcp_calls` with no text in it. Another student's things, and things that
 * never existed, answer with the same words.
 */
abstract class McpTool extends Tool
{
    public const NOT_FOUND = 'Not found: there is no such thing in your ViStud.';

    /** The tool's row in App\Mcp\ToolInfo. */
    abstract protected function info(): string;

    /**
     * The work itself.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: ?array<string, mixed>, 2: ?string, 3: ?string} the text, structured content for a
     *                                                                            native tool (or null), and what it was about (a type and an id)
     */
    abstract protected function answer(Principal $by, array $arguments): array;

    public function handle(Request $request): Response|ResponseFactory
    {
        $principals = Container::getInstance()->make(PrincipalFactory::class);
        $by = $principals->forToken(request());
        $scope = Guard::learner($by);
        $connection = request()->attributes->get(McpPrincipal::CONNECTION);
        $info = ToolInfo::of($this->info());
        $arguments = $request->all();

        if (! is_object($connection) || ! ToolInfo::allows((string) $connection->level, $info['level'])) {
            if (is_object($connection)) {
                Calls::record($scope, $connection, $this->name(), Calls::NOT_ALLOWED, null, null, 0);
            }

            return Response::error('This app is connected to ViStud as read only. The student can choose more in ViStud, Settings › AI.');
        }

        try {
            [$text, $structured, $targetType, $targetId] = $this->answer($by, $arguments);
        } catch (NotFound) {
            Calls::record($scope, $connection, $this->name(), Calls::NOT_FOUND, null, null, 0);

            return Response::error(self::NOT_FOUND);
        } catch (AppError $e) {
            Calls::record($scope, $connection, $this->name(), Calls::ERROR, null, null, 0);
            $fields = $e->details['fields'] ?? [];

            return Response::error('That didn\'t work: '.(is_array($fields) && $fields !== [] ? implode(' ', array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $fields)) : $e->getMessage()));
        }

        Calls::record($scope, $connection, $this->name(), Calls::OK, $targetType, $targetId, mb_strlen($text));

        return $structured === null ? Response::text($text) : (new ResponseFactory([Response::text($text)]))->withStructuredContent($structured);
    }

    /** @return array<string, bool> the four hints, from App\Mcp\ToolInfo: never left to the schema's defaults */
    public function annotations(): array
    {
        $info = ToolInfo::of($this->info());

        return [
            'readOnlyHint' => $info['read_only'],
            'destructiveHint' => $info['destructive'],
            'idempotentHint' => $info['idempotent'],
            'openWorldHint' => false,
        ];
    }

    public function title(): string
    {
        return ToolInfo::of($this->info())['title'];
    }

    public function name(): string
    {
        return $this->info();
    }
}
