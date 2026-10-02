{{-- A workspace's sections as the bottom tab bar on phones. --}}
@props(['workspace', 'section'])
{{-- Six at most, with short names, so they fit the narrowest phone; the Calendar is a link on the Overview's Coming up and in the sidebar's menu. --}}
@foreach (\App\Study\Workspaces::SECTIONS as [$key, $label, $icon])
    @continue($key === 'calendar')
    <a href="{{ route('workspaces.show', $key === 'overview' ? $workspace->id : [$workspace->id, $key]) }}" class="tab-item" @if ($key === $section) aria-current="page" @endif>
        <x-icon :name="$icon" class="size-5" /><span>{{ ['notes' => 'Notes', 'assignments' => 'Tasks', 'flashcards' => 'Cards'][$key] ?? $label }}</span>
    </a>
@endforeach
