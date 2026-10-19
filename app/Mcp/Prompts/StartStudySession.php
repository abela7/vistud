<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('start_study_session')]
#[Title('Start a study session')]
#[Description('Teach me from my ViStud course: pick up where I left off, teach, check what stuck, and save it.')]
final class StartStudySession extends StudyPrompt
{
    public function arguments(): array
    {
        return [
            new Argument('course', 'The course, as you call it (optional).'),
            new Argument('topic', 'What to study (optional).'),
        ];
    }

    protected function steps(): array
    {
        return [
            'Study with me from my ViStud. Call current_session first: if a session is open, continue it.',
            'Otherwise call courses, then start_session on the course I mean (ask me if it is not clear).',
            'The course: {course}.',
            'What to study: {topic}.',
            'Read the brief start_session gives you and teach from it, one part at a time. Say where my material is thin.',
            'Pass the session_id on every save. Save key points, flashcards and open questions only when I agree, and say "saved" only after ViStud answers "recorded".',
            'When I am done, call end_session with a short summary of what we covered and where I stand.',
        ];
    }
}
