<?php

namespace App\Engine\Tools;

use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Study\Quizzes;
use App\Study\Topics;

/**
 * A quiz or a test the tutor has asked and marked (docs/specs/vistud-2-blueprint.md §3.9, Appendix B): kept as a readable
 * record with its score, and each answer as evidence. After a test, the status each topic earns from its score is set
 * or proposed the way set_topic_status does it, by the student's setting.
 */
final class RecordQuizTool implements Tool
{
    public function __construct(private Quizzes $quizzes, private Topics $topics, private Settings $settings) {}

    public function name(): string
    {
        return 'record_quiz';
    }

    public function description(): string
    {
        return 'Keeps a quiz or test once every question is asked and marked: each with the student\'s answer and how it went. The score is worked out (correct one, partial half). Use it after telling them their score, once. A test also sets or proposes each topic\'s status. A question with no topic of its own goes to the quiz\'s topic, else the session\'s.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['quiz', 'test'], 'description' => 'A quiz is about five questions on a topic; a test ten to fifteen over the module.'],
            'topic' => ['type' => 'string', 'description' => 'A quiz\'s topic, by exact name; for a test, name each question\'s.'],
            'questions' => ['type' => 'array', 'maxItems' => Quizzes::MAX_QUESTIONS, 'description' => 'The questions, in the order asked.', 'items' => ['type' => 'object', 'properties' => [
                'asked' => ['type' => 'string', 'description' => 'The question, as you asked it.'],
                'answer' => ['type' => 'string', 'description' => 'What they answered, in their words.'],
                'result' => ['type' => 'string', 'enum' => ['correct', 'partial', 'incorrect']],
                'right' => ['type' => 'string', 'description' => 'What they got right, in a line.'],
                'fix' => ['type' => 'string', 'description' => 'What to fix or review, in a line.'],
                'topic' => ['type' => 'string', 'description' => 'The topic this question was about, by its exact name.'],
            ], 'required' => ['asked', 'result'], 'additionalProperties' => false]],
        ], 'required' => ['kind', 'questions'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->sessionId === null) {
            return 'Recording a quiz needs a study session: ask the student to start one.';
        }
        $quiz = $this->quizzes->record($by, $context->sessionId, $input);
        $context->effects->quiz($quiz->id, $quiz->kind, $quiz->score, $quiz->asked);

        $out = ucfirst($quiz->kind)." recorded: {$quiz->right()} of {$quiz->asked} right, {$quiz->score} %.";
        if ($quiz->proposals === []) {
            return $out.' Say in a line that it is kept.';
        }
        $marks = $this->settings->get($by)->tutorMarksTopics;
        $lines = [];
        foreach ($quiz->proposals as $p) {
            $topic = collect($this->topics->list($by, $context->workspaceId))->firstWhere('id', $p['topic_id']);
            if ($topic === null) {
                continue;
            }
            $words = $p['status'] === 'confused' ? 'confusing' : $p['status'];
            $reason = "{$p['score']} % in the test";
            if ($topic->status === $p['status']) {
                continue;
            }
            if ($topic->status !== null && $topic->statusBy !== 'tutor') {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], false, $reason);
                $lines[] = "{$topic->name}: {$words} proposed (the student's own word stays)";

                continue;
            }
            if (! $marks) {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], false, $reason);
                $lines[] = "{$topic->name}: {$words} proposed";

                continue;
            }
            $before = $this->topics->mark($by, $topic->id, $p['status']);
            if ($before !== null) {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], true, $reason, $before);
                $lines[] = "{$topic->name}: marked {$words}";
            }
        }

        return $out.($lines === [] ? '' : ' Topics from the score — '.implode('; ', $lines).'.').' Tell the student what it means in two lines; the statuses show beside your message.';
    }
}
