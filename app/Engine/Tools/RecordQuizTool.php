<?php

namespace App\Engine\Tools;

use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Study\Capture;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\TopicSuggestions;

/**
 * A quiz or a test the tutor has asked and marked (docs/specs/vistud-2-blueprint.md §3.9, Appendix B): kept as a readable
 * record with its score, and each answer as evidence. After a test, the status each topic earns from its score is set
 * or proposed the way set_topic_status does it, by the student's setting.
 */
final class RecordQuizTool implements Tool
{
    public function __construct(private Quizzes $quizzes, private Topics $topics, private Settings $settings, private Sessions $sessions) {}

    public function name(): string
    {
        return 'record_quiz';
    }

    public function description(): string
    {
        return 'Keeps a quiz or test once every question is asked and marked: each with the student\'s answer and how it went. The score is worked out (correct one, partial half). Use it after telling them their score, once. A test also sets or proposes each topic\'s status. Name each question\'s topic exactly as the topics tool gives it. A question with no topic goes to the quiz\'s topic, else the session\'s; in a test, a name the course doesn\'t have counts for no topic.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['quiz', 'test'], 'description' => 'A quiz is about five questions on a topic; a test ten to fifteen over the module.'],
            'topic' => ['type' => 'string', 'description' => 'A quiz\'s topic, by exact name; for a test, name each question\'s.'],
            'topic_id' => ['type' => 'string', 'description' => 'The id of the quiz\'s topic (from the topics tool).'],
            'questions' => ['type' => 'array', 'maxItems' => Quizzes::MAX_QUESTIONS, 'description' => 'The questions, in the order asked.', 'items' => ['type' => 'object', 'properties' => [
                'asked' => ['type' => 'string', 'description' => 'The question, as you asked it.'],
                'answer' => ['type' => 'string', 'description' => 'What they answered, in their words.'],
                'result' => ['type' => 'string', 'enum' => ['correct', 'partial', 'incorrect']],
                'right' => ['type' => 'string', 'description' => 'What they got right, in a line.'],
                'fix' => ['type' => 'string', 'description' => 'What to fix or review, in a line.'],
                'form' => ['type' => 'string', 'enum' => Capture::FORMS, 'description' => 'How it exercised the topic: recall (state it), explain (say why, in their words), apply (use it on a new case), recognise (pick it out). Left out: a quiz\'s question is recall, a test\'s apply. Mastery is earned by apply.'],
                'topic' => ['type' => 'string', 'description' => 'The topic this question was about, by its exact name.'],
                'topic_id' => ['type' => 'string', 'description' => 'The id of the topic this question was about (from the topics tool).'],
            ], 'required' => ['asked', 'result'], 'additionalProperties' => false]],
        ], 'required' => ['kind', 'questions'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->sessionId === null) {
            return 'Recording a quiz needs a study session: ask the student to start one.';
        }
        $topicList = $this->topics->list($by, $context->workspaceId);
        $given = is_array($input['questions'] ?? null) ? array_values($input['questions']) : [];
        if (($problem = self::checkTopics($by, $topicList, $input, $given)) !== null) {
            return $problem;
        }
        $quiz = $this->quizzes->record($by, $context->sessionId, $input, $context->judge());
        $context->effects->quiz($quiz->id, $quiz->kind, $quiz->score, $quiz->asked);

        $judged = $quiz->judged();
        $out = $judged === 0
            ? ucfirst($quiz->kind)." recorded: none of its {$quiz->asked} answers could be marked, so it counts for nothing."
            : ucfirst($quiz->kind)." recorded: {$quiz->right()} of {$judged} right, {$quiz->score} %.".($judged < $quiz->asked ? ' '.($quiz->asked - $judged).' could not be marked and count neither way.' : '');
        // A topic named that the course doesn't have is said, so it isn't taken as counted.
        $astray = [];
        foreach ($given as $i => $item) {
            $raw = is_array($item) ? ($item['topic_id'] ?? $item['topic'] ?? null) : null;
            $named = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            $kept = $quiz->questions[$i]['topic'] ?? null;
            $keptId = $quiz->questions[$i]['topic_id'] ?? null;
            if ($named !== null && $keptId !== $named && ($kept === null || Lookup::key($kept) !== Lookup::key($named))) {
                $astray[] = 'question '.($i + 1).' named "'.$named.'"'.($kept === null ? ', not a topic here, so it counts for no topic' : ', counted for "'.$kept.'"');
            }
        }
        if ($astray !== []) {
            $out .= ' Topics: '.implode('; ', $astray).'. Use the exact names the topics tool gives.';
        }
        if ($quiz->proposals === []) {
            return $out.' Say in a line that it is kept.';
        }
        $marks = $this->settings->get($by)->tutorMarksTopics;
        $lines = [];
        $since = $context->sessionId === null ? null : $this->sessions->find($by, $context->sessionId)->startedAt;
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
            if ($topic->studentsWord() && Topics::saidSince($topic->statusAt, $since)) {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], false, $reason);
                $lines[] = "{$topic->name}: {$words} proposed (the student's own word stays)";

                continue;
            }
            if (! $marks) {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], false, $reason);
                $lines[] = "{$topic->name}: {$words} proposed";

                continue;
            }
            $before = $this->topics->mark($by, $topic->id, $p['status'], $since);
            if ($before !== null) {
                $context->effects->status($topic->id, $topic->name, $topic->status, $p['status'], true, $reason, $before);
                $lines[] = "{$topic->name}: marked {$words}";
            }
        }

        return $out.($lines === [] ? '' : ' Topics from the score — '.implode('; ', $lines).'.').' Tell the student what it means in two lines; the statuses show beside your message.';
    }

    /**
     * Refuses an ambiguous topic in any channel, or a non-exact topic over MCP, before anything is saved.
     *
     * @param  list<TopicDetails>  $topicList
     * @param  array<string, mixed>  $input
     * @param  list<mixed>  $given
     */
    private static function checkTopics(Principal $by, array $topicList, array $input, array $given): ?string
    {
        $refs = [$input['topic_id'] ?? $input['topic'] ?? null];
        foreach ($given as $item) {
            if (is_array($item)) {
                $refs[] = $item['topic_id'] ?? $item['topic'] ?? null;
            }
        }
        $byCore = [];
        foreach ($topicList as $topic) {
            $byCore[TopicSuggestions::core($topic->name)][] = $topic;
        }
        foreach ($refs as $raw) {
            if (! is_string($raw) || ($wanted = trim($raw)) === '') {
                continue;
            }
            if ($by->channel === 'mcp') {
                $found = Lookup::exact($topicList, $wanted, fn ($t) => $t->name, 'topic');
                if (is_string($found)) {
                    return $found;
                }

                continue;
            }
            $found = Lookup::one($topicList, $wanted, fn ($t) => $t->name, 'topic');
            if (is_string($found) && str_starts_with($found, 'Several ')) {
                $alike = $byCore[TopicSuggestions::core($wanted)] ?? [];
                if (count($alike) !== 1) {
                    return $found;
                }
            }
        }

        return null;
    }
}
