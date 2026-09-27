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
    ];
    $aligns = ['left' => ['align-left', 'Left'], 'center' => ['align-center', 'Centre'], 'right' => ['align-right', 'Right'], 'justify' => ['align-justify', 'Justify']];
    $highlights = ['yellow' => 'Yellow', 'green' => 'Green', 'blue' => 'Blue', 'pink' => 'Pink', 'purple' => 'Purple'];
@endphp
<x-layouts.app :title="$shownTitle.' · '.$workspace->name" :workspace="$workspace" :section="$inModule ? 'modules' : 'notes'">
    <div class="note-page" data-note-page>
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
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
                    <button type="button" class="btn btn-ghost note-mode" data-note-read>
                        <span class="when-editing"><x-icon name="book-open-text" class="size-4" /><span class="max-sm:sr-only">Read</span></span>
                        <span class="when-reading"><x-icon name="pencil" class="size-4" /><span class="max-sm:sr-only">Edit</span></span>
                    </button>
                    <button type="button" class="btn btn-ghost note-mode" data-note-focus>
                        <span class="when-page"><x-icon name="maximize-2" class="size-4" /><span class="max-sm:sr-only">Full screen</span></span>
                        <span class="when-focused"><x-icon name="minimize-2" class="size-4" /><span class="max-sm:sr-only">Exit full screen</span></span>
                    </button>
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
                    <p class="note-title">{{ $note->displayTitle() }}</p>
                    <p class="text-fg-muted">{{ \App\Study\NoteDoc::text($note->doc) ?: 'This note is empty.' }}</p>
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

            <article class="note-card" data-note-editor data-account="{{ auth()->id() }}"
                @if ($new) data-create-url="{{ route('api.v1.notes.store') }}" data-place-type="{{ $place[0] }}" data-place-id="{{ $place[1] }}" @else data-save-url="{{ route('api.v1.notes.update', $note->id) }}" @endif>
                <div class="note-toolbar" role="toolbar" aria-label="Formatting" aria-controls="note-body" data-note-toolbar>
                    <button type="button" class="toolbar-button" data-command="undo" title="Undo (Ctrl+Z)" aria-label="Undo" tabindex="0"><x-icon name="undo-2" class="size-5" /></button>
                    <button type="button" class="toolbar-button" data-command="redo" title="Redo (Ctrl+Shift+Z)" aria-label="Redo" tabindex="-1"><x-icon name="redo-2" class="size-5" /></button>
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    <label for="note-block-style" class="sr-only">Text style</label>
                    <select id="note-block-style" class="toolbar-select" data-block-style tabindex="-1">
                        <option value="paragraph">Text</option>
                        <option value="1">Heading 1</option>
                        <option value="2">Heading 2</option>
                        <option value="3">Heading 3</option>
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
                    <span class="toolbar-separator" aria-hidden="true"></span>
                    @foreach ($blocks as [$command, $icon, $label, $toggle])
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}" tabindex="-1" @if ($toggle) aria-pressed="false" @endif><x-icon :name="$icon" class="size-5" /></button>
                    @endforeach
                    <button type="button" class="toolbar-button" data-command="link" title="Link (Ctrl+K)" aria-label="Link" tabindex="-1" aria-pressed="false" aria-expanded="false" aria-controls="note-link-bar"><x-icon name="link" class="size-5" /></button>
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

                <div class="note-content">
                    <textarea class="note-title" rows="1" maxlength="{{ \App\Study\Notes::MAX_TITLE }}" placeholder="Untitled note" aria-label="Title" data-note-title>{{ $note?->title }}</textarea>
                    <div id="note-body" data-note-body></div>
                </div>
                <p class="note-count" data-note-count aria-hidden="true"></p>
                <script type="application/json" data-note-doc>@json($payload)</script>
            </article>
        @endif
    </div>
</x-layouts.app>
