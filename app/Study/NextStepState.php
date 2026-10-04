<?php

namespace App\Study;

/**
 * What the Next rule is decided from (docs/specs/vistud-2-blueprint.md §3.3): a course's state as plain values, so
 * `NextStep::of()` is pure and each row of the rule is a one-line test. App\Study\CourseHome gathers it.
 */
final readonly class NextStepState
{
    /**
     * @param  list<NextStepModule>  $modules  in order
     * @param  ?array{topic: ?string, url: string}  $openSession  the student's open session, when there is one
     * @param  list<array{topicId: string, topic: string, moduleId: ?string}>  $attention  topics that need another look, most urgent first
     * @param  list<array{title: string, dueOn: string, hoursLeft: int, url: string}>  $deadlines  what is still open and has a day, soonest first
     */
    public function __construct(
        /** Today in the student's time zone, Y-m-d. */
        public string $today,
        public array $modules = [],
        /** The module the student is in: where they last studied, else the one running now, else the first. */
        public ?string $anchorId = null,
        public ?array $openSession = null,
        public int $cardsDue = 0,
        public string $reviewUrl = '',
        public string $modulesUrl = '',
        public array $attention = [],
        public array $deadlines = [],
    ) {}
}
