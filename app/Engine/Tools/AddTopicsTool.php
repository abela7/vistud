<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;

/**
 * Topics into the course, each in a module: the headings of a file the student shared, say, when the course
 * doesn't have them yet. Names the course has already are left as they are, so asking twice adds nothing. With no
 * module named, they go in the session's module, and in its folder when it studies in one (docs/specs/vistud-2-
 * blueprint.md, Phase 9).
 */
final class AddTopicsTool implements Tool
{
    public const MAX = 15;

    public function __construct(private Topics $topics, private Modules $modules, private Sessions $sessions, private Folders $folders) {}

    public function name(): string
    {
        return 'add_topics';
    }

    public function description(): string
    {
        return 'Adds topics to the course, each in a module: for example the chapters or headings of a file the student shared, when the course doesn\'t have them yet. Whether to ask the student first, the Topics section of your instructions says. Topics the course has already are left as they are. Up to '.self::MAX.' at a time.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'topics' => ['type' => 'array', 'description' => 'The topics to add, in the order they are studied.', 'minItems' => 1, 'maxItems' => self::MAX, 'items' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The topic\'s name, short like a chapter heading.'],
                'module' => ['type' => 'string', 'description' => 'The module\'s title. Left out: the session\'s module (and its folder, in a folder\'s session).'],
            ], 'required' => ['name'], 'additionalProperties' => false]],
        ], 'required' => ['topics'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = array_slice(array_values(array_filter(is_array($input['topics'] ?? null) ? $input['topics'] : [], 'is_array')), 0, self::MAX);
        if ($wanted === []) {
            return 'Nothing to add: send at least one topic.';
        }
        $modules = $this->modules->list($by, $context->workspaceId);
        $session = $context->sessionId !== null ? $this->sessions->find($by, $context->sessionId) : null;
        $sessionModule = $session?->moduleId;
        $sessionFolder = null;
        if ($session?->folderId !== null) {
            try {
                $sessionFolder = $this->folders->find($by, $session->folderId)->id;
            } catch (NotFound) {
                // Deleted since: the module, then.
            }
        }
        $known = [];
        foreach ($this->topics->list($by, $context->workspaceId) as $topic) {
            $known[mb_strtolower($topic->name)] = true;
        }

        $added = [];
        $there = [];
        $problems = [];
        foreach ($wanted as $one) {
            $name = Lookup::text($one, 'name');
            if ($name === null) {
                continue;
            }
            if (isset($known[mb_strtolower($name)])) {
                $there[] = $name;

                continue;
            }
            [$moduleId, $folderId] = [$sessionModule, $sessionFolder];
            if (($title = Lookup::text($one, 'module')) !== null) {
                $module = Lookup::one($modules, $title, fn ($m) => $m->title, 'module');
                if (is_string($module)) {
                    $problems[] = "{$name}: {$module}";

                    continue;
                }
                // Another module than the session's: in that module, in no folder.
                [$moduleId, $folderId] = [$module->id, $module->id === $sessionModule ? $sessionFolder : null];
            }
            try {
                $this->topics->create($by, $context->workspaceId, $name, $moduleId, $folderId);
            } catch (Unprocessable $e) {
                $problems[] = "{$name}: ".(array_values($e->details['fields'] ?? [])[0][0] ?? $e->getMessage());

                continue;
            }
            $known[mb_strtolower($name)] = true;
            $added[] = $name;
        }
        $context->effects->saved('topic', count($added));

        return implode(' ', array_filter([
            $added !== [] ? 'Added '.count($added).' '.(count($added) === 1 ? 'topic' : 'topics').': '.implode(', ', $added).'.' : 'No topics were added.',
            $there !== [] ? 'Already in the course: '.implode(', ', $there).'.' : null,
            $problems !== [] ? 'Not added: '.implode(' ', $problems) : null,
            $added !== [] ? 'Tell the student in a line.' : null,
        ]));
    }
}
