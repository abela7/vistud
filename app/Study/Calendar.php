<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Carbon\CarbonImmutable;

/**
 * What is on the calendar (the owner's review, 2026-10-03): everything the student has with a day, from one
 * workspace or from all of them, between two days in the student's own time zone. Four things, each from where it
 * already lives: deadlines (assignments, quizzes, exams, labs and to-dos), the steps, parts and milestones of a
 * plan that have a day, the study time of each day studied, and the cards to review (those due now are shown today).
 * Nothing here is stored: the calendar only reads.
 */
final class Calendar
{
    /** The kinds of thing on it, in the order they are shown within a day, with their names. */
    public const SOURCES = ['deadline' => 'Deadlines', 'plan' => 'Steps and milestones', 'studied' => 'Studied', 'cards' => 'Cards'];

    /** The most days a call covers: a month's grid is 42. */
    public const MAX_DAYS = 100;

    /** The icon of each kind of activity. */
    private const ICONS = ['exam' => 'graduation-cap', 'quiz' => 'circle-help', 'lab' => 'flask-conical', 'problem_set' => 'calculator', 'project' => 'layers', 'other' => 'circle-check'];

    public function __construct(
        private Activities $activities,
        private Plans $plans,
        private Workspaces $workspaces,
        private Sessions $sessions,
    ) {}

    /**
     * @param  ?string  $workspaceId  one workspace, or null for every workspace that isn't archived
     * @param  string  $from  the first day, Y-m-d
     * @param  string  $to  the last day, Y-m-d
     * @return list<CalendarEntry> by day, then by time (the whole day's things last), then by kind
     */
    public function between(Principal $by, ?string $workspaceId, string $from, string $to): array
    {
        $scope = Guard::learner($by);
        $start = self::day($from);
        $end = self::day($to);
        if ($start === null || $end === null || $end < $start || $start->diffInDays($end) >= self::MAX_DAYS) {
            Input::refuse(['range' => 'Choose up to '.self::MAX_DAYS.' days, the first before the last.']);
        }
        $workspaces = $workspaceId === null ? $this->workspaces->list($by) : [$this->workspaces->find($by, $workspaceId)];
        $zone = $this->sessions->timezone($by);
        $today = CarbonImmutable::now($zone)->toDateString();
        [$from, $to] = [$start->toDateString(), $end->toDateString()];

        $entries = [];
        foreach ($workspaces as $workspace) {
            $activities = $this->activities->list($by, $workspace->id);
            $titles = [];
            foreach ($activities as $activity) {
                $titles[$activity->id] = $activity->title;
                if ($activity->dueOn !== null && $activity->dueOn >= $from && $activity->dueOn <= $to) {
                    $entries[] = new CalendarEntry(
                        'deadline', $activity->dueOn, $activity->dueTime, $activity->title, $activity->kindLabel(),
                        self::ICONS[$activity->kind] ?? 'clipboard-check',
                        $activity->status === 'done' ? 'done' : ($activity->overdue() ? 'late' : 'open'),
                        $workspace->id, $workspace->name, $workspace->colour, $activity->id,
                    );
                }
            }
            foreach ($this->plans->plans($by, $workspace->id) as $activityId => $plan) {
                foreach ($plan->items as $item) {
                    if ($item->dueOn === null || $item->dueOn < $from || $item->dueOn > $to || ! in_array($item->kind, ['part', 'step', 'milestone'], true)) {
                        continue;
                    }
                    $state = $plan->stateOf($item);
                    $finished = $item->kind === 'milestone' ? $item->done() : $state === 'done';
                    $entries[] = new CalendarEntry(
                        'plan', $item->dueOn, null, $item->title,
                        ($item->kind === 'milestone' ? 'Milestone · ' : '').($titles[$activityId] ?? '').($state === 'stuck' ? ' · Stuck' : ''),
                        $item->kind === 'milestone' ? 'flag' : 'list-checks',
                        $finished ? 'done' : ($item->dueOn < $today ? 'late' : 'open'),
                        $workspace->id, $workspace->name, $workspace->colour, $activityId,
                    );
                }
            }
        }

        $ids = array_map(fn (WorkspaceDetails $workspace) => $workspace->id, $workspaces);
        $byId = array_combine($ids, $workspaces);
        array_push($entries, ...$this->studied($by, $scope, $byId, $zone, $from, $to), ...$this->cards($scope, $byId, $today, $from, $to));

        $order = array_flip(array_keys(self::SOURCES));
        usort($entries, fn (CalendarEntry $a, CalendarEntry $b) => [$a->on, $a->at ?? '99:99', $order[$a->source], $a->title] <=> [$b->on, $b->at ?? '99:99', $order[$b->source], $b->title]);

        return $entries;
    }

    /**
     * The study time of each day: one line for each workspace and day, with how many sessions made it.
     *
     * @param  array<string, WorkspaceDetails>  $workspaces
     * @return list<CalendarEntry>
     */
    private function studied(Principal $by, LearnerScope $scope, array $workspaces, string $zone, string $from, string $to): array
    {
        if ($workspaces === []) {
            return [];
        }
        $reach = [CarbonImmutable::parse($from, $zone)->startOfDay()->utc()->subDay(), CarbonImmutable::parse($to, $zone)->endOfDay()->utc()->addDay()];
        $days = [];
        $rows = LearnerTables::query($scope, 'study_sessions')->whereIn('workspace_id', array_keys($workspaces))
            ->where('started_at', '>=', $reach[0]->format('Y-m-d H:i:s'))->where('started_at', '<=', $reach[1]->format('Y-m-d H:i:s'))->get();
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->started_at, 'UTC')->setTimezone($zone)->toDateString();
            if ($day < $from || $day > $to) {
                continue;
            }
            $seconds = $row->state === 'ended' ? (int) $row->study_seconds : $this->sessions->find($by, $row->id)->studySeconds;
            if ($seconds <= 0) {
                continue;
            }
            $days[$row->workspace_id][$day]['seconds'] = ($days[$row->workspace_id][$day]['seconds'] ?? 0) + $seconds;
            $days[$row->workspace_id][$day]['sessions'] = ($days[$row->workspace_id][$day]['sessions'] ?? 0) + 1;
        }

        $entries = [];
        foreach ($days as $workspaceId => $perDay) {
            $workspace = $workspaces[$workspaceId];
            foreach ($perDay as $day => $total) {
                $entries[] = new CalendarEntry(
                    'studied', $day, null, 'Studied '.SessionDetails::duration($total['seconds']), $total['sessions'] === 1 ? '1 session' : $total['sessions'].' sessions',
                    'timer', 'done', $workspace->id, $workspace->name, $workspace->colour, section: 'progress',
                );
            }
        }

        return $entries;
    }

    /**
     * The cards to review on each day: those scheduled for it, and today those due now (new ones and any that
     * were due earlier), since they wait until they are reviewed.
     *
     * @param  array<string, WorkspaceDetails>  $workspaces
     * @return list<CalendarEntry>
     */
    private function cards(LearnerScope $scope, array $workspaces, string $today, string $from, string $to): array
    {
        if ($workspaces === []) {
            return [];
        }
        $counts = [];
        $rows = LearnerTables::query($scope, 'flashcards')->whereIn('workspace_id', array_keys($workspaces))->whereNull('retired_at')
            ->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '<=', $to))->get(['workspace_id', 'due_on']);
        foreach ($rows as $row) {
            $dueOn = $row->due_on === null ? null : substr((string) $row->due_on, 0, 10);
            $day = $dueOn === null || $dueOn < $today ? $today : $dueOn;
            if ($day >= $from && $day <= $to) {
                $counts[$row->workspace_id][$day] = ($counts[$row->workspace_id][$day] ?? 0) + 1;
            }
        }

        $entries = [];
        foreach ($counts as $workspaceId => $perDay) {
            $workspace = $workspaces[$workspaceId];
            foreach ($perDay as $day => $count) {
                $entries[] = new CalendarEntry(
                    'cards', $day, null, $count === 1 ? '1 card to review' : "{$count} cards to review", $day === $today ? 'Due now' : 'Due',
                    'gallery-vertical-end', 'open', $workspace->id, $workspace->name, $workspace->colour, section: 'flashcards',
                );
            }
        }

        return $entries;
    }

    private static function day(string $date): ?CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
