{{--
    A workspace's pages (App\Http\Controllers\WorkspacePageController).
    The Overview is short on purpose: a greeting and Start studying, the
    student's rhythm (streak, last 7 days, cards to review), what to pick up
    again, and what's coming up. Modules, Notes & files, Flashcards and
    Progress are their own pages; the Calendar says what it will hold until
    its step arrives (docs/specs/workspaces.md §6).
--}}
@php
    use App\Study\SessionDetails;
    use App\Study\Workspaces;
    use Illuminate\Support\Carbon;

    $sections = collect(Workspaces::SECTIONS)->keyBy(0);
    [, $label, $icon] = $sections[$section];
    $upcoming = [
        'calendar' => ['Calendar', 'Lectures, labs, quizzes, exams and deadlines, on a calendar.'],
    ];
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
                        <div id="overview-menu" class="row-menu" data-menu-panel hidden>
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
        @elseif (! in_array($section, ['modules', 'notes'], true))
            {{-- Modules and Notes & files draw this themselves, with their actions beside the title (livewire/workspaces/). --}}
            <x-workspace.section-header :workspace="$workspace" :title="$label" />
        @endif

        @if ($section === 'overview')
            <livewire:workspaces.study-time :workspace-id="$workspace->id" :stats="true" />

            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-5">
                <section aria-labelledby="continue-heading" class="min-w-0 space-y-2 lg:col-span-3">
                    <h2 id="continue-heading" class="section-title">Continue</h2>
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

                <div class="min-w-0 lg:col-span-2">
                    <livewire:workspaces.tasks :workspace-id="$workspace->id" />
                </div>
            </div>

            <livewire:workspaces.instructions :workspace-id="$workspace->id" />
        @elseif (in_array($section, ['modules', 'notes'], true))
            <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$section" :key="$section" />
        @elseif ($section === 'progress')
            <livewire:workspaces.progress :workspace-id="$workspace->id" />
            <livewire:workspaces.study-time :workspace-id="$workspace->id" />
        @elseif ($section === 'flashcards')
            <livewire:workspaces.deck :workspace-id="$workspace->id" />
        @else
            <section class="empty-place">
                <x-workspace.chip :workspace="$workspace" size="lg" />
                <p class="font-semibold">{{ $label }} is coming next</p>
                <p class="max-w-md text-sm text-fg-muted">{{ $upcoming[$section][1] }}</p>
            </section>
        @endif
    </div>

    <livewire:workspaces.form :workspace-id="$workspace->id" />
</x-layouts.app>
