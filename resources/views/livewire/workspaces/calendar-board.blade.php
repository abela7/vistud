{{--
    The calendar (App\Livewire\Workspaces\CalendarBoard): a month of days, each with what is on it, and what is on
    the day picked (picking is in the browser, nothing is fetched); or the month as an agenda. The month fits the
    window: its rows share the height left below the toolbar, and each day shows as many chips as its row holds
    (then "+n more", or dots when it is small, as on a phone). On a wide screen the day picked is listed beside the
    month, otherwise under it. Deadlines, steps and milestones, study time and cards, any of which can be hidden.
    Weeks start on Monday.
--}}
@php
    use App\Study\Calendar;

    $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $icons = ['deadline' => 'clipboard-check', 'plan' => 'list-checks', 'studied' => 'timer', 'cards' => 'gallery-vertical-end'];
    $colourOf = fn ($entry) => $entry->late() ? 'red' : ($entry->done() ? 'green' : ['deadline' => 'blue', 'plan' => 'teal', 'cards' => 'purple', 'studied' => 'green'][$entry->source]);
    $monthKey = $first->format('Y-m');
    $agenda = array_filter($byDay, fn ($entries, $day) => str_starts_with($day, $monthKey), ARRAY_FILTER_USE_BOTH);
@endphp
<div class="space-y-4">
    <div class="cal-toolbar">
        <div class="cal-nav">
            <button type="button" class="topbar-button" wire:click="previous" title="Previous month"><x-icon name="chevron-left" class="size-5" /><span class="sr-only">Previous month</span></button>
            <h2 class="cal-title" aria-live="polite">{{ $first->format('F Y') }}</h2>
            <button type="button" class="topbar-button" wire:click="next" title="Next month"><x-icon name="chevron-right" class="size-5" /><span class="sr-only">Next month</span></button>
            <button type="button" class="btn btn-secondary btn-sm" wire:click="today"><x-icon name="calendar" class="size-4" />Today</button>
        </div>
        <div class="cal-filters" role="group" aria-label="What to show">
            @foreach ($sources as $key => $word)
                <button type="button" class="cal-filter" wire:click="toggle('{{ $key }}')" aria-pressed="{{ in_array($key, $hidden, true) ? 'false' : 'true' }}">
                    <x-icon :name="$icons[$key]" class="size-4" />{{ $word }}<span class="tab-count">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <div class="segmented segmented-sm" role="group" aria-label="How to show it">
            <button type="button" @class(['segmented-option', 'is-current' => $view === 'month']) wire:click="show('month')" aria-pressed="{{ $view === 'month' ? 'true' : 'false' }}">Month</button>
            <button type="button" @class(['segmented-option', 'is-current' => $view === 'agenda']) wire:click="show('agenda')" aria-pressed="{{ $view === 'agenda' ? 'true' : 'false' }}">Agenda</button>
        </div>
    </div>


    @if ($view === 'agenda')
        @if ($agenda === [])
            <p class="text-sm text-fg-muted">Nothing in {{ $first->format('F') }}{{ $hidden !== [] ? ' of what is shown' : '' }}.</p>
        @else
            <div class="space-y-4">
                @foreach ($agenda as $day => $entries)
                    @php $date = \Carbon\CarbonImmutable::parse($day); @endphp
                    <section wire:key="agenda-{{ $day }}" class="space-y-2" aria-labelledby="agenda-{{ $day }}">
                        <h3 id="agenda-{{ $day }}" class="cal-agenda-heading">{{ $date->format('l j F') }}@if ($day === $today) <span class="cal-today-pill">Today</span>@endif</h3>
                        <ul class="item-list" role="list">
                            @foreach ($entries as $entry)
                                @include('livewire.workspaces.partials.calendar-entry', ['entry' => $entry, 'global' => $global])
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    @else
        <div class="cal-layout" style="--cal-weeks: {{ $weeks }}" wire:key="cal-{{ $monthKey }}"
            x-data="{
                day: '{{ $selected }}',
                // The month takes the height left in the window below where it starts (not on a phone, whose days are dots in short rows).
                fit() {
                    if (! window.matchMedia('(min-width: 40rem)').matches) { this.$el.style.removeProperty('--cal-h'); return; }
                    const tabs = document.querySelector('.app-tabbar');
                    const tabsHeight = tabs && getComputedStyle(tabs).position === 'fixed' ? tabs.offsetHeight : 0;
                    const top = this.$el.getBoundingClientRect().top + window.scrollY;
                    this.$el.style.setProperty('--cal-h', Math.max(288, Math.floor(window.innerHeight - top - tabsHeight - 16)) + 'px');
                },
            }"
            x-init="$nextTick(() => fit())" x-on:resize.window.debounce.100ms="fit()" x-on:orientationchange.window.debounce.200ms="fit()">
            <table class="cal-grid">
                <caption class="sr-only">{{ $first->format('F Y') }}. Choose a day to see what is on it.</caption>
                <thead>
                    <tr>
                        @foreach ($weekdays as $name)
                            <th scope="col"><span aria-hidden="true">{{ substr($name, 0, 3) }}</span><span class="sr-only">{{ $name }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @for ($week = 0; $week < $weeks; $week++)
                        <tr>
                            @for ($weekday = 0; $weekday < 7; $weekday++)
                                @php
                                    $date = $start->addDays($week * 7 + $weekday);
                                    $key = $date->toDateString();
                                    $entries = $byDay[$key] ?? [];
                                @endphp
                                <td @class(['cal-cell', 'is-outside' => $date->format('Y-m') !== $monthKey])>
                                    <button type="button" @class(['cal-day', 'is-today' => $key === $today]) x-on:click="day = '{{ $key }}'"
                                        aria-pressed="{{ $key === $selected ? 'true' : 'false' }}" x-bind:aria-pressed="(day === '{{ $key }}').toString()" aria-controls="cal-panel"
                                        @if ($key === $today) aria-current="date" @endif>
                                        <span class="sr-only">{{ $date->format('l j F') }}, {{ count($entries) === 0 ? 'nothing' : (count($entries) === 1 ? '1 thing' : count($entries).' things') }}</span>
                                        <span class="cal-in">
                                            <span class="cal-num">{{ $date->day }}</span>
                                            {{-- As many chips as the day's row holds (CSS, by its height), then how many more; the rest is dots. --}}
                                            <span class="cal-items" aria-hidden="true">
                                                @foreach (array_slice($entries, 0, 3) as $entry)
                                                    <span @class(['cal-chip', 'c'.($loop->iteration), 'ws-colour-'.$colourOf($entry), 'is-done' => $entry->done()])><x-icon :name="$entry->icon" class="size-3" /><span class="cal-chip-text">{{ $entry->title }}</span></span>
                                                @endforeach
                                                @foreach ([1, 2, 3] as $shown)
                                                    @if (count($entries) > $shown)
                                                        <span class="cal-more m{{ $shown }}">+{{ count($entries) - $shown }} more</span>
                                                    @endif
                                                @endforeach
                                            </span>
                                            @if ($entries !== [])
                                                <span class="cal-dots" aria-hidden="true">
                                                    @foreach (array_slice($entries, 0, 4) as $entry)
                                                        <span class="cal-dot ws-colour-{{ $colourOf($entry) }}"></span>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </span>
                                    </button>
                                </td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
            </table>

            <div id="cal-panel" class="cal-panel cal-side" aria-live="polite">
                @for ($i = 0; $i < $weeks * 7; $i++)
                    @php
                        $date = $start->addDays($i);
                        $key = $date->toDateString();
                        $entries = $byDay[$key] ?? [];
                    @endphp
                    <section x-show="day === '{{ $key }}'" @if ($key !== $selected) x-cloak @endif class="space-y-2" aria-label="{{ $date->format('l j F') }}">
                        <h3 class="cal-agenda-heading">{{ $date->format('l j F') }}@if ($key === $today) <span class="cal-today-pill">Today</span>@endif</h3>
                        @if ($entries === [])
                            <p class="text-sm text-fg-muted">Nothing on this day.</p>
                        @else
                            <ul class="item-list" role="list">
                                @foreach ($entries as $entry)
                                    @include('livewire.workspaces.partials.calendar-entry', ['entry' => $entry, 'global' => $global])
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endfor
            </div>
        </div>
    @endif
</div>
