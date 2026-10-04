<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Notes;
use Carbon\CarbonImmutable;

/** The student's notes, by module, newest first; read one with read_note. */
final class NotesTool implements Tool
{
    public function __construct(private Notes $notes, private Modules $modules) {}

    public function name(): string
    {
        return 'notes';
    }

    public function description(): string
    {
        return 'The student\'s notes in this course (or in one module), newest first, with where each is and when it was last changed. Read one with read_note.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to.']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $modules = $this->modules->list($by, $context->workspaceId);
        $titles = collect($modules)->pluck('title', 'id');
        $only = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($modules, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $only = $module->id;
        }
        $notes = array_values(array_filter($this->notes->list($by, $context->workspaceId), fn ($n) => $only === null || $n->moduleId === $only));
        usort($notes, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
        $rows = [];
        foreach (array_slice($notes, 0, 60) as $note) {
            $rows[] = array_filter([
                'note' => $note->displayTitle(),
                'module' => $note->moduleId !== null ? $titles[$note->moduleId] ?? null : 'the course\'s top level',
                'changed' => CarbonImmutable::parse($note->updatedAt)->setTimezone($context->zone)->toDateString(),
            ]);
        }

        return $rows === [] ? 'No notes'.($only !== null ? ' in that module' : '').' yet.' : Lookup::json($rows);
    }
}
