{{--
    The AI usage report (App\Livewire\Study\UsageReport): the period (tabs), its tokens as one figure, a column chart
    of them over the period (one series, the accent; the bar under the finger or pointer is read out above the chart,
    and the same numbers are in a table for screen readers), then where they went, one breakdown at a time.
--}}
@php
    use App\Engine\Choices;
    use App\Engine\TokenUsage;
    $short = fn (int $n) => TokenUsage::short($n);
    $count = count($series);
    // Axis labels that never collide: every hour band of six today, every fifth day of a month, every one otherwise.
    $every = match ($period) { 'today' => 6, 'month' => 5, default => 1 };
    $now = collect($series)->search(fn ($b) => $b['now']);
    $readout = fn (array $b) => $b['tip'].' · '.number_format($b['total']).' tokens · '.Choices::spent($b['cost']);
@endphp
<div class="usage-report">
    <header class="usage-head">
        <h1 class="usage-title">AI usage</h1>
        <div class="usage-periods" role="tablist" aria-label="Period">
            @foreach (TokenUsage::PERIODS as $key => $label)
                <button type="button" role="tab" @class(['usage-period', 'is-active' => $period === $key]) wire:click="$set('period', '{{ $key }}')" aria-selected="{{ $period === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </header>

    {{-- The one figure. --}}
    <section class="usage-card usage-hero" aria-label="{{ TokenUsage::PERIODS[$period] }}">
        <p class="usage-hero-label">Tokens · {{ mb_strtolower(TokenUsage::PERIODS[$period]) }}</p>
        <p class="usage-hero-value">{{ $short($total['total']) }}</p>
        <p class="usage-hero-sub">{{ Choices::spent($total['cost']) }} · {{ number_format($total['calls']) }} {{ $total['calls'] === 1 ? 'answer' : 'answers' }} · {{ $short($total['in']) }} sent, {{ $short($total['out']) }} written</p>
    </section>

    @if ($total['total'] === 0 && $total['cost'] === 0)
        <section class="usage-card usage-empty">
            <x-icon name="sparkles" class="size-6 text-fg-muted" />
            <p>No AI use {{ $period === 'all' ? 'yet' : mb_strtolower(TokenUsage::PERIODS[$period]) }}.</p>
        </section>
    @else
        {{-- Over time: one column a bucket; the one under the finger is read out above. --}}
        <section class="usage-card" aria-labelledby="usage-time-title"
            x-data="{ readout: @js($now !== false ? $readout($series[$now]) : '') }">
            <div class="usage-card-head">
                <h2 id="usage-time-title" class="usage-card-title">Over time</h2>
                <p class="usage-readout" x-text="readout" aria-live="polite"></p>
            </div>
            <div class="usage-chart" style="--bars: {{ $count }}" aria-hidden="true">
                <div class="usage-grid">
                    <span class="usage-grid-line"><span class="usage-grid-label">{{ $short($scale) }}</span></span>
                    <span class="usage-grid-line"><span class="usage-grid-label">{{ $short(intdiv($scale, 2)) }}</span></span>
                    <span class="usage-grid-line is-base"></span>
                </div>
                <div class="usage-bars">
                    @foreach ($series as $i => $bucket)
                        <button type="button" tabindex="-1" @class(['usage-bar', 'is-now' => $bucket['now'], 'is-empty' => $bucket['total'] === 0])
                            x-on:mouseenter="readout = @js($readout($bucket))" x-on:click="readout = @js($readout($bucket))">
                            <span class="usage-bar-fill" style="height: {{ $bucket['total'] > 0 ? max(2, round(100 * $bucket['total'] / $scale, 1)) : 0 }}%"></span>
                        </button>
                    @endforeach
                </div>
                <div class="usage-axis">
                    @foreach ($series as $i => $bucket)
                        <span class="usage-axis-label">{{ $i % $every === 0 ? $bucket['label'] : '' }}</span>
                    @endforeach
                </div>
            </div>
            <table class="sr-only">
                <caption>Tokens over {{ mb_strtolower(TokenUsage::PERIODS[$period]) }}</caption>
                <thead><tr><th scope="col">When</th><th scope="col">Tokens</th><th scope="col">Cost</th></tr></thead>
                <tbody>
                    @foreach ($series as $bucket)
                        <tr><th scope="row">{{ $bucket['tip'] }}</th><td>{{ number_format($bucket['total']) }}</td><td>{{ Choices::spent($bucket['cost']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Where they went: one breakdown at a time, each a share of the period. --}}
        <section class="usage-card" aria-labelledby="usage-where-title">
            <div class="usage-card-head">
                <h2 id="usage-where-title" class="usage-card-title">Where it went</h2>
                <div class="usage-by" role="tablist" aria-label="Break down by">
                    @foreach (['area' => 'Area', 'course' => 'Course', 'model' => 'Model'] as $key => $label)
                        <button type="button" role="tab" @class(['usage-by-btn', 'is-active' => $by === $key]) wire:click="$set('by', '{{ $key }}')" aria-selected="{{ $by === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <ul class="usage-parts" role="list">
                @foreach ($parts as $part)
                    <li class="usage-part">
                        <div class="usage-part-line">
                            <span class="usage-part-name" title="{{ $part['name'] }}">{{ $part['name'] }}</span>
                            <span class="usage-part-value">{{ $short($part['total']) }} <span class="usage-part-share">{{ $part['share'] }}%</span></span>
                        </div>
                        <div class="usage-part-track" aria-hidden="true"><span class="usage-part-fill" style="width: {{ max($part['total'] > 0 ? 1 : 0, $part['share']) }}%"></span></div>
                    </li>
                @endforeach
            </ul>
        </section>

        <p class="usage-note">Tokens are as the AI service counted them: "sent" is your words, your material and the chat so far; "written" is the AI's answers.</p>
    @endif
</div>
