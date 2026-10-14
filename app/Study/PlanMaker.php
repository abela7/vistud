<?php

namespace App\Study;

use App\Platform\Access\Principal;
use Carbon\CarbonImmutable;

/**
 * Making an assignment's plan with an AI (the owner's review, 2026-10-02; the owner's ask, 2026-10-13). The AI is
 * given the rules (resources/prompts/assignment-plan.md) and everything ViStud knows about the assignment: what it
 * understood from the sources, how the student stands on the topics it draws on, what is already in the plan, what
 * else is due, and the brief they paste. Either ViStud's own AI makes it (App\Engine\Jobs\PlanAssignment) or the
 * student copies the same prompt into any AI and pastes the reply back. Either way the reply is read
 * (PlanMaker::read), checked (PlanMaker::tidy), looked over by the student, and only then kept.
 */
final class PlanMaker
{
    public const PROMPT = 'resources/prompts/assignment-plan.md';

    /** The most of a brief the prompt carries, in characters. */
    public const BRIEF_LIMIT = 12_000;

    /** The most of its written sources the prompt carries when the assignment hasn't been understood yet, in characters. */
    private const SOURCES_LIMIT = 20_000;

    /** The most other deadlines, plan items and log entries the prompt lists. */
    private const OTHER_DEADLINES = 8;

    private const LOG_ENTRIES = 6;

    public function __construct(
        private Workspaces $workspaces,
        private Activities $activities,
        private AssignmentUnderstandings $understandings,
        private AssignmentSources $sources,
        private Plans $plans,
        private Topics $topics,
        private CourseProfiles $profiles,
        private AssignmentLog $log,
    ) {}

    /** The prompt to copy: what to make and how, then everything known, then the brief. */
    public function prompt(Principal $by, string $activityId, string $brief): string
    {
        return $this->rules($by, $activityId)."\n\n".$this->material($by, $activityId, $brief);
    }

    /** What to make and how, with this assignment's course, deadline and time left filled in. */
    public function rules(Principal $by, string $activityId): string
    {
        $assignment = $this->activities->find($by, $activityId);
        $workspace = $this->workspaces->find($by, $assignment->workspaceId);
        $deadline = $assignment->dueOn === null
            ? 'none given'
            : ($assignment->dueAt()->format('l j F Y').($assignment->dueTime === null ? '' : ' at '.$assignment->dueTime));

        $now = CarbonImmutable::now($assignment->zone);
        $days = $assignment->dueOn === null ? null : (int) $now->startOfDay()->diffInDays(CarbonImmutable::parse($assignment->dueOn, $assignment->zone), false);
        $timeLeft = match (true) {
            $days === null => 'no deadline given',
            $days < 0 => 'the deadline has passed',
            $days === 0 => 'due today',
            $days < 14 => $days === 1 ? '1 day' : "{$days} days",
            default => "{$days} days (about ".round($days / 7).' weeks)',
        };

        $template = (string) file_get_contents(base_path(self::PROMPT));
        $template = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $template);
        $prompt = trim(strtr($template, [
            '{{course}}' => $workspace->name,
            '{{assignment}}' => $assignment->title,
            '{{kind}}' => strtolower($assignment->kindLabel()),
            '{{deadline}}' => $deadline,
            '{{today}}' => $now->format('l j F Y'),
            '{{time_left}}' => $timeLeft,
        ]));

        return $prompt;
    }

    /**
     * Everything the AI should weigh besides the rules, each part only when there is something to say: what ViStud
     * understood (or, before that, the written sources), where the student stands, what the plan already holds, what
     * else is due, how the course is assessed, what was noted along the way, and the brief the student pasted.
     */
    public function material(Principal $by, string $activityId, string $brief): string
    {
        $assignment = $this->activities->find($by, $activityId);
        $understanding = $this->understandings->latest($by, $activityId);
        [$known, $hasSources] = $this->known($by, $assignment, $understanding);

        $sections = array_filter([
            $known,
            $this->standing($by, $assignment, $understanding),
            $this->already($by, $activityId),
            $this->alongside($by, $assignment),
            $this->assessment($by, $assignment->workspaceId),
            $this->logged($by, $activityId),
        ]);

        $brief = trim($brief);
        if (mb_strlen($brief) > self::BRIEF_LIMIT) {
            $brief = rtrim(mb_substr($brief, 0, self::BRIEF_LIMIT))."\n\n(The rest of the brief is left out.)";
        }
        $kind = strtolower($assignment->kindLabel());
        $workspace = $this->workspaces->find($by, $assignment->workspaceId);
        $sections[] = "## The brief\n\n".match (true) {
            $brief !== '' => $brief,
            $hasSources => 'The student hasn\'t pasted a brief here: what they were given is under "What ViStud knows about it" above.',
            default => 'The student hasn\'t shared the brief. Plan it from what a '.$kind.' called "'.$assignment->title.'" in '.$workspace->name.' usually asks, keep to the basics, and say at the end that it is a general plan to check against the real brief.',
        };

        return implode("\n\n", $sections)."\n";
    }

    /**
     * What ViStud knows of the assignment: its understanding, or before there is one its written sources.
     *
     * @return array{0: ?string, 1: bool} the text, and whether there is any material at all
     */
    private function known(Principal $by, ActivityDetails $assignment, ?AssignmentUnderstanding $understanding): array
    {
        if ($understanding !== null) {
            $stale = $this->sources->outOfDate($by, $assignment->id, $understanding);
            $lines = ["## What ViStud knows about it\n"
                .'Read from everything the student was given (version '.$understanding->revision.', '
                .($understanding->confirmed() ? 'confirmed by the student' : 'not yet confirmed by the student')
                .($stale ? '; the sources have changed since, so trust the brief below where they differ' : '')
                .'). Plan from this: each point says where it came from.'];
            foreach (['summary' => 'What it is', 'goal' => 'The goal'] as $part => $label) {
                if ($understanding->text($part) !== '') {
                    $lines[] = "{$label}: {$understanding->text($part)}";
                }
            }
            $parts = [
                'deliverables' => 'What has to be handed in', 'requirements' => 'What it must do', 'constraints' => 'Limits',
                'criteria' => 'How it is marked (the marks are percentages)', 'teacher_expects' => 'What the teacher expects',
                'best_practices' => 'What good work looks like', 'dates' => 'Dates it names', 'risks' => 'Risks',
                'gaps' => 'Not clear yet', 'assumptions' => 'Assumed so far', 'questions' => 'Still to ask the teacher',
            ];
            foreach ($parts as $part => $label) {
                if ($understanding->items($part) !== []) {
                    $lines[] = "{$label}:\n".implode("\n", array_map(fn (array $item) => '- '.AssignmentUnderstanding::line($part, $item), $understanding->items($part)));
                }
            }
            foreach ($understanding->extraSections() as $section) {
                $lines[] = "{$section['title']}:\n".implode("\n", array_map(fn (array $item) => '- '.AssignmentUnderstanding::line('extra', $item), $section['items']));
            }

            return [implode("\n", $lines), true];
        }

        $written = $this->sources->list($by, $assignment->id);
        $files = $this->sources->files($by, $assignment->id);
        if ($written === [] && $files === []) {
            return [null, false];
        }
        $left = self::SOURCES_LIMIT;
        $lines = ["## What the student was given\nThe AI hasn't read these into an understanding yet, so here are the written ones as they are."];
        foreach ($written as $source) {
            $text = mb_substr($source->text, 0, max(0, $left));
            $left -= mb_strlen($text);
            $lines[] = "### {$source->kindLabel()}".($source->title !== null ? " · {$source->title}" : '')."\n".$text;
        }
        if ($files !== []) {
            $lines[] = 'Files in its folder (not read here): '.implode(', ', array_map(fn (FileDetails $file) => $file->fileName(), $files));
        }

        return [implode("\n", $lines), $written !== []];
    }

    /** Where the student is on it, the days they have, and how they stand on the topics it draws on. */
    private function standing(Principal $by, ActivityDetails $assignment, ?AssignmentUnderstanding $understanding): string
    {
        $now = CarbonImmutable::now($assignment->zone);
        $lines = [
            "## Where the student is\n"
            .'Progress: '.(['todo' => 'not started', 'doing' => 'working on it', 'done' => 'done'][$assignment->status] ?? $assignment->status)
            .'; stage: '.$assignment->stage,
        ];
        if ($assignment->dueOn !== null && ! $assignment->overdue($now)) {
            $due = CarbonImmutable::parse($assignment->dueOn, $assignment->zone);
            $days = (int) $now->startOfDay()->diffInDays($due, false) + 1;
            $weekend = self::weekendDays($now->startOfDay(), $due);
            $lines[] = "Days to work in, today to the deadline day: {$days}".($weekend > 0 ? " ({$weekend} of them weekend days)" : '').'. The student also has other things to do: don\'t plan every hour.';
        }

        $drawsOn = $understanding?->items('topics') ?? [];
        if ($drawsOn !== []) {
            $byId = [];
            foreach ($this->topics->list($by, $assignment->workspaceId) as $topic) {
                $byId[$topic->id] = $topic;
            }
            $lines[] = 'The course topics it draws on, and how the student stands on each (add a study step early for any marked "Not started" or "Not understood", and none for "Mastered" ones):';
            foreach ($drawsOn as $item) {
                $topic = $byId[$item['id'] ?? ''] ?? null;
                if ($topic !== null) {
                    $shown = $topic->shown();
                    $lines[] = '- '.($item['topic'] ?? $topic->name).': '.(TopicDetails::STATUS_WORDS[$shown] ?? $shown).(($item['why'] ?? '') !== '' ? " (why: {$item['why']})" : '');
                }
            }
        }

        return implode("\n", $lines);
    }

    /** What the plan already holds, so the AI adds what is missing and doesn't repeat or undo anything. */
    private function already(Principal $by, string $activityId): ?string
    {
        $plan = $this->plans->get($by, $activityId);
        if ($plan->empty()) {
            return null;
        }
        $mark = fn (PlanItem $item) => $item->done() ? '[x] ' : '[ ] ';
        $steps = function (?string $parent, string $indent) use ($plan, $mark, &$steps): array {
            $lines = [];
            foreach ($plan->steps($parent) as $step) {
                $lines[] = $indent.'- '.$mark($step).$step->title;
                array_push($lines, ...$steps($step->id, $indent.'  '));
            }

            return $lines;
        };
        $lines = ["## What is already in the plan\nAdd only what is missing. Don't repeat any of it, and don't change what the student has ticked."];
        foreach ($plan->parts() as $part) {
            $lines[] = '- Part: '.$part->title.($part->weight !== null ? " ({$part->weight}%)" : '');
            array_push($lines, ...$steps($part->id, '  '));
        }
        array_push($lines, ...$steps(null, ''));
        foreach ($plan->milestones() as $milestone) {
            $lines[] = '- Milestone: '.$milestone->title.($milestone->dueOn !== null ? " ({$milestone->dueOn})" : '');
        }
        foreach ($plan->criteria() as $criterion) {
            $lines[] = '- Criterion: '.$criterion->title.($criterion->weight !== null ? " ({$criterion->weight}%)" : '');
        }

        return implode("\n", $lines);
    }

    /** What else the student has due across all their courses up to a week past this deadline, to spread the work around it. */
    private function alongside(Principal $by, ActivityDetails $assignment): ?string
    {
        if ($assignment->dueOn === null) {
            return null;
        }
        $until = CarbonImmutable::parse($assignment->dueOn, $assignment->zone)->addDays(7)->toDateString();
        $found = [];
        foreach ($this->workspaces->list($by) as $workspace) {
            foreach ($this->activities->list($by, $workspace->id) as $other) {
                if ($other->id !== $assignment->id && $other->status !== 'done' && $other->dueOn !== null && ! $other->overdue() && $other->dueOn <= $until) {
                    $found[] = [$other->dueOn.' '.($other->dueTime ?? ''), '- '.$other->title.' ('.$other->kindLabel().($workspace->id !== $assignment->workspaceId ? ', '.$workspace->name : '').'): '.$other->dueWords()];
                }
            }
        }
        if ($found === []) {
            return null;
        }
        usort($found, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return "## What else is due around then\nThe student's time is shared with these: plan lighter where they pile up.\n"
            .implode("\n", array_column(array_slice($found, 0, self::OTHER_DEADLINES), 1));
    }

    /** How the whole course is assessed, so the AI knows how much this one is worth in the course. */
    private function assessment(Principal $by, string $workspaceId): ?string
    {
        $assessment = $this->profiles->get($by, $workspaceId)->assessment;
        if ($assessment === []) {
            return null;
        }

        return "## How the course is assessed\n".implode("\n", array_map(
            fn (array $item) => '- '.$item['name'].(($item['weight'] ?? null) !== null ? " ({$item['weight']} %)" : '').(($item['due_on'] ?? null) !== null ? ", due {$item['due_on']}" : ''),
            $assessment,
        ));
    }

    /** What the student and their tutor wrote down about it: decisions, advice, feedback and risks. */
    private function logged(Principal $by, string $activityId): ?string
    {
        $entries = $this->log->list($by, $activityId, self::LOG_ENTRIES);
        if ($entries === []) {
            return null;
        }

        return "## What was noted about it so far\n".implode("\n", array_map(fn (AssignmentLogEntry $entry) => "- {$entry->kindLabel()} ({$entry->stageLabel()} stage): {$entry->text}", array_reverse($entries)));
    }

    private static function weekendDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $count = 0;
        for ($day = $from; $day->lessThanOrEqualTo($to) && $count < 200; $day = $day->addDay()) {
            $count += $day->isWeekend() ? 1 : 0;
        }

        return $count;
    }

    /**
     * What an AI's plan holds once it has been checked against what is true, so a plan with a slip in it can't be
     * kept unseen: what the plan already has is left out; marks that add up to more than 100 (with what is there)
     * are dropped; a milestone date that is not between today and the deadline is cleared; milestones go for a
     * course without project tools. Each change is said in `notes`, for the student to read.
     *
     * @param  array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>, milestones: list<array{title: string, due_on: ?string}>}  $read
     * @return array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>, milestones: list<array{title: string, due_on: ?string}>, notes: list<string>}
     */
    public static function tidy(array $read, ActivityDetails $assignment, PlanDetails $plan, bool $milestones, ?CarbonImmutable $now = null): array
    {
        $notes = [];
        $today = ($now ?? CarbonImmutable::now($assignment->zone))->setTimezone($assignment->zone)->toDateString();
        $key = fn (string $title) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
        $has = function (string $kind) use ($plan, $key): array {
            return array_flip(array_map(fn (PlanItem $item) => $key($item->title), array_filter($plan->items, fn (PlanItem $item) => $item->kind === $kind)));
        };
        $left = 0;
        // Each title once, among what the plan has and what this reply has already said.
        $fresh = function (array $items, array $have, callable $titleOf) use ($key, &$left): array {
            $kept = [];
            foreach ($items as $item) {
                $title = $key($titleOf($item));
                if (isset($have[$title])) {
                    $left++;

                    continue;
                }
                $have[$title] = true;
                $kept[] = $item;
            }

            return $kept;
        };

        $parts = $fresh($read['parts'], $has('part'), fn (array $part) => $part['title']);
        $steps = $fresh($read['steps'], $has('step'), fn (string $step) => $step);
        $criteria = $fresh($read['criteria'], $has('criterion'), fn (array $criterion) => $criterion['title']);
        $dated = $fresh($read['milestones'], $has('milestone'), fn (array $milestone) => $milestone['title']);
        if ($left > 0) {
            $notes[] = ($left === 1 ? '1 thing was' : "{$left} things were").' already in your plan, so '.($left === 1 ? 'it was' : 'they were').' left out.';
        }

        // Marks are percentages of the whole: what is new and what is there must fit in 100.
        $taken = fn (string $kind) => array_sum(array_map(fn (PlanItem $item) => $item->weight ?? 0, array_filter($plan->items, fn (PlanItem $item) => $item->kind === $kind)));
        if (array_sum(array_column($parts, 'marks')) + $taken('part') > 100) {
            $parts = array_map(fn (array $part) => ['marks' => null] + $part, $parts);
            $notes[] = 'The marks on the sections added up to more than 100 with what is already there, so they were left off. Set them yourself.';
        }
        if (array_sum(array_column($criteria, 'marks')) + $taken('criterion') > 100) {
            $criteria = array_map(fn (array $criterion) => ['marks' => null] + $criterion, $criteria);
            $notes[] = 'The marks on the criteria added up to more than 100 with what is already there, so they were left off. Set them yourself.';
        }

        // A milestone is a day between today and the deadline: the hand-in itself is the deadline.
        $dated = $milestones ? $dated : [];
        $moved = 0;
        $dated = array_map(function (array $milestone) use ($assignment, $today, &$moved) {
            $date = $milestone['due_on'];
            if ($date !== null && ($date <= $today || ($assignment->dueOn !== null && $date >= $assignment->dueOn))) {
                $moved++;

                return ['due_on' => null] + $milestone;
            }

            return $milestone;
        }, $dated);
        if ($moved > 0) {
            $notes[] = ($moved === 1 ? '1 milestone date' : "{$moved} milestone dates").' fell outside the time left, so '.($moved === 1 ? 'it was' : 'they were').' left blank. Set '.($moved === 1 ? 'it' : 'them').' yourself.';
        }

        return ['parts' => $parts, 'steps' => $steps, 'criteria' => $criteria, 'milestones' => $dated, 'notes' => $notes];
    }

    /**
     * The parts, steps and criteria in an AI's reply, ready to look at. Anything that isn't a mark is ignored;
     * text is cleaned and cut to a title's length, and an item without text is left out.
     *
     * @return array{parts: list<array{title: string, marks: ?int, steps: list<string>}>, steps: list<string>, criteria: list<array{title: string, marks: ?int}>, milestones: list<array{title: string, due_on: ?string}>}
     */
    public static function read(string $reply): array
    {
        $parts = [];
        $rest = (string) preg_replace_callback('/<part\b([^>]*)>(.*?)<\/part\s*>/si', function (array $match) use (&$parts) {
            $title = self::clean(self::attributes($match[1])['title'] ?? '');
            if ($title !== '') {
                $parts[] = ['title' => $title, 'marks' => self::marks(self::attributes($match[1])['marks'] ?? null), 'steps' => self::steps($match[2])];
            }

            return '';
        }, $reply);

        $criteria = [];
        $rest = (string) preg_replace_callback('/<criterion\b([^>]*)>(.*?)<\/criterion\s*>/si', function (array $match) use (&$criteria) {
            $title = self::clean($match[2]);
            if ($title !== '') {
                $criteria[] = ['title' => $title, 'marks' => self::marks(self::attributes($match[1])['marks'] ?? null)];
            }

            return '';
        }, $rest);

        $milestones = [];
        $rest = (string) preg_replace_callback('/<milestone\b([^>]*)>(.*?)<\/milestone\s*>/si', function (array $match) use (&$milestones) {
            $title = self::clean($match[2]);
            if ($title !== '') {
                $milestones[] = ['title' => $title, 'due_on' => self::date(self::attributes($match[1])['date'] ?? null)];
            }

            return '';
        }, $rest);

        return ['parts' => $parts, 'steps' => self::steps($rest), 'criteria' => $criteria, 'milestones' => $milestones];
    }

    /** @return list<string> */
    private static function steps(string $text): array
    {
        preg_match_all('/<step\b[^>]*>(.*?)<\/step\s*>/si', $text, $found);

        return array_values(array_filter(array_map(self::clean(...), $found[1]), fn (string $step) => $step !== ''));
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($text, 0, Plans::MAX_TITLE);
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $source, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }

        return $attributes;
    }

    /** A date as Y-m-d, or null when it isn't one. */
    private static function date(?string $value): ?string
    {
        $parsed = $value === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $parsed !== false && $parsed->format('Y-m-d') === trim((string) $value) ? $parsed->format('Y-m-d') : null;
    }

    /** 40, "40" and "40%" are 40; anything else, or out of 1 to 100, is no marks. */
    private static function marks(?string $value): ?int
    {
        if ($value === null || preg_match('/^\s*(\d{1,3})\s*%?\s*$/', $value, $match) !== 1) {
            return null;
        }

        return $match[1] >= 1 && $match[1] <= 100 ? (int) $match[1] : null;
    }
}
