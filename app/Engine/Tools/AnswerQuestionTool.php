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

        // Its exact words first; else the one question whose words contain these (open ones before answered ones). Never a
        // guess between several: the tutor is told which match, to say the one it means.
        $norm = fn (string $s) => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
        $wantedNorm = $norm($wanted);
        if ($wantedNorm === '') {
            return 'Say which question to answer, in its words.';
        }
        $matches = array_values(array_filter($allQuestions, fn ($q) => $q->id === $wanted || $norm($q->text) === $wantedNorm));
        // The same words written down twice are the same question: an open one first.
        usort($matches, fn ($a, $b) => ($a->status === 'answered') <=> ($b->status === 'answered'));
        $matches = array_slice($matches, 0, 1);
        foreach ([fn ($q) => $q->status !== 'answered', fn ($q) => true] as $which) {
            if ($matches !== []) {
                break;
            }
            $matches = array_values(array_filter($allQuestions, fn ($q) => $which($q) && $norm($q->text) !== '' && str_contains($norm($q->text), $wantedNorm)));
        }

        if ($matches === []) {
            $openTexts = array_map(fn ($q) => "\"{$q->text}\"", array_slice(array_filter($allQuestions, fn ($q) => $q->status !== 'answered'), 0, 5));
            $available = $openTexts !== [] ? ' Available open questions: '.implode(', ', $openTexts) : '';

            return "Could not find a question matching \"{$wanted}\".{$available}";
        }
        if (count($matches) > 1) {
            return 'Several questions match "'.$wanted.'": '.implode(', ', array_map(fn ($q) => "\"{$q->text}\"", array_slice($matches, 0, 5))).'. Send the one you mean, in its exact words.';
        }

        $matched = $matches[0];
        $conciseAnswer = mb_substr($answer, 0, Questions::MAX_ANSWER);
        // The tutor's answer answers it; whether it clicked is the student's to say.
        $this->questions->setStatus($by, $matched->id, 'answered', $conciseAnswer, theirWord: false);

        return "Saved answer to question \"{$matched->text}\": {$conciseAnswer}. It shows as answered by the AI until the student says it makes sense.";
    }
}
