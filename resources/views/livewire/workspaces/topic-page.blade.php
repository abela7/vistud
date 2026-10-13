{{--
    A topic on a page of its own (App\Livewire\Workspaces\TopicPage), the full width of the page. On a wide screen
    the work (AI tutor, questions, notes & files, key points, as tabs) on the left, and a slim column on the right:
    where the student stands with its numbers, then renaming, moving and removing folded away. Below 1024 px one
    column: where they stand first, then the work, then the rest. The status is the one the topic's row shows.
--}}
@php
    $shown = $topic->shown();
    $current = $shown === 'mastered' ? 'understood' : $shown;
    $statusLabels = [
        'not_started' => ['label' => 'Not started', 'hint' => 'No contact yet'],
        'covered' => ['label' => 'Studying', 'hint' => 'Working on it'],
        'confused' => ['label' => 'Not understood', 'hint' => 'Still confusing'],
        'understood' => ['label' => 'Understood', 'hint' => 'I can explain it'],
    ];
    $statusIcons = [
        'not_started' => 'circle-dashed',
        'covered' => 'book-open',
        'confused' => 'circle-alert',
        'understood' => 'circle-check',
    ];

    $backUrl = match (true) {
        $folder !== null => route('workspaces.folders.show', [$workspaceId, $folder->id]),
        $module !== null => route('workspaces.modules.show', [$workspaceId, $module->id]),
        default => route('workspaces.show', [$workspaceId, 'modules']),
    };
    $backLabel = $folder ? $folder->name : ($module ? $module->title : 'Modules');

    $openQuestions = array_filter($questions, fn ($q) => $q->status !== 'answered');
    $answeredQuestions = array_filter($questions, fn ($q) => $q->status === 'answered');
@endphp

<div class="topic-page">
    <x-toast :message="$notice" />

    {{-- Clean Standard ViStud Header --}}
    <x-workspace.section-header
        :workspace="$workspace"
        :title="$cleanName"
        :back-href="$backUrl"
        :back-to="$backLabel"
        :eyebrow="$workspace->name.($module ? ' · '.$module->title : '').($folder ? ' · '.$folder->name : '')">
        <x-ai-menu kind="topic" :id="$topic->id" :label="$cleanName" />
    </x-workspace.section-header>

    <div @class(['topic-page-layout', 'is-chat' => $tab === 'ai'])>
        <div class="topic-page-main">
            @php
                $topicTabs = [
                    'ai' => ['AI tutor', 'sparkles', 0],
                    'questions' => ['Questions', 'circle-help', count($openQuestions)],
                    'materials' => ['Notes & files', 'folder', count($topicNotes) + count($topicFiles)],
                    'settings' => ['Key points', 'pin', count($points)],
                ];
            @endphp
            <nav class="page-tabs" aria-label="This topic">
                @foreach ($topicTabs as $key => [$tabLabel, $tabIcon, $tabCount])
                    <button type="button" wire:click="setTab('{{ $key }}')" class="page-tab" @if ($tab === $key) aria-current="page" @endif>
                        <x-icon :name="$tabIcon" class="size-4" />
                        <span>{{ $tabLabel }}</span>
                        @if ($tabCount > 0)
                            <span class="tab-count">{{ $tabCount }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>

            @if ($tab === 'ai')
                {{-- The same tutor chat as a study session's (App\Livewire\Workspaces\TutorChat), about this one topic and with no
                     session: the model picker, dictation, attachments, formatting, the stream, retry and where you stand. --}}
                <div class="topic-tutor">
                    <livewire:workspaces.tutor-chat :workspace-id="$workspaceId" :topic-id="$topicId" :key="'topic-tutor-'.$topicId" />
                </div>
            @endif

            {{-- TAB 2: TOPIC SPECIFIC QUESTIONS --}}
            @if ($tab === 'questions')
                <div class="space-y-3">
                    {{-- Add Question --}}
                    <div class="topic-panel space-y-2">
                        <h3 class="field-label flex items-center gap-1.5">
                            <x-icon name="circle-help" class="size-3.5 text-accent" />
                            <span>Ask a question on this topic</span>
                        </h3>
                        <form wire:submit="askQuestion" novalidate class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <label for="topic-new-question" class="sr-only">Your Question</label>
                                <input id="topic-new-question" class="input w-full text-sm py-1.5" type="text"
                                    wire:model="newQuestionText"
                                    placeholder="What don't you understand about {{ $cleanName }} yet?"
                                    maxlength="1000" autocomplete="off" />
                                @error('newQuestionText') <p class="field-error mt-1 text-xs">{{ $message }}</p> @enderror
                            </div>
                            <x-button size="sm" type="submit" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="askQuestion" busy-label="Saving…">
                                Add
                            </x-button>
                        </form>
                    </div>

                    {{-- Questions List --}}
                    <div class="topic-panel space-y-3">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-sm font-semibold text-fg">Questions ({{ count($questions) }})</h3>
                            <div class="flex items-center gap-1.5 text-xs">
                                <span class="px-1.5 py-0.5 rounded-full bg-warning-subtle text-warning font-semibold">{{ count($openQuestions) }} Open</span>
                                <span class="px-1.5 py-0.5 rounded-full bg-success-subtle text-success font-semibold">{{ count($answeredQuestions) }} Understood</span>
                            </div>
                        </div>

                        @if ($questions === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-sm font-medium">No questions asked yet.</p>
                                <p class="text-xs mt-0.5">Whenever you get stuck or confused, add a question above.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-sm" role="list">
                                @foreach ($questions as $q)
                                    <li class="py-2.5 space-y-1.5" wire:key="question-item-{{ $q->id }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1 space-y-1">
                                                <div class="flex items-center gap-1.5">
                                                    <span @class([
                                                        'inline-flex items-center text-[11px] px-1.5 py-0.2 rounded font-semibold',
                                                        'bg-warning-subtle text-warning' => $q->status === 'stuck',
                                                        'bg-info-subtle text-info' => $q->status === 'pending',
                                                        'bg-success-subtle text-success' => $q->status === 'answered',
                                                    ])>
                                                        {{ ucfirst($q->status) }}
                                                    </span>
                                                    <span class="text-xs text-fg-muted">{{ \Illuminate\Support\Carbon::parse($q->askedAt)->format('j M') }}</span>
                                                </div>
                                                <a href="{{ route('workspaces.questions.show', [$workspaceId, $q->id]) }}" class="font-medium text-fg hover:text-accent transition-colors block break-words" style="text-decoration: none !important;">
                                                    {{ $q->text }}
                                                </a>
                                                @if ($q->answer)
                                                    <p class="text-xs text-fg-muted bg-surface-sunken p-2 rounded border border-border/50 break-words">
                                                        <strong class="text-fg">Answer:</strong> {{ $q->answer }}
                                                    </p>
                                                @endif
                                            </div>

                                            <div class="flex items-center gap-1 shrink-0">
                                                @if ($q->status !== 'answered')
                                                    <button type="button" class="btn btn-secondary text-sm py-1 px-2" wire:click="startAnswering('{{ $q->id }}')">
                                                        Answer
                                                    </button>
                                                    @if ($q->status !== 'stuck')
                                                        <button type="button" class="btn btn-ghost text-sm text-warning py-1 px-2" wire:click="markQuestionStuck('{{ $q->id }}')">
                                                            Stuck
                                                        </button>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Inline Answer Form --}}
                                        @if ($answeringQuestionId === $q->id)
                                            <div class="p-2.5 rounded-lg border border-accent bg-accent-subtle space-y-2 mt-1">
                                                <label for="answer-input-{{ $q->id }}" class="block text-xs font-semibold text-fg">Write answer / solution:</label>
                                                <textarea id="answer-input-{{ $q->id }}" class="input w-full text-sm" rows="2" wire:model="answeringText" placeholder="What explains this question?"></textarea>
                                                @error('answeringText') <p class="field-error mt-1 text-xs">{{ $message }}</p> @enderror
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <x-button size="sm" variant="ghost" wire:click="cancelAnswering">Cancel</x-button>
                                                    <x-button size="sm" variant="primary" wire:click="saveAnswer('{{ $q->id }}')">Save Answer</x-button>
                                                </div>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- TAB 3: NOTES & FILES ON THIS TOPIC --}}
            @if ($tab === 'materials')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {{-- Notes --}}
                    <div class="topic-panel space-y-2.5">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-sm font-semibold text-fg flex items-center gap-1.5">
                                <x-icon name="file-text" class="size-3.5 text-accent" />
                                <span>Notes ({{ count($topicNotes) }})</span>
                            </h3>
                            @php
                                $noteTarget = $topic->folderId ? "folder:{$topic->folderId}" : ($topic->moduleId ? "module:{$topic->moduleId}" : '');
                                $newNoteUrl = route('workspaces.notes.create', array_filter([
                                    'workspace' => $workspaceId,
                                    'in' => $noteTarget ?: null,
                                    'title' => "Notes on {$cleanName}",
                                ]));
                            @endphp
                            <a href="{{ $newNoteUrl }}" class="btn btn-secondary text-sm py-1 px-2 inline-flex items-center gap-1" style="text-decoration: none !important;">
                                <x-icon name="plus" class="size-3" />
                                <span>New Note</span>
                            </a>
                        </div>

                        @if ($topicNotes === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-sm font-medium">No notes created yet.</p>
                                <p class="text-xs mt-0.5">Write notes or synthesize lecture takeaways.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-sm" role="list">
                                @foreach ($topicNotes as $n)
                                    <li class="py-2 flex items-center justify-between gap-2" wire:key="topic-note-{{ $n->id }}">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('workspaces.notes.show', [$workspaceId, $n->id]) }}" class="font-medium text-fg hover:text-accent transition-colors truncate block" style="text-decoration: none !important;">
                                                {{ $n->displayTitle() }}
                                            </a>
                                            <span class="text-xs text-fg-muted">{{ \Illuminate\Support\Carbon::parse($n->updatedAt)->diffForHumans() }}</span>
                                        </div>
                                        <a href="{{ route('workspaces.notes.show', [$workspaceId, $n->id]) }}" class="btn btn-ghost text-sm py-1 px-2 shrink-0" style="text-decoration: none !important;">
                                            Open
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Files --}}
                    <div class="topic-panel space-y-2.5">
                        <div class="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <h3 class="text-sm font-semibold text-fg flex items-center gap-1.5">
                                <x-icon name="paperclip" class="size-3.5 text-success" />
                                <span>Files ({{ count($topicFiles) }})</span>
                            </h3>
                        </div>

                        {{-- Upload File --}}
                        <div class="p-2 rounded-lg border border-dashed border-border/80 bg-surface-sunken space-y-1">
                            <label for="topic-file-upload" class="block text-xs font-semibold text-fg">Upload document or slide:</label>
                            <div class="flex items-center gap-2">
                                <input id="topic-file-upload" type="file" wire:model="uploadedFile" class="text-sm file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-accent file:text-on-accent hover:file:bg-accent-hover cursor-pointer" />
                                @if ($uploadedFile)
                                    <x-button size="sm" variant="primary" wire:click="uploadFileToTopic" wire:loading.attr="aria-busy" wire:target="uploadFileToTopic">Save File</x-button>
                                @endif
                            </div>
                            @error('uploadedFile') <p class="field-error text-xs mt-1">{{ $message }}</p> @enderror
                        </div>

                        @if ($topicFiles === [])
                            <div class="text-center py-3 text-fg-muted">
                                <p class="text-sm font-medium">No files attached yet.</p>
                                <p class="text-xs mt-0.5">Upload lecture slides, readings, or labs.</p>
                            </div>
                        @else
                            <ul class="divide-y divide-border/50 text-sm" role="list">
                                @foreach ($topicFiles as $f)
                                    <li class="py-2 flex items-center justify-between gap-2" wire:key="topic-file-{{ $f->id }}">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('workspaces.files.show', [$workspaceId, $f->id]) }}" class="font-medium text-fg hover:text-accent transition-colors truncate block" style="text-decoration: none !important;">
                                                {{ $f->fileName() }}
                                            </a>
                                            <span class="text-xs text-fg-muted">{{ \Illuminate\Support\Number::fileSize($f->size) }}</span>
                                        </div>
                                        <a href="{{ route('workspaces.files.show', [$workspaceId, $f->id]) }}" class="btn btn-ghost text-sm py-1 px-2 shrink-0" style="text-decoration: none !important;">
                                            View
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- TAB 4: KEY POINTS --}}
            @if ($tab === 'settings')
                <div class="topic-panel space-y-2.5">
                    <div class="border-b border-border/60 pb-2">
                        <h3 class="text-sm font-semibold text-fg flex items-center gap-1.5">
                            <x-icon name="pin" class="size-3.5 text-accent" />
                            <span>Key Points ({{ count($points) }})</span>
                        </h3>
                        <p class="text-xs text-fg-muted mt-0.5">Essential takeaways and formulas pinned to this topic.</p>
                    </div>

                    @if ($points !== [])
                        <ul class="divide-y divide-border/50 text-sm" role="list">
                            @foreach ($points as $kp)
                                <li class="py-1.5 flex items-start justify-between gap-2" wire:key="setting-point-{{ $kp->id }}">
                                    <div class="min-w-0 flex-1 text-fg break-words">
                                        <span>{{ $kp->text }}</span>
                                        @if ($kp->sourceName !== null)
                                            <span class="text-xs text-fg-muted block mt-0.5">from {{ $kp->sourceName }}{{ $kp->locator ? ', '.$kp->locator : '' }}</span>
                                        @endif
                                    </div>
                                    <button type="button" class="topbar-button -my-1 shrink-0 text-fg-muted hover:text-danger" wire:click="removePoint('{{ $kp->id }}')" aria-label="Remove key point">
                                        <x-icon name="trash-2" class="size-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-center py-3 text-fg-muted">
                            <p class="text-sm">No key points pinned yet.</p>
                        </div>
                    @endif

                    <form wire:submit="addPoint" novalidate class="flex items-center gap-2 pt-1 border-t border-border/50">
                        <div class="min-w-0 flex-1">
                            <label for="topic-new-point" class="sr-only">Add key point</label>
                            <input id="topic-new-point" class="input w-full text-sm py-1.5" type="text" wire:model="newPoint" placeholder="Add a key point or takeaway…" maxlength="500" autocomplete="off" />
                            @error('newPoint') <p class="field-error mt-1 text-xs">{{ $message }}</p> @enderror
                        </div>
                        <x-button size="sm" type="submit" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="addPoint">Add Point</x-button>
                    </form>
                </div>
            @endif
        </div>

        <section class="topic-panel topic-page-standing tsheet-tones" aria-labelledby="topic-standing-title">
            <h2 id="topic-standing-title" class="tsheet-label">Where You Stand</h2>
            <div class="tsheet-choices" role="radiogroup" aria-labelledby="topic-standing-title">
                @foreach ($statusLabels as $value => $meta)
                    <button type="button" wire:click="report('{{ $value }}')" @class(['tsheet-choice', "is-{$value}", 'is-active' => $value === $current]) aria-pressed="{{ $value === $current ? 'true' : 'false' }}">
                        <x-icon :name="$statusIcons[$value]" class="size-4 shrink-0" />
                        <span class="min-w-0 text-left">
                            <span class="tsheet-choice-label">{{ $meta['label'] }}</span>
                            <span class="tsheet-choice-hint">{{ $meta['hint'] }}</span>
                        </span>
                        <x-icon name="check" class="tsheet-choice-mark size-4" />
                    </button>
                @endforeach
            </div>
            <div class="tsheet-meta">
                @if ($shown === 'mastered')
                    <span><x-icon name="shield-check" class="size-3.5" />Mastered: your reviews show it has stuck.</span>
                @endif
                <span>From your studying: {{ $topic->evidence() }}</span>
                @if ($topic->byTutor())
                    <span>Set by the tutor{{ $topic->statusAt !== null ? ', '.\Illuminate\Support\Carbon::parse($topic->statusAt, 'UTC')->format('j M') : '' }}. Pick another to change it.</span>
                @endif
            </div>

            <ul class="tsheet-stats topic-page-stats" role="list" aria-label="Progress">
                <li>
                    @if ($cards['total'] > 0)
                        <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'topic' => $topic->id]) }}" class="tsheet-stat is-link">
                            <span class="tsheet-stat-value">{{ $cards['total'] }}</span>
                            <span class="tsheet-stat-label">{{ \Illuminate\Support\Str::plural('card', $cards['total']) }}@if ($cards['due'] > 0) · <span class="text-warning">{{ $cards['due'] }} due</span>@endif</span>
                        </a>
                    @else
                        <span class="tsheet-stat"><span class="tsheet-stat-value">0</span><span class="tsheet-stat-label">cards</span></span>
                    @endif
                </li>
                <li><button type="button" class="tsheet-stat is-link w-full text-left" wire:click="setTab('questions')"><span class="tsheet-stat-value">{{ count($openQuestions) }}</span><span class="tsheet-stat-label">open of {{ count($questions) }} {{ \Illuminate\Support\Str::plural('question', count($questions)) }}</span></button></li>
                <li><button type="button" class="tsheet-stat is-link w-full text-left" wire:click="setTab('settings')"><span class="tsheet-stat-value">{{ count($points) }}</span><span class="tsheet-stat-label">key {{ \Illuminate\Support\Str::plural('point', count($points)) }}</span></button></li>
                <li><span class="tsheet-stat"><span class="tsheet-stat-value">{{ count($sessions) }}</span><span class="tsheet-stat-label">{{ \Illuminate\Support\Str::plural('session', count($sessions)) }}</span></span></li>
            </ul>
        </section>

        @if ($tab === 'ai')
            {{-- Beside the chat (from a laptop up): the topic's own notes and files, which the tutor teaches from first. It
                 takes the column's spare height, so the two columns end together; a long list scrolls inside it. --}}
            <section class="topic-panel topic-page-reads" aria-labelledby="topic-reads-title">
                <div class="topic-page-reads-head">
                    <h2 id="topic-reads-title" class="tsheet-label">The tutor reads</h2>
                    @if ($topicNotes !== [] || $topicFiles !== [])
                        <button type="button" class="topic-page-reads-more" wire:click="setTab('materials')">Manage</button>
                    @endif
                </div>
                @if ($topicNotes === [] && $topicFiles === [])
                    <div class="topic-page-reads-empty">
                        <x-icon name="folder-open" class="size-5" />
                        <p>No notes or files on this topic yet. Add some and the tutor teaches from them.</p>
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="setTab('materials')"><x-icon name="plus" class="size-4" />Add notes or files</button>
                    </div>
                @else
                    <ul class="topic-page-reads-list" role="list">
                        @foreach ($topicNotes as $n)
                            <li wire:key="reads-note-{{ $n->id }}">
                                <a href="{{ route('workspaces.notes.show', [$workspaceId, $n->id]) }}" class="topic-page-read">
                                    <x-icon name="notebook-pen" class="size-4 shrink-0" />
                                    <span class="min-w-0 flex-1 truncate">{{ $n->displayTitle() }}</span>
                                    <span class="topic-page-read-kind">Note</span>
                                </a>
                            </li>
                        @endforeach
                        @foreach ($topicFiles as $f)
                            <li wire:key="reads-file-{{ $f->id }}">
                                <a href="{{ route('workspaces.files.show', [$workspaceId, $f->id]) }}" class="topic-page-read">
                                    <x-icon name="file-text" class="size-4 shrink-0" />
                                    <span class="min-w-0 flex-1 truncate" title="{{ $f->fileName() }}">{{ $f->fileName() }}</span>
                                    <span class="topic-page-read-kind">{{ \Illuminate\Support\Number::fileSize($f->size) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <details class="topic-panel tsheet-more topic-page-manage" @if ($confirmingRemove || $errors->hasAny(['name', 'place'])) open @endif>
            <summary>
                <x-icon name="pencil" class="size-4" />
                <span class="flex-1">Rename, move or remove</span>
                <x-icon name="chevron-down" class="tsheet-more-chevron size-4" />
            </summary>
            <div class="tsheet-more-body">
                <form wire:submit="rename" novalidate class="space-y-1.5">
                    <label for="topic-rename-input" class="field-label">Name</label>
                    <div class="tsheet-inline-form">
                        <input id="topic-rename-input" class="input" type="text" wire:model="name" maxlength="120" placeholder="Topic name" autocomplete="off" />
                        <x-button type="submit" wire:loading.attr="aria-busy" wire:target="rename">Rename</x-button>
                    </div>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </form>

                <div class="space-y-1.5">
                    <label for="topic-page-place" class="field-label">Module or folder</label>
                    <div class="tsheet-inline-form">
                        <select id="topic-page-place" class="input" wire:model="place">
                            <option value="">No module</option>
                            @foreach ($modules as $mod)
                                @if (($foldersByModule[$mod->id] ?? []) === [])
                                    <option value="module:{{ $mod->id }}">{{ $mod->title }}</option>
                                @else
                                    <optgroup label="{{ $mod->title }}">
                                        <option value="module:{{ $mod->id }}">In {{ $mod->title }}</option>
                                        @foreach ($foldersByModule[$mod->id] as $fld)
                                            <option value="folder:{{ $fld['id'] }}">{{ $fld['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                        <x-button wire:click="move" wire:loading.attr="aria-busy" wire:target="move">Move</x-button>
                    </div>
                    @error('place') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                @if ($confirmingRemove)
                    <div class="tsheet-confirm" role="alert">
                        <p>Remove {{ $cleanName }}? What you did on it stays in your study record.</p>
                        <div class="tsheet-confirm-actions">
                            <x-button size="sm" wire:click="cancelRemove">Keep it</x-button>
                            <x-button size="sm" variant="danger" icon="trash-2" wire:click="removeTopic" wire:loading.attr="aria-busy" wire:target="removeTopic" busy-label="Removing…">Remove</x-button>
                        </div>
                    </div>
                @else
                    <button type="button" class="btn btn-ghost btn-sm tsheet-danger self-start" wire:click="askRemove">
                        <x-icon name="trash-2" class="size-4" />Remove topic
                    </button>
                @endif
            </div>
        </details>
    </div>
</div>
