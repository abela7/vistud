{{-- An attempt's outcome as a badge. The word carries the meaning. --}}
@props(['entry'])
@php $outcome = \App\View\JournalEntryText::outcome($entry); @endphp
@if ($outcome === 'correct')
    <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />Correct</span>
@elseif ($outcome === 'incorrect')
    <span class="badge badge-warning"><x-icon name="circle-alert" class="size-3.5" />Incorrect</span>
@elseif ($outcome !== null)
    <span class="badge badge-neutral">{{ ucfirst($outcome) }}</span>
@endif
