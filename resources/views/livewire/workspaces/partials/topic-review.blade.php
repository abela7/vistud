{{--
    The main topics the AI chose for this part from all of its files (App\Engine\Jobs\ChooseTopics), waiting for the
    student, as cards in the order to study them: a number, the topic's name (with an (i) for why the AI chose it and what
    it covers), a line on why it matters, and the file it is taught in, small; one quiet Accept, and the rest (rename,
    rethink, skip) in its menu. Accept all, and Choose again with what to change, sit above. What the AI made of each file
    opens from one line. Finding the topics, or choosing again, the student first ticks which of the part's files the AI
    reads (the others are left out until ticked again), and may say what to change. While the files are read or the
    topics chosen, it says so and looks again until it is done. One card across on a phone, two from a tablet, three on a
    wide screen.
--}}
@php
    $reviewed = $topicReview['files'] ?? [];
    $usedFiles = array_values(array_filter($reviewed, fn ($f) => $f['use'] !== 'skip'));
    $leftOut = array_values(array_filter($reviewed, fn ($f) => $f['use'] === 'skip'));
    $useLabel = ['core' => 'Main material', 'support' => 'Supporting', 'skip' => 'Left out'];
    $finding = $suggestions === [];
    $fileState = fn (array $file) => match ($file['state']) {
        'read' => 'Read',
        'reading' => 'Being read…',
        'unread' => 'Not read yet: it is read first',
        default => $file['reason'] === 'picture' ? 'A picture: the AI can\'t read it' : 'No text the AI can read',
    };
@endphp
@if ($suggestions !== [] || $topicProgress !== null || $choosingAgain)
    <section class="topic-review" aria-labelledby="topic-review-title" @if ($topicProgress !== null) wire:poll.4s @endif>
        <header class="topic-review-head">
            <div class="topic-review-title">
                <h2 id="topic-review-title" class="topic-review-heading"><x-icon name="sparkles" class="size-4 text-accent" />{{ $finding && $topicProgress === null ? 'Find the main topics' : 'Main topics to review' }}</h2>
                <p class="topic-review-status" role="status">
                    @if ($topicProgress === 'reading')
                        <x-icon name="loader-circle" class="size-3.5 animate-spin" />Reading your files…
                    @elseif ($topicProgress === 'choosing')
                        <x-icon name="loader-circle" class="size-3.5 animate-spin" />Choosing the main topics from all the files…
                    @elseif ($finding)
                        Tick the files the AI should read. It chooses the main topics from them.
                    @elseif ($reviewed !== [])
                        {{ count($suggestions) }} {{ \Illuminate\Support\Str::plural('topic', count($suggestions)) }} from {{ count($usedFiles) }} of {{ count($reviewed) }} {{ \Illuminate\Support\Str::plural('file', count($reviewed)) }}, in the order to study them
                    @else
                        Found in your files. Accept the ones you want to track.
                    @endif
                </p>
            </div>
            @if ($suggestions !== [] && $topicProgress === null)
                <div class="topic-review-actions">
                    <x-button size="sm" variant="primary" icon="check" wire:click="addSuggested" wire:loading.attr="aria-busy" wire:target="addSuggested" busy-label="Adding…">Accept all</x-button>
                    <x-button size="sm" icon="rotate-ccw" wire:click="openChooseAgain" aria-expanded="{{ $choosingAgain ? 'true' : 'false' }}">Choose again</x-button>
                    @include('livewire.workspaces.partials.row-menu', ['id' => 'topic-review', 'label' => 'the topics to review', 'items' => [
                        ['Skip all', 'x', 'dismissSuggested', false],
                    ]])
                </div>
            @endif
        </header>

        {{-- Before the AI chooses: which files it reads, and (choosing again) what to change. --}}
        @if ($choosingAgain && $topicProgress === null)
            <form class="topic-again" wire:submit="chooseAgain" novalidate>
                @if ($topicFiles !== [])
                    <fieldset class="topic-files">
                        <legend class="field-label">Files the AI reads</legend>
                        <ul class="topic-files-list" role="list">
                            @foreach ($topicFiles as $topicFile)
                                @php $usable = in_array($topicFile['state'], ['read', 'reading', 'unread'], true); @endphp
                                <li wire:key="topic-file-{{ $topicFile['id'] }}">
                                    <label @class(['topic-file', 'is-unusable' => ! $usable])>
                                        <span class="checkbox-box">
                                            <input type="checkbox" class="checkbox" value="{{ $topicFile['id'] }}" wire:model="aiFiles" @disabled(! $usable)>
                                            <x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" />
                                        </span>
                                        <x-icon name="file-text" class="size-4 shrink-0 text-fg-muted" />
                                        <span class="topic-file-text">
                                            <span class="topic-file-name">{{ $topicFile['name'] }}</span>
                                            <span class="topic-file-state">{{ $fileState($topicFile) }}</span>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        @error('aiFiles') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>
                @endif
                <label for="topic-again-note" class="field-label">{{ $finding ? 'Anything the AI should know?' : 'What should the AI change?' }} <span class="font-normal text-fg-muted">(optional)</span></label>
                <textarea id="topic-again-note" class="input" rows="3" maxlength="600" wire:model="againNote"
                    placeholder="{{ $finding ? 'Like “Focus on the lab skills” or “Leave out the revision slides”.' : 'Like “Too broad: split Graphs into BFS and DFS”. Leave it empty to choose again from the files.' }}"></textarea>
                @error('againNote') <p class="field-error">{{ $message }}</p> @enderror
                <div class="topic-again-actions">
                    <x-button type="submit" size="sm" variant="primary" icon="sparkles" wire:loading.attr="aria-busy" wire:target="chooseAgain" busy-label="Starting…">{{ $finding ? 'Find the main topics' : 'Choose again' }}</x-button>
                    <x-button size="sm" variant="ghost" wire:click="cancelChooseAgain">Cancel</x-button>
                </div>
                @unless ($finding)
                    <p class="topic-again-hint">The topics waiting now stay until the new choice is ready. Topics you accepted are kept.</p>
                @endunless
            </form>
        @endif

        @if ($reviewed !== [])
            <div class="topic-review-files" x-data="{ open: false }">
                <button type="button" class="topic-review-files-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
                    <x-icon name="chevron-right" class="topic-review-chevron size-4" />
                    <span>What the AI read: {{ count($usedFiles) }} used{{ $leftOut !== [] ? ', '.count($leftOut).' left out' : '' }}</span>
                </button>
                <ul class="topic-review-file-list" role="list" x-show="open" x-cloak>
                    @foreach ($reviewed as $reviewedFile)
                        <li @class(['topic-review-file', 'is-skipped' => $reviewedFile['use'] === 'skip'])>
                            <x-icon :name="$reviewedFile['use'] === 'skip' ? 'circle-minus' : 'circle-check'" class="size-4 shrink-0" />
                            <span class="min-w-0">
                                <span class="font-medium text-fg">{{ $reviewedFile['name'] }}</span>
                                <span class="topic-review-use">· {{ $useLabel[$reviewedFile['use']] ?? '' }}</span>
                                @if ($reviewedFile['why'] !== '')<span class="block text-fg-muted">{{ $reviewedFile['why'] }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($suggestions !== [])
            <ol class="topic-grid" role="list">
                @foreach ($suggestions as $i => $suggestion)
                    <li class="topic-card" wire:key="topic-review-{{ $suggestion->id }}">
                        <div class="topic-card-top">
                            <span class="topic-card-num" aria-hidden="true">{{ $i + 1 }}</span>
                            @if ($editingSuggestionId === $suggestion->id)
                                <form class="topic-card-rename" wire:submit="saveRenameSuggestion('{{ $suggestion->id }}')">
                                    <label for="rename-{{ $suggestion->id }}" class="sr-only">Topic name</label>
                                    <input id="rename-{{ $suggestion->id }}" type="text" class="input" wire:model="editingSuggestionName" maxlength="120" autofocus>
                                    <div class="topic-card-rename-actions">
                                        <x-button size="sm" type="submit" variant="primary">Save</x-button>
                                        <x-button size="sm" variant="ghost" wire:click="cancelRenameSuggestion">Cancel</x-button>
                                    </div>
                                </form>
                            @else
                                <h3 class="topic-card-title">
                                    {{ $suggestion->name }}
                                    @if ($suggestion->note || $suggestion->covers !== [])
                                        {{-- Why the AI chose it, and what it covers: on hover, or pinned by a tap or Enter; a screen reader hears it with the button. --}}
                                        <span class="topic-note-wrap" x-data="{ open: false, pinned: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = pinned"
                                            x-on:keydown.escape="open = pinned = false" x-on:click.outside="open = pinned = false">
                                            <button type="button" class="topic-note-btn" x-on:click="pinned = ! pinned; open = pinned"
                                                x-bind:aria-expanded="open.toString()" aria-describedby="topic-note-{{ $suggestion->id }}" aria-label="Why the AI chose {{ $suggestion->name }}">
                                                <x-icon name="info" class="size-4" />
                                            </button>
                                            <span id="topic-note-{{ $suggestion->id }}" class="topic-note" role="tooltip" x-show="open" x-cloak>
                                                @if ($suggestion->note)
                                                    <span class="topic-note-title"><x-icon name="sparkles" class="size-3.5" />Why the AI chose it</span>
                                                    <span>{{ $suggestion->note }}</span>
                                                @endif
                                                @if ($suggestion->covers !== [])
                                                    <span class="topic-note-title">It covers</span>
                                                    <span>{{ implode(' · ', $suggestion->covers) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    @endif
                                </h3>
                            @endif
                        </div>

                        @if ($suggestion->reason)
                            <p class="topic-card-why">{{ $suggestion->reason }}</p>
                        @endif
                        @if ($suggestion->sources !== [] || $suggestion->sourceName)
                            <p class="topic-card-file">
                                <x-icon name="file-text" class="size-3.5 shrink-0" />
                                <span>{{ $suggestion->sources !== [] ? collect($suggestion->sources)->map(fn ($source) => $source['name'].($source['pages'] !== '' ? ' · p. '.$source['pages'] : ''))->implode(', ') : $suggestion->sourceName }}</span>
                            </p>
                        @endif

                        <div class="topic-card-foot">
                            <x-button size="sm" icon="check" wire:click="addOneSuggested('{{ $suggestion->id }}')" aria-label="Accept {{ $suggestion->name }}">Accept</x-button>
                            @include('livewire.workspaces.partials.row-menu', ['id' => 'topic-suggestion-'.$suggestion->id, 'label' => $suggestion->name, 'items' => [
                                ['Rename', 'pencil', "startRenameSuggestion('{$suggestion->id}')", false],
                                ['Ask the AI to rethink it', 'rotate-ccw', "openChooseAgain('{$suggestion->id}')", false],
                                ['Skip this topic', 'x', "dismissOneSuggested('{$suggestion->id}')", false],
                            ]])
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif
