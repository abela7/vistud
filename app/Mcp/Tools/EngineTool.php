<?php

namespace App\Mcp\Tools;

use App\Engine\Toolbox;
use App\Engine\Tools\Tool as EngineToolContract;
use App\Mcp\Calls;
use App\Mcp\ContextBuilder;
use App\Mcp\ToolInfo;
use App\Platform\Access\Principal;
use Illuminate\Container\Container;

/**
 * One of the in-app tutor's tools, offered over MCP (docs/specs/mcp-connections-plan.md §3.1). It runs the same
 * App\Engine\Tools class the tutor runs, with a Context built from ids (App\Mcp\ContextBuilder), so there is one set of
 * rules for both. A course tool takes `course_id` (from `courses`), or `session_id` inside a study session; a ViStud
 * AI tool works across courses. The answer is the tool's text, capped as in the app; what a write saved goes into the
 * acknowledgement and the call record as counts and ids.
 */
final class EngineTool extends McpTool
{
    public function __construct(private EngineToolContract $tool) {}

    protected function info(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        $name = $this->info();
        $hint = match (true) {
            (ToolInfo::SESSION[$name] ?? null) === 'required' => ' Pass session_id (start_session gives it).',
            isset(ToolInfo::SESSION[$name]) => ' Pass session_id inside a study session, or course_id.',
            ToolInfo::of($name)['course'] => ' Pass course_id from the courses tool.',
            default => '',
        };

        return $this->tool->description().$hint;
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $context = Container::getInstance()->make(ContextBuilder::class)->for($by, $this->info(), $arguments, $connection);
        unset($arguments['course_id'], $arguments['module_id'], $arguments['folder_id'], $arguments['session_id'], $arguments['assignment_id']);

        $out = $this->tool->run($by, $context, $arguments);
        if (mb_strlen($out) > Toolbox::MAX_OUTPUT) {
            $out = rtrim(mb_substr($out, 0, Toolbox::MAX_OUTPUT))."\n\n(Cut here: the answer is longer than ".Toolbox::MAX_OUTPUT.' characters. Ask for less at a time.)';
        }
        $effects = Calls::reduce($context->effects->take());

        return [
            'text' => $out,
            'type' => $context->sessionId !== null ? 'session' : ($context->workspaceId !== '' ? 'course' : null),
            'id' => $context->sessionId ?? ($context->workspaceId !== '' ? $context->workspaceId : null),
            'effects' => $effects,
        ];
    }

    /** The tool's own JSON schema, with the ids it takes over MCP. */
    public function toArray(): array
    {
        $result = parent::toArray();
        $schema = $this->tool->parameters();
        $properties = (array) ($schema['properties'] ?? []);
        $schema['properties'] = (object) ($this->idProperties() + $properties);
        $schema['required'] = array_values(array_unique([...$this->requiredIds(), ...($schema['required'] ?? [])]));
        if ($schema['required'] === []) {
            unset($schema['required']);
        }
        $schema['type'] = 'object';
        $result['inputSchema'] = $schema;

        return $result;
    }
}
