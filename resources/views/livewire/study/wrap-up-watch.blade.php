{{-- App\Livewire\Study\WrapUpWatch: checks every few seconds only while a session's summary is being written. --}}
<div @if ($watching) wire:poll.8s="check" @endif hidden>
    <x-toast :message="$notice" />
</div>
