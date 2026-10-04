<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;

/** Flashcards straight into the student's deck, for review later on the ladder. */
final class MakeFlashcardsTool extends Saving
{
    public function name(): string
    {
        return 'make_flashcards';
    }

    public function description(): string
    {
        return 'Saves flashcards straight into the student\'s deck in ViStud, to review later. Use it when the student asks for cards or agrees to them: each card a short question on the front and its answer on the back, from what you studied together. Up to ten at a time.';
    }

    public function parameters(): array
    {
        return self::listOf('cards', ['front' => 'The question, short.', 'back' => 'The answer, short.'], 'The cards to save.');
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        return $this->save($by, $context, 'flashcard', self::items($input, 'cards', ['front', 'back']), ['flashcard', 'flashcards']);
    }
}
