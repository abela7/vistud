<?php

namespace App\Study;

/**
 * One thing on the calendar (App\Study\Calendar): a deadline, a step or a milestone with a day, a day's study time,
 * or the cards to review on a day. $on is the day (Y-m-d, the student's own), $at the time on it (H:i) or null for
 * the whole day. $state is open, done or late. $activityId is the assignment it belongs to, if any; $section is
 * the workspace section it opens otherwise (progress, flashcards). $colour and $workspaceName say whose it is
 * when the calendar spans every workspace.
 */
final readonly class CalendarEntry
{
    public function __construct(
        public string $source,
        public string $on,
        public ?string $at,
        public string $title,
        public string $detail,
        public string $icon,
        public string $state,
        public string $workspaceId,
        public string $workspaceName,
        public string $colour,
        public ?string $activityId = null,
        public string $section = 'assignments',
    ) {}

    public function done(): bool
    {
        return $this->state === 'done';
    }

    public function late(): bool
    {
        return $this->state === 'late';
    }
}
