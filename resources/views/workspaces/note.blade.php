{{--
    A note (App\Http\Controllers\NotePageController). The editor host below is
    outside every Livewire component; resources/js/note/editor.js mounts
    Tiptap on it and autosaves through the JSON API (ADR 0003 §5). Without a
    $note it's a new one, in $place: nothing is kept until it has a title or
    some text, and then it's made by its first save (POST /api/v1/notes).
--}}
@php
    use Illuminate\Support\Str;

    $new = $note === null;
    $shownTitle = $new ? 'New note' : $note->displayTitle();
    $payload = $new ? ['id' => null, 'version' => 0, 'doc' => \App\Study\NoteDoc::empty()] : ['id' => $note->id, 'version' => $note->version, 'doc' => $note->doc];
    $statusIcons = [
        'pencil' => 'draft',
        'check' => 'saved',
        'loader-circle' => 'saving',
        'cloud-off' => 'local offline',
        'triangle-alert' => 'retrying nostorage conflict gone session blocked deleted rejected account',
    ];
    // [command, icon, label, shows pressed]; null starts a new group.
    $marks = [
        ['bold', 'bold', 'Bold (Ctrl+B)', true],
        ['italic', 'italic', 'Italic (Ctrl+I)', true],
        ['underline', 'underline', 'Underline (Ctrl+U)', true],
        ['strike', 'strikethrough', 'Strikethrough', true],
    ];
    $scripts = [
        ['superscript', 'superscript', 'Superscript', true],
        ['subscript', 'subscript', 'Subscript', true],
        ['code', 'code', 'Code in a line', true],
    ];
    $lists = [
        ['bulletList', 'list', 'Bulleted list', true],
        ['orderedList', 'list-ordered', 'Numbered list', true],
        ['taskList', 'list-todo', 'Checklist', true],
    ];
    $blocks = [
        ['blockquote', 'text-quote', 'Quote', true],
        ['codeBlock', 'square-code', 'Code block', true],
        ['table', 'table', 'Table', true],
        ['divider', 'minus', 'Divider', false],
        ['pageBreak', 'file-plus', 'Page break (Ctrl+Enter)', false],
    ];
    $aligns = ['left' => ['align-left', 'Left'], 'center' => ['align-center', 'Centre'], 'right' => ['align-right', 'Right'], 'justify' => ['align-justify', 'Justify']];
    $highlights = ['yellow' => 'Yellow', 'green' => 'Green', 'blue' => 'Blue', 'pink' => 'Pink', 'purple' => 'Purple'];
    $callouts = [
        'definition' => ['Definition', 'Defines a concept'],
        'theorem' => ['Theorem / Law', 'Physics law or math theorem'],
        'formula' => ['Key Formula', 'Formula to remember'],
        'example' => ['Worked Example', 'Step-by-step problem'],
        'note' => ['Note / Tip', 'Helpful reminder'],
    ];
    $greekLetters = ['α', 'β', 'γ', 'Δ', 'ε', 'θ', 'λ', 'μ', 'π', 'ρ', 'σ', 'τ', 'φ', 'ω', 'Ω'];
    $mathOps = ['±', '×', '÷', '√', '∫', '∑', '∂', '∇', '≈', '≠', '≤', '≥', '∞', '°', '→'];
    $mathTemplates = ['F⃗ = ma', 'E = mc²', 'v⃗', 'Δt', 'x²', 'H₂O', 'μm', 'm/s²'];
@endphp
<x-layouts.app :title="$shownTitle.' · '.$workspace->name" :workspace="$workspace" :section="$inModule ? 'modules' : 'notes'">
    <div class="note-page" data-note-page data-page-view="pages">
        <div class="note-header-bar flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            @php
                [$backTo, $backUrl] = $trail !== [] ? end($trail) : [$inModule ? 'Modules' : 'Notes & files', route('workspaces.show', $inModule ? [$workspace->id, 'modules'] : [$workspace->id, 'notes'])];
            @endphp
            <x-back :href="$backUrl" :to="$backTo" class="basis-full" />
            <nav aria-label="Where this note is" class="min-w-0">
                <ol class="breadcrumbs">
                    <li><a href="{{ route('workspaces.show', $inModule ? [$workspace->id, 'modules'] : [$workspace->id, 'notes']) }}">{{ $inModule ? 'Modules' : 'Notes & files' }}</a></li>
                    @foreach ($trail as [$label, $url])
                        <li>
                            <x-icon name="chevron-right" class="size-4 shrink-0 text-fg-subtle" />
                            @if ($url)
                                <a href="{{ $url }}">{{ $label }}</a>
                            @else
                                <span>{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                @if ($new || $note->trashedAt === null)
                    <p class="save-status" data-save-status data-state="{{ $new ? 'draft' : 'saved' }}" role="status">
                        @foreach ($statusIcons as $icon => $states)
                            <x-icon :name="$icon" class="size-4" data-for="{{ $states }}" />
                        @endforeach
                        <span data-save-label>{{ $new ? 'Not saved yet' : 'Saved' }}</span>
                    </p>
                    <button type="button" class="btn btn-ghost note-mode" data-note-view-toggle title="Document view mode: Pages (A4) or Full Width" aria-label="Toggle document view mode">
                        <span class="when-pages"><x-icon name="file-text" class="size-4" /><span class="max-sm:sr-only">Pages</span></span>
                        <span class="when-continuous"><x-icon name="scroll-text" class="size-4" /><span class="max-sm:sr-only">Full Width</span></span>
                    </button>
                    <button type="button" class="btn btn-ghost note-mode" data-note-read>
                        <span class="when-editing"><x-icon name="book-open-text" class="size-4" /><span class="max-sm:sr-only">Read</span></span>
                        <span class="when-reading"><x-icon name="pencil" class="size-4" /><span class="max-sm:sr-only">Edit</span></span>
                    </button>
                    <button type="button" class="btn btn-ghost note-mode" data-note-focus>
                        <span class="when-page"><x-icon name="maximize-2" class="size-4" /><span class="max-sm:sr-only">Full screen</span></span>
                        <span class="when-focused"><x-icon name="minimize-2" class="size-4" /><span class="max-sm:sr-only">Exit full screen</span></span>
                    </button>
                    <button type="button" class="btn btn-ghost note-mode" onclick="window.print()" title="Print / Export PDF (Ctrl+P)" aria-label="Print or export PDF">
                        <x-icon name="printer" class="size-4" /><span class="max-sm:sr-only">Print</span>
                    </button>
                    {{-- Only while the note is empty (resources/js/note/editor.js shows it). --}}
                    <button type="button" class="btn btn-ghost note-mode" data-note-import title="Start this note from a Markdown (.md) or text (.txt) file" hidden>
                        <span><x-icon name="file-up" class="size-4" /><span class="max-sm:sr-only">Import</span></span>
                    </button>
                    <input type="file" hidden data-import-file accept=".md,.markdown,.txt,text/markdown,text/plain">
                @endif
                @unless ($new)
                    <livewire:workspaces.note-actions :note-id="$note->id" />
                @endunless
            </div>
        </div>

        <h1 class="sr-only" data-note-heading>{{ $shownTitle }}</h1>

        @if (! $new && $note->trashedAt !== null)
            <x-alert tone="warning" title="This note is in the trash">
                It can't be changed while it's there. Restore it to keep writing; otherwise it's deleted 30 days after it was trashed.
            </x-alert>
            <article class="note-card">
                <div class="note-content">
                    <div class="note-sheet">
                        <p class="note-title">{{ $note->displayTitle() }}</p>
                        <p class="text-fg-muted">{{ \App\Study\NoteDoc::text($note->doc) ?: 'This note is empty.' }}</p>
                    </div>
                </div>
            </article>
        @else
            <div class="space-y-2" data-note-alerts>
                <x-alert tone="info" title="Restored" data-alert="restored" hidden :live="false">
                    Changes you hadn't saved yet were kept on this device, and are being saved now.
                    <button type="button" class="link-button" data-action="dismiss">Dismiss</button>
                </x-alert>
                <x-alert tone="warning" title="This note was changed somewhere else" data-alert="conflict" hidden>
                    Another tab or device saved a newer version while you were writing. Nothing is lost: choose which version to keep.
                    <span class="mt-2 flex flex-wrap gap-2">
                        <x-button variant="primary" data-action="keep-mine">Keep my version</x-button>
                        <x-button data-action="keep-theirs">Use the newer version</x-button>
                    </span>
                </x-alert>
                <x-alert tone="info" title="You're offline" data-alert="offline" hidden>
                    Keep writing: your changes are saved on this device and sent when you're back online.
                </x-alert>
                <x-alert tone="danger" title="Your changes can't be stored on this device" data-alert="nostorage" hidden>
                    This browser isn't letting ViStud store anything (a private window, or storage is full or blocked). Keep this tab open until it says Saved.
                </x-alert>
                <x-alert tone="warning" title="Your session has ended" data-alert="session" hidden>
                    Your changes are kept on this device. <a href="{{ route('login') }}" class="font-semibold underline underline-offset-2">Log in again</a> and open this note to save them.
                </x-alert>
                <x-alert tone="danger" title="This note is in the trash" data-alert="gone" hidden>
                    It was moved to the trash or deleted, so these changes can't be saved to it.
                </x-alert>
                <x-alert tone="danger" title="Access removed" data-alert="blocked" hidden>
                    This account can't save notes any more.
                </x-alert>
                <x-alert tone="danger" title="This account no longer exists" data-alert="deleted" hidden>
                    Its unsaved changes were removed from this device.
                </x-alert>
                <x-alert tone="danger" title="This note can't be saved" data-alert="rejected" hidden>
                    <span data-rejected-message></span>
                </x-alert>
            </div>

            <article class="note-card" data-note-editor data-account="{{ auth()->id() }}" data-max-bytes="{{ \App\Study\NoteDoc::MAX_BYTES }}" data-image-upload-url="{{ route('api.v1.notes.images.store') }}"
                @if ($import ?? null) data-import-url="{{ $import['url'] }}" data-import-name="{{ $import['name'] }}" data-import-kind="{{ $import['markdown'] ? 'markdown' : 'text' }}" @endif
                @if ($new) data-create-url="{{ route('api.v1.notes.store') }}" data-place-type="{{ $place[0] }}" data-place-id="{{ $place[1] }}" @else data-save-url="{{ route('api.v1.notes.update', $note->id) }}" @endif>
                <div class="note-toolbar" role="toolbar" aria-label="Formatting" aria-controls="note-body" data-note-toolbar>
                    <button type="button" class="toolbar-button" data-command="undo" title="Undo (Ctrl+Z)" aria-label="Undo" tabindex="0"><x-icon name="undo-2" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="redo" title="Redo (Ctrl+Shift+Z)" aria-label="Redo" tabindex="-1"><x-icon name="redo-2" class="size-5" /></button>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <label for="note-block-style" class="sr-only">Text style</label>
                    <select id="note-block-style" class="toolbar-select" data-block-style tabindex="-1">
                        <option value="paragraph">Text</option>
                        <option value="1">Title</option>
                        <option value="2">Heading 1</option>
                        <option value="3">Heading 2</option>
                        <option value="4">Heading 3</option>
                    </select>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    @foreach ($marks as [$command, $icon, $label, $toggle])
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ Str::before($label, ' (') }}" tabindex="-1" aria-pressed="false"><x-icon :name="$icon" class="size-5" /></button>
                    @endforeach
                    <button type="button" class="toolbar-button toolbar-menu-button" data-menu-for="note-highlight-menu" popovertarget="note-highlight-menu" title="Highlight" aria-label="Highlight" aria-haspopup="menu" tabindex="-1">
                        <x-icon name="highlighter" class="size-5" /><x-icon name="chevron-down" class="size-3" />
                    </button>
                    @foreach ($scripts as [$command, $icon, $label, $toggle])
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}" tabindex="-1" aria-pressed="false"><x-icon :name="$icon" class="size-5" /></button>
                    @endforeach
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <button type="button" class="toolbar-button toolbar-menu-button" data-menu-for="note-align-menu" popovertarget="note-align-menu" title="Align" aria-label="Align" aria-haspopup="menu" tabindex="-1">
                        @foreach ($aligns as $value => [$icon, $word])
                            <x-icon :name="$icon" class="size-5" data-align-icon="{{ $value }}" :hidden="$value !== 'left'" />
                        @endforeach
                        <x-icon name="chevron-down" class="size-3" />
                    </button>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    @foreach ($lists as [$command, $icon, $label, $toggle])
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}" tabindex="-1" aria-pressed="false"><x-icon :name="$icon" class="size-5" /></button>
                    @endforeach
                    <button type="button" class="toolbar-button" data-command="outdent" title="Decrease indent (Shift+Tab)" aria-label="Decrease indent" tabindex="-1"><x-icon name="indent-decrease" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="indent" title="Increase indent (Tab)" aria-label="Increase indent" tabindex="-1"><x-icon name="indent-increase" class="size-5" /></button>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    @foreach ($blocks as [$command, $icon, $label, $toggle])
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}" tabindex="-1" @if ($toggle) aria-pressed="false" @endif><x-icon :name="$icon" class="size-5" /></button>
                    @endforeach
                    <button type="button" class="toolbar-button toolbar-menu-button" data-menu-for="note-callout-menu" popovertarget="note-callout-menu" title="Callout (Theorem, Formula, Definition...)" aria-label="Callout" aria-haspopup="menu" tabindex="-1">
                        <x-icon name="lightbulb" class="size-5" /><x-icon name="chevron-down" class="size-3" />
                    </button>
                    <button type="button" class="toolbar-button toolbar-menu-button" data-menu-for="note-math-menu" popovertarget="note-math-menu" title="Math & Physics symbols" aria-label="Symbols" aria-haspopup="dialog" tabindex="-1">
                        <x-icon name="sigma" class="size-5" /><x-icon name="chevron-down" class="size-3" />
                    </button>
                    <button type="button" class="toolbar-button" data-command="image" title="Insert image (Upload or URL)" aria-label="Insert image" tabindex="-1"><x-icon name="image" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="link" title="Link (Ctrl+K)" aria-label="Link" tabindex="-1" aria-pressed="false" aria-expanded="false" aria-controls="note-link-bar"><x-icon name="link" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="find" title="Find & Replace (Ctrl+F / Ctrl+H)" aria-label="Find and replace" tabindex="-1"><x-icon name="search" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="shortcuts" title="Keyboard shortcuts (Ctrl+/)" aria-label="Keyboard shortcuts" tabindex="-1"><x-icon name="keyboard" class="size-5" /></button>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <button type="button" class="toolbar-button" data-command="clear" title="Clear formatting" aria-label="Clear formatting" tabindex="-1"><x-icon name="remove-formatting" class="size-5" /></button>
                </div>

                {{-- The toolbar's menus: popovers, so a toolbar that scrolls sideways on a phone never cuts them off. --}}
                <div id="note-highlight-menu" class="toolbar-menu" popover role="menu" aria-label="Highlight">
                    @foreach ($highlights as $value => $word)
                        <button type="button" class="toolbar-menu-item" role="menuitemradio" aria-checked="false" data-highlight="{{ $value }}">
                            <span class="highlight-swatch" data-tone="{{ $value }}" aria-hidden="true"></span>{{ $word }}
                        </button>
                    @endforeach
                    <button type="button" class="toolbar-menu-item" role="menuitem" data-highlight="">
                        <x-icon name="x" class="size-4" />No highlight
                    </button>
                </div>
                <div id="note-align-menu" class="toolbar-menu" popover role="menu" aria-label="Align">
                    @foreach ($aligns as $value => [$icon, $word])
                        <button type="button" class="toolbar-menu-item" role="menuitemradio" aria-checked="{{ $value === 'left' ? 'true' : 'false' }}" data-align="{{ $value }}">
                            <x-icon :name="$icon" class="size-4" />{{ $word }}
                        </button>
                    @endforeach
                </div>
                <div id="note-callout-menu" class="toolbar-menu" popover role="menu" aria-label="Callouts">
                    @foreach ($callouts as $tone => [$label, $desc])
                        <button type="button" class="toolbar-menu-item" role="menuitem" data-callout-tone="{{ $tone }}">
                            <span class="callout-indicator" data-indicator="{{ $tone }}" aria-hidden="true"></span>
                            <span class="min-w-0">
                                <span class="block font-medium">{{ $label }}</span>
                                <span class="block text-xs text-fg-subtle">{{ $desc }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <div id="note-math-menu" class="toolbar-menu math-palette" popover role="dialog" aria-label="Symbols">
                    <div class="math-palette-section">
                        <p class="math-palette-title">Formulas (LaTeX)</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" data-formula-new="inline"><x-icon name="sigma" class="size-4" />In the line</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-formula-new="block"><x-icon name="sigma" class="size-4" />On its own line</button>
                        </div>
                    </div>
                    <div class="math-palette-section">
                        <p class="math-palette-title">Physics & Math Variables</p>
                        <div class="math-palette-grid">
                            @foreach ($greekLetters as $sym)
                                <button type="button" class="math-sym-button" data-insert-text="{{ $sym }}">{{ $sym }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="math-palette-section">
                        <p class="math-palette-title">Calculus & Operators</p>
                        <div class="math-palette-grid">
                            @foreach ($mathOps as $sym)
                                <button type="button" class="math-sym-button" data-insert-text="{{ $sym }}">{{ $sym }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="math-palette-section">
                        <p class="math-palette-title">Units & Formulas</p>
                        <div class="math-palette-grid">
                            @foreach ($mathTemplates as $template)
                                <button type="button" class="math-template-button" data-insert-text="{{ $template }}">{{ $template }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- A link: its address, for the selected words. --}}
                <div id="note-link-bar" class="editor-bar" data-link-bar hidden>
                    <label for="note-link" class="text-sm font-medium">Link address</label>
                    <input id="note-link" type="url" class="input input-sm min-w-0 flex-1" placeholder="https://…" autocomplete="off" data-link-input>
                    <button type="button" class="btn btn-primary btn-sm" data-link-apply>Apply</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-link-remove><x-icon name="unlink" class="size-4" />Remove</button>
                </div>

                {{-- In a table: its rows and columns. --}}
                <div class="editor-bar" role="toolbar" aria-label="Table" data-table-bar hidden>
                    @foreach (['addRowBefore' => 'Row above', 'addRowAfter' => 'Row below', 'addColumnBefore' => 'Column left', 'addColumnAfter' => 'Column right', 'toggleHeaderRow' => 'Header row', 'deleteRow' => 'Delete row', 'deleteColumn' => 'Delete column', 'deleteTable' => 'Delete table'] as $command => $word)
                        <button type="button" @class(['btn btn-ghost btn-sm', 'text-danger' => $command === 'deleteTable']) data-table-command="{{ $command }}">{{ $word }}</button>
                    @endforeach
                </div>

                {{-- Find & Replace bar --}}
                <div id="note-find-bar" class="editor-bar find-replace-bar" data-find-bar hidden>
                    <div class="find-group">
                        <x-icon name="search" class="size-4 shrink-0 text-fg-subtle" />
                        <input type="text" class="input input-sm find-input" placeholder="Find in note…" data-find-input autocomplete="off" aria-label="Find in note">
                        <span class="find-count" data-find-count hidden>0 / 0</span>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" data-find-prev title="Previous match (Shift+Enter)" aria-label="Previous match">
                            <x-icon name="arrow-up" class="size-4" />
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" data-find-next title="Next match (Enter)" aria-label="Next match">
                            <x-icon name="arrow-down" class="size-4" />
                        </button>
                    </div>
                    <div class="replace-group" data-replace-group hidden>
                        <input type="text" class="input input-sm replace-input" placeholder="Replace with…" data-replace-input autocomplete="off" aria-label="Replace with">
                        <button type="button" class="btn btn-secondary btn-sm" data-replace-one>Replace</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-replace-all>Replace All</button>
                    </div>
                    <div class="find-actions">
                        <button type="button" class="btn btn-ghost btn-sm" data-toggle-replace title="Toggle Replace (Ctrl+H)">
                            <x-icon name="arrow-left-right" class="size-4" /><span class="max-sm:sr-only">Replace</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" data-find-close title="Close (Escape)" aria-label="Close find bar">
                            <x-icon name="x" class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="note-content">
                    <div class="note-sheet" data-note-sheet>
                        <textarea class="note-title" rows="1" maxlength="{{ \App\Study\Notes::MAX_TITLE }}" placeholder="Untitled note" aria-label="Title" data-note-title>{{ $note?->title }}</textarea>
                        <div id="note-body" data-note-body></div>
                    </div>
                </div>
                <div class="note-count-bar">
                    <button type="button" class="note-count note-count-button" data-note-count title="Click for document statistics and reading time" aria-label="Document statistics"></button>
                </div>

                {{-- The note's side panels: its statistics, the shortcuts, and a picture to add. --}}
                <dialog id="note-stats-dialog" class="modal" aria-labelledby="note-stats-title" data-stats-dialog>
                    <div class="modal-panel">
                        <div class="modal-head">
                            <h2 id="note-stats-title" class="min-w-0 flex-1 text-lg font-semibold">Statistics</h2>
                            <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" data-dialog-close>
                                <x-icon name="x" />
                            </button>
                        </div>
                        <div class="px-5">
                            <div class="note-stats">
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-pages>1</p>
                                    <p class="note-stat-label">Pages</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-words>0</p>
                                    <p class="note-stat-label">Words</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-chars>0</p>
                                    <p class="note-stat-label">Characters</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-reading>0 min</p>
                                    <p class="note-stat-label">Reading Time</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-speaking>0 min</p>
                                    <p class="note-stat-label">Speaking Time</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-headings>0</p>
                                    <p class="note-stat-label">Headings</p>
                                </div>
                                <div class="note-stat">
                                    <p class="note-stat-value" data-stat-paragraphs>0</p>
                                    <p class="note-stat-label">Paragraphs</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </dialog>

                <dialog id="note-shortcuts-dialog" class="modal" aria-labelledby="note-shortcuts-title" data-shortcuts-dialog>
                    <div class="modal-panel">
                        <div class="modal-head">
                            <h2 id="note-shortcuts-title" class="min-w-0 flex-1 text-lg font-semibold">Keyboard shortcuts</h2>
                            <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" data-dialog-close>
                                <x-icon name="x" />
                            </button>
                        </div>
                        <div class="px-5">
                            <div class="shortcuts-grid">
                                <div class="shortcuts-section">
                                    <h3 class="shortcuts-category">General & History</h3>
                                    <dl class="shortcuts-list">
                                        <dt>Undo</dt><dd><kbd>Ctrl</kbd> + <kbd>Z</kbd></dd>
                                        <dt>Redo</dt><dd><kbd>Ctrl</kbd> + <kbd>Y</kbd></dd>
                                        <dt>Select All</dt><dd><kbd>Ctrl</kbd> + <kbd>A</kbd></dd>
                                        <dt>Save Note</dt><dd><kbd>Ctrl</kbd> + <kbd>S</kbd></dd>
                                        <dt>Find</dt><dd><kbd>Ctrl</kbd> + <kbd>F</kbd></dd>
                                        <dt>Find & Replace</dt><dd><kbd>Ctrl</kbd> + <kbd>H</kbd></dd>
                                        <dt>Print / PDF</dt><dd><kbd>Ctrl</kbd> + <kbd>P</kbd></dd>
                                    </dl>
                                </div>
                                <div class="shortcuts-section">
                                    <h3 class="shortcuts-category">Text Formatting</h3>
                                    <dl class="shortcuts-list">
                                        <dt>Bold</dt><dd><kbd>Ctrl</kbd> + <kbd>B</kbd></dd>
                                        <dt>Italic</dt><dd><kbd>Ctrl</kbd> + <kbd>I</kbd></dd>
                                        <dt>Underline</dt><dd><kbd>Ctrl</kbd> + <kbd>U</kbd></dd>
                                        <dt>Strikethrough</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>X</kbd></dd>
                                        <dt>Subscript</dt><dd><kbd>Ctrl</kbd> + <kbd>=</kbd></dd>
                                        <dt>Superscript</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>+</kbd></dd>
                                        <dt>Clear Formatting</dt><dd><kbd>Ctrl</kbd> + <kbd>\</kbd></dd>
                                    </dl>
                                </div>
                                <div class="shortcuts-section">
                                    <h3 class="shortcuts-category">Paragraph & Alignment</h3>
                                    <dl class="shortcuts-list">
                                        <dt>Align Left</dt><dd><kbd>Ctrl</kbd> + <kbd>L</kbd></dd>
                                        <dt>Align Center</dt><dd><kbd>Ctrl</kbd> + <kbd>E</kbd></dd>
                                        <dt>Align Right</dt><dd><kbd>Ctrl</kbd> + <kbd>R</kbd></dd>
                                        <dt>Justify</dt><dd><kbd>Ctrl</kbd> + <kbd>J</kbd></dd>
                                        <dt>Normal Text</dt><dd><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>0</kbd></dd>
                                        <dt>Heading 1–4</dt><dd><kbd>Ctrl</kbd> + <kbd>Alt</kbd> + <kbd>1–4</kbd></dd>
                                        <dt>Indent a list item / back</dt><dd><kbd>Tab</kbd> / <kbd>Shift</kbd> + <kbd>Tab</kbd></dd>
                                    </dl>
                                </div>
                                <div class="shortcuts-section">
                                    <h3 class="shortcuts-category">Structure & Insert</h3>
                                    <dl class="shortcuts-list">
                                        <dt>Page Break</dt><dd><kbd>Ctrl</kbd> + <kbd>Enter</kbd></dd>
                                        <dt>Bullet List</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>8</kbd></dd>
                                        <dt>Numbered List</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>7</kbd></dd>
                                        <dt>Task Checklist</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>9</kbd></dd>
                                        <dt>Insert Link</dt><dd><kbd>Ctrl</kbd> + <kbd>K</kbd></dd>
                                        <dt>Insert Image</dt><dd><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>I</kbd></dd>
                                        <dt>Shortcuts Guide</dt><dd><kbd>Ctrl</kbd> + <kbd>/</kbd></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                </dialog>

                <dialog id="note-image-dialog" class="modal" aria-labelledby="note-image-title" data-image-dialog>
                    <div class="modal-panel">
                        <div class="modal-head">
                            <h2 id="note-image-title" class="min-w-0 flex-1 text-lg font-semibold">Add a picture</h2>
                            <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" data-dialog-close>
                                <x-icon name="x" />
                            </button>
                        </div>
                        <div class="space-y-4 px-5">
                            <div class="image-tabs flex gap-2 border-b border-divider pb-2">
                                <button type="button" class="btn btn-sm btn-secondary" data-image-tab="upload" aria-pressed="true">From this device</button>
                                <button type="button" class="btn btn-sm btn-ghost" data-image-tab="url" aria-pressed="false">From the web</button>
                            </div>
                            <div data-image-panel="upload" class="space-y-3">
                                <div class="image-dropzone" data-image-dropzone>
                                    <x-icon name="image" class="size-8 mx-auto mb-2 text-fg-subtle" />
                                    <p class="text-sm font-medium">Drop a picture here, or</p>
                                    <x-button class="btn-sm mt-2" data-image-choose>Choose a picture</x-button>
                                    <p class="text-xs text-fg-subtle mt-2">PNG, JPEG, WebP or GIF, up to 10 MB</p>
                                </div>
                                <input type="file" hidden data-modal-file-input accept="image/png,image/jpeg,image/webp,image/gif">
                                <div data-image-upload-progress hidden class="text-sm text-center text-fg-subtle py-2">
                                    Uploading image…
                                </div>
                            </div>
                            <div data-image-panel="url" hidden class="space-y-3">
                                <div>
                                    <label for="note-image-url-input" class="text-xs font-medium text-fg-subtle block mb-1">Image URL</label>
                                    <input id="note-image-url-input" type="url" class="input input-sm w-full" placeholder="https://example.com/image.png" data-image-url-input autocomplete="off">
                                </div>
                            </div>
                            <div class="space-y-2 pt-2 border-t border-divider">
                                <div>
                                    <label for="note-image-caption-input" class="text-xs font-medium text-fg-subtle block mb-1">Caption (optional)</label>
                                    <input id="note-image-caption-input" type="text" class="input input-sm w-full" placeholder="e.g. Figure 1: Graph of f(x)" data-image-caption-input autocomplete="off">
                                </div>
                                <div>
                                    <label for="note-image-alt-input" class="text-xs font-medium text-fg-subtle block mb-1">Alt description (optional)</label>
                                    <input id="note-image-alt-input" type="text" class="input input-sm w-full" placeholder="Brief visual description for screen readers" data-image-alt-input autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="modal-actions">
                            <x-button data-dialog-close>Cancel</x-button>
                            <x-button variant="primary" data-insert-image-btn>Add</x-button>
                        </div>
                    </div>
                </dialog>

                {{-- A formula: its LaTeX, shown as it will look. --}}
                <dialog id="note-formula-dialog" class="modal" aria-labelledby="note-formula-title" data-formula-dialog>
                    <div class="modal-panel">
                        <div class="modal-head">
                            <h2 id="note-formula-title" class="min-w-0 flex-1 text-lg font-semibold" data-formula-heading>Formula</h2>
                            <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" data-dialog-close><x-icon name="x" /></button>
                        </div>
                        <div class="space-y-3 px-5">
                            <label for="note-formula-latex" class="text-sm font-medium">LaTeX</label>
                            <textarea id="note-formula-latex" class="input font-mono text-sm" rows="4" spellcheck="false" placeholder="\frac{a}{b} + \sqrt{x^2 + y^2}" data-formula-latex></textarea>
                            <p class="text-sm text-fg-muted">Powers <code>x^2</code>, indexes <code>a_n</code>, fractions <code>\frac{a}{b}</code>, roots <code>\sqrt{x}</code>, sums <code>\sum_{i=1}^{n}</code>, Greek letters <code>\alpha</code>. An AI can write any formula in LaTeX for you.</p>
                            <div class="formula-preview" data-formula-preview aria-live="polite"></div>
                        </div>
                        <div class="modal-actions">
                            <x-button data-dialog-close>Cancel</x-button>
                            <x-button variant="danger" data-formula-remove hidden>Remove</x-button>
                            <x-button variant="primary" data-formula-apply>Apply</x-button>
                        </div>
                    </div>
                </dialog>

                {{-- Floating Image Controls Bar for Selected Image --}}
                <div id="note-image-toolbar" class="image-floating-toolbar" role="toolbar" aria-label="Picture" data-image-toolbar hidden>
                    <div class="image-toolbar-group">
                        <button type="button" class="toolbar-button" data-image-align="left" title="Align Left" aria-label="Align Left">
                            <x-icon name="align-left" class="size-4" />
                        </button>
                        <button type="button" class="toolbar-button" data-image-align="center" title="Align Center" aria-label="Align Center">
                            <x-icon name="align-center" class="size-4" />
                        </button>
                        <button type="button" class="toolbar-button" data-image-align="right" title="Align Right" aria-label="Align Right">
                            <x-icon name="align-right" class="size-4" />
                        </button>
                    </div>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <div class="image-toolbar-group">
                        <button type="button" class="image-size-btn" data-image-width="25%" title="Small (25%)">25%</button>
                        <button type="button" class="image-size-btn" data-image-width="50%" title="Medium (50%)">50%</button>
                        <button type="button" class="image-size-btn" data-image-width="75%" title="Large (75%)">75%</button>
                        <button type="button" class="image-size-btn" data-image-width="100%" title="Full Width (100%)">100%</button>
                    </div>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <div class="image-toolbar-group">
                        <button type="button" class="toolbar-button" data-image-action="caption" title="Edit caption" aria-label="Edit caption">
                            <x-icon name="pencil" class="size-4" /><span class="text-xs ml-1 max-sm:sr-only">Caption</span>
                        </button>
                        <button type="button" class="toolbar-button text-danger" data-image-action="delete" title="Delete image" aria-label="Delete image">
                            <x-icon name="trash-2" class="size-4" />
                        </button>
                    </div>
                </div>
                <script type="application/json" data-note-doc>@json($payload)</script>
            </article>
        @endif
    </div>
</x-layouts.app>
