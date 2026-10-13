<?php

namespace App\Engine\Tools;

use App\Engine\Settings;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Study\TutorMemories;

/**
 * Keeps one short line about the student for the next session (App\Study\TutorMemories): what they told or showed about
 * themselves that should change how they are taught. For this course or all of them, or in place of a line the tutor's
 * instructions list by number (outdated, or to make room). Nothing is kept when the student has turned it off. The chat
 * shows what was kept, with a tap to forget it.
 */
final class RememberTool implements Tool
{
    public function __construct(private TutorMemories $memories, private Settings $settings) {}

    public function name(): string
    {
        return 'remember';
    }

    public function description(): string
    {
        return 'Keeps one short line about the student for next time: something lasting that should change how you teach them (background, a worry, a goal or exam date, how they learn, a mistake they repeat). Not course content; health, money or family only if they ask. Say what you kept. To update one, or when full, replace it by its number in your list.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'note' => ['type' => 'string', 'description' => 'One short line, at most '.TutorMemories::MAX_TEXT.' characters, about them and what it means for teaching them, e.g. "Learns best from a worked example before the theory."'],
            'all_courses' => ['type' => 'boolean', 'description' => 'True when it is about them in general, not only this course.'],
            'replaces' => ['type' => 'integer', 'description' => 'The number of a line you remember that this one replaces.'],
        ], 'required' => ['note'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if (! $this->settings->get($by)->tutorRemembers) {
            return 'Not kept: the student has turned off the tutor remembering things. Don\'t say you will remember it.';
        }
        $note = Lookup::text($input, 'note');
        if ($note === null) {
            return 'Send the line to remember.';
        }
        $replaced = null;
        if (isset($input['replaces'])) {
            $list = $this->memories->list($by, $context->workspaceId);
            $replaced = $list[(int) $input['replaces'] - 1] ?? null;
            if ($replaced === null) {
                return 'There is no line '.(int) $input['replaces'].' in what you remember (1 to '.count($list).').';
            }
        }
        try {
            $kept = $this->memories->add($by, ($input['all_courses'] ?? false) === true ? null : $context->workspaceId, $note, 'tutor', $context->sessionId, $this->topicOf($by, $context), $replaced?->id);
        } catch (Unprocessable $e) {
            return 'Not kept: '.($e->details['fields']['text'][0] ?? $e->getMessage());
        }
        $context->effects->remembered($kept->id, $kept->text, $replaced?->text);
        $where = $kept->everywhere() ? 'for all courses' : 'for this course';

        return $replaced !== null
            ? "Remembered {$where}, in place of \"{$replaced->text}\": \"{$kept->text}\". The student sees it and can change or delete it."
            : "Remembered {$where}: \"{$kept->text}\". The student sees it and can change or delete it.";
    }

    /** The topic of a topic's chat (it has no session), so the line says where it came from. */
    private function topicOf(Principal $by, Context $context): ?string
    {
        if ($context->sessionId !== null || $context->threadId === null) {
            return null;
        }
        $topic = LearnerTables::query(Guard::learner($by), 'engine_threads')->where('id', $context->threadId)->value('topic_id');

        return $topic === null ? null : (string) $topic;
    }
}
