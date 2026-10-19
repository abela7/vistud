<?php

namespace App\Mcp\Apps;

use Laravel\Mcp\Server\Attributes\Description;

#[Description('A quiz card. It shows the questions from show_quiz and sends the answers to record_quiz. It saves nothing itself.')]
final class QuizRound extends ViStudApp
{
    protected string $title = 'Quiz';

    protected function kind(): string
    {
        return 'quiz';
    }
}
