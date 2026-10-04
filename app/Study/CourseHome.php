<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use Carbon\CarbonImmutable;

/**
 * Gathers what a course's home page shows (docs/specs/vistud-2-blueprint.md §3.5.1): the one Next line, how far the
 * student is (for now: the topics understood, out of all of them; Phase 4's roll-ups replace these numbers), the
 * modules with their bars, and what to pick up again. The Next rule itself is the pure App\Study\NextStep; this
 * collects its state from the services, for the student's own course only.
 */
final class CourseHome
{
    public function __construct(
        private Workspaces $workspaces,
        private Modules $modules,
        private Topics $topics,
        private Files $files,
        private Notes $notes,
        private Sessions $sessions,
        private Flashcards $flashcards,
        private Questions $questions,
        private Activities $activities,
    ) {}

    public function for(Principal $by, string $workspaceId): CourseHomeData
    {
        Guard::learner($by);
        $workspace = $this->workspaces->find($by, $workspaceId);
        $modules = $this->modules->list($by, $workspaceId);
        $topics = $this->topics->list($by, $workspaceId);
        $zone = $this->sessions->timezone($by);
        $today = CarbonImmutable::now($zone)->toDateString();
        $last = $this->sessions->list($by, $workspaceId, 1)[0] ?? null;
        $open = $this->sessions->current($by);
        $cards = $this->flashcards->counts($by, $workspaceId);

        $understood = fn (TopicDetails $topic) => in_array($topic->shown(), ['understood', 'mastered'], true);
        $materials = [];
        foreach ($this->files->list($by, $workspaceId) as $file) {
            $materials[$file->moduleId ?? ''] = ($materials[$file->moduleId ?? ''] ?? 0) + 1;
        }
        foreach ($this->notes->list($by, $workspaceId) as $note) {
            $materials[$note->moduleId ?? ''] = ($materials[$note->moduleId ?? ''] ?? 0) + 1;
        }

        $anchor = $this->anchor($modules, $last, $today);
        $states = [];
        $rows = [];
        foreach ($modules as $index => $module) {
            $own = array_values(array_filter($topics, fn (TopicDetails $topic) => $topic->moduleId === $module->id));
            $done = count(array_filter($own, $understood));
            $next = null;
            foreach ($own as $topic) {
                if (! $understood($topic)) {
                    $next = $topic;
                    break;
                }
            }
            $url = route('workspaces.modules.show', [$workspaceId, $module->id]);
            $states[] = new NextStepModule(
                id: $module->id, number: $index + 1, title: $module->title, url: $url,
                materials: $materials[$module->id] ?? 0, topics: count($own), understood: $done,
                studied: count(array_filter($own, fn (TopicDetails $topic) => $topic->shown() !== 'not_started')) > 0,
                nextTopicId: $next?->id, nextTopicName: $next?->name,
            );
            $rows[] = ['module' => $module, 'number' => $index + 1, 'topics' => count($own), 'done' => $done, 'current' => $module->id === $anchor, 'url' => $url];
        }

        $state = new NextStepState(
            today: $today,
            modules: $states,
            anchorId: $anchor,
            openSession: $open === null ? null : ['topic' => $this->topicName($topics, $open->topicId), 'url' => route('workspaces.sessions.show', [$open->workspaceId, $open->id])],
            cardsDue: $cards['due'],
            reviewUrl: route('workspaces.flashcards.review', $workspaceId),
            modulesUrl: route('workspaces.show', [$workspaceId, 'modules']).'?new=1',
            deadlines: $this->deadlines($by, $workspaceId, $zone),
        );
        $step = NextStep::of($state);

        $done = count(array_filter($topics, $understood));
        $stuck = count(array_filter($this->questions->list($by, $workspaceId), fn (QuestionDetails $question) => $question->status === 'stuck'));
        $current = null;
        foreach ($states as $module) {
            if ($module->id === $anchor) {
                $current = $module;
            }
        }

        return new CourseHomeData(
            workspace: $workspace,
            step: $step,
            modules: $rows,
            progress: ['done' => $done, 'total' => count($topics), 'percent' => $topics === [] ? 0 : (int) round($done / count($topics) * 100), 'cardsDue' => $cards['due'], 'stuck' => $stuck],
            studyTarget: ['moduleId' => $step->study ? $step->moduleId : $current?->id, 'topicId' => $step->study ? $step->topicId : $current?->nextTopicId],
            openSession: $open?->workspaceId === $workspaceId ? $open : null,
            lastSession: $last,
            topicNames: array_column(array_map(fn (TopicDetails $topic) => ['id' => $topic->id, 'name' => $topic->name], $topics), 'name', 'id'),
        );
    }

    /**
     * The module the student is in: where they last studied, else the one running today, else the first.
     *
     * @param  list<ModuleDetails>  $modules
     */
    private function anchor(array $modules, ?SessionDetails $last, string $today): ?string
    {
        foreach ($modules as $module) {
            if ($last?->moduleId === $module->id) {
                return $module->id;
            }
        }
        foreach ($modules as $module) {
            if ($module->startsOn !== null && $module->startsOn <= $today && ($module->endsOn === null || $module->endsOn >= $today)) {
                return $module->id;
            }
        }

        return $modules[0]->id ?? null;
    }

    /** @param list<TopicDetails> $topics */
    private function topicName(array $topics, ?string $id): ?string
    {
        foreach ($topics as $topic) {
            if ($topic->id === $id) {
                return $topic->name;
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
