{{--
    An assignment's plan (App\Livewire\Workspaces\AssignmentPlan), kept as plain as it can be: one card with a thin
    progress line, then the sections and their steps as a quiet outline. Nothing is asked for until it is wanted:
    adding is a "+ Add" that opens where it stands, a step's dates, priority, labels, notes and person are in its
    ⋯ menu, and milestones, the team and marking criteria appear only when there is something in them or the
    student asks. The pre-made plans are behind "Add a pre-made plan" and the whole plan can be cleared.
--}}
@php
    $tones = ['ok' => 'blue', 'tight' => 'amber', 'late' => 'red'];
    $tone = $tones[$pace['tone'] ?? 'ok'] ?? 'blue';
    $parts = $plan->parts();
    $loose = $plan->steps();
    $items = $plan->criteria();
    $milestones = $plan->milestones();
    $blank = $plan->empty();
    $milestonesShown = $showMilestones || $milestones !== [];
    $teamShown = $showTeam || $plan->members !== [];
    $criteriaShown = $showCriteria || $items !== [];
    $states = ['not_yet' => 'Not yet', 'partly' => 'Partly', 'met' => 'Met'];
    $icons = ['todo' => 'circle', 'doing' => 'circle-dot', 'stuck' => 'circle-alert', 'done' => 'circle-check'];
@endphp
<section class="plan-card question-panel space-y-4" aria-labelledby="plan-heading" x-data="{ part: false }">
    <div class="plan-head">
        <h2 id="plan-heading" class="font-semibold">Plan</h2>
        @unless ($blank)
            <div class="plan-head-actions">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAi" aria-expanded="{{ $aiOpen ? 'true' : 'false' }}"><x-icon name="brain" class="size-4" />Plan with an AI</button>
                @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-head', 'label' => 'the plan', 'items' => [
                    ['Add a pre-made plan', 'layout-grid', 'toggleStarters', false],
                    ['Clear the plan', 'trash-2', 'askClear', false],
                ]])
            </div>
        @endunless
    </div>

    @error('plan') <p class="field-error">{{ $message }}</p> @enderror

    @if ($confirmClear)
        <div class="plan-confirm" role="alert">
            <p class="font-medium">Remove everything in the plan?</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-danger btn-sm" wire:click="clearPlan">Remove it all</button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelClear">Keep it</button>
            </div>
        </div>
    @endif

    @if ($progress->total > 0)
        <div @class(['plan-progress', "ws-colour-{$tone}"])>
            <div class="plan-progress-row">
                <p class="plan-percent tabular-nums">{{ $progress->percent }}%</p>
                <p class="text-sm text-fg-muted">{{ $progress->done }} of {{ $progress->total }} done{{ $progress->weighted ? ' · by marks' : '' }}</p>
                <x-workspace.plan-health :health="$health" reasons class="plan-progress-health" />
            </div>
            <div class="meter" role="progressbar" aria-label="Plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress->percent }}">
                <span style="width: {{ $progress->percent }}%"></span>
            </div>
            @if ($pace)
                <p class="plan-pace"><x-icon :name="$pace['tone'] === 'ok' ? 'hourglass' : 'circle-alert'" class="size-4 shrink-0" />{{ $pace['words'] }}</p>
            @endif
        </div>
        @if ($progress->complete() && $assignment->status !== 'done')
            <div class="plan-complete">
                <p class="font-medium">Everything in your plan is ticked.</p>
                <button type="button" class="btn btn-primary btn-sm" wire:click="markDone"><x-icon name="circle-check" class="size-4" />Mark the assignment done</button>
            </div>
        @endif
    @endif

    @if ($blank && ! $showStarters && ! $aiOpen)
        <div class="plan-empty">
            <p class="font-semibold">Break it down</p>
            <div class="flex flex-wrap justify-center gap-2">
                <button type="button" class="btn btn-primary" wire:click="toggleAi"><x-icon name="brain" class="size-4" />Plan it with an AI</button>
                <button type="button" class="btn btn-secondary" x-on:click="part = true; $nextTick(() => $refs.partName.focus())"><x-icon name="plus" class="size-4" />Add a section</button>
            </div>
            <button type="button" class="quiet-link" wire:click="toggleStarters"><x-icon name="layout-grid" class="size-4" />Add a pre-made plan</button>
        </div>
    @endif

    @if ($showStarters)
        <div class="plan-picker">
            <div class="flex items-center justify-between gap-2">
                <p class="font-semibold">Add a pre-made plan</p>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleStarters">Close</button>
            </div>
            <ul class="plan-starters" role="list">
                @foreach ($starters as $key => $starter)
                    <li>
                        <button type="button" class="plan-starter" wire:click="useStarter('{{ $key }}')">
                            <x-icon :name="$starter['icon']" class="size-5 shrink-0" />
                            <span class="min-w-0"><span class="font-semibold">{{ $starter['label'] }}</span> <span class="text-sm text-fg-muted">{{ $starter['hint'] }}</span></span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($aiOpen)
        <div class="plan-ai space-y-4" x-data="{ copied: false }">
            <div class="flex items-center justify-between gap-2">
                <p class="font-semibold">Plan it with an AI</p>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAi">Close</button>
            </div>

            <div class="field">
                <label for="plan-brief" class="field-label">The assignment brief <span class="font-normal text-fg-muted">(optional)</span></label>
                <textarea id="plan-brief" class="input mt-2" rows="3" wire:model.live.debounce.500ms="brief" placeholder="Paste the brief or the rubric here, for a better plan"></textarea>
            </div>

            <section class="space-y-2" aria-labelledby="plan-ai-1">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="plan-ai-1" class="font-semibold"><span class="step-number" aria-hidden="true">1</span>Copy the prompt into an AI</h3>
                    <x-button icon="copy" x-on:click="
                        const text = $refs.prompt.textContent;
                        const done = () => { copied = true; setTimeout(() => copied = false, 2500); };
                        const select = () => {
                            const range = document.createRange();
                            range.selectNodeContents($refs.prompt);
                            const selection = window.getSelection();
                            selection.removeAllRanges();
                            selection.addRange(range);
                            try { if (document.execCommand('copy')) done(); } catch (e) {}
                        };
                        navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(text).then(done, select) : select();
                    "><span x-show="! copied">Copy the prompt</span><span x-show="copied" x-cloak>Copied</span></x-button>
                </div>
                <details class="plan-prompt">
                    <summary>See the prompt</summary>
                    <pre class="briefing-text card-maker-prompt" tabindex="0" aria-label="The prompt" x-ref="prompt" wire:loading.class="opacity-60" wire:target="brief">{{ $prompt }}</pre>
                </details>
                <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
            </section>

            <section class="space-y-2" aria-labelledby="plan-ai-2">
                <h3 id="plan-ai-2" class="font-semibold"><span class="step-number" aria-hidden="true">2</span>Paste its reply here</h3>
                <div class="field">
                    <label for="plan-reply" class="sr-only">The AI's reply</label>
                    <textarea id="plan-reply" class="input capture-paste" rows="4" wire:model="reply"></textarea>
                    @error('reply') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <x-button wire:click="readReply" icon="scan-text" wire:loading.attr="aria-busy" wire:target="readReply" busy-label="Reading…">Read the reply</x-button>
            </section>

            @if ($read)
                <section class="space-y-3" aria-labelledby="plan-ai-3">
                    <h3 id="plan-ai-3" class="font-semibold"><span class="step-number" aria-hidden="true">3</span>Look it over, then add it</h3>
                    <div class="plan-preview">
                        @foreach ($read['parts'] as $part)
                            <p class="font-medium">{{ $part['title'] }}@if ($part['marks'] !== null) <span class="plan-marks-chip">{{ $part['marks'] }}%</span>@endif</p>
                            @if ($part['steps'] !== [])
                                <ul class="plan-preview-steps">@foreach ($part['steps'] as $step)<li>{{ $step }}</li>@endforeach</ul>
                            @endif
                        @endforeach
                        @if ($read['steps'] !== [])
                            <p class="font-medium">Other steps</p>
                            <ul class="plan-preview-steps">@foreach ($read['steps'] as $step)<li>{{ $step }}</li>@endforeach</ul>
                        @endif
                        @if ($read['milestones'] !== [])
                            <p class="font-medium">Milestones</p>
                            <ul class="plan-preview-steps">@foreach ($read['milestones'] as $milestone)<li>{{ $milestone['title'] }}@if ($milestone['due_on'] !== null) <span class="plan-marks-chip">{{ \Illuminate\Support\Carbon::parse($milestone['due_on'])->format('j M') }}</span>@endif</li>@endforeach</ul>
                        @endif
                        @if ($read['criteria'] !== [])
                            <p class="font-medium">Marking criteria</p>
                            <ul class="plan-preview-steps">@foreach ($read['criteria'] as $criterion)<li>{{ $criterion['title'] }}@if ($criterion['marks'] !== null) <span class="plan-marks-chip">{{ $criterion['marks'] }}%</span>@endif</li>@endforeach</ul>
                        @endif
                    </div>
                    <x-button variant="primary" icon="plus" wire:click="addRead" wire:loading.attr="aria-busy" wire:target="addRead" busy-label="Adding…">Add to my plan</x-button>
                </section>
            @endif
        </div>
    @endif

    @foreach ($parts as $part)
        @php
            $steps = $plan->steps($part->id);
            [$doneSteps, $allSteps] = $plan->counts($part);
            $partState = $plan->stateOf($part);
            $actions = [];
            if ($steps === []) {
                foreach ([['doing', 'Start it', 'play'], ['stuck', 'I am stuck', 'circle-alert'], ['todo', 'Back to to do', 'circle'], ['done', 'Mark done', 'circle-check']] as [$to, $text, $icon]) {
                    if ($partState !== $to) {
                        $actions[] = [$text, $icon, "setState('{$part->id}', '{$to}')", false];
                    }
                }
            }
            $actions[] = ['Edit details', 'pencil', "startEdit('{$part->id}')", false];
            $actions[] = ['Move up', 'arrow-up', "move('{$part->id}', 'up')", $loop->first];
            $actions[] = ['Move down', 'arrow-down', "move('{$part->id}', 'down')", $loop->last];
            $actions[] = [$steps === [] ? 'Delete section' : 'Delete section and its steps', 'trash-2', "remove('{$part->id}')", false];
        @endphp
        <section wire:key="plan-part-{{ $part->id }}" class="plan-part" aria-label="Part: {{ $part->title }}">
            @if ($editing === $part->id)
                <div class="plan-part-head">@include('livewire.workspaces.partials.plan-edit', ['item' => $part])</div>
            @else
                <div class="plan-part-head">
                    @if ($steps === [])
                        <button type="button" class="task-check is-{{ $partState }}" wire:click="setState('{{ $part->id }}', '{{ $partState === 'done' ? 'todo' : 'done' }}')" aria-pressed="{{ $partState === 'done' ? 'true' : 'false' }}">
                            <x-icon :name="$icons[$partState]" class="size-5" />
                            <span class="sr-only">Done: {{ $part->title }}</span>
                        </button>
                    @endif
                    <h3 @class(['plan-part-title', 'text-fg-muted line-through' => $steps === [] && $part->done()])>{{ $part->title }}</h3>
                    @if ($part->weight !== null)
                        <span class="plan-marks-chip">{{ $part->weight }}%</span>
                    @endif
                    @if ($steps !== [])
                        <span class="plan-part-count tabular-nums">{{ $doneSteps }}/{{ $allSteps }}</span>
                    @endif
                    @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$part->id, 'label' => $part->title, 'items' => $actions])
                </div>
                <div class="plan-part-meta">@include('livewire.workspaces.partials.plan-meta', ['item' => $part])</div>
            @endif
            @if ($steps !== [])
                <ul class="plan-steps" role="list" aria-label="Steps of {{ $part->title }}">
                    @foreach ($steps as $step)
                        @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last, 'depth' => 1])
                    @endforeach
                </ul>
            @endif
            <x-workspace.quiet-add label="Add a step" :submit="'addStep(\''.$part->id.'\')'">
                <label for="plan-step-{{ $part->id }}" class="sr-only">Add a step to {{ $part->title }}</label>
                <input id="plan-step-{{ $part->id }}" type="text" class="input" wire:model="stepText.{{ $part->id }}" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="What is the step?" autocomplete="off">
            </x-workspace.quiet-add>
            @error('stepText.'.$part->id) <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endforeach

    @if ($loose !== [])
        <div class="plan-part">
            @if ($parts !== [])
                <h3 class="plan-subhead">Other steps</h3>
            @endif
            <ul class="plan-steps" role="list" aria-label="Steps outside a section">
                @foreach ($loose as $step)
                    @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last, 'depth' => 1])
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Adding a section or a loose step: quiet until asked for. --}}
    <div class="plan-adders">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            @unless ($blank)
                <button type="button" class="quiet-add-button" x-show="! part" x-on:click="part = true; $nextTick(() => $refs.partName.focus())"><x-icon name="folder-plus" class="size-4" />Add a section</button>
                <x-workspace.quiet-add label="Add a step" icon="plus" submit="addStep('')">
                    <label for="plan-step-loose" class="sr-only">Add a step</label>
                    <input id="plan-step-loose" type="text" class="input" wire:model="stepText.loose" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="A step that is in no section" autocomplete="off">
                </x-workspace.quiet-add>
            @endunless
        </div>
        <form wire:submit="addPart" novalidate class="plan-add" x-show="part" x-cloak x-on:keydown.escape="part = false">
            <label for="plan-part-name" class="sr-only">Name of the section</label>
            <input id="plan-part-name" type="text" class="input" x-ref="partName" wire:model="partText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Name the section, like “Research”" autocomplete="off">
            <button type="submit" class="btn btn-secondary btn-sm">Add</button>
            <button type="button" class="btn btn-ghost btn-sm" x-on:click="part = false">Close</button>
        </form>
        @error('stepText.loose') <p class="field-error">{{ $message }}</p> @enderror
        @error('partText') <p class="field-error">{{ $message }}</p> @enderror
        @error('partMarks') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    @if ($milestonesShown)
        <section class="plan-extra" aria-labelledby="milestones-heading">
            <h3 id="milestones-heading" class="plan-extra-title"><x-icon name="flag" class="size-4 text-fg-muted" />Milestones
                @if ($milestones !== [])
                    <span class="count-pill" title="{{ count(array_filter($milestones, fn ($m) => $m->done())) }} of {{ count($milestones) }} reached">{{ count(array_filter($milestones, fn ($m) => $m->done())) }}/{{ count($milestones) }}</span>
                @endif
            </h3>
            @if ($milestones !== [])
                <ul class="plan-criteria" role="list" aria-label="Milestones">
                    @foreach ($milestones as $milestone)
                        <li wire:key="plan-milestone-{{ $milestone->id }}" class="plan-criterion">
                            @if ($editing === $milestone->id)
                                @include('livewire.workspaces.partials.plan-edit', ['item' => $milestone])
                            @else
                                <button type="button" class="task-check is-{{ $milestone->done() ? 'done' : 'todo' }}" wire:click="setState('{{ $milestone->id }}', '{{ $milestone->done() ? 'pending' : 'achieved' }}')" aria-pressed="{{ $milestone->done() ? 'true' : 'false' }}">
                                    <x-icon :name="$milestone->done() ? 'circle-check' : 'circle'" class="size-5" />
                                    <span class="sr-only">Reached: {{ $milestone->title }}</span>
                                </button>
                                <div class="plan-step-main">
                                    <p @class(['plan-step-title', 'text-fg-muted line-through' => $milestone->done()])>{{ $milestone->title }}</p>
                                    @include('livewire.workspaces.partials.plan-meta', ['item' => $milestone])
                                </div>
                                @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$milestone->id, 'label' => $milestone->title, 'items' => [
                                    ['Edit details', 'pencil', "startEdit('{$milestone->id}')", false],
                                    ['Delete', 'trash-2', "remove('{$milestone->id}')", false],
                                ]])
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-workspace.quiet-add label="Add a milestone" icon="flag" submit="addMilestone">
                <label for="plan-milestone" class="sr-only">Add a milestone</label>
                <input id="plan-milestone" type="text" class="input" wire:model="milestoneText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Like “First draft done”" autocomplete="off">
                <label for="plan-milestone-date" class="sr-only">Its day (optional)</label>
                <input id="plan-milestone-date" type="date" class="input plan-date-input" wire:model="milestoneDate">
            </x-workspace.quiet-add>
            @error('milestoneText') <p class="field-error">{{ $message }}</p> @enderror
            @error('milestoneDate') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    @if ($teamShown)
        @php
            $load = collect($workload)->filter(fn ($row) => $row['member'] !== null)->keyBy(fn ($row) => $row['member']->id);
            $nobody = collect($workload)->first(fn ($row) => $row['member'] === null);
            $anyMe = collect($plan->members)->contains(fn ($m) => $m->me);
        @endphp
        <section class="plan-extra" aria-labelledby="team-heading">
            <h3 id="team-heading" class="plan-extra-title"><x-icon name="users" class="size-4 text-fg-muted" />Team
                @if ($plan->members !== [])
                    <span class="count-pill">{{ count($plan->members) }}</span>
                @endif
            </h3>
            @if ($plan->members !== [])
                <ul class="plan-team" role="list" aria-label="The team">
                    @foreach ($plan->members as $member)
                        @php $row = $load[$member->id] ?? null; @endphp
                        <li wire:key="plan-member-{{ $member->id }}" class="plan-member">
                            @if ($renamingMember === $member->id)
                                <form wire:submit="saveRename" novalidate class="plan-edit" x-on:keydown.escape.prevent="$wire.cancelRename()">
                                    <div class="field plan-edit-wide">
                                        <label for="plan-member-rename" class="sr-only">New name for {{ $member->name }}</label>
                                        <input id="plan-member-rename" type="text" class="input" wire:model="memberRename" maxlength="{{ \App\Study\Plans::MAX_NAME }}" autocomplete="off" autofocus>
                                        @error('memberRename') <p class="field-error mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelRename">Cancel</button>
                                    </div>
                                </form>
                            @else
                                <x-avatar :name="$member->name" />
                                <div class="plan-member-main">
                                    <p class="plan-step-title">{{ $member->name }}@if ($member->me) <span class="plan-marks-chip">You</span>@endif</p>
                                    @if ($row)
                                        <div class="meter" role="progressbar" aria-label="Done of {{ $member->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($row['done'] / $row['total'] * 100) }}"><span style="width: {{ (int) round($row['done'] / $row['total'] * 100) }}%"></span></div>
                                        <p class="text-sm text-fg-muted tabular-nums">{{ $row['done'] }} of {{ $row['total'] }} done</p>
                                    @else
                                        <p class="text-sm text-fg-muted">Nothing yet</p>
                                    @endif
                                </div>
                                @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-member-'.$member->id, 'label' => $member->name, 'items' => [
                                    ['Rename', 'pencil', "startRename('{$member->id}')", false],
                                    $member->me ? ['This is not me', 'user-round', "toggleMe('{$member->id}', false)", false] : ['This is me', 'user-round', "toggleMe('{$member->id}', true)", false],
                                    ['Take out of the team', 'trash-2', "removeMember('{$member->id}')", false],
                                ]])
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($nobody)
                    <p class="text-sm text-fg-muted tabular-nums">{{ $nobody['total'] }} {{ $nobody['total'] === 1 ? 'thing has' : 'things have' }} nobody yet.</p>
                @endif
            @endif
            <x-workspace.quiet-add label="Add a person" icon="user-round" submit="addMember">
                <label for="plan-member-name" class="sr-only">Add a person</label>
                <input id="plan-member-name" type="text" class="input" wire:model="memberName" maxlength="{{ \App\Study\Plans::MAX_NAME }}" placeholder="Their name" autocomplete="off">
                @unless ($anyMe)
                    <x-checkbox name="member-me" id="plan-member-me" label="This is me" wire:model="memberMe" />
                @endunless
            </x-workspace.quiet-add>
            @error('memberName') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    @if ($criteriaShown)
        <section class="plan-extra" aria-labelledby="criteria-heading">
            <h3 id="criteria-heading" class="plan-extra-title"><x-icon name="clipboard-check" class="size-4 text-fg-muted" />Marking criteria
                @if ($criteria['total'] > 0)
                    <span class="count-pill" title="{{ $criteria['met'] }} of {{ $criteria['total'] }} met">{{ $criteria['met'] }}/{{ $criteria['total'] }}</span>
                    <span class="plan-extra-note">about {{ $criteria['percent'] }}%</span>
                @endif
            </h3>
            @if ($items !== [])
                <ul class="plan-criteria" role="list" aria-label="Marking criteria">
                    @foreach ($items as $criterion)
                        <li wire:key="plan-criterion-{{ $criterion->id }}" class="plan-criterion">
                            @if ($editing === $criterion->id)
                                @include('livewire.workspaces.partials.plan-edit', ['item' => $criterion])
                            @else
                                <p class="break-words font-medium">{{ $criterion->title }}@if ($criterion->weight !== null) <span class="plan-marks-chip">{{ $criterion->weight }}%</span>@endif</p>
                                <div class="segmented segmented-sm" role="group" aria-label="How well “{{ $criterion->title }}” is met">
                                    @foreach ($states as $key => $word)
                                        <button type="button" @class(['segmented-option', 'is-current' => $criterion->state === $key]) wire:click="setState('{{ $criterion->id }}', '{{ $key }}')" aria-pressed="{{ $criterion->state === $key ? 'true' : 'false' }}">{{ $word }}</button>
                                    @endforeach
                                </div>
                                @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$criterion->id, 'label' => $criterion->title, 'items' => [
                                    ['Rename or give marks', 'pencil', "startEdit('{$criterion->id}')", false],
                                    ['Move up', 'arrow-up', "move('{$criterion->id}', 'up')", $loop->first],
                                    ['Move down', 'arrow-down', "move('{$criterion->id}', 'down')", $loop->last],
                                    ['Delete', 'trash-2', "remove('{$criterion->id}')", false],
                                ]])
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-workspace.quiet-add label="Add a criterion" icon="clipboard-check" submit="addCriterion">
                <label for="plan-criterion" class="sr-only">Add a criterion</label>
                <input id="plan-criterion" type="text" class="input" wire:model="criterionText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Like “Use of sources”" autocomplete="off">
                <label for="plan-criterion-marks" class="sr-only">Marks, as a percentage (optional)</label>
                <input id="plan-criterion-marks" type="number" class="input plan-marks-input" wire:model="criterionMarks" min="1" max="100" inputmode="numeric" placeholder="Marks %">
            </x-workspace.quiet-add>
            @error('criterionText') <p class="field-error">{{ $message }}</p> @enderror
            @error('criterionMarks') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    {{-- What else to track: only what isn't there yet. --}}
    @if (! $blank && (! $milestonesShown || ! $teamShown || ! $criteriaShown))
        <div class="plan-also">
            <span class="text-sm text-fg-muted">Also track</span>
            @unless ($milestonesShown)
                <button type="button" class="quiet-link" wire:click="$set('showMilestones', true)"><x-icon name="flag" class="size-4" />Milestones</button>
            @endunless
            @unless ($teamShown)
                <button type="button" class="quiet-link" wire:click="$set('showTeam', true)"><x-icon name="users" class="size-4" />Team</button>
            @endunless
            @unless ($criteriaShown)
                <button type="button" class="quiet-link" wire:click="$set('showCriteria', true)"><x-icon name="clipboard-check" class="size-4" />Marking criteria</button>
            @endunless
        </div>
    @endif

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</section>
