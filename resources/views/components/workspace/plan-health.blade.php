{{--
    How an assignment's work is going (App\Study\PlanDetails::health): on track, at risk or off track, in the colour of
    each and always with its words and an icon, never by colour alone. With `reasons` the why follows in a few words on
    the same line. $health is null when there is nothing to judge, and then nothing is shown.
--}}
@props(['health', 'reasons' => false])
@php
    $look = ['on_track' => ['On track', 'trending-up', 'green'], 'at_risk' => ['At risk', 'circle-alert', 'amber'], 'off_track' => ['Off track', 'triangle-alert', 'red']];
@endphp
@if ($health)
    @php [$words, $icon, $colour] = $look[$health['state']]; @endphp
    <span {{ $attributes->class(['plan-health', "ws-colour-{$colour}"]) }}>
        <span class="question-status"><x-icon :name="$icon" class="size-4" />{{ $words }}</span>
        @if ($reasons && $health['reasons'] !== [])
            <span class="plan-health-reasons">{{ implode(' · ', $health['reasons']) }}</span>
        @endif
    </span>
@endif
