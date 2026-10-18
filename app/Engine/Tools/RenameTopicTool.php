<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\TopicDetails;
use App\Study\Topics;

final class RenameTopicTool implements Tool
{
    public function __construct(private Topics $topics) {}

    public function name(): string
    {
        return 'rename_topic';
    }

    public function description(): string
    {
        return 'Renames an existing topic in the course. Use it when the student asks to rename, edit, or change a topic\'s name.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'topic' => ['type' => 'string', 'description' => 'The current name (or part of it) of the topic to rename.'],
            'new_name' => ['type' => 'string', 'description' => 'The new name for the topic.'],
        ], 'required' => ['topic', 'new_name'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = Lookup::text($input, 'topic');
        $newName = Lookup::text($input, 'new_name');
        if ($wanted === null || $wanted === '') {
            return 'Say which topic to rename.';
        }
        if ($newName === null || trim($newName) === '') {
            return 'Send the new name for the topic.';
        }
        $newName = Topics::cleanMarkdown(trim($newName));
        $topic = Lookup::forWrite($by, $this->topics->list($by, $context->workspaceId), $wanted, fn (TopicDetails $t) => $t->name, 'topic');
        if (is_string($topic)) {
            return $topic;
        }

        $this->topics->rename($by, $topic->id, $newName);
        if ($context->sessionId !== null) {
            $context->effects->topic($newName, $topic->id);
        }

        return "Renamed topic \"{$topic->name}\" to \"{$newName}\".";
    }
}
