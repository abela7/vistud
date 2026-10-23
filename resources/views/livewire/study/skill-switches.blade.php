{{--
    A student's skill switches in AI settings (App\Livewire\Study\SkillSwitches): each skill of ViStud's chats an admin
    left on, saved the moment it is switched.
--}}
<div>
    <x-accordion id="skills" title="Skills" icon="sparkles">
        <x-slot:status>{{ count(array_filter($skills, fn ($skill) => $skill['on'])) }} of {{ count($skills) }} on</x-slot:status>
        <p class="acc-note">Skills teach the AI to do one kind of thing well. Turn one off and the AI won't use it in your chats.</p>
        @forelse ($skills as $name => $skill)
            <div wire:key="my-skill-{{ $name }}">
                <x-checkbox :name="'skill-'.$name" :label="$skill['title']" :hint="$skill['description']" :checked="$skill['on']" wire:click="toggle('{{ $name }}')" />
            </div>
        @empty
            <p class="acc-note">No skills are on right now.</p>
        @endforelse
    </x-accordion>
</div>
