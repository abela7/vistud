{{--
    A workspace's pages (App\Http\Controllers\WorkspacePageController).
    The Overview ("where am I": topics by status, what's confusing, open
    questions, assignments and tasks, notes to pick up, instructions),
    Modules, Notes & files and Progress are built; the Calendar says what it
    will hold until its step arrives (docs/specs/workspaces.md §6).
--}}
@php
    use App\Study\Workspaces;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $sections = collect(Workspaces::SECTIONS)->keyBy(0);
    [, $label, $icon] = $sections[$section];
    $date = fn (?string $d) => $d ? Carbon::parse($d)->format('j M Y') : null;
    $upcoming = [
        'calendar' => ['Calendar', 'Lectures, labs, quizzes, exams and deadlines for this subject, on a calendar and in "Coming up".'],
    ];
@endphp
<x-layouts.app :title="$section === 'overview' ? $workspace->name : $label.' · '.$workspace->name" :workspace="$workspace" :section="$section">
    <div class="mx-auto max-w-7xl space-y-6">
        @if (session('workspace-notice'))
            <x-alert tone="success">{{ session('workspace-notice') }}</x-alert>
        @endif
        @if ($workspace->archived())
            <x-alert tone="warning" title="This workspace is archived">
                It's hidden from your workspaces, and nothing in it is deleted.
                <button type="button" class="font-semibold underline underline-offset-2" x-data x-on:click="Livewire.dispatch('workspace-restore')">Restore it</button>
            </x-alert>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <x-workspace.chip :workspace="$workspace" size="lg" class="max-sm:hidden" />
                <div class="min-w-0">
                    @if ($section === 'overview')
                        <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $workspace->name }}</h1>
                        @if ($workspace->subtitle() !== '')
                            <p class="text-fg-muted">{{ $workspace->subtitle() }}</p>
                        @endif
                    @else
                        <p class="text-sm break-words text-fg-muted">{{ $workspace->name }}</p>
                        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $label }}</h1>
                    @endif
                </div>
            </div>
            @if ($section === 'overview')
                <x-button icon="pencil" x-data x-on:click="$dispatch('workspace-form-open')">Edit</x-button>
            @endif
        </div>

        @if ($section === 'overview')
            @php
                $statusWords = ['not_started' => 'not started', 'covered' => 'covered', 'understood' => 'understood', 'confused' => 'confused', 'mastered' => 'mastered'];
                $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
            @endphp
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <section aria-labelledby="where-heading" class="overview-card space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="where-heading" class="font-semibold">Where you are</h2>
                            <a href="{{ route('workspaces.show', [$workspace->id, 'progress']) }}" class="item-link text-sm">Open Progress</a>
                        </div>
                        @if ($topicCount === 0)
                            <p class="text-sm text-fg-muted">No topics yet. List the course's topics in Progress, like “Joins” or “Normalisation”, then mark each one covered, understood or confusing as you go.</p>
                        @else
                            <ul class="flex flex-wrap gap-2" role="list" aria-label="Topics by status">
                                @foreach ($counts as $status => $count)
                                    <li @class(['status-count', "status-{$status}"])><x-icon :name="$statusIcons[$status]" class="size-4" />{{ $count }} {{ $statusWords[$status] }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($confused !== [] || $openQuestions !== [])
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if ($confused !== [])
                                    <div class="space-y-1.5">
                                        <h3 class="text-sm font-semibold">Still confusing</h3>
                                        <ul class="space-y-1 text-sm" role="list">
                                            @foreach (array_slice($confused, 0, 5) as $topic)
                                                <li class="flex items-start gap-2"><x-icon name="circle-alert" class="mt-0.5 size-4 shrink-0 text-warning" /><span class="break-words">{{ $topic->name }}</span></li>
                                            @endforeach
                                        </ul>
                                        @if (count($confused) > 5)
                                            <p class="text-sm text-fg-muted">and {{ count($confused) - 5 }} more</p>
                                        @endif
                                    </div>
                                @endif
                                @if ($openQuestions !== [])
                                    <div class="space-y-1.5">
                                        <h3 class="text-sm font-semibold">Open questions <span class="font-normal text-fg-muted">({{ count($openQuestions) }})</span></h3>
                                        <ul class="space-y-1 text-sm" role="list">
                                            @foreach (array_slice($openQuestions, 0, 3) as $question)
                                                <li class="flex items-start gap-2">
                                                    <x-icon name="circle-help" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                                                    <span class="min-w-0 break-words">{{ Str::limit($question->text, 120) }}@if ($question->askTeacher) <span class="text-fg-muted">· for the teacher</span>@endif</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if (count($openQuestions) > 3)
                                            <p class="text-sm text-fg-muted">and {{ count($openQuestions) - 3 }} more</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>

                    <livewire:workspaces.tasks :workspace-id="$workspace->id" />

                    <livewire:workspaces.instructions :workspace-id="$workspace->id" />
                </div>

                <div class="min-w-0 space-y-4">
                    @if ($recent !== [])
                        <section aria-labelledby="continue-heading" class="overview-card space-y-2">
                            <h2 id="continue-heading" class="font-semibold">Pick up where you left off</h2>
                            <ul class="space-y-2" role="list">
                                @foreach ($recent as $note)
                                    <li class="flex items-start gap-2">
                                        <x-icon name="file-text" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                                        <span class="min-w-0">
                                            <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="item-link">{{ $note->displayTitle() }}</a>
                                            <span class="block text-sm text-fg-muted">edited {{ Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    <section class="overview-card space-y-3">
                        <h2 class="font-semibold">About</h2>
                        @php $about = array_filter(['Course code' => $workspace->code, 'Term' => $workspace->term, 'Starts' => $date($workspace->startsOn), 'Ends' => $date($workspace->endsOn)]); @endphp
                        @if ($about !== [])
                            <dl class="grid grid-cols-2 gap-3">
                                @foreach ($about as $term => $value)
                                    <div class="min-w-0">
                                        <dt class="text-sm text-fg-muted">{{ $term }}</dt>
                                        <dd class="font-medium break-words">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <p class="text-sm text-fg-muted">Add a course code, term or dates with <strong class="font-semibold text-fg">Edit</strong>.</p>
                        @endif
                    </section>

                    <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="overview-card flex items-start gap-3 text-fg no-underline hover:border-border-strong">
                        <span class="avatar shrink-0"><x-icon name="layers" class="size-4" /></span>
                        <span class="min-w-0 space-y-1">
                            <span class="block font-semibold">Modules</span>
                            @if ($modules === [])
                                <span class="block text-sm text-fg-muted">Split {{ $workspace->name }} into units, like “Week 1: Cells”, and keep each unit's folders together.</span>
                                <span class="block text-sm font-medium text-accent-contrast">Add the first module</span>
                            @else
                                @foreach (array_slice($modules, 0, 4) as $module)
                                    <span class="block text-sm break-words">{{ $module->title }}</span>
                                @endforeach
                                @if (count($modules) > 4)
                                    <span class="block text-sm text-fg-muted">and {{ count($modules) - 4 }} more</span>
                                @endif
                            @endif
                        </span>
                    </a>

                    @foreach ($upcoming as $key => [$title, $about])
                        <a href="{{ route('workspaces.show', [$workspace->id, $key]) }}" class="overview-card flex items-start gap-3 text-fg no-underline hover:border-border-strong">
                            <span class="avatar shrink-0"><x-icon :name="$sections[$key][2]" class="size-4" /></span>
                            <span class="space-y-1">
                                <span class="block font-semibold">{{ $title }}</span>
                                <span class="block text-sm text-fg-muted">{{ $about }}</span>
                                <span class="block text-sm font-medium text-fg-subtle">Coming next</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @elseif (in_array($section, ['modules', 'notes'], true))
            <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$section" :key="$section" />
        @elseif ($section === 'progress')
            <livewire:workspaces.progress :workspace-id="$workspace->id" />
        @else
            <section class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
                <x-workspace.chip :workspace="$workspace" size="lg" />
                <h2 class="text-lg font-semibold">{{ $label }} is coming next</h2>
                <p class="max-w-md text-fg-muted">{{ $upcoming[$section][1] }}</p>
            </section>
        @endif
    </div>

    <livewire:workspaces.form :workspace-id="$workspace->id" />
</x-layouts.app>
