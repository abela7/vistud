{{--
    The calendar of every workspace (App\Livewire\Workspaces\CalendarBoard with no workspace): everything with a
    day, from all the student's workspaces that are not archived. Each workspace has its own in its Calendar section.
--}}
<x-layouts.app title="Calendar">
    <div class="mx-auto max-w-7xl space-y-4">
        <x-back :href="route('home')" to="All courses" />
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Calendar</h1>
            <p class="max-w-2xl text-sm text-fg-muted">Deadlines, steps and milestones with a day, what you studied and the cards to review, from all your courses.</p>
        </div>
        <livewire:workspaces.calendar-board />
    </div>
</x-layouts.app>
