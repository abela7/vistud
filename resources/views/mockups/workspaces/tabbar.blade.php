{{-- Mockup: a workspace's sections as a bottom tab bar on phones. --}}
@php
    $base = '/_mockups/workspaces/biology';
    $tabs = [
        ['overview', 'Overview', 'layout-grid', $base],
        ['modules', 'Modules', 'layers', "{$base}/modules"],
        ['notes', 'Notes', 'file-text', "{$base}/modules"],
        ['calendar', 'Calendar', 'calendar', '#'],
        ['progress', 'Progress', 'trending-up', "{$base}/progress"],
    ];
@endphp
@foreach ($tabs as [$key, $label, $icon, $href])
    <a href="{{ $href }}" class="tab-item" @if ($key === $current) aria-current="page" @endif>
        <x-icon :name="$icon" class="size-5" />{{ $label }}
    </a>
@endforeach
