<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Study\Calendar;
use App\Study\Sessions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The calendar (the owner's review, 2026-10-03): a month of the student's days, with what is on each, from one
 * workspace or (no workspace) from all of them; or the same month as an agenda. Deadlines, the days of steps and
 * milestones, study time and cards to review, any of which can be hidden. Weeks start on Monday. The month and the
 * view are in the address, so the back button and a bookmark work. The service only reads.
 */
final class CalendarBoard extends Component
{
    /** The workspace, or null for every workspace. */
    #[Locked]
    public ?string $workspaceId = null;

    /** The month shown, Y-m. */
    #[Url(as: 'm')]
    public string $month = '';

    /** month or agenda. */
    #[Url(as: 'view')]
    public string $view = 'month';

    /** The kinds of thing hidden (keys of Calendar::SOURCES). */
    public array $hidden = [];

    private Calendar $calendar;

    private Sessions $sessions;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Calendar $calendar, Sessions $sessions, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        [$this->calendar, $this->sessions, $this->workspaces, $this->principals] = [$calendar, $sessions, $workspaces, $principals];
    }

    public function mount(?string $workspaceId = null): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function previous(): void
    {
        $this->month = $this->first()->subMonthNoOverflow()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = $this->first()->addMonthNoOverflow()->format('Y-m');
    }

    public function today(): void
    {
        $this->month = $this->currentDay()->format('Y-m');
    }

    public function show(string $view): void
    {
        $this->view = $view === 'agenda' ? 'agenda' : 'month';
    }

    /** Hides or shows one kind of thing. */
    public function toggle(string $source): void
    {
        if (! array_key_exists($source, Calendar::SOURCES)) {
            return;
        }
        $this->hidden = in_array($source, $this->hidden, true) ? array_values(array_diff($this->hidden, [$source])) : [...$this->hidden, $source];
    }

    public function render(): View
    {
        $by = $this->principal();
        $today = $this->currentDay();
        $first = $this->first();
        $offset = $first->dayOfWeekIso - 1;
        $weeks = (int) ceil(($offset + $first->daysInMonth) / 7);
        $start = $first->subDays($offset);
        $end = $start->addDays($weeks * 7 - 1);

        $all = $this->calendar->between($by, $this->workspaceId, $start->toDateString(), $end->toDateString());
        $counts = array_fill_keys(array_keys(Calendar::SOURCES), 0);
        $byDay = [];
        foreach ($all as $entry) {
            $counts[$entry->source]++;
            if (! in_array($entry->source, $this->hidden, true)) {
                $byDay[$entry->on][] = $entry;
            }
        }

        return view('livewire.workspaces.calendar-board', [
            'first' => $first,
            'start' => $start,
            'weeks' => $weeks,
            'today' => $today->toDateString(),
            'selected' => $first->format('Y-m') === $today->format('Y-m') ? $today->toDateString() : $first->toDateString(),
            'byDay' => $byDay,
            'counts' => $counts,
            'sources' => Calendar::SOURCES,
            'workspace' => $this->workspaceId === null ? null : $this->workspaces->find($by, $this->workspaceId),
            'global' => $this->workspaceId === null,
        ]);
    }

    /** The first day of the month shown: the one in the address if it is a real month, otherwise this month. */
    private function first(): CarbonImmutable
    {
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : false;
        if ($first === false || $first->year < 2000 || $first->year > 2100) {
            return $this->currentDay()->startOfMonth();
        }

        return $first;
    }

    /** Today, by the student's own clock. */
    private function currentDay(): CarbonImmutable
    {
        return CarbonImmutable::now($this->sessions->timezone($this->principal()))->startOfDay();
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
