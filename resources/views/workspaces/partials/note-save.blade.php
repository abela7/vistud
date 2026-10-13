{{--
    Save the note now (resources/js/note/editor.js): it saves as you write, so this is for the moment you say
    "that's done". It then offers the note as a Word (.docx) file where LibreOffice can make one (App\Study\NoteExports).
    $compact: an icon button for the editor's toolbar.
--}}
<button type="button" class="{{ $compact ? 'toolbar-button note-mode' : 'btn btn-ghost note-mode' }}" data-note-save title="Save this note (Ctrl+S)">
    @if ($compact)
        <x-icon name="save" class="size-4" /><span class="sr-only">Save</span>
    @else
        <span><x-icon name="save" class="size-4" /><span class="max-sm:sr-only">Save</span></span>
    @endif
</button>
