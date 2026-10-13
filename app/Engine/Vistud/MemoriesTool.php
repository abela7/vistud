<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Lookup;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;
use App\Study\TutorMemories;
use App\Study\TutorMemory;

/**
 * Everything the student's tutors remember about them, numbered (remember and forget_memory take the number): for all
 * courses first, then course by course. At most a few per course, so the list stays short.
 */
final class MemoriesTool implements Tool
{
    public function __construct(private TutorMemories $memories, private Courses $courses) {}

    public function name(): string
    {
        return 'memories';
    }

    public function description(): string
    {
        return 'Everything remembered about the student, numbered: for all courses, then by course, each with who wrote it (the student or a tutor) and when. remember (to replace one) and forget_memory take the number.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if (! $this->memories->enabled($by)) {
            return 'The student has turned off remembering things about them: nothing is shown or kept.';
        }

        return self::numbered($by, $this->memories, $this->courses) ?? 'Nothing is remembered about them yet.';
    }

    /** The lines as the tools number them: "1. (all courses) …". Null when there are none. */
    public static function numbered(Principal $by, TutorMemories $memories, Courses $courses): ?string
    {
        $names = [];
        foreach ($courses->all($by) as $course) {
            $names[$course->id] = $course->name;
        }
        $all = $memories->all($by);
        if ($all === []) {
            return null;
        }

        return Lookup::json(array_map(fn (TutorMemory $m, int $i) => [
            'number' => $i + 1,
            'for' => $m->everywhere() ? 'all courses' : ($names[$m->workspaceId] ?? 'a removed course'),
            'text' => $m->text,
            'by' => $m->by === 'tutor' ? 'a tutor' : 'the student',
            'on' => substr($m->updatedAt, 0, 10),
        ], $all, array_keys($all)));
    }
}
