{{--
    The pinned notes' button in the corner (App\Livewire\Study\PinnedNotes):
    one pin is a button that opens its note, several are one button that opens
    a short list, each with its own unpin. Notes open in a window of their own
    beside the page (resources/js/note-window.js). The menu is shell.js's
    (data-menu-button / data-menu-panel); Livewire leaves its open or closed
    state alone (wire:ignore.self). Empty when nothing is pinned.
--}}
<div>
    @if ($pinned !== [])
        @php
            $windowLink = fn (\App\Study\NoteDetails $n) => route('workspaces.notes.show', [$n->workspaceId, $n->id, 'window' => 1]);
        @endphp
        {{-- Room at the foot of the page, so the last row isn't under the button. --}}
        <div class="pin-clearance" aria-hidden="true"></div>
        <div class="pin-dock" data-pin-dock>
            @if (count($pinned) === 1)
                @php $note = $pinned[0]; @endphp
                <a class="pin-fab" href="{{ $windowLink($note) }}" target="vistud-note-{{ $note->id }}" data-note-window title="Open “{{ $note->displayTitle() }}”">
                    <x-icon name="pin" class="size-5 shrink-0" />
                    <span class="sr-only">Pinned note: </span>
                    <span class="pin-fab-label">{{ $note->displayTitle() }}</span>
                    <span class="sr-only"> (opens in a new window)</span>
                </a>
            @else
                <button type="button" class="pin-fab" wire:ignore.self data-menu-button aria-controls="pin-menu" aria-expanded="false" title="Pinned notes">
                    <x-icon name="pin" class="size-5 shrink-0" />
                    {{-- Text, not an attribute: the button's own attributes are ignored by updates, its content isn't. --}}
                    <span class="pin-fab-label">Pinned notes</span>
                    <span class="pin-count" aria-hidden="true">{{ count($pinned) }}</span>
                    <span class="sr-only">, {{ count($pinned) }} pinned</span>
                </button>
                <div id="pin-menu" class="pin-menu" wire:ignore.self data-menu-panel popover="manual" hidden role="group" aria-label="Pinned notes">
                    <ul role="list">
                        @foreach ($pinned as $note)
                            <li class="pin-row" wire:key="pin-{{ $note->id }}">
                                <a class="menu-item pin-open" href="{{ $windowLink($note) }}" target="vistud-note-{{ $note->id }}" data-note-window>
                                    <span class="min-w-0 flex-1">
                                        <span class="pin-title">{{ $note->displayTitle() }}</span>
                                        <span class="pin-where">{{ $names[$note->workspaceId] ?? '' }}</span>
                                    </span>
                                    <span class="sr-only">(opens in a new window)</span>
                                </a>
                                <button type="button" class="topbar-button pin-remove" wire:click="unpin('{{ $note->id }}')" title="Unpin">
                                    <x-icon name="pin-off" class="size-4" />
                                    <span class="sr-only">Unpin {{ $note->displayTitle() }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
    <x-toast :message="$notice" :tone="$noticeTone" />
</div>
