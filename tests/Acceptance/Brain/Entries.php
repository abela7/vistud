<?php

namespace Tests\Acceptance\Brain;

/**
 * Builders for entry specifications in the ADR 0002 shapes, shared by the
 * golden replay and the edge-case tests. Learner L1 throughout.
 */
final class Entries
{
    public const LEARNER = ['type' => 'learner', 'id' => 'L1', 'channel' => 'web'];

    public const CHATGPT = ['type' => 'chat_client', 'id' => 'chatgpt', 'channel' => 'mcp'];

    public const INTERPRETER = ['type' => 'processor', 'id' => 'local-interpreter', 'channel' => 'job'];

    public const RESOLVER = ['type' => 'processor', 'id' => 'resolver', 'channel' => 'job'];

    public const CHECKER = ['id' => 'lab-checker', 'version' => 1, 'key_source' => 'course_material'];

    /** Provenance of a verdict made by the chat AI (judged_by ai). */
    public const AI_JUDGE = ['model' => 'openai/gpt-x', 'prompt' => 'judge-attempt@1', 'via' => 'chat'];

    public static function person(): array
    {
        return ['kind' => 'person', 'id' => 'L1', 'version' => '1'];
    }

    public static function rule(string $id): array
    {
        return ['kind' => 'rule', 'id' => $id, 'version' => '1'];
    }

    public static function interpreter(string $prompt): array
    {
        return ['kind' => 'interpreter', 'id' => 'local-interpreter', 'version' => '1', 'prompt' => $prompt];
    }

    public static function chatAi(): array
    {
        return ['kind' => 'chat_ai', 'id' => 'chatgpt', 'version' => '1'];
    }

    public static function auto(): array
    {
        return ['kind' => 'auto', 'id' => 'lab-checker', 'version' => '1'];
    }

    /** A claim. $review is 'accepted' (by policy@1 unless the method is a person), or 'pending'. */
    public static function claim(string $id, string $at, string $type, array $targets, array $value, array $method, ?float $confidence = null, string $review = 'accepted', array $extra = [], ?array $actor = null): array
    {
        $actor ??= match ($method['kind']) {
            'person' => self::LEARNER,
            'interpreter' => self::INTERPRETER,
            'chat_ai' => self::CHATGPT,
            default => self::RESOLVER,
        };
        $reviewBody = $review === 'accepted' && $method['kind'] !== 'person' ? ['state' => 'accepted', 'by' => 'policy@1'] : ['state' => $review];

        return [
            'id' => $id, 'kind' => 'claim', 'actor' => $actor, 'occurred_at' => $at,
            'body' => ['type' => $type, 'targets' => $targets, 'value' => $value, 'confidence' => $confidence, 'method' => $method, 'review' => $reviewBody] + $extra,
        ];
    }

    public static function topic(string $id, string $topic, string $at): array
    {
        return self::claim($id, $at, 'defines', ["topic:{$topic}"], ['entity_type' => 'topic', 'status' => 'active', 'kind' => 'concept', 'aliases' => []], self::person());
    }

    public static function relates(string $id, string $at, string $from, string $relation, string $to, ?array $method = null, ?float $confidence = null, string $review = 'accepted', array $extra = [], array $qualifiers = []): array
    {
        $value = ['relation' => $relation, 'status' => 'active'] + ($qualifiers === [] ? [] : ['qualifiers' => $qualifiers]);

        return self::claim($id, $at, 'relates', [$from, $to], $value, $method ?? self::person(), $confidence, $review, $extra);
    }

    public static function task(string $id, string $key, string $at, ?string $activity = null, ?array $actor = null): array
    {
        return [
            'id' => $id, 'kind' => 'record', 'actor' => $actor ?? self::LEARNER, 'occurred_at' => $at,
            'body' => ['record_type' => 'task', 'record_id' => $id, 'key' => $key, 'revision' => 1, 'status' => 'active'] + ($activity === null ? [] : ['activity' => $activity]),
            'content' => ['prompt' => "The task {$key}."],
        ];
    }

    /**
     * An attempt. $judgedBy auto uses the trusted lab checker, ai carries the
     * chat AI's provenance.
     */
    public static function attempt(string $id, string $at, string $session, string $task, string $outcome, string $judgedBy = 'auto', string $form = 'apply', string $setting = 'lab', array $extra = [], ?array $actor = null): array
    {
        $body = ['task' => $task, 'task_revision' => 1, 'form' => $form, 'support' => 'unaided', 'setting' => $setting, 'outcome' => $outcome, 'judged_by' => $judgedBy];
        if ($judgedBy === 'auto') {
            $body['checker'] = self::CHECKER;
        }
        if ($judgedBy === 'ai') {
            $body['judge'] = self::AI_JUDGE;
        }

        return array_replace_recursive([
            'id' => $id, 'kind' => 'attempt', 'actor' => $actor ?? self::LEARNER, 'origin' => 'first_hand',
            'occurred_at' => $at, 'session' => $session, 'body' => $body,
            'content' => ['answer' => 'SELECT c.name FROM customers c LEFT JOIN orders o ON o.customer_id = c.id;'],
        ], $extra);
    }

    /** An exposure. A chat AI explanation carries the required summary. */
    public static function exposure(string $id, string $at, ?string $session, string $format, array $about, array $by, array $approach = [], array $respondsTo = [], array $extra = [], ?array $actor = null): array
    {
        $links = [
            ...array_map(fn ($t) => ['rel' => 'about', 'target' => "topic:{$t}"], $about),
            ...array_map(fn ($e) => ['rel' => 'responds_to', 'target' => "event:{$e}"], $respondsTo),
        ];

        return array_replace(array_filter([
            'id' => $id, 'kind' => 'exposure', 'actor' => $actor ?? self::LEARNER, 'origin' => 'first_hand',
            'occurred_at' => $at, 'session' => $session, 'links' => $links,
            'body' => ['format' => $format, 'approach' => $approach, 'by' => $by],
            'content' => ['summary' => 'A short summary of what was taught.'],
        ], fn ($v) => $v !== null), $extra);
    }

    public static function question(string $id, string $at, string $session, string $text, array $about = [], ?array $actor = null): array
    {
        return [
            'id' => $id, 'kind' => 'question', 'actor' => $actor ?? self::CHATGPT, 'origin' => 'first_hand',
            'occurred_at' => $at, 'session' => $session,
            'links' => array_map(fn ($ref) => ['rel' => 'about', 'target' => $ref], $about),
            'content' => ['text' => $text],
        ];
    }

    /** A self-report about refs such as "topic:T-LEFT" or "question:Q1". */
    public static function selfReport(string $id, string $at, string $session, string $stance, array $about, ?string $triggeredBy = null, ?array $actor = null): array
    {
        $links = array_map(fn ($ref) => ['rel' => 'about', 'target' => $ref], $about);
        if ($triggeredBy !== null) {
            $links[] = ['rel' => 'triggered_by', 'target' => "event:{$triggeredBy}"];
        }

        return [
            'id' => $id, 'kind' => 'self_report', 'actor' => $actor ?? self::CHATGPT, 'origin' => 'first_hand',
            'occurred_at' => $at, 'session' => $session, 'links' => $links,
            'body' => ['stance' => $stance], 'content' => ['text' => "I feel {$stance}."],
        ];
    }

    /** A verdict on an attempt by the interpreter (judge-attempt@1). */
    public static function judgesAttempt(string $id, string $at, string $attempt, array $value, float $confidence = 0.85, array $extra = [], ?array $method = null): array
    {
        return self::claim($id, $at, 'judges', ["event:{$attempt}"], $value, $method ?? self::interpreter('judge-attempt@1'), $confidence, 'accepted', $extra);
    }

    /** Facet helpers for judges values. */
    public static function topics(array $outcomes): array
    {
        return array_map(fn ($topic, $outcome) => ['topic' => "topic:{$topic}", 'outcome' => $outcome], array_keys($outcomes), $outcomes);
    }

    public static function misconception(string $m, bool $present): array
    {
        return [['misconception' => "misconception:{$m}", 'present' => $present]];
    }

    /** A review by the learner. */
    public static function review(string $id, string $at, string $target, string $decision, string $reason = 'wrong_outcome', array $value = []): array
    {
        return self::claim($id, $at, 'reviews', [$target], ['decision' => $decision, 'reason' => $reason] + $value, self::person());
    }
}
