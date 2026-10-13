{{-- A note or file ($item) in a session's Notes & files panel (App\Livewire\Workspaces\StudySession): open it, or Use it, which the tutor then reads, while the session is open. --}}
@php
    $used = $session->uses($item['key']);
    $isNote = str_starts_with($item['key'], 'note:');
    $itemId = substr($item['key'], strpos($item['key'], ':') + 1);
@endphp
<li class="item-row" wire:key="material-{{ $item['key'] }}" x-show="has(@js(\Illuminate\Support\Str::lower($item['name'])))">
    <span class="item-icon ws-colour-{{ $item['colour'] }}" aria-hidden="true"><x-icon :name="$item['icon']" class="size-5" /></span>
    <span class="min-w-0 flex-1">
        <a href="{{ $item['url'] }}" class="font-semibold text-fg hover:underline cursor-pointer break-words" x-on:click.prevent.stop="$dispatch(@js($isNote ? 'session-open-note' : 'session-open-file'), { url: @js($item['url']), title: @js($item['name']), name: @js($item['name']) }); $el.closest('dialog')?.close()">{{ $item['name'] }}</a>
        <span class="item-meta">{{ $item['type'] }}</span>
    </span>
    <div class="relative z-10 flex items-center gap-1.5 shrink-0" x-on:click.stop>
        <button type="button"
            class="session-icon-btn size-7 text-fg-subtle hover:text-danger hover:bg-danger-subtle hover:border-danger/30 transition-colors"
            x-on:click.stop.prevent="$dispatch('session-confirm', { kind: @js($isNote ? 'note' : 'file'), id: @js($itemId), name: @js($item['name']) })"
            title="{{ $isNote ? 'Move' : 'Delete' }} {{ $item['name'] }} {{ $isNote ? 'to trash' : 'from platform' }}"
            aria-label="{{ $isNote ? 'Move' : 'Delete' }} {{ $item['name'] }} {{ $isNote ? 'to trash' : 'from platform' }}">
            <x-icon name="trash-2" class="size-3.5" />
        </button>
        @if ($open)
            <button type="button" class="material-toggle" wire:click="toggleMaterial('{{ $item['key'] }}')" aria-pressed="{{ $used ? 'true' : 'false' }}">
                <x-icon :name="$used ? 'check' : 'plus'" class="size-4" />Use<span class="sr-only"> {{ $item['name'] }} for the tutor</span>
            </button>
        @elseif ($used)
            <span class="status-chip shrink-0">Used</span>
        @endif
    </div>
</li>
