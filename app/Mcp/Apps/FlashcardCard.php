<?php

namespace App\Mcp\Apps;

use Laravel\Mcp\Server\Attributes\Description;

#[Description('A flashcard. It shows the front, then the back, and sends the answer to answer_flashcard.')]
final class FlashcardCard extends ViStudApp
{
    protected string $title = 'Flashcard';

    protected function kind(): string
    {
        return 'cards';
    }
}
