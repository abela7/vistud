<?php

namespace App\Mcp\Tools;

use App\Mcp\ContextBuilder;
use App\Platform\Access\Principal;
use App\Study\Fence;
use App\Study\Flashcards;
use Illuminate\Container\Container;

/**
 * The flashcards due for review in a course (docs/specs/mcp-connections-plan.md §3.1), the same round the review page
 * gives: the longest waiting first, then new ones, at most one round. Each card has its id, so the answer can be
 * saved with answer_flashcard (M6.3).
 */
final class FlashcardsDueTool extends McpTool
{
    protected function info(): string
    {
        return 'flashcards_due';
    }

    public function description(): string
    {
        return 'The flashcards due for review in a course, longest waiting first, with their ids: front, back, how often reviewed. Pass course_id from the courses tool, and module_id to keep to one module. Ask the front, let the student answer, then show the back.';
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $context = Container::getInstance()->make(ContextBuilder::class)->forCourse($by, $arguments);
        $cards = Container::getInstance()->make(Flashcards::class);
        $rows = [];
        foreach ($cards->queue($by, $context->workspaceId, moduleId: $context->moduleId) as $id) {
            $card = $cards->find($by, $id);
            $rows[] = [
                'id' => $card->id,
                'front' => $card->front,
                'back' => $card->back,
                'topic_id' => $card->topicId,
                'reviews' => $card->reviews,
                'due_on' => $card->dueOn,
            ];
        }
        if ($rows === []) {
            return ['text' => 'No flashcards are due in this course.', 'structured' => ['cards' => []], 'type' => 'course', 'id' => $context->workspaceId];
        }

        $lines = array_map(fn (array $r, int $i) => ($i + 1).'. [id '.$r['id'].'] Front: '.$r['front']."\n   Back: ".$r['back'], $rows, array_keys($rows));
        $text = count($rows).' '.(count($rows) === 1 ? 'card is' : 'cards are')." due. The cards are the student's own words:\n".Fence::wrap(implode("\n", $lines));

        return ['text' => $text, 'structured' => ['cards' => $rows], 'type' => 'course', 'id' => $context->workspaceId];
    }
}
