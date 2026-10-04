<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use Carbon\CarbonImmutable;

/**
 * Gathers what a course's home page shows (docs/specs/vistud-2-blueprint.md §3.5.1): the one Next line, how far the
 * student is, the modules with their bars, and what to pick up again. The numbers are App\Study\Rollups', the same
 * ones Progress and the Modules page show; the Next rule itself is the pure App\Study\NextStep, which this feeds with
 * the course's state, for the student's own course only.
 */
final class CourseHome
{
    public function __construct(
        private Workspaces $workspaces,
        private Rollups $rollups,
        private Sessions $sessions,
        private Activities $activities,
    ) {}

    public function for(Principal $by, string $workspaceId): CourseHomeData
    {
        Guard::learner($by);
        $workspace = $this->workspaces->find($by, $workspaceId);
        $roll = $this->rollups->for($by, $workspaceId);
        $zone = $this->sessions->timezone($by);
        $open = $this->sessions->current($by);

        $states = [];
        $rows = [];
        foreach ($roll->modules as $group) {
            $next = $group->next();
            $url = route('workspaces.modules.show', [$workspaceId, $group->id()]);
            $states[] = new NextStepModule(
                id: $group->id(), number: $group->number, title: $group->title(), url: $url,
                materials: $group->materials, topics: $group->total(), understood: $group->done(), studied: $group->studied(),
                suggested: $group->suggested, suggestedFrom: $group->suggestedFrom,
                nextTopicId: $next?->id(), nextTopicName: $next?->topic->name,
            );
            $rows[] = ['module' => $group->module, 'number' => $group->number, 'topics' => $group->total(), 'done' => $group->done(), 'tested' => $group->tested, 'current' => $group->id() === $roll->currentId, 'url' => $url];
        }
        $topics = $roll->topics();

        $state = new NextStepState(
            today: $roll->today,
            modules: $states,
            anchorId: $roll->currentId,
            openSession: $open === null ? null : ['topic' => $this->topicName($topics, $open->topicId), 'url' => route('workspaces.sessions.show', [$open->workspaceId, $open->id])],
            cardsDue: $roll->cards['due'],
            reviewUrl: route('workspaces.flashcards.review', $workspaceId),
            modulesUrl: route('workspaces.show', [$workspaceId, 'modules']).'?new=1',
            attention: array_map(fn (TopicRoll $topic) => ['topicId' => $topic->id(), 'topic' => $topic->topic->name, 'moduleId' => $topic->topic->moduleId], $roll->attention),
            deadlines: $this->deadlines($by, $workspaceId, $zone),
        );
        $step = NextStep::of($state);

        $current = null;
        foreach ($states as $module) {
            if ($module->id === $roll->currentId) {
                $current = $module;
            }
        }

        return new CourseHomeData(
            workspace: $workspace,
            step: $step,
            modules: $rows,
            progress: ['done' => $roll->done(), 'total' => $roll->total(), 'percent' => $roll->percent(), 'cardsDue' => $roll->cards['due'], 'stuck' => $roll->stuck, 'attention' => count($roll->attention)],
            studyTarget: ['moduleId' => $step->study ? $step->moduleId : $current?->id, 'topicId' => $step->study ? $step->topicId : $current?->nextTopicId],
            openSession: $open?->workspaceId === $workspaceId ? $open : null,
            lastSession: $roll->last,
            topicNames: array_column(array_map(fn (TopicRoll $topic) => ['id' => $topic->id(), 'name' => $topic->topic->name], $topics), 'name', 'id'),
        );
    }

    /** @param list<TopicRoll> $topics */
    private function topicName(array $topics, ?string $id): ?string
    {
        foreach ($topics as $topic) {
            if ($topic->id() === $id) {
                return $topic->topic->name;
            }
        }

        return null;
    }

    /** @return list<array{title: string, dueOn: string, hoursLeft: int, url: string}> */
    private function deadlines(Principal $by, string $workspaceId, string $zone): array
    {
        $now = CarbonImmutable::now($zone);
        $items = [];
        foreach ($this->activities->list($by, $workspaceId) as $activity) {
            if ($activity->status === 'done' || $activity->dueOn === null || in_array($activity->kind, ['other'], true)) {
                continue;
            }
            $items[] = [
                'title' => $activity->title, 'dueOn' => $activity->dueOn,
                'hoursLeft' => (int) floor($now->diffInMinutes($activity->dueAt(), false) / 60),
                'url' => route('workspaces.assignments.show', [$workspaceId, $activity->id]),
            ];
        }
        usort($items, fn (array $a, array $b) => $a['hoursLeft'] <=> $b['hoursLeft']);

        return $items;
    }
}
