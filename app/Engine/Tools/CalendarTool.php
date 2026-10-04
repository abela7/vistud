<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Calendar;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** What is on the student's calendar between two days: deadlines, plan dates and milestones, study time and cards due. */
final class CalendarTool implements Tool
{
    public function __construct(private Calendar $calendar) {}

    public function name(): string
    {
        return 'calendar';
    }

    public function description(): string
    {
        return 'Everything with a day in this course between two dates (the next two weeks when none are given): deadlines, the due dates of plan tasks and milestones, the days studied, and flashcards due. Dates are YYYY-MM-DD in the student\'s own time zone.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'from' => ['type' => 'string', 'description' => 'The first day, YYYY-MM-DD (today when left out).'],
            'to' => ['type' => 'string', 'description' => 'The last day, YYYY-MM-DD (14 days after the first when left out; at most 100 days).'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $today = CarbonImmutable::now($context->zone)->startOfDay();
        $from = self::day(Lookup::text($input, 'from')) ?? $today;
        $to = self::day(Lookup::text($input, 'to')) ?? $from->addDays(14);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 99) {
            $to = $from->addDays(99);
        }
        $rows = [];
        foreach ($this->calendar->between($by, $context->workspaceId, $from->toDateString(), $to->toDateString()) as $entry) {
            $rows[] = array_filter([
                'on' => $entry->on,
                'at' => $entry->at,
                'what' => $entry->title,
                'detail' => $entry->detail,
                'kind' => $entry->source,
                'state' => $entry->late() ? 'late' : ($entry->done() ? 'done' : null),
            ]);
        }

        return $rows === [] ? "Nothing on the calendar from {$from->toDateString()} to {$to->toDateString()}." : Lookup::json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'entries' => array_slice($rows, 0, 100)]);
    }

    private static function day(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }
}
