{{--
    A module's or a folder's own page (App\Livewire\Workspaces\Contents,
    view `module` or `folder`): the path to it, its folders, its notes,
    files and links, and for a module a link to its questions (their own
    page) and its study sessions.
--}}
@php
    use App\Study\Folders;
    use App\Study\SessionDetails;
    use Illuminate\Support\Carbon;

    $isModule = $view === 'module';
    $placeType = $view;
    $dates = $isModule && ($place->startsOn || $place->endsOn)
        ? trim(($place->startsOn ? Carbon::parse($place->startsOn)->format('j M') : '').' – '.($place->endsOn ? Carbon::parse($place->endsOn)->format('j M') : ''), ' –')
        : null;
@endphp
<div class="space-y-6">
    <div class="space-y-3">
        <nav aria-label="Path" class="crumbs">
            <ol role="list">
                @foreach ($trail as [$label, $url])
                    <li><a href="{{ $url }}">{{ $label }}</a></li>
                @endforeach
            </ol>
        </nav>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                @if ($isModule)
                    <span class="place-badge ws-colour-{{ \App\Appearance\Theme::CATEGORIES[crc32($place->id) % count(\App\Appearance\Theme::CATEGORIES)] }}" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                @else
                    <span class="place-badge ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                @endif
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $placeName }}</h1>
                    @if ($dates)
                        <p class="text-sm text-fg-muted">{{ $dates }}</p>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('livewire.workspaces.partials.new-menu', ['placeId' => $place->id, 'folders' => $isModule || $place->depth < Folders::MAX_DEPTH, 'questionUrl' => $isModule ? route('workspaces.modules.questions', [$workspaceId, $place->id, 'ask' => 1]) : null])
                @if ($isModule)
                    <a href="{{ route('workspaces.modules.questions', [$workspaceId, $place->id]) }}" class="btn btn-secondary">
                        <x-icon name="circle-help" class="size-4" />Questions
                        @if ($questions['open'] > 0)
                            <span class="tab-count">{{ $questions['open'] }}<span class="sr-only"> open{{ $questions['stuck'] > 0 ? ', '.$questions['stuck'].' stuck' : '' }}</span></span>
                        @endif
                    </a>
                @endif
                @if ($isModule || $place->moduleId !== null)
                    <x-button variant="primary" icon="play" wire:click="studyHere">Study this</x-button>
                @endif
                @include('livewire.workspaces.partials.row-menu', ['id' => 'place-'.$place->id, 'label' => $placeName, 'items' => $isModule ? [
                    ['Edit', 'pencil', "editModule('{$place->id}')", false],
                    ['Instructions for the AI', 'message-square-text', "editInstructions('{$place->id}')", false],
                    ['Delete', 'trash-2', "confirmDelete('module', '{$place->id}')", false],
                ] : [
                    ['Rename', 'pencil', "renameFolder('{$place->id}')", false],
                    ['Move to…', 'folder-input', "moveFolder('{$place->id}')", false],
                    ['Delete', 'trash-2', "confirmDelete('folder', '{$place->id}')", false],
                ]])
            </div>
        </div>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    @include('livewire.workspaces.partials.place', ['key' => $key, 'placeId' => $place->id])

    @if ($studied !== [])
        <section aria-labelledby="studied-heading" class="space-y-2">
            <h2 id="studied-heading" class="section-title">Study sessions</h2>
            <ul class="item-list" role="list">
                @foreach ($studied as $session)
                    <li class="item-row" wire:key="session-{{ $session->id }}">
                        <span class="item-icon ws-colour-green" aria-hidden="true"><x-icon :name="$session->isOpen() ? 'timer' : 'history'" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.sessions.show', [$workspaceId, $session->id]) }}" class="tile-link">{{ $session->topicId !== null ? ($topicNames[$session->topicId] ?? 'Study session') : 'Study session' }}</a>
                            <span class="item-meta">{{ ($session->isOpen() ? 'Studying now' : SessionDetails::duration($session->studySeconds)).' · '.Carbon::parse($session->startedAt)->diffForHumans() }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('livewire.workspaces.partials.dialog')
</div>
