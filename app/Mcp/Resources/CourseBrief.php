<?php

namespace App\Mcp\Resources;

use App\Engine\Toolbox;
use App\Engine\Tools\Tool;
use App\Mcp\ContextBuilder;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Support\UriTemplate;

/** A course at a glance, by its id: the same overview the `course_overview` tool gives. */
#[Name('course_brief')]
#[Title('A course at a glance')]
#[Description('The student\'s course in brief: its modules, topics and where they stand.')]
#[Cacheable]
final class CourseBrief extends McpResource
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('vistud://courses/{course}/brief');
    }

    protected function kind(): string
    {
        return 'course';
    }

    protected function read(Principal $by, array $variables, ?object $connection): array
    {
        $container = Container::getInstance();
        $tool = collect($container->make(Toolbox::class)->tools())->first(fn (Tool $t) => $t->name() === 'course_overview') ?? throw new NotFound;
        $context = $container->make(ContextBuilder::class)->for($by, 'course_overview', ['course_id' => $variables['course']], $connection);

        return ['text' => $tool->run($by, $context, []), 'id' => $context->workspaceId];
    }
}
