<?php

namespace App\Engine;

use App\Engine\Tools\AssignmentPlanTool;
use App\Engine\Tools\AssignmentsTool;
use App\Engine\Tools\CalendarTool;
use App\Engine\Tools\Context;
use App\Engine\Tools\CourseOverview;
use App\Engine\Tools\EarlierSessionsTool;
use App\Engine\Tools\FilesTool;
use App\Engine\Tools\FindingsTool;
use App\Engine\Tools\NotesTool;
use App\Engine\Tools\QuestionsTool;
use App\Engine\Tools\ReadNoteTool;
use App\Engine\Tools\SearchNotesTool;
use App\Engine\Tools\Tool;
use App\Engine\Tools\TopicsTool;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\Container\Container;

/**
 * What the engine may look up in ViStud during a chat (docs/specs/study-memory.md §6): the tools, as the
 * OpenAI chat format describes them, and running one. Every tool runs as the student, through the services the
 * pages use, so another student's things are "not found" exactly as they are on a page. A tool's answer is
 * capped, so one look-up can't flood the chat. The same tools will serve the MCP server.
 */
final class Toolbox
{
    public const MAX_OUTPUT = 12_000;

    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        CourseOverview::class,
        TopicsTool::class,
        QuestionsTool::class,
        FindingsTool::class,
        AssignmentsTool::class,
        AssignmentPlanTool::class,
        CalendarTool::class,
        NotesTool::class,
        ReadNoteTool::class,
        SearchNotesTool::class,
        FilesTool::class,
        EarlierSessionsTool::class,
    ];

    public function __construct(private Container $container) {}

    /** @return list<Tool> */
    public function tools(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), self::TOOLS);
    }

    /** @return list<array<string, mixed>> the tools in the OpenAI chat format */
    public function definitions(): array
    {
        return array_map(fn (Tool $tool) => [
            'type' => 'function',
            'function' => ['name' => $tool->name(), 'description' => $tool->description(), 'parameters' => $tool->parameters()],
        ], $this->tools());
    }

    /** @param array<string, mixed> $input */
    public function run(Principal $by, Context $context, string $name, array $input = []): string
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }
            try {
                $out = $tool->run($by, $context, $input);
            } catch (NotFound) {
                $out = 'Not found: there is no such thing in this course.';
            } catch (AppError $e) {
                $out = 'That could not be looked up: '.$e->getMessage();
            }
            if (mb_strlen($out) > self::MAX_OUTPUT) {
                $out = rtrim(mb_substr($out, 0, self::MAX_OUTPUT))."\n\n(Cut here: the answer is longer than ".self::MAX_OUTPUT.' characters. Ask for less at a time.)';
            }

            return $out;
        }

        return "There is no tool called \"{$name}\". The tools are: ".implode(', ', array_map(fn (Tool $t) => $t->name(), $this->tools())).'.';
    }
}
