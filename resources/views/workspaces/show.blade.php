{{--
    A workspace's pages (App\Http\Controllers\WorkspacePageController).
    The Overview and Modules are built; the other sections say what they
    will hold until their steps arrive (docs/specs/workspaces.md §6).
--}}
@php
    use App\Study\Workspaces;
    use Illuminate\Support\Carbon;

    $sections = collect(Workspaces::SECTIONS)->keyBy(0);
    [, $label, $icon] = $sections[$section];
    $date = fn (?string $d) => $d ? Carbon::parse($d)->format('j M Y') : null;
    $upcoming = [
        'notes' => ['Notes & files', 'Write notes in the editor, and upload lecture slides, PDFs and photos.'],
        'calendar' => ['Calendar', 'Lectures, labs, quizzes, exams and deadlines for this subject, on a calendar and in "Coming up".'],
        'progress' => ['Progress', 'What you\'ve mastered and what\'s worth revisiting, in plain words, from your journal.'],
    ];
@endphp
<x-layouts.app :title="$section === 'overview' ? $workspace->name : $label.' · '.$workspace->name" :workspace="$workspace" :section="$section">
    <div class="mx-auto max-w-5xl space-y-6">
        @if (session('workspace-notice'))
            <x-alert tone="success">{{ session('workspace-notice') }}</x-alert>
        @endif
        @if ($workspace->archived())
            <x-alert tone="warning" title="This workspace is archived">
                It's hidden from your workspaces, and nothing in it is deleted.
                <button type="button" class="font-semibold underline underline-offset-2" x-data x-on:click="Livewire.dispatch('workspace-restore')">Restore it</button>
            </x-alert>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <x-workspace.chip :workspace="$workspace" size="lg" class="max-sm:hidden" />
                <div class="min-w-0">
                    @if ($section === 'overview')
                        <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $workspace->name }}</h1>
                        @if ($workspace->subtitle() !== '')
                            <p class="text-fg-muted">{{ $workspace->subtitle() }}</p>
                        @endif
                    @else
                        <p class="text-sm break-words text-fg-muted">{{ $workspace->name }}</p>
                        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $label }}</h1>
                    @endif
                </div>
            </div>
            @if ($section === 'overview')
                <x-button icon="pencil" x-data x-on:click="$dispatch('workspace-form-open')">Edit</x-button>
            @endif
        </div>

        @if ($section === 'overview')
            <div class="grid gap-4 lg:grid-cols-3">
                <section class="space-y-3 rounded-xl border border-border bg-surface-raised p-5 lg:row-span-2">
                    <h2 class="font-semibold">About</h2>
                    <dl class="space-y-3">
                        @foreach (['Course code' => $workspace->code, 'Term' => $workspace->term, 'Starts' => $date($workspace->startsOn), 'Ends' => $date($workspace->endsOn)] as $term => $value)
                            @if ($value)
                                <div>
                                    <dt class="text-sm text-fg-muted">{{ $term }}</dt>
                                    <dd class="font-medium break-words">{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    @unless ($workspace->code || $workspace->term || $workspace->startsOn || $workspace->endsOn)
                        <p class="text-sm text-fg-muted">Add a course code, term or dates with <strong class="font-semibold text-fg">Edit</strong>.</p>
                    @endunless
                </section>

                <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="flex items-start gap-3 rounded-xl border border-border bg-surface-raised p-5 text-fg no-underline hover:border-border-strong">
                    <span class="avatar shrink-0"><x-icon name="layers" class="size-4" /></span>
                    <span class="min-w-0 space-y-1">
                        <span class="block font-semibold">Modules</span>
                        @if ($modules === [])
                            <span class="block text-sm text-fg-muted">Split {{ $workspace->name }} into units, like “Week 1: Cells”, and keep each unit's folders together.</span>
                            <span class="block text-sm font-medium text-accent-contrast">Add the first module</span>
                        @else
                            @foreach (array_slice($modules, 0, 4) as $module)
                                <span class="block text-sm break-words">{{ $module->title }}</span>
                            @endforeach
                            @if (count($modules) > 4)
                                <span class="block text-sm text-fg-muted">and {{ count($modules) - 4 }} more</span>
                            @endif
                        @endif
                    </span>
                </a>

                @foreach ($upcoming as $key => [$title, $about])
                    <a href="{{ route('workspaces.show', [$workspace->id, $key]) }}" class="flex items-start gap-3 rounded-xl border border-border bg-surface-raised p-5 text-fg no-underline hover:border-border-strong">
                        <span class="avatar shrink-0"><x-icon :name="$sections[$key][2]" class="size-4" /></span>
                        <span class="space-y-1">
                            <span class="block font-semibold">{{ $title }}</span>
                            <span class="block text-sm text-fg-muted">{{ $about }}</span>
                            <span class="block text-sm font-medium text-fg-subtle">Coming next</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @elseif ($section === 'modules')
            <livewire:workspaces.modules :workspace-id="$workspace->id" />
        @else
            <section class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
                <x-workspace.chip :workspace="$workspace" size="lg" />
                <h2 class="text-lg font-semibold">{{ $label }} is coming next</h2>
                <p class="max-w-md text-fg-muted">{{ $upcoming[$section][1] }}</p>
            </section>
        @endif
    </div>

    <livewire:workspaces.form :workspace-id="$workspace->id" />
</x-layouts.app>
