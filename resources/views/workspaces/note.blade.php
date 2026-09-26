{{--
    A note (App\Http\Controllers\NotePageController). The editor host below is
    outside every Livewire component; resources/js/note/editor.js mounts
    Tiptap on it and autosaves through the JSON API (ADR 0003 §5).
--}}
@php
    $statusIcons = [
        'check' => 'saved',
        'loader-circle' => 'saving',
        'cloud-off' => 'local offline',
        'triangle-alert' => 'retrying nostorage conflict gone session blocked rejected account',
    ];
    $tools = [
        ['bold', 'bold', 'Bold (Ctrl+B)', true],
        ['italic', 'italic', 'Italic (Ctrl+I)', true],
        ['heading2', 'heading-2', 'Heading', true],
        ['heading3', 'heading-3', 'Subheading', true],
        ['bulletList', 'list', 'Bulleted list', true],
        ['orderedList', 'list-ordered', 'Numbered list', true],
        ['taskList', 'list-todo', 'To-do list', true],
        ['blockquote', 'text-quote', 'Quote', true],
        ['codeBlock', 'square-code', 'Code', true],
        ['divider', 'minus', 'Divider', false],
        ['undo', 'undo-2', 'Undo (Ctrl+Z)', false],
        ['redo', 'redo-2', 'Redo (Ctrl+Shift+Z)', false],
    ];
@endphp
<x-layouts.app :title="$note->displayTitle().' · '.$workspace->name" :workspace="$workspace" section="notes">
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <nav aria-label="Where this note is" class="min-w-0">
                <ol class="breadcrumbs">
                    <li><a href="{{ route('workspaces.show', [$workspace->id, 'notes']) }}">{{ $workspace->name }}</a></li>
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
            <div class="ml-auto flex items-center gap-2">
                @if ($note->trashedAt === null)
                    <p class="save-status" data-save-status data-state="saved" role="status">
                        @foreach ($statusIcons as $icon => $states)
                            <x-icon :name="$icon" class="size-4" data-for="{{ $states }}" />
                        @endforeach
                        <span data-save-label>Saved</span>
                    </p>
                @endif
                <livewire:workspaces.note-actions :note-id="$note->id" />
            </div>
        </div>

        <h1 class="sr-only" data-note-heading>{{ $note->displayTitle() }}</h1>

        @if ($note->trashedAt !== null)
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
                <x-alert tone="danger" title="This note can't be saved" data-alert="rejected" hidden>
                    <span data-rejected-message></span>
                </x-alert>
            </div>

            <article class="note-card" data-note-editor data-account="{{ auth()->id() }}" data-save-url="{{ route('api.v1.notes.update', $note->id) }}">
                <div class="note-toolbar" role="toolbar" aria-label="Formatting" aria-controls="note-body" data-note-toolbar>
                    @foreach ($tools as $i => [$command, $icon, $label, $toggle])
                        @if (in_array($command, ['bulletList', 'divider', 'undo'], true))
                            <span class="toolbar-separator" aria-hidden="true"></span>
                        @endif
                        <button type="button" class="toolbar-button" data-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}" tabindex="{{ $i === 0 ? 0 : -1 }}" @if ($toggle) aria-pressed="false" @endif>
                            <x-icon :name="$icon" class="size-5" />
                        </button>
                    @endforeach
                </div>
                <div class="note-content">
                    <textarea class="note-title" rows="1" maxlength="{{ \App\Study\Notes::MAX_TITLE }}" placeholder="Untitled note" aria-label="Title" data-note-title>{{ $note->title }}</textarea>
                    <div id="note-body" data-note-body></div>
                </div>
                <script type="application/json" data-note-doc>@json(['id' => $note->id, 'version' => $note->version, 'doc' => $note->doc])</script>
            </article>
        @endif
    </div>
</x-layouts.app>
