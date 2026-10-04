{{--
    A course's pages (App\Http\Controllers\WorkspacePageController). The home (the Overview) says what to do next
    first (App\Study\NextStep), then how far the student is, the modules with their bars, what is coming up and what to
    pick up again (docs/specs/vistud-2-blueprint.md §3.5.1). Modules, Notes & files, Flashcards and Progress are their
    own pages.
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
            <x-alert tone="warning" title="This course is archived">
                <button type="button" class="font-semibold underline underline-offset-2" x-data x-on:click="Livewire.dispatch('workspace-restore')">Restore it</button>
            </x-alert>
        @endif

        @if ($section === 'overview')
            @php
                $step = $home->step;
                $current = collect($home->modules)->firstWhere('current', true);
                $context = $current !== null ? 'Module '.$current['number'].' of '.count($home->modules) : null;
                $startNow = fn (?string $moduleId, ?string $topicId) => "Livewire.dispatch('study-next', { moduleId: ".json_encode($moduleId).", topicId: ".json_encode($topicId)." })";
            @endphp
            <x-page :title="$workspace->name" :back-href="route('home')" back-to="All courses" :eyebrow="$workspace->subtitle() !== '' ? $workspace->subtitle() : null" :context="$context">
                <x-slot:menu>
                    <button type="button" class="menu-item" x-data x-on:click="$dispatch('workspace-form-open')"><x-icon name="pencil" class="size-4" />Edit course</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('course-setup-open', { step: 'about' })"><x-icon name="notebook-text" class="size-4" />About this course</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('course-setup-open', { step: 'learn' })"><x-icon name="lightbulb" class="size-4" />How you learn</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('instructions-open')"><x-icon name="message-square-text" class="size-4" />Instructions for the AI</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('study-start')"><x-icon name="timer" class="size-4" />Study with options</button>
                    <a href="{{ route('engine.settings') }}" class="menu-item"><x-icon name="brain" class="size-4" />AI settings</a>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('study-log')"><x-icon name="history" class="size-4" />Log time</button>
                </x-slot:menu>
                <x-slot:action>
                    @if ($openSession === null)
                        <x-button variant="primary" size="lg" icon="play" x-data x-on:click="{{ $startNow($home->studyTarget['moduleId'], $home->studyTarget['topicId']) }}">Study</x-button>
                    @else
                        <a href="{{ route('workspaces.sessions.show', [$openSession->workspaceId, $openSession->id]) }}" class="btn btn-primary btn-lg"><x-icon name="timer" class="size-4" />Back to your session</a>
                    @endif
                </x-slot:action>
            </x-page>
        @elseif (! in_array($section, ['modules', 'notes', 'assignments'], true))
            {{-- Modules, Notes & files and Assignments draw this themselves, with their actions beside the title (livewire/workspaces/). --}}
            <x-workspace.section-header :workspace="$workspace" :title="$label" />
        @endif

        @if ($section === 'overview')
            <livewire:workspaces.study-time :workspace-id="$workspace->id" />

            <section class="next-line" aria-label="Next">
                <span class="item-icon ws-colour-{{ $workspace->colour }}" aria-hidden="true"><x-icon name="arrow-right" class="size-5" /></span>
                <p class="next-line-text"><span class="font-semibold">Next:</span> {{ $step->text }}</p>
                @if ($step->study)
                    <x-button variant="secondary" icon="play" x-data x-on:click="{{ $startNow($step->moduleId, $step->topicId) }}">{{ $step->label }}</x-button>
                @else
                    <a href="{{ $step->url }}" class="btn btn-secondary">{{ $step->label }}</a>
                @endif
            </section>

            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-5">
                @if ($home->modules !== [] || $home->progress['total'] > 0 || $home->progress['cardsDue'] > 0)
                    <section aria-labelledby="progress-heading" class="ov-panel lg:col-span-2">
                        <div class="ov-head">
                            <span class="item-icon" aria-hidden="true"><x-icon name="trending-up" class="size-5" /></span>
                            <h2 id="progress-heading" class="panel-title">Progress</h2>
                            <a href="{{ route('workspaces.show', [$workspace->id, 'progress']) }}" class="quiet-link coming-actions">Open<x-icon name="chevron-right" class="size-4" /></a>
                        </div>
                        <div class="progress-summary">
                            <div class="ring" role="progressbar" aria-label="Topics understood" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $home->progress['percent'] }}">
                                <svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle class="ring-track" cx="18" cy="18" r="15.9155" /><circle class="ring-bar" cx="18" cy="18" r="15.9155" stroke-dasharray="{{ $home->progress['percent'] }} 100" /></svg>
                                <span>{{ $home->progress['percent'] }}%</span>
                            </div>
                            <ul class="progress-facts" role="list">
                                <li>{{ $home->progress['done'] }} of {{ $home->progress['total'] }} {{ $home->progress['total'] === 1 ? 'topic' : 'topics' }}</li>
                                @if ($home->progress['cardsDue'] > 0)
                                    <li><a href="{{ route('workspaces.flashcards.review', $workspace->id) }}" class="quiet-link">{{ $home->progress['cardsDue'] }} {{ $home->progress['cardsDue'] === 1 ? 'card' : 'cards' }} due</a></li>
                                @endif
                                @if ($home->progress['stuck'] > 0)
                                    <li>{{ $home->progress['stuck'] }} stuck {{ $home->progress['stuck'] === 1 ? 'question' : 'questions' }}</li>
                                @endif
                                @if ($home->progress['attention'] > 0)
                                    <li><a href="{{ route('workspaces.show', [$workspace->id, 'progress']) }}?filter=attention" class="quiet-link">{{ $home->progress['attention'] }} {{ $home->progress['attention'] === 1 ? 'topic needs' : 'topics need' }} another look</a></li>
                                @endif
                            </ul>
                        </div>
                    </section>
                @endif

                @if ($home->modules !== [])
                    @php
                        // Around where the student is: up to five modules, the current one among them.
                        $total = count($home->modules);
                        $at = collect($home->modules)->search(fn ($row) => $row['current']);
                        $from = max(0, min(($at === false ? 0 : $at) - 1, $total - 5));
                        $shown = array_slice($home->modules, $from, 5);
                        $when = fn ($m) => $m->startsOn || $m->endsOn
                            ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M') : ''), ' –')
                            : null;
                    @endphp
                    <section aria-labelledby="modules-heading" class="ov-panel lg:col-span-3">
                        <div class="ov-head">
                            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                            <h2 id="modules-heading" class="panel-title">Modules<span class="count-pill">{{ $total }}</span></h2>
                            <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="quiet-link coming-actions">All modules<x-icon name="chevron-right" class="size-4" /></a>
                        </div>
                        <ul class="item-list" role="list">
                            @foreach ($shown as $row)
                                @php
                                    $module = $row['module'];
                                    $colour = Theme::CATEGORIES[crc32($module->id) % count(Theme::CATEGORIES)];
                                @endphp
                                <li class="item-row module-row ws-colour-{{ $colour }}">
                                    <span class="module-number" aria-hidden="true">{{ $row['number'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ $row['url'] }}" class="tile-link">{{ $module->title }}@if ($row['current'])<span class="sr-only"> (where you are)</span>@endif</a>
                                        @if ($when($module))
                                            <span class="item-meta">{{ $when($module) }}</span>
                                        @endif
                                    </span>
                                    @if ($row['current'])
                                        <span class="current-dot" aria-hidden="true" title="Where you are"></span>
                                    @endif
                                    @if ($row['topics'] > 0)
                                        <span class="module-row-progress" title="Topics understood in {{ $module->title }}">
                                            <span class="meter" role="progressbar" aria-label="Topics understood in {{ $module->title }}" aria-valuemin="0" aria-valuemax="{{ $row['topics'] }}" aria-valuenow="{{ $row['done'] }}"><span style="width: {{ round($row['done'] / $row['topics'] * 100) }}%"></span></span>
                                            <span class="tabular-nums">{{ $row['done'] }}/{{ $row['topics'] }}</span>
                                            @if ($row['tested'] !== null)
                                                <span class="item-meta">tested {{ $row['tested'] }} %</span>
                                            @endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <div class="min-w-0 lg:col-span-2">
                    <livewire:workspaces.tasks :workspace-id="$workspace->id" />
                </div>

                <section aria-labelledby="continue-heading" class="ov-panel lg:col-span-3">
                    <div class="ov-head">
                        <span class="item-icon" aria-hidden="true"><x-icon name="history" class="size-5" /></span>
                        <h2 id="continue-heading" class="panel-title">Continue</h2>
                    </div>
                    @if ($lastSession === null && $recent === [])
                        <div class="empty-place">
                            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                            <p class="font-medium">Nothing to pick up yet</p>
                        </div>
                    @else
                        <ul class="item-list" role="list">
                            @if ($lastSession)
                                <li class="item-row">
                                    <span class="item-icon ws-colour-green" aria-hidden="true"><x-icon :name="$lastSession->isOpen() ? 'timer' : 'history'" class="size-5" /></span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ route('workspaces.sessions.show', [$workspace->id, $lastSession->id]) }}" class="tile-link">{{ $lastSession->topicId !== null ? ($home->topicNames[$lastSession->topicId] ?? 'Study session') : 'Study session' }}</a>
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
            </div>

            <livewire:workspaces.instructions :workspace-id="$workspace->id" />
            <livewire:workspaces.course-setup :workspace-id="$workspace->id" :open-on-load="request()->boolean('setup')" />
        @elseif (in_array($section, ['modules', 'notes'], true))
            <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$section" :key="$section" />
        @elseif ($section === 'progress')
            <livewire:workspaces.progress :workspace-id="$workspace->id" />
            <livewire:workspaces.study-time :workspace-id="$workspace->id" :stats="true" />
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
