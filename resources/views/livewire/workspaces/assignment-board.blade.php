{{--
    A workspace's assignments (App\Livewire\Workspaces\AssignmentBoard): cards across the whole width, the
    soonest deadline first, coloured by how near it is (late red, within two days amber, done green). A card
    is one link to the assignment's own page.
--}}
@php
    use Illuminate\Support\Str;

    $tabs = ['open' => 'To do', 'done' => 'Done'];
@endphp
<div class="space-y-5">
    <x-workspace.section-header :workspace="$workspace" title="Assignments" :count="$counts['open']" count-label="to do">
        <a href="{{ route('workspaces.assignments.create', $workspace->id) }}" class="btn btn-primary"><x-icon name="plus" class="size-4" />New assignment</a>
    </x-workspace.section-header>

    @if ($counts['open'] + $counts['done'] === 0)
        <section class="empty-place">
            <span class="item-icon ws-colour-blue size-12" aria-hidden="true"><x-icon name="clipboard-check" class="size-6" /></span>
            <p class="font-semibold">No assignments yet</p>
            <p class="max-w-md text-sm text-fg-muted">Add coursework, labs, quizzes and exams: a name, a deadline and its files, all in one place.</p>
            <a href="{{ route('workspaces.assignments.create', $workspace->id) }}" class="btn btn-primary"><x-icon name="plus" class="size-4" />New assignment</a>
        </section>
    @else
        <div class="segmented segmented-sm" role="group" aria-label="Show assignments">
            @foreach ($tabs as $key => $word)
                <button type="button" @class(['segmented-option', 'is-current' => $filter === $key]) wire:click="show('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
                    {{ $word }} <span class="tab-count">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>

        @if ($shown === [])
            <p class="text-sm text-fg-muted">{{ $filter === 'done' ? 'Nothing done yet.' : 'Nothing left to do. Well done!' }}</p>
        @else
            <ul class="question-grid" role="list" aria-label="{{ $tabs[$filter] }}">
                @foreach ($shown as $a)
                    @php
                        $colour = match (true) {
                            $a->status === 'done' => 'green',
                            $a->overdue() => 'red',
                            $a->dueAt() !== null && $a->dueAt()->lessThan(now()->addDays(2)) => 'amber',
                            default => 'blue',
                        };
                        $fileCount = $a->folderId === null ? 0 : ($filesIn[$a->folderId] ?? 0);
                    @endphp
                    <li class="question-card ws-colour-{{ $colour }}" wire:key="assignment-{{ $a->id }}">
                        <div class="question-card-head">
                            <span class="question-status">
                                <x-icon :name="match ($colour) { 'red' => 'circle-alert', 'green' => 'circle-check', default => 'clipboard-check' }" class="size-4" />{{ $a->kindLabel() }}
                            </span>
                            @if ($a->status === 'doing')
                                <span class="text-sm text-fg-muted">In progress</span>
                            @endif
                        </div>
                        <h3 class="question-card-text"><a href="{{ route('workspaces.assignments.show', [$workspace->id, $a->id]) }}" class="tile-link">{{ $a->title }}</a></h3>
                        @if ($a->timeLeft())
                            <p class="assignment-left assignment-left-sm"><x-icon :name="$a->overdue() ? 'circle-alert' : 'hourglass'" class="size-4 shrink-0" />{{ $a->timeLeft() }}</p>
                        @endif
                        <p class="question-card-meta">
                            @if ($a->dueWords())
                                <span class="question-meta-item"><x-icon name="calendar-clock" class="size-3.5 shrink-0" />{{ $a->dueWords() }}</span>
                            @endif
                            @if ($a->moduleId !== null && isset($moduleTitles[$a->moduleId]))
                                <span class="question-meta-item"><x-icon name="layers" class="size-3.5 shrink-0" />{{ Str::limit($moduleTitles[$a->moduleId], 40) }}</span>
                            @endif
                            @if ($fileCount > 0)
                                <span class="question-meta-item"><x-icon name="paperclip" class="size-3.5 shrink-0" />{{ $fileCount }} {{ Str::plural('file', $fileCount) }}</span>
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
