{{--
    A course's guide (App\Http\Controllers\CourseGuidePageController): the talk with the tutor that sets the course up
    (what it is about, how it is assessed), or, from Modules, adds some of its modules (?for=modules) knowing what the course
    is about. Everything the guide proposes waits for the student's ticks.
--}}
<x-layouts.app :title="($for === 'modules' ? 'Add modules' : 'Set up').' · '.$workspace->name" :workspace="$workspace" :section="$for === 'modules' ? 'modules' : 'overview'">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page :title="$for === 'modules' ? 'Add modules' : 'Set up with the AI'" :back-href="$for === 'modules' ? route('workspaces.show', [$workspace->id, 'modules']) : route('workspaces.show', $workspace->id)" :back-to="$for === 'modules' ? 'Modules' : 'Home'" :eyebrow="$workspace->name"
            :context="$for === 'modules' ? 'The AI reads what your course says. Tell it the weeks; it adds only what you tick.' : 'The AI asks, you answer; it adds only what you tick.'">
            <x-slot:menu>
                <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('guide-start-over')"><x-icon name="rotate-ccw" class="size-4" />Start over</button>
                @if ($for === 'modules')
                    <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="menu-item"><x-icon name="layers" class="size-4" />Add modules myself</a>
                @else
                    <a href="{{ route('workspaces.show', [$workspace->id, 'setup' => 1]) }}" class="menu-item"><x-icon name="pencil" class="size-4" />Set the course up myself</a>
                    <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="menu-item"><x-icon name="layers" class="size-4" />Go to Modules</a>
                @endif
            </x-slot:menu>
        </x-page>
        <livewire:workspaces.guide-chat :workspace-id="$workspace->id" :for="$for" />
    </div>
</x-layouts.app>
