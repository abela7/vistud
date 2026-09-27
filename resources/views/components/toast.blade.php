{{--
    A passing notice ("The question is deleted."), shown as a toast at the
    bottom that closes itself (resources/js/toasts.js). Renders nothing
    without a message. A fresh ID each time it renders, so the same words
    twice in a row show twice: a Livewire notice lasts one request
    (App\Livewire\Concerns\Notices).
--}}
@props(['message' => null, 'tone' => 'success'])
@if (filled($message))
    @php $id = \Illuminate\Support\Str::random(16); @endphp
    <span hidden data-toast="{{ $message }}" data-toast-tone="{{ $tone }}" data-toast-id="{{ $id }}" wire:key="toast-{{ $id }}"></span>
@endif
