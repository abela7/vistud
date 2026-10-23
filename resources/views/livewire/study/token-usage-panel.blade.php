{{--
    Token usage (App\Livewire\Study\TokenUsagePanel): the four periods' totals as tiles, then for the period chosen
    what the tokens went on, by area, course and model. Tokens are as the AI service counted them.
--}}
@php
    use App\Engine\Choices;
    use App\Engine\TokenUsage;
    $short = fn (int $n) => TokenUsage::short($n);
    $rows = fn (array $list) => array_slice($list, 0, 12, true);
@endphp
<div>
    <x-accordion id="tokens" title="Token usage" icon="trending-up">
        <x-slot:status>{{ $short($report['totals']['today']['total']) }} today · {{ $short($report['totals']['month']['total']) }} this month</x-slot:status>

        <dl class="token-tiles" data-tokens>
            @foreach (TokenUsage::PERIODS as $key => $label)
                @php $t = $report['totals'][$key]; @endphp
                <div class="usage-tile" data-tokens-period="{{ $key }}">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $short($t['total']) }} <span class="token-unit">tokens</span></dd>
                    <dd class="token-sub">{{ $short($t['in']) }} in · {{ $short($t['out']) }} out · {{ Choices::spent($t['cost']) }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="token-period" role="group" aria-label="Show what the tokens went on for">
            @foreach (TokenUsage::PERIODS as $key => $label)
                <button type="button" @class(['token-period-btn', 'is-active' => $report['period'] === $key]) wire:click="$set('period', '{{ $key }}')" aria-pressed="{{ $report['period'] === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($report['areas'] === [])
            <p class="acc-note">No AI use in this period yet.</p>
        @else
            @foreach (['areas' => ['By area', 'Area'], 'courses' => ['By course', 'Course'], 'models' => ['By model', 'Model']] as $part => [$heading, $column])
                <h3 class="token-heading">{{ $heading }}</h3>
                <table class="token-table" data-tokens-by="{{ $part }}">
                    <thead><tr><th scope="col">{{ $column }}</th><th scope="col">Tokens</th><th scope="col">In · out</th><th scope="col">Cost</th></tr></thead>
                    <tbody>
                        @foreach ($rows($report[$part]) as $name => $t)
                            <tr>
                                <th scope="row" class="token-name">{{ $name }}</th>
                                <td>{{ $short($t['total']) }}</td>
                                <td class="token-sub">{{ $short($t['in']) }} · {{ $short($t['out']) }}</td>
                                <td>{{ Choices::spent($t['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
            <p class="acc-note">Tokens are as the AI service counted them. "In" is what was sent (your words, your material and the chat so far); "out" is what the AI wrote.</p>
        @endif
    </x-accordion>
</div>
