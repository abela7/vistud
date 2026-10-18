<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Lookup;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\TutorMemories;

/**
 * ViStud AI keeps a line about the student (App\Study\TutorMemories): for all courses unless a course is named, or in
 * place of a line by its number in the memories tool's list. Their tutors read it from then on; the student sees it,
 * with a tap to change or forget it.
 */
final class RememberTool implements Tool
{
    public function __construct(private TutorMemories $memories, private Courses $courses) {}

    public function name(): string
    {
        return 'remember';
    }

    public function description(): string
    {
        return 'Keeps one short line about the student, for their tutors and you from now on: something lasting that should change how they are helped (how they learn, a worry, a goal, an exam date, the time they have each week). For all courses unless you name one. Not course content. To update a line, replace it by its number in the memories list.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'note' => ['type' => 'string', 'description' => 'One short line, at most '.TutorMemories::MAX_TEXT.' characters, e.g. "Studies best in the morning, about an hour a day."'],
            'course' => ['type' => 'string', 'description' => 'A course\'s name, when it holds only there.'],
            'replaces' => ['type' => 'string', 'description' => 'The id (or in-app list number) of a line in the memories list that this one replaces.'],
        ], 'required' => ['note'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if (! $this->memories->enabled($by)) {
            return 'Not kept: the student has turned off remembering things about them. Don\'t say you will remember it.';
        }
        $note = Lookup::text($input, 'note');
        if ($note === null) {
            return 'Send the line to remember.';
        }
        $course = null;
        if (($wanted = Lookup::text($input, 'course')) !== null) {
            $course = $this->courses->find($by, $wanted, exact: $by->channel === 'mcp');
            if (is_string($course)) {
                return $course;
            }
        }
        $replaced = null;
        if (isset($input['replaces']) && trim((string) $input['replaces']) !== '') {
            $wanted = trim((string) $input['replaces']);
            $all = $this->memories->all($by);
            $replaced = collect($all)->firstWhere('id', $wanted);
            if ($replaced === null) {
                if ($by->channel === 'mcp') {
                    return ctype_digit($wanted)
                        ? 'Give the memory\'s id from the memories tool, not its list number.'
                        : "There is no memory with id \"{$wanted}\".";
                }
                if (! is_int($input['replaces']) && ! ctype_digit($wanted)) {
                    return "There is no memory with id \"{$wanted}\".";
                }
                $replaced = $all[(int) $wanted - 1] ?? null;
                if ($replaced === null) {
                    return 'There is no line '.(int) $wanted.' in the memories list (1 to '.count($all).').';
                }
            }
        }
        try {
            $kept = $this->memories->add($by, $course?->id, $note, 'tutor', replacesId: $replaced?->id);
        } catch (Unprocessable $e) {
            return 'Not kept: '.($e->details['fields']['text'][0] ?? $e->getMessage());
        }
        $context->effects->remembered($kept->id, $kept->text, $replaced?->text);
        $where = $course === null ? 'for all courses' : "for {$course->name}";

        return ($replaced !== null ? "Remembered {$where}, in place of \"{$replaced->text}\"" : "Remembered {$where}").": \"{$kept->text}\". The student sees it and can change or delete it.";
    }
}
