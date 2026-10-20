<?php

namespace App\Engine\Tools;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;

/**
 * The student's yes before a tool does what can't be undone (deleting a topic, renaming the course, a module or a
 * folder). The model's word is not enough: words it read in a note or a file could have asked for it. So the first
 * call only asks, and is kept with the tool's message (Effects::asked); the same change runs only on a call made
 * after the student's next message, the one that answers that ask. A call outside a chat (no thread) never runs.
 */
final class Agreement
{
    /**
     * Null when the student has agreed to `$change` (what it is, e.g. "delete_topic:{id}"); else the words that tell
     * the model to ask first, with the ask kept for the student's answer.
     */
    public static function needed(Principal $by, Context $context, string $change, string $question): ?string
    {
        if ($context->threadId === null) {
            return 'This can only be done in a study session\'s chat, once the student has agreed to it.';
        }
        if (self::agreed($by, $context->threadId, $change)) {
            return null;
        }
        $context->effects->asked($change);

        return "Not done yet: this can't be undone, so the student must agree first. Ask them, in one line: \"{$question}\" "
            .'Call this tool again with the same input only after they answer yes in their next message; if they say no or anything else, leave it.';
    }

    /** Whether the ask for `$change` was made in the turn just before the student's latest message. */
    private static function agreed(Principal $by, string $threadId, string $change): bool
    {
        $users = LearnerTables::query(Guard::learner($by), 'engine_messages')->where('thread_id', $threadId)->where('role', 'user')
            ->orderByDesc('position')->limit(2)->pluck('position')->all();
        if ($users === []) {
            return false;
        }
        $asks = LearnerTables::query(Guard::learner($by), 'engine_messages')->where('thread_id', $threadId)->where('role', 'tool')
            ->where('position', '<', $users[0])->where('position', '>', $users[1] ?? 0)->pluck('effects')->filter(fn ($effects) => $effects !== null);
        foreach ($asks as $effects) {
            $asked = json_decode((string) $effects, true)['asked'] ?? [];
            if (is_array($asked) && in_array($change, $asked, true)) {
                return true;
            }
        }

        return false;
    }
}
