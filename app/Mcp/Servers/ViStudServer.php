<?php

namespace App\Mcp\Servers;

use App\Engine\Toolbox;
use App\Engine\Vistud\VistudToolbox;
use App\Mcp\ToolInfo;
use App\Mcp\Tools\CurrentSessionTool;
use App\Mcp\Tools\EngineTool;
use App\Mcp\Tools\FlashcardsDueTool;
use App\Mcp\Tools\MeTool;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * ViStud over MCP (docs/specs/mcp-connections-plan.md): one server for Claude, ChatGPT and Gemini. The tools are
 * listed in App\Mcp\ToolInfo: the in-app tutor's own tools, wrapped (App\Mcp\Tools\EngineTool), and a few built here
 * on the services. M6.2 offers the reads; writes arrive in M6.3. The rules are resources/prompts/mcp-rules.md.
 */
#[Name('ViStud')]
#[Version('0.2.0')]
final class ViStudServer extends Server
{
    /** laravel/mcp pages tools at 15 by default, and a chat app may read only the first page (plan §3.1). */
    public int $defaultPaginationLength = 50;

    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];

    protected function boot(): void
    {
        $this->instructions = (string) file_get_contents(resource_path('prompts/mcp-rules.md'));
        $this->tools = self::tools();
    }

    /** @return list<Tool> every tool App\Mcp\ToolInfo lists, in its order */
    public static function tools(): array
    {
        $container = Container::getInstance();
        $engine = [];
        foreach ($container->make(Toolbox::class)->tools() as $tool) {
            $engine[$tool->name()] = $tool;
        }
        foreach ($container->make(VistudToolbox::class)->tools() as $tool) {
            // Only ViStud AI's own tools: its course tools are the tutor's, taken above, by course_id.
            if (in_array($tool::class, VistudToolbox::OWN, true)) {
                $engine[$tool->name()] ??= $tool;
            }
        }
        $native = [
            'me' => MeTool::class,
            'current_session' => CurrentSessionTool::class,
            'flashcards_due' => FlashcardsDueTool::class,
        ];

        $tools = [];
        foreach (array_keys(ToolInfo::TOOLS) as $name) {
            $tools[] = match (true) {
                isset($native[$name]) => $container->make($native[$name]),
                isset($engine[$name]) => new EngineTool($engine[$name]),
                default => throw new \LogicException("ToolInfo lists {$name}, but no tool has that name."),
            };
        }

        return $tools;
    }
}
