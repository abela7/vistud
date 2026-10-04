<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;

/** Open questions onto the student's question board, to come back to or ask the teacher. */
final class AddQuestionsTool extends Saving
{
    public function name(): string
    {
        return 'add_questions';
    }

    public function description(): string
    {
        return 'Adds open questions to the student\'s question board in ViStud: what they still don\'t get, to come back to or ask the teacher. Use it when the student wants to keep a question for later.';
    }

    public function parameters(): array
    {
        return self::listOf('questions', ['text' => 'The question, as the student would ask it.'], 'The questions to add.');
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        return $this->save($by, $context, 'question', self::items($input, 'questions', ['text']), ['question', 'questions']);
    }
}
