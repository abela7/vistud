{{--
    The admin area's Skills page (App\Livewire\Admin\SkillsAdmin): each skill with what it is for, where it is used and
    a switch for everyone.
--}}
<div class="space-y-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Skills</h1>
        <p class="max-w-2xl text-fg-muted">Skills teach the AI how to do one kind of thing well. Turn one off and its instructions are left out completely, so it costs nothing. Students can also turn ViStud's chat skills off for themselves.</p>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>

    <ul class="space-y-3" role="list">
        @foreach ($skills as $name => $skill)
            @php $isOn = ! in_array($name, $off, true); @endphp
            <li class="question-panel" wire:key="skill-{{ $name }}">
                <div class="panel-head">
                    <span class="item-icon" aria-hidden="true"><x-icon :name="$skill['where'] === \App\Engine\Skills\Skills::VISTUD ? 'sparkles' : 'puzzle'" class="size-5" /></span>
                    <div class="panel-head-text">
                        <h2 class="panel-title">
                            {{ $skill['title'] }}
                            @if ($isOn)
                                <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />On</span>
                            @else
                                <span class="badge">Off</span>
                            @endif
                        </h2>
                        <p class="panel-hint">{{ $skill['description'] }}</p>
                        <p class="text-xs text-fg-muted">{{ $skill['where'] === \App\Engine\Skills\Skills::VISTUD ? 'In ViStud\'s own chats: the tutor, assignments and ViStud AI.' : 'In the plugin students download for Claude, ChatGPT or Gemini.' }}</p>
                    </div>
                    <div class="panel-head-actions">
                        <x-button size="sm" :variant="$isOn ? 'secondary' : 'primary'" wire:click="toggle('{{ $name }}')" wire:loading.attr="aria-busy" wire:target="toggle('{{ $name }}')" busy-label="Saving…">{{ $isOn ? 'Turn off' : 'Turn on' }}</x-button>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</div>
