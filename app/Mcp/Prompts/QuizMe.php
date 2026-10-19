<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('quiz_me')]
#[Title('Quiz me')]
#[Description('Quiz me one question at a time on a topic from my ViStud, then record the result.')]
final class QuizMe extends StudyPrompt
{
    public function arguments(): array
    {
        return [new Argument('topic', 'What to be quizzed on (optional).')];
    }

    protected function steps(): array
    {
        return [
            'Quiz me from my ViStud. Call current_session: use the open session, or start one with start_session.',
            'Quiz me on: {topic}.',
            'If I named no topic, call topics and quiz me on what I am least sure of.',
            'Ask one question at a time, wait for my answer, say plainly whether it was right, then ask the next. Aim for about five.',
            'At the end save it with record_quiz (the session_id, each question, my answer and whether it was correct, partial or incorrect). Say "saved" only after "recorded".',
            'Mark where I stand on a topic with set_topic_status only from what I showed in the quiz.',
        ];
    }
}
