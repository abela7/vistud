{{-- A note or file ($item) in a session's Notes & files panel (App\Livewire\Workspaces\StudySession): open it, or Use it in the briefing while the session is open. --}}
@php $used = $session->uses($item['key']); @endphp
<li class="item-row" wire:key="material-{{ $item['key'] }}" x-show="has(@js(\Illuminate\Support\Str::lower($item['name'])))">
    <span class="item-icon ws-colour-{{ $item['colour'] }}" aria-hidden="true"><x-icon :name="$item['icon']" class="size-5" /></span>
    <span class="min-w-0 flex-1">
        <a href="{{ $item['url'] }}" class="tile-link">{{ $item['name'] }}</a>
        <span class="item-meta">{{ $item['type'] }}</span>
    </span>
    @if ($open)
        <button type="button" class="material-toggle" wire:click="toggleMaterial('{{ $item['key'] }}')" aria-pressed="{{ $used ? 'true' : 'false' }}">
            <x-icon :name="$used ? 'check' : 'plus'" class="size-4" />Use<span class="sr-only"> {{ $item['name'] }} in the briefing</span>
        </button>
    @elseif ($used)
        <span class="status-chip shrink-0">Used</span>
    @endif
</li>
