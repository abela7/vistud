{{--
    Token usage in AI settings (App\Livewire\Study\TokenUsagePanel): one row, today's and this month's tokens, and the
    way to the report (App\Livewire\Study\UsageReport).
--}}
@php
    $short = fn (int $n) => \App\Engine\TokenUsage::short($n);
@endphp
<div>
    <a href="{{ route('ai-usage') }}" class="acc usage-link">
        <span class="item-icon" aria-hidden="true"><x-icon name="trending-up" class="size-5" /></span>
        <span class="acc-text">
            <span class="panel-title">Token usage</span>
            <span class="acc-status">{{ $short($report['totals']['today']['total']) }} today · {{ $short($report['totals']['month']['total']) }} this month</span>
        </span>
        <span class="usage-link-go">Open report<x-icon name="chevron-right" class="size-4" /></span>
    </a>
</div>
