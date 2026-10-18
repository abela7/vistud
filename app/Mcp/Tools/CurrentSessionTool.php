<?php

namespace App\Mcp\Tools;

use App\Platform\Access\Principal;
use App\Study\Fence;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * The student's open study session, if any (docs/specs/mcp-connections-plan.md §3.1): its course, module and topic by
 * id and name, its state and how long it has run. A student has at most one open session, in any course.
 */
#[Description('The student\'s open study session in ViStud, if there is one: course, module, topic, state and minutes studied, with their ids. Use it at the start of a chat to pick up where they are.')]
final class CurrentSessionTool extends McpTool
{
    protected function info(): string
    {
        return 'current_session';
    }

    protected function answer(Principal $by, array $arguments): array
    {
        $container = Container::getInstance();
        $session = $container->make(Sessions::class)->current($by);
        if ($session === null) {
            return ['No study session is open.', ['open' => false], null, null];
        }

        $course = $container->make(Workspaces::class)->find($by, $session->workspaceId);
        $module = $session->moduleId === null ? null : $container->make(Modules::class)->find($by, $session->moduleId);
        $topic = null;
        if ($session->topicId !== null) {
            foreach ($container->make(Topics::class)->list($by, $session->workspaceId) as $t) {
                if ($t->id === $session->topicId) {
                    $topic = $t;
                }
            }
        }
        $minutes = intdiv($session->studySeconds, 60);
        $data = [
            'open' => true,
            'session_id' => $session->id,
            'state' => $session->state,
            'mode' => $session->mode,
            'course_id' => $course->id,
            'course' => $course->name,
            'module_id' => $module?->id,
            'module' => $module?->title,
            'topic_id' => $topic?->id,
            'topic' => $topic?->name,
            'started_at' => $session->startedAt,
            'study_minutes' => $minutes,
        ];
        // Names are the student's own words: fenced, never loose in the text.
        $where = implode("\n", array_filter(['Course: '.$course->name, $module !== null ? 'Module: '.$module->title : null, $topic !== null ? 'Topic: '.$topic->name : null]));
        $text = "A study session is {$session->state}: {$minutes} min studied so far. Its ids are in the structured result.\n".Fence::wrap($where);

        return [$text, $data, 'session', $session->id];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
