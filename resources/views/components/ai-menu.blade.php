{{--
    The ✦ menu (docs/specs/vistud-2-blueprint.md §3.6.5): the AI jobs a thing is offered, one tap on the thing itself. It
    asks nothing until a choice is made; the choice goes to the sheet (App\Livewire\Workspaces\AiAssist), which shows what
    came back for the student to keep or discard. $kind is card, question, file, folder, note or topic; $id the thing; $label
    names it for people using a screen reader. Needs <livewire:workspaces.ai-assist> on the page. Placed and moved like a row
    menu (resources/js/shell.js, floating.js).
--}}
@props(['kind', 'id', 'label'])
@php
    $items = [
        'card' => [['card.improve', 'Improve', 'wand-sparkles'], ['card.shorter', 'Shorter', 'minus'], ['card.fix', 'Fix the wording', 'check'], ['card.more', 'Two more like this', 'copy']],
        'question' => [['question.clarify', 'Clarify', 'wand-sparkles'], ['question.split', 'Split into two', 'minus'], ['question.answer', 'Answer from my notes', 'notebook-text'], ['tutor', 'Ask the tutor', 'message-square-text']],
        'file' => [['file.summarise', 'Summarise', 'scan-text'], ['file.note', 'Make a note from it', 'file-text'], ['file.cards', 'Make cards from it', 'gallery-vertical-end'], ['file.topics', 'Find topics', 'list-checks']],
        'note' => [['note.cards', 'Make cards from it', 'gallery-vertical-end']],
        'topic' => [['topic.cards', 'Make cards from it', 'gallery-vertical-end']],
        'folder' => [['folder.where', 'Where should these go?', 'folder-input']],
    ][$kind] ?? [];
@endphp
<div class="relative shrink-0 ai-menu">
    <button type="button" class="topbar-button ai-menu-button" wire:ignore.self data-menu-button aria-controls="ai-menu-{{ $kind }}-{{ $id }}" aria-expanded="false" title="Ask the AI">
        <x-icon name="sparkles" class="size-5" />
        {{-- Text, not an attribute: the button's own attributes are ignored by updates, its content isn't. --}}
        <span class="sr-only">AI help with {{ $label }}</span>
    </button>
    <div id="ai-menu-{{ $kind }}-{{ $id }}" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>
        @foreach ($items as [$action, $text, $icon])
            <button type="button" class="menu-item" x-data
                x-on:click="{{ $action === 'tutor'
                    ? "Livewire.dispatch('ai-ask-tutor', { id: ".json_encode($id).' })'
                    : "Livewire.dispatch('ai-assist', { action: ".json_encode($action).', id: '.json_encode($id).' })' }}">
                <x-icon :name="$icon" class="size-4" />{{ $text }}
            </button>
        @endforeach
    </div>
</div>
