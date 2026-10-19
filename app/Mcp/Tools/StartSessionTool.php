<?php

namespace App\Mcp\Tools;

use App\Engine\Context\Stack;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Unprocessable;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Container\Container;

/**
 * Starts a study session in ViStud from an AI app (docs/specs/mcp-connections-plan.md §3.3): the same session the
 * study page starts, with its clock. It answers the `session_id` every save in it passes, and the brief the tutor
 * teaches from (the course, the student, the module and the session, fenced). A student has one open session at a
 * time: when one is open it says so, with its id, and never works around it.
 */
final class StartSessionTool extends McpTool
{
    protected function info(): string
    {
        return 'start_session';
    }

    public function description(): string
    {
        return 'Starts a study session in ViStud and gives its session_id and a brief of where the student stands. Call it before teaching, quizzing or saving anything: every save passes the session_id. Give module_id to study a whole module, or topic_id for one topic. If a session is already open, it says so with its id: continue that one.';
    }

    protected function fields(): array
    {
        return [
            'topic_id' => ['type' => 'string', 'description' => 'A topic\'s id, from the topics tool, to study that one topic.'],
            'mode' => ['type' => 'string', 'enum' => Sessions::MODES, 'description' => 'module (a whole module), topic (one topic), quiz, test or free.'],
        ];
    }

    protected function requiredFields(): array
    {
        return ['course_id'];
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        $container = Container::getInstance();
        $sessions = $container->make(Sessions::class);
        $courseId = is_string($arguments['course_id'] ?? null) ? $arguments['course_id'] : '';
        $course = $container->make(Workspaces::class)->find($by, $courseId);
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : null;
        $topicId = is_string($arguments['topic_id'] ?? null) && $arguments['topic_id'] !== '' ? $arguments['topic_id'] : null;
        $moduleId = is_string($arguments['module_id'] ?? null) && $arguments['module_id'] !== '' ? $arguments['module_id'] : null;

        try {
            $session = $sessions->start($by, $course->id, $topicId, $moduleId, mode: $mode);
        } catch (Conflict $e) {
            if ($e->errorCode !== 'session_open') {
                throw $e;
            }
            $open = $sessions->current($by);
            throw new Unprocessable('session_open', $open === null
                ? 'Another session is still open. End it first.'
                : "A session is already open (session_id {$open->id}, course_id {$open->workspaceId}). Continue it with that session_id, or call end_session first.");
        }

        $brief = $container->make(Stack::class)->standing($by, $session);

        return [
            'text' => "Session started. session_id: {$session->id}. Pass it on every save in this session, and call end_session when the student is done.\n\n".$brief,
            'structured' => ['session_id' => $session->id, 'course_id' => $session->workspaceId, 'module_id' => $session->moduleId, 'topic_id' => $session->topicId, 'mode' => $session->mode],
            'type' => 'session',
            'id' => $session->id,
            'changed' => true,
        ];
    }
}
