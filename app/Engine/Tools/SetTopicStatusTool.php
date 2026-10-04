<?php

namespace App\Engine\Tools;

use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Study\Sessions;
use App\Study\Topics;

/**
 * Where the student stands on a topic, as the tutor sees it: covered, understood or confusing (docs/specs/
 * vistud-2-blueprint.md §3.8, Appendix B). With the student's setting "Let the tutor mark topics" on, it is set at
 * once, shown as the tutor's and undoable; with it off, it is only proposed, for the student to accept. The student's
 * own word on a topic always wins: the tutor can't change it, only say what it saw.
 */
final class SetTopicStatusTool implements Tool
{
    public function __construct(private Topics $topics, private Settings $settings, private Sessions $sessions) {}

    public function name(): string
    {
        return 'set_topic_status';
    }

    public function description(): string
    {
        return 'Says where the student stands on a topic: covered (you taught it), understood (they showed it) or confusing (they struggled). Use it when a topic\'s part ends, after a quiz, and when a test shows it; never from a hunch. Set at once and shown as yours when the student lets you mark topics, else only proposed; their own word always wins. Defaults to the session\'s topic.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'topic' => ['type' => 'string', 'description' => 'The topic\'s exact name; leave out for the session\'s topic.'],
            'status' => ['type' => 'string', 'enum' => ['covered', 'understood', 'confusing'], 'description' => 'Where they stand now.'],
            'reason' => ['type' => 'string', 'description' => 'Why, in one sentence, from what they said or answered.'],
        ], 'required' => ['status', 'reason'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $status = Lookup::text($input, 'status');
        $status = $status === 'confusing' ? 'confused' : $status;
        if (! in_array($status, Topics::STATUSES, true)) {
            return 'The status is covered, understood or confusing.';
        }
        $topics = $this->topics->list($by, $context->workspaceId);
        $wanted = Lookup::text($input, 'topic');
        if ($wanted === null) {
            $current = $context->sessionId === null ? null : $this->sessions->find($by, $context->sessionId)->topicId;
            $topic = $current === null ? null : collect($topics)->firstWhere('id', $current);
            if ($topic === null) {
                return 'Say which topic: the session has none yet.';
            }
        } else {
            $topic = Lookup::one($topics, $wanted, fn ($t) => $t->name, 'topic');
            if (is_string($topic)) {
                return $topic;
            }
        }
        $reason = Lookup::text($input, 'reason');
        $words = $status === 'confused' ? 'confusing' : $status;
        if ($topic->status === $status) {
            return "\"{$topic->name}\" is {$words} already.";
        }
        if ($topic->status !== null && $topic->statusBy !== 'tutor') {
            // The student's own word on it: it stays. What the tutor saw is still told to them, to take or leave.
            $context->effects->status($topic->id, $topic->name, $topic->status, $status, false, $reason);

            return "The student set \"{$topic->name}\" to ".($topic->status === 'confused' ? 'confusing' : $topic->status).' themselves, so it stays. Told them what you saw as a suggestion they can accept; say so in a line.';
        }
        if (! $this->settings->get($by)->tutorMarksTopics) {
            $context->effects->status($topic->id, $topic->name, $topic->status, $status, false, $reason);

            return "Proposed \"{$topic->name}\" as {$words}: the student has chosen to accept statuses themselves, so it shows as a suggestion they can tap. Say so in a line; don't say it is set.";
        }
        $before = $this->topics->mark($by, $topic->id, $status);
        if ($before === null) {
            return "\"{$topic->name}\" is {$words} already.";
        }
        $context->effects->status($topic->id, $topic->name, $topic->status, $status, true, $reason, $before);

        return "Marked \"{$topic->name}\" as {$words}. It shows as yours and the student can undo it. Say so in a line.";
    }
}
