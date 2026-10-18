<?php

namespace App\Mcp\Tools;

use App\Engine\Toolbox;
use App\Engine\Tools\Tool as EngineToolContract;
use App\Mcp\ContextBuilder;
use App\Mcp\ToolInfo;
use App\Platform\Access\Principal;
use Illuminate\Container\Container;

/**
 * One of the in-app tutor's tools, offered over MCP (docs/specs/mcp-connections-plan.md §3.1). It runs the same
 * App\Engine\Tools class the tutor runs, with a Context built from ids (App\Mcp\ContextBuilder), so there is one set of
 * rules for both. A course tool takes `course_id` (from the `courses` tool) and, where it helps, `module_id` and
 * `folder_id`; a ViStud AI tool works across courses. The answer is the tool's text, capped as in the app.
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
        $info = ToolInfo::of($this->info());

        return $this->tool->description().($info['course'] ? ' Pass course_id from the courses tool.' : '');
    }

    protected function answer(Principal $by, array $arguments): array
    {
        $contexts = Container::getInstance()->make(ContextBuilder::class);
        $info = ToolInfo::of($this->info());
        $context = $info['course'] ? $contexts->forCourse($by, $arguments) : $contexts->acrossCourses($by);
        unset($arguments['course_id'], $arguments['module_id'], $arguments['folder_id']);

        $out = $this->tool->run($by, $context, $arguments);
        if (mb_strlen($out) > Toolbox::MAX_OUTPUT) {
            $out = rtrim(mb_substr($out, 0, Toolbox::MAX_OUTPUT))."\n\n(Cut here: the answer is longer than ".Toolbox::MAX_OUTPUT.' characters. Ask for less at a time.)';
        }

        return [$out, null, $info['course'] ? 'course' : null, $info['course'] ? $context->workspaceId : null];
    }

    /** The tool's own JSON schema, with the ids a course tool needs. */
    public function toArray(): array
    {
        $result = parent::toArray();
        $schema = $this->tool->parameters();
        if (ToolInfo::of($this->info())['course']) {
            $properties = (array) ($schema['properties'] ?? []);
            $schema['properties'] = [
                'course_id' => ['type' => 'string', 'description' => 'The course\'s id, from the courses tool.'],
                'module_id' => ['type' => 'string', 'description' => 'A module\'s id in that course, from course_overview; leave out for the whole course.'],
            ] + $properties;
            $schema['required'] = array_values(array_unique(['course_id', ...($schema['required'] ?? [])]));
        }
        $schema['type'] = 'object';
        $schema['properties'] = (object) ($schema['properties'] ?? []);
        $result['inputSchema'] = $schema;

        return $result;
    }
}
