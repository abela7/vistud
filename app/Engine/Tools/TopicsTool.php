<?php

namespace App\Engine\Tools;

use App\Engine\Context\Stack;
use App\Platform\Access\Principal;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/**
 * The topics of the course, or of one module, each with the student's own word on it and what their practice shows. In a
 * folder's session (docs/specs/vistud-2-blueprint.md, Phase 9) it is the folder's topics, unless the whole course or a
 * module is asked for; a topic in a folder says which.
 */
final class TopicsTool implements Tool
{
    public function __construct(private Topics $topics, private Modules $modules, private Folders $folders) {}

    public function name(): string
    {
        return 'topics';
    }

    public function description(): string
    {
        return 'The topics of the course (or of one module), each with where it stands (not started, covered, understood, confusing, or mastered when their practice earned it), who set that and when (the student or you; nobody, for a default), and the evidence. Use it to see what the student knows and what still confuses them. In a session in a folder it lists the folder\'s topics and the module\'s topics in no folder, unless you ask for the whole course or a module.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to; leave out for the whole course (or, in a folder\'s session, the folder).'],
            'everywhere' => ['type' => 'boolean', 'description' => 'True for every topic of the course, in a folder\'s session.'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $modules = $this->modules->list($by, $context->workspaceId);
        $titles = [];
        foreach ($modules as $module) {
            $titles[$module->id] = $module->title;
        }
        $only = null;
        $inFolder = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($modules, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $only = $module->id;
        } elseif (($input['everywhere'] ?? false) !== true) {
            $inFolder = Lookup::folder($this->folders, $by, $context);
        }
        $folderNames = [];
        foreach ($this->folders->tree($by, $context->workspaceId) as $folder) {
            $folderNames[$folder->id] = $folder->name;
        }
        $rows = [];
        foreach ($this->topics->list($by, $context->workspaceId) as $topic) {
            $own = $topic->folderId === null && $topic->moduleId !== null && $topic->moduleId === $context->moduleId;
            if (($only !== null && $topic->moduleId !== $only) || ($inFolder !== null && ! $topic->in($inFolder) && ! $own)) {
                continue;
            }
            $rows[] = array_filter([
                'topic' => $topic->name,
                'module' => $topic->moduleId !== null ? ($titles[$topic->moduleId] ?? null) : null,
                'folder' => $topic->folderId !== null ? ($folderNames[$topic->folderId] ?? null) : null,
                'status' => Stack::word($topic->shown()),
                'set_by' => $topic->status === null ? 'nobody yet' : ($topic->byTutor() ? 'you' : 'the student'),
                'set_at' => $topic->status !== null && $topic->statusAt !== null ? CarbonImmutable::parse($topic->statusAt, 'UTC')->setTimezone($context->zone)->format('Y-m-d H:i') : null,
                'evidence' => $topic->evidence(),
            ]);
        }

        return $rows === []
            ? ($inFolder !== null ? 'No topics in this folder yet. Ask with everywhere for the whole course.' : 'No topics yet'.($only !== null ? ' in that module' : '').'.')
            : Lookup::json($rows);
    }
}
