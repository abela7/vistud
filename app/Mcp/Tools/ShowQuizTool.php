<?php

namespace App\Mcp\Tools;

use App\Mcp\ContextBuilder;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Fence;
use App\Study\Quizzes;
use App\Study\Topics;
use Illuminate\Container\Container;

/**
 * Shows a quiz without saving it (docs/specs/mcp-connections-plan.md M6.6). It checks the course, the open session
 * and the topic, then echoes the questions. The card, or the model, saves the student's answers with record_quiz.
 * The text is the whole quiz, so an app that cannot draw the card still has it.
 */
final class ShowQuizTool extends McpTool
{
    protected function info(): string
    {
        return 'show_quiz';
    }

    public function description(): string
    {
        return 'Shows a quiz card for an open session. It saves nothing: pass course_id, session_id, topic_id and the questions. The card sends the student\'s answers to record_quiz, and so can you. The text of this answer is the whole quiz.';
    }

    protected function fields(): array
    {
        return [
            'topic_id' => ['type' => 'string', 'description' => 'The topic\'s id, from the topics tool.'],
            'questions' => [
                'type' => 'array',
                'maxItems' => Quizzes::MAX_QUESTIONS,
                'description' => 'The questions to show, in order. At most '.Quizzes::MAX_QUESTIONS.'.',
                'items' => ['type' => 'object', 'properties' => [
                    'asked' => ['type' => 'string', 'description' => 'The question.'],
                    'choices' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional choices, at most six.'],
                    'right' => ['type' => 'string', 'description' => 'The right choice, when there are choices, so the card can mark the answer.'],
                ], 'required' => ['asked']],
            ],
        ];
    }

    protected function requiredFields(): array
    {
        return ['course_id', 'topic_id', 'questions'];
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $context = Container::getInstance()->make(ContextBuilder::class)->for($by, $this->info(), $arguments, $connection);
        $topicId = ContextBuilder::id($arguments, 'topic_id') ?? throw new Unprocessable('topic_required', 'missing: topic_id. Call topics for the ids.');
        $topic = Container::getInstance()->make(Topics::class)->find($by, $topicId);
        if ($topic->workspaceId !== $context->workspaceId) {
            throw new NotFound;
        }
        $questions = self::questions($arguments['questions'] ?? null);
        $lines = [];
        foreach ($questions as $i => $question) {
            $line = ($i + 1).'. '.$question['asked'];
            foreach ($question['choices'] ?? [] as $n => $choice) {
                $line .= "\n   ".chr(65 + $n).'. '.$choice;
            }
            $lines[] = $line;
        }
        $count = count($questions);
        $text = 'Quiz, not saved yet. Topic '.$topic->name.' ('.$topic->id."). Session {$context->sessionId}. {$count} ".($count === 1 ? 'question' : 'questions').".\n"
            .Fence::wrap(implode("\n", $lines))
            ."\nSave the student's answers with record_quiz. The card does that too. This call saved nothing.";

        return [
            'text' => $text,
            'structured' => [
                'course_id' => $context->workspaceId,
                'session_id' => $context->sessionId,
                'topic_id' => $topic->id,
                'questions' => $questions,
            ],
            'type' => 'session',
            'id' => $context->sessionId,
        ];
    }

    /**
     * @return list<array{asked: string, choices?: list<string>, right?: string}>
     */
    private static function questions(mixed $given): array
    {
        if (! is_array($given) || $given === [] || count($given) > Quizzes::MAX_QUESTIONS) {
            throw new Unprocessable('invalid_questions', 'Give between 1 and '.Quizzes::MAX_QUESTIONS.' questions, each with what was asked.');
        }
        $out = [];
        foreach (array_values($given) as $i => $item) {
            if (! is_array($item)) {
                throw new Unprocessable('invalid_questions', 'Question '.($i + 1).' needs what was asked, in 500 characters or fewer.');
            }
            $asked = is_string($item['asked'] ?? null) ? trim($item['asked']) : '';
            if ($asked === '' || mb_strlen($asked) > 500) {
                throw new Unprocessable('invalid_questions', 'Question '.($i + 1).' needs what was asked, in 500 characters or fewer.');
            }
            $row = ['asked' => $asked];
            if (array_key_exists('choices', $item)) {
                $choices = $item['choices'];
                if (! is_array($choices) || count($choices) > 6) {
                    throw new Unprocessable('invalid_questions', 'Question '.($i + 1).' has at most six choices.');
                }
                $clean = [];
                foreach ($choices as $choice) {
                    $choice = is_string($choice) ? trim($choice) : '';
                    if ($choice === '' || mb_strlen($choice) > 200) {
                        throw new Unprocessable('invalid_questions', 'Question '.($i + 1).' has an empty choice.');
                    }
                    $clean[] = $choice;
                }
                if ($clean !== []) {
                    $row['choices'] = $clean;
                }
            }
            $right = is_string($item['right'] ?? null) ? trim($item['right']) : '';
            if ($right !== '') {
                if (isset($row['choices']) && ! in_array($right, $row['choices'], true)) {
                    throw new Unprocessable('invalid_questions', 'Question '.($i + 1).': the right answer has to be one of the choices.');
                }
                $row['right'] = $right;
            }
            $out[] = $row;
        }

        return $out;
    }
}
