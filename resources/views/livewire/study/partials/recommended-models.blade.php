{{--
    The models suggested to a student choosing what to pay for (App\Engine\Providers::recommended): the smartest, the
    best value and the cheapest, each able to call tools. A card is a link to where its provider's API is paid for and
    its keys are made.
--}}
@php
    $kinds = ['smartest' => ['Smartest', 'crown'], 'value' => ['Best value', 'gem'], 'cheapest' => ['Cheapest', 'coins']];
@endphp
<x-accordion id="recommended" title="Recommended models" icon="sparkles" :open="$keyState === 'none'">
    <x-slot:status>{{ collect($recommended)->flatten(1)->count() }} picks. Open one to get its key.</x-slot:status>
    <div class="rec-body">
        @foreach ($recommended as $kind => $picks)
            <x-accordion :id="'rec-'.$kind" :title="$kinds[$kind][0]" :icon="$kinds[$kind][1]" :level="3">
                <x-slot:status>{{ count($picks) === 1 ? '1 pick' : count($picks).' picks' }}</x-slot:status>
                <ul class="rec-grid" role="list">
                    @foreach ($picks as $pick)
                        <li>
                            <a href="{{ $pick['url'] }}" class="rec-card" target="_blank" rel="noopener noreferrer" data-recommended="{{ $pick['model'] }}">
                                <span class="rec-card-head">
                                    <span class="rec-card-name">{{ $pick['name'] }}</span>
                                    <x-icon name="external-link" class="size-4 rec-card-go" />
                                </span>
                                <span class="rec-card-by">{{ $pick['providerName'] }}@if ($pick['images']) · sees pictures @endif</span>
                                <span class="rec-card-why">{{ $pick['why'] }}</span>
                                @if ($pick['session'] !== null)
                                    <strong class="rec-card-price">About {{ $pick['session'] < 0.01 ? 'under 1¢' : '$'.number_format($pick['session'], 2) }} a session</strong>
                                @endif
                                <span class="rec-card-cta">Get a {{ $pick['providerName'] }} key <span class="sr-only">(opens in a new tab)</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-accordion>
        @endforeach
    </div>
</x-accordion>
