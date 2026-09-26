{{-- Mockup: the Biology workspace's Progress: topics and the journal in plain words. Static content for review. --}}
<x-layouts.app title="Progress · Biology">
    <x-slot:sidebar>@include('mockups.workspaces.sidebar', ['current' => 'progress'])</x-slot:sidebar>
    <x-slot:tabbar>@include('mockups.workspaces.tabbar', ['current' => 'progress'])</x-slot:tabbar>

    <div class="mx-auto max-w-4xl space-y-8">
        @include('mockups.workspaces.header', ['title' => 'Progress'])
        <p class="-mt-4 max-w-2xl text-fg-muted">What ViStud has noticed about your studying in Biology. Only you can see it, and you can correct anything that's wrong.</p>

        <section class="space-y-3">
            <h2 class="font-semibold">Topics</h2>
            <ul class="account-list divide-y divide-divider" role="list">
                @foreach ([
                    ['Mitosis vs meiosis', 'badge-warning', 'Shaky', 'You mixed them up on Tuesday\'s practice quiz, then got it right after the diagram. Worth one more go.'],
                    ['Chromosome number', 'badge-warning', 'Shaky', 'Not practised for 2 weeks.'],
                    ['Cell cycle', 'badge-accent', 'Learning', '2 of 3 questions right so far.'],
                    ['Parts of a cell', 'badge-success', 'Mastered', 'Right 4 times in a row, without help.'],
                    ['Photosynthesis', 'badge-neutral', 'Not started', 'Starts with tomorrow\'s lecture.'],
                ] as [$topic, $tone, $state, $why])
                    <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3.5">
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="font-semibold">{{ $topic }}</p>
                            <p class="text-sm text-fg-muted">{{ $why }}</p>
                        </div>
                        <span class="badge {{ $tone }}">{{ $state }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="font-semibold">What happened <span class="font-normal text-fg-muted">· your journal for Biology</span></h2>
            <ol class="account-list divide-y divide-divider" role="list">
                @foreach ([
                    ['Tue 14:25', 'You answered practice quiz question 3 correctly.', 'badge-success', 'Correct'],
                    ['Tue 14:18', 'You studied a diagram comparing mitosis and meiosis.', null, null],
                    ['Tue 14:16', 'ViStud noticed you may think mitosis makes 4 cells.', null, null],
                    ['Tue 14:12', 'You said meiosis confused you.', null, null],
                    ['Tue 14:10', 'You answered practice quiz question 3 incorrectly.', 'badge-warning', 'Incorrect'],
                    ['Mon 10:00', 'You attended the lecture on cell division.', null, null],
                ] as [$when, $what, $tone, $label])
                    <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3">
                        <p class="w-24 shrink-0 text-sm text-fg-muted">{{ $when }}</p>
                        <p class="min-w-0 flex-1">{{ $what }}</p>
                        @if ($tone)
                            <span class="badge {{ $tone }}">{{ $label }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-layouts.app>
