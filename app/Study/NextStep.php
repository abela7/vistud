<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/**
 * The one line that says what to do next (docs/specs/vistud-2-blueprint.md §3.3). Pure: it takes a course's state and
 * returns one Step, never stored and never typed. The order is the rule's table, except that an open session and a
 * deadline within 24 hours always come first:
 *
 *  continue an open session · a deadline within 24 hours · add a first module · add the module's files · add the
 *  topics found in them · study the module · the topic still to do · review cards (10 or more due) · go over
 *  what needs attention · start the next module · a deadline within 3 days · all caught up
 *
 * "The module" is the one the student is in (the state's anchor). For a module's own page, `of($state, $moduleId)`
 * asks the same of that module alone: its files, topics and study, then the next module.
 */
final class NextStep
{
    /** Cards due before Review is worth a line of its own. */
    public const CARDS_DUE = 10;

    /** About how long a card takes to review, in seconds. */
    private const SECONDS_PER_CARD = 25;

    public static function of(NextStepState $state, ?string $moduleId = null): Step
    {
        $modules = $state->modules;
        $anchor = self::module($state, $moduleId ?? $state->anchorId) ?? ($modules[0] ?? null);
        $inModule = $moduleId !== null;

        if ($state->openSession !== null) {
            $topic = $state->openSession['topic'];

            return new Step('continue', $topic !== null ? "Continue the session on {$topic}" : 'Continue your session', 'Continue', $state->openSession['url']);
        }
        $soon = $inModule ? null : self::deadline($state, 24);
        if ($soon !== null) {
            return self::due($state, $soon, 'due_soon');
        }
        if ($modules === []) {
            return new Step('add_module', 'Add your first module', 'Add module', $state->modulesUrl);
        }
        if ($anchor !== null) {
            $name = "Module {$anchor->number}";
            if ($anchor->materials === 0 && $anchor->topics === 0) {
                return new Step('add_files', "Add {$name}'s files", 'Open module', $anchor->url, moduleId: $anchor->id);
            }
            if ($anchor->suggested > 0 && $anchor->topics === 0) {
                return new Step('add_topics', 'Add the topics found in '.($anchor->suggestedFrom ?? 'your files'), 'Add topics', $anchor->url, moduleId: $anchor->id);
            }
            if ($anchor->topics > 0 && ! $anchor->studied) {
                return new Step('study_new', "Study {$name}", 'Study', $anchor->url, true, $anchor->id, $anchor->nextTopicId);
            }
            if ($anchor->left() > 0 && $anchor->nextTopicName !== null) {
                $left = $anchor->left() === 1 ? '1 topic left' : "{$anchor->left()} topics left";

                return new Step('study_topic', "{$anchor->nextTopicName}: {$left} in {$name}", 'Study', $anchor->url, true, $anchor->id, $anchor->nextTopicId);
            }
        }
        if (! $inModule && $state->cardsDue >= self::CARDS_DUE) {
            $minutes = max(1, (int) round($state->cardsDue * self::SECONDS_PER_CARD / 60));

            return new Step('review', "Review {$state->cardsDue} cards ({$minutes} min)", 'Review', $state->reviewUrl);
        }
        $look = $inModule ? null : ($state->attention[0] ?? null);
        if ($look !== null) {
            $module = self::module($state, $look['moduleId']);

            return new Step('attention', "Go over {$look['topic']} again", 'Study', $module->url ?? $state->modulesUrl, true, $look['moduleId'], $look['topicId']);
        }
        if ($anchor !== null && $anchor->done()) {
            $next = self::after($state, $anchor);
            if ($next !== null) {
                return new Step('next_module', "Start Module {$next->number}", 'Open module', $next->url, moduleId: $next->id);
            }
        }
        $later = $inModule ? null : self::deadline($state, 72);
        if ($later !== null) {
            return self::due($state, $later, 'deadline');
        }

        return new Step('caught_up', $inModule ? 'This module is up to date. Review its cards or add a topic' : 'All caught up. Review cards or add a module', 'Review', $state->reviewUrl);
    }

    private static function module(NextStepState $state, ?string $id): ?NextStepModule
    {
        foreach ($state->modules as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        return null;
    }

    private static function after(NextStepState $state, NextStepModule $module): ?NextStepModule
    {
        foreach ($state->modules as $candidate) {
            if ($candidate->number === $module->number + 1) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return ?array{title: string, dueOn: string, hoursLeft: int, url: string} the soonest open deadline that is not past and comes within $hours */
    private static function deadline(NextStepState $state, int $hours): ?array
    {
        foreach ($state->deadlines as $deadline) {
            if ($deadline['hoursLeft'] >= 0 && $deadline['hoursLeft'] <= $hours) {
                return $deadline;
            }
        }

        return null;
    }

    private static function due(NextStepState $state, array $deadline, string $kind): Step
    {
        $today = CarbonImmutable::parse($state->today);
        $due = CarbonImmutable::parse($deadline['dueOn']);
        $days = (int) $today->diffInDays($due, false);
        $when = match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            $days < 7 => 'on '.$due->format('l'),
            default => 'on '.$due->format('j M'),
        };

        return new Step($kind, "{$deadline['title']} is due {$when}", 'Open', $deadline['url']);
    }
}
