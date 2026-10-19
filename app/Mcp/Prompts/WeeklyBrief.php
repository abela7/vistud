<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('weekly_brief')]
#[Title('My week')]
#[Description('Look at my ViStud and tell me where my week stands and what to do next.')]
final class WeeklyBrief extends StudyPrompt
{
    protected function steps(): array
    {
        return [
            'Look at my ViStud and give me a short brief on my week. Read only; save nothing.',
            'Call study_time for what I studied, courses for what I am on, and for each course the calendar, assignments and flashcards_due.',
            'Tell me in plain words: what is due soon, what I have not touched, and the three things to do next, in order.',
        ];
    }
}
