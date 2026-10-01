{{--
    Share the note as a file (resources/js/note/editor.js): the device's share
    sheet where it can share files, a download where it can't. PDF and Word
    only where LibreOffice is (App\Study\NoteExports).
    $id: the menu's id; $compact: an icon button for the editor's toolbar.
--}}
@php
    $kinds = ['pdf' => ['PDF', 'file-text'], 'docx' => ['Word', 'file-type'], 'md' => ['Markdown', 'file-code'], 'txt' => ['Plain text', 'file']];
@endphp
<div class="relative">
    <button type="button" class="{{ $compact ? 'toolbar-button note-mode' : 'btn btn-ghost note-mode' }}" data-menu-button aria-controls="{{ $id }}" aria-expanded="false" title="Share this note as a file">
        @if ($compact)
            <x-icon name="share-2" class="size-4" /><span class="sr-only">Share</span>
        @else
            <span><x-icon name="share-2" class="size-4" /><span class="max-sm:sr-only">Share</span></span>
        @endif
    </button>
    <div id="{{ $id }}" class="row-menu" data-menu-panel popover="manual" hidden>
        @foreach (\App\Study\NoteExports::available() as $format)
            <button type="button" class="menu-item" data-share-format="{{ $format }}">
                <x-icon :name="$kinds[$format][1]" class="size-4" />
                <span class="flex-1">{{ $kinds[$format][0] }}</span>
                <span class="text-xs text-fg-muted">.{{ $format }}</span>
            </button>
        @endforeach
    </div>
</div>
