<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;

/**
 * What the study session is about: a topic the course has, or a new one made in the session's module (in its folder,
 * when the session studies in one: docs/specs/vistud-2-blueprint.md, Phase 9). The student can change it on the
 * session page too; what's saved afterwards goes to it.
 */
final class SetTopicTool implements Tool
{
    public function __construct(private Sessions $sessions, private Topics $topics, private Modules $modules, private Folders $folders) {}

    public function name(): string
    {
        return 'set_topic';
    }

    public function description(): string
    {
        return 'Sets what this study session is about: one of the course\'s topics, by its exact name, or a new topic made in the session\'s module (and folder) when the course doesn\'t have it. Use it when the session has no topic and when you move on to another part (whether to ask first, the Topics section of your instructions says). Look at the course\'s topics first and use an existing one when it fits. Flashcards, questions and key points saved afterwards go to it.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'topic' => ['type' => 'string', 'description' => 'The topic\'s name: one the course has, or a new one, short like a chapter heading ("CPU scheduling").'],
        ], 'required' => ['topic'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->sessionId === null) {
            return 'Setting a topic needs a study session: ask the student to start one.';
        }
        $name = Lookup::text($input, 'topic');
        if ($name === null) {
            return 'Say which topic.';
        }
        $session = $this->sessions->find($by, $context->sessionId);
        if (! $session->isOpen()) {
            return 'This session has ended: its topic stays as it was.';
        }

        $topic = collect($this->topics->list($by, $context->workspaceId))->first(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name));
        $made = $topic === null;
        $folder = null;
        if ($made && $session->folderId !== null) {
            try {
                $folder = $this->folders->find($by, $session->folderId);
            } catch (NotFound) {
                // Deleted since: the topic goes in the module.
            }
        }
        $topic ??= $this->topics->create($by, $context->workspaceId, $name, $session->moduleId, $folder?->id);
        if ($session->topicId === $topic->id) {
            return "The session's topic is \"{$topic->name}\" already.";
        }
        $this->sessions->setTopic($by, $context->sessionId, $topic->id);
        $context->effects->topic($topic->name, $topic->id);
        if ($made) {
            $context->effects->saved('topic', 1);
        }
        $module = $topic->moduleId !== null ? $this->modules->find($by, $topic->moduleId)->title : null;

        $where = $folder !== null ? "{$folder->name}, in {$module}" : $module;

        return "The session's topic is now \"{$topic->name}\"".($made ? ', new in the course'.($where !== null ? " in {$where}" : '') : '').'. Tell the student in a line.';
    }
}
