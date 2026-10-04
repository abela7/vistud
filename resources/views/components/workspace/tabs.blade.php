{{--
    A workspace's doors as the bottom tab bar on phones (docs/specs/vistud-2-blueprint.md §3.4): Home · Modules · Cards · Ask ·
    More. Ask opens the helper's sheet (App\Livewire\Workspaces\Ask) and More a sheet of the rest: Questions, Assignments,
    Progress, Notes & files, Calendar and Settings. A rest page keeps More as the current tab.
--}}
@props(['workspace', 'section'])
@php
    $more = [
        ['Questions', 'circle-help', route('workspaces.show', [$workspace->id, 'questions']), 'questions'],
        ['Assignments', 'clipboard-check', route('workspaces.show', [$workspace->id, 'assignments']), 'assignments'],
        ['Progress', 'trending-up', route('workspaces.show', [$workspace->id, 'progress']), 'progress'],
        ['Notes & files', 'file-text', route('workspaces.show', [$workspace->id, 'notes']), 'notes'],
        ['Calendar', 'calendar', route('workspaces.show', [$workspace->id, 'calendar']), 'calendar'],
        ['Settings', 'settings', route('settings'), null],
    ];
    $inMore = in_array($section, array_filter(array_column($more, 3)), true);
@endphp
@foreach ([['overview', 'Home', 'layout-grid'], ['modules', 'Modules', 'layers'], ['flashcards', 'Cards', 'gallery-vertical-end']] as [$key, $label, $icon])
    <a href="{{ route('workspaces.show', $key === 'overview' ? $workspace->id : [$workspace->id, $key]) }}" class="tab-item" @if ($key === $section) aria-current="page" @endif>
        <x-icon :name="$icon" class="size-5" /><span>{{ $label }}</span>
    </a>
@endforeach
<button type="button" class="tab-item" aria-haspopup="dialog" aria-controls="ask-sheet" x-data
    x-on:click="document.getElementById('ask-sheet')?.showModal(); document.getElementById('ask-text')?.focus()">
    <x-icon name="sparkles" class="size-5" /><span>Ask</span>
</button>
<button type="button" class="tab-item" aria-haspopup="dialog" aria-controls="more-sheet" x-data @if ($inMore) data-current @endif
    x-on:click="document.getElementById('more-sheet')?.showModal()">
    <x-icon name="ellipsis" class="size-5" /><span>More</span>
</button>
<dialog id="more-sheet" class="modal" aria-labelledby="more-sheet-title" x-data x-on:click="$event.target === $el && $el.close()">
    <div class="modal-panel">
        <div class="modal-head">
            <h2 id="more-sheet-title" class="min-w-0 flex-1 text-lg font-semibold">More</h2>
            <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
        </div>
        <ul class="more-list px-3 pb-4" role="list">
            @foreach ($more as [$label, $icon, $url, $key])
                <li><a href="{{ $url }}" class="menu-item" @if ($key !== null && $key === $section) aria-current="page" @endif><x-icon :name="$icon" class="size-5" />{{ $label }}</a></li>
            @endforeach
        </ul>
    </div>
</dialog>
