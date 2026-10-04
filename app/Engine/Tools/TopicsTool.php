<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Topics;

/** The topics of the course, or of one module, each with the student's own word on it and what their practice shows. */
final class TopicsTool implements Tool
{
    public function __construct(private Topics $topics, private Modules $modules) {}

    public function name(): string
    {
        return 'topics';
    }

    public function description(): string
    {
        return 'The topics of the course (or of one module), each with the status the student set (not started, covered, understood, confused, or mastered when their practice earned it) and the evidence. Use it to see what the student knows and what still confuses them.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to; leave out for the whole course.']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $modules = $this->modules->list($by, $context->workspaceId);
        $titles = [];
        foreach ($modules as $module) {
            $titles[$module->id] = $module->title;
        }
        $only = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($modules, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $only = $module->id;
        }
        $rows = [];
        foreach ($this->topics->list($by, $context->workspaceId) as $topic) {
            if ($only !== null && $topic->moduleId !== $only) {
                continue;
            }
            $rows[] = array_filter([
                'topic' => $topic->name,
                'module' => $topic->moduleId !== null ? ($titles[$topic->moduleId] ?? null) : null,
                'status' => str_replace('_', ' ', $topic->shown()),
                'evidence' => $topic->evidence(),
            ]);
        }

        return $rows === [] ? 'No topics yet'.($only !== null ? ' in that module' : '').'.' : Lookup::json($rows);
    }
}
