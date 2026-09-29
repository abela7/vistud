{{--
    A passing notice ("The question is deleted."), shown as a toast at the
    bottom that closes itself (resources/js/toasts.js). Renders nothing
    without a message. A fresh ID each time it renders, so the same words
    twice in a row show twice: a Livewire notice lasts one request
    (App\Livewire\Concerns\Notices).
--}}
@props(['message' => null, 'tone' => 'success', 'actionLabel' => null, 'actionEvent' => null, 'actionPayload' => null])
@php
    $tone = $tone !== 'success' ? $tone : ($noticeTone ?? 'success');
    $actionLabel = $actionLabel ?? ($noticeActionLabel ?? null);
    $actionEvent = $actionEvent ?? ($noticeActionEvent ?? null);
    $actionPayload = $actionPayload ?? ($noticeActionPayload ?? null);
@endphp
@if (filled($message))
    @php $id = \Illuminate\Support\Str::random(16); @endphp
    <span hidden
        data-toast="{{ $message }}"
        data-toast-tone="{{ $tone }}"
        data-toast-id="{{ $id }}"
        @if ($actionLabel) data-toast-action-label="{{ $actionLabel }}" @endif
        @if ($actionEvent) data-toast-action-event="{{ $actionEvent }}" @endif
        @if ($actionPayload) data-toast-action-payload="{{ json_encode($actionPayload) }}" @endif
        wire:key="toast-{{ $id }}"></span>
@endif
