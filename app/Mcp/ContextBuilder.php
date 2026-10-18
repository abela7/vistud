<?php

namespace App\Mcp;

use App\Engine\Tools\Context;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Workspaces;

/**
 * The tools' Context for a call from an AI app (docs/specs/mcp-connections-plan.md §3.1): built from the student's
 * token and ids the app passes, every one checked to belong to the course. Names never pick a course here: an app
 * gets ids from `courses` and passes them back. Another student's id answers exactly like one that never existed.
 */
final class ContextBuilder
{
    public function __construct(
        private Workspaces $workspaces,
        private Modules $modules,
        private Folders $folders,
        private Sessions $sessions,
    ) {}

    /**
     * A course's Context: `course_id` required; `module_id` and `folder_id` optional, each in that course.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws NotFound when an id is unknown, another student's, or in another course
     */
    public function forCourse(Principal $by, array $arguments): Context
    {
        $courseId = self::id($arguments, 'course_id') ?? throw new NotFound;
        $course = $this->workspaces->find($by, $courseId);

        $moduleId = self::id($arguments, 'module_id');
        if ($moduleId !== null && $this->modules->find($by, $moduleId)->workspaceId !== $course->id) {
            throw new NotFound;
        }
        $folderId = self::id($arguments, 'folder_id');
        if ($folderId !== null && $this->folders->find($by, $folderId)->workspaceId !== $course->id) {
            throw new NotFound;
        }

        return new Context($course->id, $moduleId, null, $this->sessions->timezone($by), folderId: $folderId, via: 'mcp');
    }

    /** A Context with no course, for the tools that work across courses (as ViStud AI's do). */
    public function acrossCourses(Principal $by): Context
    {
        return new Context('', null, null, $this->sessions->timezone($by), via: 'mcp');
    }

    /** @param  array<string, mixed>  $arguments */
    public static function id(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) === 1 ? $value : null;
    }
}
