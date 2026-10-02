{{--
    The calendar of every workspace (App\Livewire\Workspaces\CalendarBoard with no workspace): everything with a
    day, from all the student's workspaces that are not archived. Each workspace has its own in its Calendar section.
--}}
<x-layouts.app title="Calendar">
    <div class="mx-auto max-w-6xl space-y-5">
        <x-back :href="route('home')" to="All workspaces" />
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Calendar</h1>
            <p class="max-w-2xl text-fg-muted">Deadlines, steps and milestones with a day, what you studied and the cards to review, from all your workspaces.</p>
        </div>
        <livewire:workspaces.calendar-board />
    </div>
</x-layouts.app>
