{{--
    A module's or a folder's own page (App\Livewire\Workspaces\Contents,
    view `module` or `folder`): the path to it, its folders, its notes,
    files and links, and for a module a link to its questions (their own
    page) and its study sessions.
--}}
@php
    use App\Study\Folders;
    use Illuminate\Support\Carbon;

    $isModule = $view === 'module';
    $placeType = $view;
    $dates = $isModule && ($place->startsOn || $place->endsOn)
        ? trim(($place->startsOn ? Carbon::parse($place->startsOn)->format('j M') : '').' – '.($place->endsOn ? Carbon::parse($place->endsOn)->format('j M') : ''), ' –')
        : null;
@endphp
<div class="space-y-6" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                @if ($trail !== [])
                    <x-back :href="end($trail)[1]" :to="end($trail)[0]" />
                @endif
                {{-- The whole path, once it says more than the Back link does. --}}
                @if (count($trail) > 1)
                    <nav aria-label="Path" class="crumbs">
                        <ol role="list">
                            @foreach ($trail as [$label, $url])
                                <li><a href="{{ $url }}">{{ $label }}</a></li>
                            @endforeach
                        </ol>
                    </nav>
                @endif
            </div>
            @if ($isModule)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('workspaces.modules.questions', [$workspaceId, $place->id]) }}" class="btn btn-secondary">
                        <x-icon name="circle-help" class="size-4" />Questions
                        @if ($questions['open'] > 0)
                            <span class="tab-count">{{ $questions['open'] }}<span class="sr-only"> open{{ $questions['stuck'] > 0 ? ', '.$questions['stuck'].' stuck' : '' }}</span></span>
                        @endif
                    </a>
                    <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $place->id]) }}" class="btn btn-secondary">
                        <x-icon name="gallery-vertical-end" class="size-4" />Flashcards
                        @if ($cards['total'] > 0)
                            <span class="tab-count">{{ $cards['due'] > 0 ? $cards['due'] : $cards['total'] }}<span class="sr-only"> {{ $cards['due'] > 0 ? 'due' : Str::plural('card', $cards['total']) }}</span></span>
                        @endif
                    </a>
                    <a href="{{ route('workspaces.modules.sessions', [$workspaceId, $place->id]) }}" class="btn btn-secondary">
                        <x-icon name="history" class="size-4" />Study sessions
                        @if ($sessionsCount > 0)
                            <span class="tab-count">{{ $sessionsCount }}<span class="sr-only"> {{ Str::plural('session', $sessionsCount) }}</span></span>
                        @endif
                    </a>
                </div>
            @endif
        </div>
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
                <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                    <x-icon name="list-checks" class="size-4" />
                    <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
                </button>
                @include('livewire.workspaces.partials.new-menu', ['placeId' => $place->id, 'folders' => $isModule || $place->depth < Folders::MAX_DEPTH, 'questionUrl' => $isModule ? route('workspaces.questions.create', [$workspaceId, 'module' => $place->id]) : null])
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
        <x-toast :message="$notice" :tone="$noticeTone" :action-label="$noticeActionLabel" :action-event="$noticeActionEvent" :action-payload="$noticeActionPayload" />
    </div>

    <x-selection-bar>
        <x-button variant="secondary" size="sm" icon="folder-input" ::disabled="count === 0" x-on:click="$wire.openBulkMove(selectedKeys())">Move…</x-button>
        <x-button variant="secondary" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('note:') || k.startsWith('file:'))" title="Move selected notes and files to the trash" x-on:click="$wire.bulk('trash', selectedKeys().filter(k => k.startsWith('note:') || k.startsWith('file:')))">Move to trash</x-button>
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('folder:') || k.startsWith('link:'))" title="Delete selected folders and links" x-on:click="$wire.openBulkDelete(selectedKeys().filter(k => k.startsWith('folder:') || k.startsWith('link:')))">Delete</x-button>
    </x-selection-bar>

    @if ($isModule)
        @php
            $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confusing', 'mastered' => 'Mastered'];
            $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
        @endphp
        {{-- The module's topics: the parts it is made of, each with how it stands. The tutor adds them as it teaches; here they can be added by hand. --}}
        <section class="module-topics" aria-labelledby="module-topics-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="module-topics-heading" class="font-semibold">Topics <span class="text-sm font-normal text-fg-muted">· {{ count($moduleTopics) }}</span></h2>
                @if ($moduleTopics !== [])
                    <a href="{{ route('workspaces.show', [$workspaceId, 'progress']) }}" class="item-link text-sm">All in Progress</a>
                @endif
            </div>
            @if ($moduleTopics === [])
                <p class="text-sm text-fg-muted">The parts of this module, like a chapter's sections. The tutor adds them from what you study; you can add one here too.</p>
            @else
                <ul class="module-topic-list" role="list">
                    @foreach ($moduleTopics as $t)
                        @php
                            $shown = $t->shown();
                            $tc = $topicCards[$t->id] ?? null;
                        @endphp
                        <li class="module-topic" wire:key="module-topic-{{ $t->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium break-words">{{ $t->name }}</p>
                                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted">
                                    <span @class(['status-chip', "status-{$shown}"])><x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}</span>
                                    @if ($tc)
                                        <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $place->id, 'topic' => $t->id]) }}" class="item-link">{{ Str::plural('card', $tc['total'], prependCount: true) }}{{ $tc['due'] > 0 ? ', '.$tc['due'].' due' : '' }}</a>
                                    @endif
                                </p>
                            </div>
                            <x-button size="sm" icon="play" wire:click="studyTopic('{{ $t->id }}')" aria-label="Study {{ $t->name }}">Study</x-button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addTopic" class="module-topic-add" novalidate>
                <label for="module-topic-name" class="sr-only">Add a topic to {{ $placeName }}</label>
                <input id="module-topic-name" type="text" class="input" wire:model="topicName" maxlength="{{ \App\Study\Topics::MAX_NAME }}" placeholder="Add a topic, like “CPU scheduling”" @error('topicName') aria-invalid="true" aria-describedby="module-topic-error" @enderror>
                <x-button type="submit" icon="plus" wire:loading.attr="aria-busy" wire:target="addTopic">Add</x-button>
            </form>
            @error('topicName') <p id="module-topic-error" class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    @include('livewire.workspaces.partials.place', ['key' => $key, 'placeId' => $place->id])

    @include('livewire.workspaces.partials.dialog')
</div>
