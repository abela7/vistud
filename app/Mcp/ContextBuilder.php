<?php

namespace App\Mcp;

use App\Engine\Tools\Context;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Workspaces;

/**
 * The tools' Context for a call from an AI app (docs/specs/mcp-connections-plan.md §3.1, §3.3): built from the
 * student's token and ids the app passes, every one checked to belong to the course. Names never pick a course here:
 * an app gets ids from `courses` and passes them back. Another student's id answers exactly like one that never
 * existed.
 *
 * A session, named by `session_id`, gives the course, module and folder; a `course_id` that is not the session's is
 * refused, naming both. The judge of what the tools record is the connection's company ("anthropic-connector") and
 * the version of ViStud's MCP rules: ViStud never knows which model sits behind a chat app.
 */
final class ContextBuilder
{
    public function __construct(
        private Workspaces $workspaces,
        private Modules $modules,
        private Folders $folders,
        private Sessions $sessions,
        private Activities $activities,
    ) {}

    /**
     * The Context for one tool call.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws NotFound when an id is unknown, another student's, or in another course
     */
    public function for(Principal $by, string $tool, array $arguments, ?object $connection): Context
    {
        $info = ToolInfo::of($tool);
        $needs = ToolInfo::SESSION[$tool] ?? null;
        $sessionId = self::id($arguments, 'session_id');
        $assignment = in_array($tool, ToolInfo::ASSIGNMENT, true) ? self::id($arguments, 'assignment_id') : null;

        if ($needs !== null && $sessionId !== null) {
            $session = $this->openSession($by, $sessionId);
            $courseId = self::id($arguments, 'course_id');
            if ($courseId !== null && $courseId !== $session->workspaceId) {
                $mine = $this->workspaces->find($by, $session->workspaceId);
                throw new Unprocessable('wrong_course', "That session is in the course \"{$mine->name}\" (course_id {$mine->id}). Pass that course_id, or end the session first.");
            }
            $activityId = $assignment === null ? null : $this->assignment($by, $assignment, $session->workspaceId);

            return $this->context($by, $session->workspaceId, $session->moduleId, $session->id, $session->folderId, $activityId, $connection);
        }
        if ($needs === 'required' && ! ($tool === 'write_note' && $assignment !== null)) {
            throw new Unprocessable('session_required', 'missing: session_id. Call start_session first (or current_session to find the open one), then pass its session_id.');
        }
        if (! $info['course']) {
            return $this->context($by, '', null, null, null, null, $connection);
        }

        $courseId = self::id($arguments, 'course_id') ?? throw new Unprocessable('course_required', 'missing: course_id. Call courses for the ids.');
        $course = $this->workspaces->find($by, $courseId);
        $moduleId = self::id($arguments, 'module_id');
        if ($moduleId !== null && $this->modules->find($by, $moduleId)->workspaceId !== $course->id) {
            throw new NotFound;
        }
        $folderId = self::id($arguments, 'folder_id');
        if ($folderId !== null && $this->folders->find($by, $folderId)->workspaceId !== $course->id) {
            throw new NotFound;
        }
        $activityId = $assignment === null ? null : $this->assignment($by, $assignment, $course->id);
        if (in_array($tool, ['save_to_assignment', 'log_progress'], true) && $activityId === null) {
            throw new Unprocessable('assignment_required', 'missing: assignment_id. Call assignments for the ids.');
        }

        return $this->context($by, $course->id, $moduleId, null, $folderId, $activityId, $connection);
    }

    /** A course's Context for a read: `course_id` required; `module_id` and `folder_id` optional, each in that course. */
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

    /** The student's session, open: an ended one is refused with what to do. */
    public function openSession(Principal $by, string $sessionId): SessionDetails
    {
        $session = $this->sessions->find($by, $sessionId);
        if ($session->state === 'ended') {
            throw new Conflict('session_ended', 'That session has ended. Call start_session for a new one.');
        }

        return $session;
    }

    /** Who judged what a call records, for the journal: the connection's company and the version of the MCP rules. */
    public static function judge(?object $connection): array
    {
        $provider = is_object($connection) ? (string) $connection->provider : Provider::OTHER;

        return ['model' => $provider.'-connector', 'prompt' => self::rulesVersion()];
    }

    /** "mcp-rules@3f2a9c1b": the rules an AI app was given, which the server sends on every connection. */
    public static function rulesVersion(): string
    {
        static $version = null;

        return $version ??= 'mcp-rules@'.substr(sha1((string) @file_get_contents(resource_path('prompts/mcp-rules.md'))), 0, 8);
    }

    /** @param  array<string, mixed>  $arguments */
    public static function id(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) === 1 ? $value : null;
    }

    private function assignment(Principal $by, string $id, string $courseId): string
    {
        $activity = $this->activities->find($by, $id);
        if ($activity->workspaceId !== $courseId) {
            throw new NotFound;
        }

        return $activity->id;
    }

    private function context(Principal $by, string $courseId, ?string $moduleId, ?string $sessionId, ?string $folderId, ?string $activityId, ?object $connection): Context
    {
        $judge = self::judge($connection);

        return new Context($courseId, $moduleId, $sessionId, $this->sessions->timezone($by), folderId: $folderId, model: $judge['model'], activityId: $activityId, via: 'mcp', prompt: $judge['prompt']);
    }
}
