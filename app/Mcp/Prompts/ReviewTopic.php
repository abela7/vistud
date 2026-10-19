<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('review_topic')]
#[Title('Review a topic')]
#[Description('Go over a topic with me from my notes and key points, and find what is still unclear.')]
final class ReviewTopic extends StudyPrompt
{
    public function arguments(): array
    {
        return [new Argument('topic', 'The topic to review.', true)];
    }

    protected function steps(): array
    {
        return [
            'Review {topic} with me from my ViStud. Find it with topics, then read my notes on it (notes, read_note) and my key points (findings).',
            'Tell me in a few lines what my own material says, then ask me to explain it back in my words.',
            'Point out what I got wrong or left out, and list the open questions I still have (questions).',
            'Save nothing unless I ask you to.',
        ];
    }
}
