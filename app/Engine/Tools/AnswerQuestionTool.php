<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Questions;

/** Puts a concise, question-oriented answer on an open question on the student's question board. */
final class AnswerQuestionTool implements Tool
{
    public function __construct(private Questions $questions) {}

    public function name(): string
    {
        return 'answer_question';
    }

    public function description(): string
    {
        return 'Answers an open question from the student\'s question board or updates its answer. The answer MUST be direct, simple, question-oriented and concise (1-2 sentences at most, never a long explanation). Use it when the student asks you to put or save the answer to their question.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The question text or key words to match the open question.',
                ],
                'answer' => [
                    'type' => 'string',
                    'description' => 'The simple, concise, and question-oriented answer (1 to 2 sentences at most, under 40 words, no filler).',
                ],
            ],
            'required' => ['question', 'answer'],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $wanted = trim((string) ($input['question'] ?? ''));
        $answer = trim((string) ($input['answer'] ?? ''));

        if ($wanted === '') {
            return 'Say which question to answer.';
        }
        if ($answer === '') {
            return 'Give the concise answer to put on the question.';
        }

        $allQuestions = $this->questions->list($by, $context->workspaceId);
        if ($allQuestions === []) {
            return 'There are no questions recorded in this course yet.';
        }

        // Find best matching open question first, then answered
        $norm = fn (string $s) => preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($s)));
        $wantedNorm = $norm($wanted);

        $matched = null;
        foreach ($allQuestions as $q) {
            if ($q->id === $wanted || $norm($q->text) === $wantedNorm) {
                $matched = $q;
                break;
            }
        }

        if ($matched === null) {
            $open = array_filter($allQuestions, fn ($q) => $q->status !== 'answered');
            foreach ($open as $q) {
                if (str_contains($norm($q->text), $wantedNorm) || str_contains($wantedNorm, $norm($q->text))) {
                    $matched = $q;
                    break;
                }
            }
        }

        if ($matched === null) {
            foreach ($allQuestions as $q) {
                if (str_contains($norm($q->text), $wantedNorm) || str_contains($wantedNorm, $norm($q->text))) {
                    $matched = $q;
                    break;
                }
            }
        }

        if ($matched === null) {
            $openTexts = array_map(fn ($q) => "\"{$q->text}\"", array_slice(array_filter($allQuestions, fn ($q) => $q->status !== 'answered'), 0, 5));
            $available = $openTexts !== [] ? ' Available open questions: '.implode(', ', $openTexts) : '';

            return "Could not find a question matching \"{$wanted}\".{$available}";
        }

        $conciseAnswer = mb_substr($answer, 0, Questions::MAX_ANSWER);
        $this->questions->setStatus($by, $matched->id, 'answered', $conciseAnswer);

        return "Saved answer to question \"{$matched->text}\": {$conciseAnswer}";
    }
}
