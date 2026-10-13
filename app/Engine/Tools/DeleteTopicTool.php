<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\TopicDetails;
use App\Study\Topics;

final class DeleteTopicTool implements Tool
{
    public function __construct(private Topics $topics) {}

    public function name(): string
    {
        return 'delete_topic';
    }

    public function description(): string
    {
        return 'Removes or deletes an existing topic from the course. Use it when the student asks to delete or remove a topic (for example, redundant, duplicate or unwanted topics).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'topic' => ['type' => 'string', 'description' => 'The name (or part of it) of the topic to delete.'],
        ], 'required' => ['topic'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = Lookup::text($input, 'topic');
        if ($wanted === null || $wanted === '') {
            return 'Say which topic to remove.';
        }
        $topic = Lookup::one($this->topics->list($by, $context->workspaceId), $wanted, fn (TopicDetails $t) => $t->name, 'topic');
        if (is_string($topic)) {
            return $topic;
        }
        if (($ask = Agreement::needed($by, $context, "delete_topic:{$topic->id}", "Remove the topic \"{$topic->name}\"? Its questions, quizzes and flashcards stay, with no topic; its key points are deleted with it.")) !== null) {
            return $ask;
        }

        $this->topics->retire($by, $topic->id, 'tutor');

        return "Removed topic \"{$topic->name}\" from the course.";
    }
}
