{{-- A workspace's sections as the bottom tab bar on phones. --}}
@props(['workspace', 'section'])
@foreach (\App\Study\Workspaces::SECTIONS as [$key, $label, $icon])
    <a href="{{ route('workspaces.show', $key === 'overview' ? $workspace->id : [$workspace->id, $key]) }}" class="tab-item" @if ($key === $section) aria-current="page" @endif>
        <x-icon :name="$icon" class="size-5" />{{ $key === 'notes' ? 'Notes' : $label }}
    </a>
@endforeach
