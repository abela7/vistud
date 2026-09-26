{{-- A note or file ($item) of a session's material (App\Livewire\Workspaces\StudySession), with its Use toggle while the session is open. --}}
@php $used = $session->uses($item['key']); @endphp
<li class="flex items-start gap-2" wire:key="material-{{ $item['key'] }}">
    <x-icon :name="$item['icon']" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
    <span class="min-w-0 flex-1">
        <a href="{{ $item['url'] }}" class="item-link">{{ $item['name'] }}</a>
        <span class="block text-sm text-fg-muted">{{ $item['type'] }}</span>
    </span>
    @if ($open)
        <button type="button" class="material-toggle" wire:click="toggleMaterial('{{ $item['key'] }}')" aria-pressed="{{ $used ? 'true' : 'false' }}">
            <x-icon :name="$used ? 'check' : 'plus'" class="size-4" />Use<span class="sr-only"> {{ $item['name'] }} in the briefing</span>
        </button>
    @elseif ($used)
        <span class="status-chip shrink-0">Used</span>
    @endif
</li>
