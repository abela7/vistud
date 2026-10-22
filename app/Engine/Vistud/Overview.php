<?php

namespace App\Engine\Vistud;

use App\Engine\Context\StudentName;
use App\Engine\Settings;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\Fence;
use App\Study\Instructions;
use App\Study\Memory;
use App\Study\Rollups;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;

/**
 * ViStud AI's snapshot of the student's whole study, written fresh for every call: today and their week, what they
 * wrote about themselves and what their tutors remember, their active courses with where each stands and what is next,
 * the next deadlines, and their last sessions.
 *
 * Bounded however long they study (three years of courses, sessions and memories): at most COURSES courses (the most
 * recently studied), DEADLINES deadlines and SESSIONS sessions, with what is left out counted and the tools named that
 * fetch it. The journal is read once for all the courses, and only the shown courses are added up.
 */
final class Overview
{
    public const COURSES = 12;

    public const DEADLINES = 8;

    public const SESSIONS = 5;

    /** Days ahead a deadline is "coming up". */
    public const AHEAD = 14;

    public function __construct(
        private Workspaces $workspaces,
        private Rollups $rollups,
        private Memory $memory,
        private Activities $activities,
        private CourseProfiles $courseProfiles,
        private Instructions $instructions,
        private TutorMemories $memories,
        private Sessions $sessions,
        private Topics $topics,
        private Settings $settings,
        private Courses $courses,
    ) {}

    public function build(Principal $by): string
    {
        $scope = Guard::learner($by);
        $zone = $this->sessions->timezone($by);
        $now = CarbonImmutable::now($zone);
        $today = $now->toDateString();
        $all = $this->courses->all($by);
        $names = [];
        foreach ($all as $workspace) {
            $names[$workspace->id] = $workspace->name;
        }

        // When each course was last studied, and its minutes this week: one query each, whatever the number of sessions.
        $last = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->selectRaw('workspace_id, max(started_at) as last')->groupBy('workspace_id')->get() as $row) {
            $last[(string) $row->workspace_id] = (string) $row->last;
        }
        $week = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->where('started_at', '>=', $now->subDays(7)->utc())->selectRaw('workspace_id, sum(study_seconds) as seconds')->groupBy('workspace_id')->get() as $row) {
            $week[(string) $row->workspace_id] = (int) $row->seconds;
        }

        $sections = [
            $this->today($now, array_sum($week)),
            $this->about($by, $names),
            ...$this->courses($by, $all, $last, $week, $today, $zone),
            $this->recent($by, $names, $zone),
        ];

        $body = implode("\n\n", array_filter($sections, fn (string $s) => $s !== ''));

        return $body === '' ? '' : Fence::wrap($body);
    }

    private function today(CarbonImmutable $now, int $weekSeconds): string
    {
        return "## Today\n".$now->format('l j F Y, H:i')." ({$now->getTimezone()->getName()}). Studied in the last 7 days: ".SessionDetails::duration($weekSeconds).'.';
    }

    /** @param array<string, string> $names */
    private function about(Principal $by, array $names): string
    {
        $me = trim($this->instructions->get($by, 'me'));
        $lines = ['## About them', 'In their words: '.($me !== '' ? $me : 'nothing written yet.')];
        // Last: their first name, how to use it, and to ask who they are when no one knows (ViStud AI has the remember tool).
        $who = StudentName::lines(StudentName::of($by), $this->memories->enabled($by));
        $language = $this->settings->get($by)->language;
        if ($language !== null) {
            $lines[] = "They chose to be helped in {$language}: write in it.";
        }
        if (! $this->memories->enabled($by)) {
            $lines[] = 'They turned off remembering things about them: keep nothing with remember.';

            return implode("\n", [...$lines, ...$who]);
        }
        $everywhere = [];
        $perCourse = [];
        foreach ($this->memories->all($by) as $memory) {
            if ($memory->everywhere()) {
                $everywhere[] = '- '.$memory->text;
            } else {
                $perCourse[$memory->workspaceId] = ($perCourse[$memory->workspaceId] ?? 0) + 1;
            }
        }
        $lines[] = $everywhere === [] ? 'What their tutors remember about them for all courses: nothing yet.' : "What their tutors remember about them for all courses:\n".implode("\n", $everywhere);
        if ($perCourse !== []) {
            $lines[] = 'Remembered for one course (the memories tool lists them): '.implode(' · ', array_map(fn (string $id, int $n) => ($names[$id] ?? 'a removed course')." {$n}", array_keys($perCourse), $perCourse)).'.';
        }

        return implode("\n", [...$lines, ...$who]);
    }

    /**
     * The active courses, most recently studied first (then in the student's order), with where each stands; and the
     * deadlines of the shown ones coming up.
     *
     * @param  list<WorkspaceDetails>  $all
     * @param  array<string, string>  $last
     * @param  array<string, int>  $week
     * @return list<string>
     */
    private function courses(Principal $by, array $all, array $last, array $week, string $today, string $zone): array
    {
        $active = array_values(array_filter($all, fn (WorkspaceDetails $w) => ! $w->archived()));
        $archived = count($all) - count($active);
        if ($active === []) {
            return ["## Courses\nNo active courses".($archived > 0 ? " ({$archived} archived: the courses tool lists them)" : '').'.'];
        }
        usort($active, fn (WorkspaceDetails $a, WorkspaceDetails $b) => [$last[$b->id] ?? '', $a->position] <=> [$last[$a->id] ?? '', $b->position]);
        $shown = array_slice($active, 0, self::COURSES);
        $snapshot = $this->memory->snapshot(Guard::learner($by));
        $until = CarbonImmutable::parse($today)->addDays(self::AHEAD)->toDateString();

        $lines = ['## Courses ('.count($shown).' of '.count($active).' active, most recently studied first)'];
        $deadlines = [];
        foreach ($shown as $course) {
            $roll = $this->rollups->for($by, $course->id, $snapshot);
            $due = $this->deadlines($by, $course, $today);
            foreach ($due as $item) {
                if ($item['on'] <= $until) {
                    $deadlines[] = $item;
                }
            }
            $parts = [
                $roll->total() === 0 ? 'no topics yet' : "{$roll->done()} of {$roll->total()} topics understood ({$roll->percent()} %)",
                count($roll->attention) > 0 ? count($roll->attention).' need attention' : null,
                $roll->cards['due'] > 0 ? $roll->cards['due'].' cards due' : null,
                isset($last[$course->id]) ? 'last studied '.CarbonImmutable::parse($last[$course->id], 'UTC')->setTimezone($zone)->diffForHumans() : 'never studied in a session',
                ($week[$course->id] ?? 0) > 0 ? SessionDetails::duration($week[$course->id]).' this week' : null,
                $due !== [] ? 'next: '.$due[0]['what'].' on '.CarbonImmutable::parse($due[0]['on'])->format('j M') : null,
            ];
            $label = $course->name.(($tag = implode(', ', array_filter([$course->code, $course->term]))) !== '' ? " ({$tag})" : '');
            $lines[] = "- {$label}: ".implode(' · ', array_filter($parts));
        }
        $hidden = count($active) - count($shown);
        if ($hidden > 0 || $archived > 0) {
            $lines[] = 'Not shown: '.implode(' and ', array_filter([$hidden > 0 ? "{$hidden} more active" : null, $archived > 0 ? "{$archived} archived" : null])).'. The courses tool lists every course.';
        }

        usort($deadlines, fn (array $a, array $b) => $a['on'] <=> $b['on']);
        $coming = ['## Coming up (next '.self::AHEAD.' days, in the courses shown)'];
        foreach (array_slice($deadlines, 0, self::DEADLINES) as $item) {
            $coming[] = '- '.CarbonImmutable::parse($item['on'])->format('D j M').": {$item['what']} ({$item['course']})";
        }
        if (count($deadlines) > self::DEADLINES) {
            $coming[] = '('.(count($deadlines) - self::DEADLINES).' more: the assignments and calendar tools have them.)';
        }
        if ($deadlines === []) {
            $coming[] = 'Nothing due.';
        }

        return [implode("\n", $lines), implode("\n", $coming)];
    }

    /**
     * A course's coming deadlines, soonest first: its open assignments with a date, and the parts of its assessment
     * (an exam) with a date that aren't an assignment already.
     *
     * @return list<array{on: string, what: string, course: string}>
     */
    private function deadlines(Principal $by, WorkspaceDetails $course, string $today): array
    {
        $items = [];
        foreach ($this->activities->list($by, $course->id) as $activity) {
            if ($activity->status !== 'done' && $activity->dueOn !== null && $activity->dueOn >= $today) {
                $items[] = ['on' => $activity->dueOn, 'what' => "{$activity->title} ({$activity->kind})", 'course' => $course->name];
            }
        }
        foreach ($this->courseProfiles->get($by, $course->id)->assessment as $part) {
            $on = $part['due_on'] ?? null;
            if (is_string($on) && $on >= $today && ($part['activity_id'] ?? null) === null) {
                $items[] = ['on' => $on, 'what' => $part['name'].(($part['weight'] ?? null) !== null ? " ({$part['weight']} %)" : ''), 'course' => $course->name];
            }
        }
        usort($items, fn (array $a, array $b) => $a['on'] <=> $b['on']);

        return $items;
    }

    /** @param array<string, string> $names */
    private function recent(Principal $by, array $names, string $zone): string
    {
        $rows = LearnerTables::query(Guard::learner($by), 'study_sessions')->orderByDesc('started_at')->limit(self::SESSIONS)->get();
        if ($rows->isEmpty()) {
            return "## Last sessions\nNone yet.";
        }
        $lines = ['## Last sessions (the newest '.self::SESSIONS.'; earlier_sessions has more, by course)'];
        // Their topics' names in one look-up (a removed one is still named: it was studied).
        $topicNames = LearnerTables::query(Guard::learner($by), 'topics')->whereIn('id', $rows->pluck('topic_id')->filter()->all())->pluck('name', 'id')->all();
        foreach ($rows as $row) {
            $topic = $row->topic_id !== null && isset($topicNames[(string) $row->topic_id]) ? Topics::cleanMarkdown((string) $topicNames[(string) $row->topic_id]) : null;
            $summary = trim((string) ($row->summary ?? ''));
            $lines[] = '- '.CarbonImmutable::parse((string) $row->started_at, 'UTC')->setTimezone($zone)->format('j M').', '
                .($names[(string) $row->workspace_id] ?? 'a removed course').($topic !== null ? " · {$topic}" : '')
                .', '.SessionDetails::duration((int) $row->study_seconds)
                .($summary !== '' ? ': '.mb_strimwidth($summary, 0, 160, '…') : '');
        }

        return implode("\n", $lines);
    }
}
