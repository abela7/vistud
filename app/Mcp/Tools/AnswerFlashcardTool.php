<?php

namespace App\Mcp\Tools;

use App\Mcp\ContextBuilder;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Flashcards;
use Illuminate\Container\Container;

/**
 * Records a flashcard answer from an AI app's review (docs/specs/mcp-connections-plan.md M6.3), on the same schedule
 * as the review page. Who marked it is kept honest: `graded_by: student` when the student said how it went, otherwise
 * the chat app's judge (`judged_by: ai`).
 */
final class AnswerFlashcardTool extends McpTool
{
    protected function info(): string
    {
        return 'answer_flashcard';
    }

    public function description(): string
    {
        return 'Records how the student did on a flashcard, by card_id from flashcards_due: correct, partial or incorrect. Set graded_by to student when they said how it went themselves; otherwise you marked it. ViStud schedules the next review.';
    }

    protected function fields(): array
    {
        return [
            'card_id' => ['type' => 'string', 'description' => 'The card\'s id, from flashcards_due.'],
            'result' => ['type' => 'string', 'enum' => Flashcards::RESULTS, 'description' => 'How it went.'],
            'graded_by' => ['type' => 'string', 'enum' => ['student', 'you'], 'description' => 'student when they judged their own answer; you when you did.'],
        ];
    }

    protected function requiredFields(): array
    {
        return ['card_id', 'result'];
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $container = Container::getInstance();
        $context = $container->make(ContextBuilder::class)->for($by, $this->info(), $arguments, $connection);
        $cards = $container->make(Flashcards::class);
        $cardId = ContextBuilder::id($arguments, 'card_id') ?? throw new Unprocessable('card_required', 'missing: card_id. Call flashcards_due for the ids.');
        $card = $cards->find($by, $cardId);
        if ($card->workspaceId !== $context->workspaceId) {
            throw new NotFound;
        }
        $result = is_string($arguments['result'] ?? null) ? $arguments['result'] : '';
        $judge = ($arguments['graded_by'] ?? 'you') === 'student' ? null : $context->judge();
        $after = $cards->answer($by, $card->id, $result, judge: $judge);

        return [
            'text' => 'Saved'.($after->dueOn !== null ? "; next review on {$after->dueOn}." : '.'),
            'structured' => ['card_id' => $after->id, 'result' => $result, 'next_review' => $after->dueOn, 'graded_by' => $judge === null ? 'student' : 'ai'],
            'type' => 'flashcard',
            'id' => $after->id,
            'effects' => ['rows' => 1, 'saved' => ['flashcard_answers' => 1]],
        ];
    }
}
