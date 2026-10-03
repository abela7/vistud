{{--
    A workspace's pages (App\Http\Controllers\WorkspacePageController).
    The Overview is short on purpose: a greeting and Start studying, the
    student's rhythm (streak, last 7 days, cards to review in one box), what's
    coming up (the soonest first, grouped) and what to pick up again, each its own box. Modules, Notes & files, Flashcards and
    Progress are their own pages; the Calendar says what it will hold until
    its step arrives (docs/specs/workspaces.md §6).
--}}
@php
    use App\Appearance\Theme;
    use App\Study\SessionDetails;
    use App\Study\Workspaces;
    use Illuminate\Support\Carbon;

    $sections = collect(Workspaces::SECTIONS)->keyBy(0);
    [, $label, $icon] = $sections[$section];
@endphp
<x-layouts.app :title="$section === 'overview' ? $workspace->name : $label.' · '.$workspace->name" :workspace="$workspace" :section="$section">
    <div class="mx-auto max-w-6xl space-y-6">
        @if ($workspace->archived())
            <x-alert tone="warning" title="This workspace is archived">
                <button type="button" class="font-semibold underline underline-offset-2" x-data x-on:click="Livewire.dispatch('workspace-restore')">Restore it</button>
            </x-alert>
        @endif

        @if ($section === 'overview')
            <x-back :href="route('home')" to="All workspaces" />
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <x-workspace.chip :workspace="$workspace" size="lg" class="max-sm:hidden" />
                    <div class="min-w-0">
                        <p class="text-sm text-fg-muted">{{ $greeting }}</p>
                        <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $workspace->name }}</h1>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative shrink-0">
                        <button type="button" class="topbar-button" data-menu-button aria-controls="overview-menu" aria-expanded="false" title="More">
                            <x-icon name="ellipsis" class="size-5" /><span class="sr-only">More for {{ $workspace->name }}</span>
                        </button>
                        <div id="overview-menu" class="row-menu" data-menu-panel popover="manual" hidden>
                            <button type="button" class="menu-item" x-data x-on:click="$dispatch('workspace-form-open')"><x-icon name="pencil" class="size-4" />Edit workspace</button>
                            <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('instructions-open')"><x-icon name="message-square-text" class="size-4" />Instructions for the AI</button>
                            <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('study-log')"><x-icon name="history" class="size-4" />Log time</button>
                        </div>
                    </div>
                    @if ($openSession === null)
                        <x-button variant="primary" size="lg" icon="play" x-data x-on:click="Livewire.dispatch('study-start')">Start studying</x-button>
                    @else
                        <a href="{{ route('workspaces.sessions.show', [$openSession->workspaceId, $openSession->id]) }}" class="btn btn-primary btn-lg"><x-icon name="timer" class="size-4" />Back to your session</a>
                    @endif
                </div>
            </div>
        @elseif (! in_array($section, ['modules', 'notes', 'assignments'], true))
            {{-- Modules, Notes & files and Assignments draw this themselves, with their actions beside the title (livewire/workspaces/). --}}
            <x-workspace.section-header :workspace="$workspace" :title="$label" />
        @endif

        @if ($section === 'overview')
            <livewire:workspaces.study-time :workspace-id="$workspace->id" :stats="true" />

            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-5">
                <div class="min-w-0 lg:col-span-3">
                    <livewire:workspaces.tasks :workspace-id="$workspace->id" />
                </div>

                <div class="min-w-0 space-y-4 lg:col-span-2">
                <section aria-labelledby="continue-heading" class="ov-panel">
                    <div class="ov-head">
                        <span class="item-icon" aria-hidden="true"><x-icon name="history" class="size-5" /></span>
                        <h2 id="continue-heading" class="panel-title">Continue</h2>
                    </div>
                    @if ($lastSession === null && $recent === [])
                        <div class="empty-place">
                            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                            <p class="font-medium">{{ $moduleCount === 0 ? 'Add your first module, like “Week 1”' : 'Open a module to begin' }}</p>
                            <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="btn btn-secondary"><x-icon name="arrow-right" class="size-4" />Open Modules</a>
                        </div>
                    @else
                        <ul class="item-list" role="list">
                            @if ($lastSession)
                                <li class="item-row">
                                    <span class="item-icon ws-colour-green" aria-hidden="true"><x-icon :name="$lastSession->isOpen() ? 'timer' : 'history'" class="size-5" /></span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ route('workspaces.sessions.show', [$workspace->id, $lastSession->id]) }}" class="tile-link">{{ $lastSession->topicId !== null ? ($topicNames[$lastSession->topicId] ?? 'Study session') : 'Study session' }}</a>
                                        <span class="item-meta line-clamp-2">{{ implode(' · ', array_filter([
                                            $lastSession->isOpen() ? 'Studying now' : SessionDetails::duration($lastSession->studySeconds),
                                            Carbon::parse($lastSession->startedAt)->diffForHumans(),
                                            $lastSession->checkpoint,
                                        ])) }}</span>
                                    </span>
                                    <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                                </li>
                            @endif
                            @foreach ($recent as $note)
                                <li class="item-row">
                                    <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                                        <span class="item-meta">{{ implode(' · ', array_filter([$places[$note->placeKey()] ?? null, Carbon::parse($note->updatedAt)->diffForHumans()])) }}</span>
                                    </span>
                                    <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($modules !== [])
                    @php
                        // Where the course is now comes first: a module that has ended goes last (the order is kept otherwise).
                        $today = now()->toDateString();
                        $ordered = collect($modules)->sortBy(fn ($m) => $m->endsOn !== null && $m->endsOn < $today ? 1 : 0)->values();
                        $shownModules = $ordered->take(5);
                        $when = fn ($m) => $m->startsOn || $m->endsOn
                            ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M') : ''), ' –')
                            : null;
                    @endphp
                    <section aria-labelledby="modules-heading" class="ov-panel">
                        <div class="ov-head">
                            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                            <h2 id="modules-heading" class="panel-title">Modules<span class="count-pill">{{ count($modules) }}</span></h2>
                            <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="quiet-link coming-actions">All modules<x-icon name="chevron-right" class="size-4" /></a>
                        </div>
                        <ul class="item-list" role="list">
                            @foreach ($shownModules as $module)
                                @php
                                    $number = collect($modules)->search(fn ($m) => $m->id === $module->id) + 1;
                                    $colour = Theme::CATEGORIES[crc32($module->id) % count(Theme::CATEGORIES)];
                                    $topicsDone = $moduleProgress[$module->id] ?? null;
                                @endphp
                                <li class="item-row module-row ws-colour-{{ $colour }}">
                                    <span class="module-number" aria-hidden="true">{{ $number }}</span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ route('workspaces.modules.show', [$workspace->id, $module->id]) }}" class="tile-link">{{ $module->title }}</a>
                                        @if ($when($module))
                                            <span class="item-meta">{{ $when($module) }}</span>
                                        @endif
                                    </span>
                                    @if ($topicsDone)
                                        <span class="module-row-progress" title="Topics understood in {{ $module->title }}">
                                            <span class="meter" role="progressbar" aria-label="Topics understood in {{ $module->title }}" aria-valuemin="0" aria-valuemax="{{ $topicsDone['total'] }}" aria-valuenow="{{ $topicsDone['done'] }}"><span style="width: {{ round($topicsDone['done'] / $topicsDone['total'] * 100) }}%"></span></span>
                                            <span class="tabular-nums">{{ $topicsDone['done'] }}/{{ $topicsDone['total'] }}</span>
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                </div>
            </div>

            <livewire:workspaces.instructions :workspace-id="$workspace->id" />
        @elseif (in_array($section, ['modules', 'notes'], true))
            <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$section" :key="$section" />
        @elseif ($section === 'progress')
            <livewire:workspaces.progress :workspace-id="$workspace->id" />
            <livewire:workspaces.study-time :workspace-id="$workspace->id" />
        @elseif ($section === 'assignments')
            <livewire:workspaces.assignment-board :workspace-id="$workspace->id" />
        @elseif ($section === 'flashcards')
            <livewire:workspaces.deck :workspace-id="$workspace->id" />
        @elseif ($section === 'calendar')
            <livewire:workspaces.calendar-board :workspace-id="$workspace->id" />
        @endif
    </div>

    <livewire:workspaces.form :workspace-id="$workspace->id" />
</x-layouts.app>
