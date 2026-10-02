{{--
    An assignment's plan (App\Livewire\Workspaces\AssignmentPlan): how far it has got and how it is going, its
    parts with their steps (which can hold steps), the steps outside every part, its milestones, the team that
    shares the work, and the marking criteria to check oneself against. One plan for any kind of assignment, from an
    essay to a group project: start from a starter or an AI's reply, or add things by hand.
--}}
@php
    $tones = ['ok' => 'blue', 'tight' => 'amber', 'late' => 'red'];
    $tone = $tones[$pace['tone'] ?? 'ok'] ?? 'blue';
    $parts = $plan->parts();
    $loose = $plan->steps();
    $items = $plan->criteria();
    $milestones = $plan->milestones();
    $milestonesShown = $showMilestones || $milestones !== [] || $assignment->kind === 'project';
    $teamShown = $showTeam || $plan->members !== [] || $assignment->kind === 'project';
    $states = ['not_yet' => 'Not yet', 'partly' => 'Partly', 'met' => 'Met'];
    $icons = ['todo' => 'circle', 'doing' => 'circle-dot', 'stuck' => 'circle-alert', 'done' => 'circle-check'];
@endphp
<div class="space-y-5">
    <section class="question-panel space-y-4" aria-labelledby="plan-heading" x-data="{ part: false }">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="plan-heading" class="flex items-center gap-2 font-semibold"><x-icon name="list-checks" class="size-4 text-fg-muted" />Plan</h2>
            <div class="flex flex-wrap gap-2">
                @unless ($plan->empty())
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$toggle('showStarters')" aria-expanded="{{ $showStarters ? 'true' : 'false' }}"><x-icon name="layout-grid" class="size-4" />Starters</button>
                @endunless
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAi" aria-expanded="{{ $aiOpen ? 'true' : 'false' }}"><x-icon name="brain" class="size-4" />Plan it with an AI</button>
            </div>
        </div>

        @error('plan') <p class="field-error">{{ $message }}</p> @enderror

        @if ($progress->total > 0)
            <div @class(['plan-progress', "ws-colour-{$tone}"])>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p class="plan-percent tabular-nums">{{ $progress->percent }}%</p>
                    <p class="text-sm text-fg-muted">{{ $progress->done }} of {{ $progress->total }} done{{ $progress->weighted ? ' · counted by marks' : '' }}</p>
                </div>
                <div class="meter" role="progressbar" aria-label="Plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress->percent }}">
                    <span style="width: {{ $progress->percent }}%"></span>
                </div>
                @if ($pace)
                    <p class="plan-pace"><x-icon :name="$pace['tone'] === 'ok' ? 'hourglass' : 'circle-alert'" class="size-4 shrink-0" />{{ $pace['words'] }}</p>
                @endif
                <x-workspace.plan-health :health="$health" reasons />
            </div>
            @if ($progress->complete() && $assignment->status !== 'done')
                <div class="plan-complete">
                    <p class="font-medium">Everything in your plan is ticked.</p>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="markDone"><x-icon name="circle-check" class="size-4" />Mark the assignment done</button>
                </div>
            @endif
        @endif

        @if ($plan->empty() || $showStarters)
            <div class="plan-starters">
                <p class="font-medium">{{ $plan->empty() ? 'How do you want to start?' : 'Add a starter' }}</p>
                <p class="field-hint">Pick the nearest one and change whatever doesn't fit. Nothing is fixed.</p>
                <div class="plan-starter-grid">
                    @foreach ($starters as $key => $starter)
                        <button type="button" class="plan-starter" wire:click="useStarter('{{ $key }}')">
                            <x-icon :name="$starter['icon']" class="size-5 shrink-0" />
                            <span class="min-w-0"><span class="block font-semibold">{{ $starter['label'] }}</span><span class="block text-sm text-fg-muted">{{ $starter['hint'] }}</span></span>
                        </button>
                    @endforeach
                    <button type="button" class="plan-starter" wire:click="toggleAi">
                        <x-icon name="brain" class="size-5 shrink-0" />
                        <span class="min-w-0"><span class="block font-semibold">Plan it with an AI</span><span class="block text-sm text-fg-muted">From the brief you paste</span></span>
                    </button>
                </div>
                @if ($plan->empty())
                    <p class="text-sm text-fg-muted">Or start from nothing: add your first part or step below.</p>
                @endif
            </div>
        @endif

        @if ($aiOpen)
            <div class="plan-ai space-y-4" x-data="{ copied: false }">
                <div class="field">
                    <label for="plan-brief" class="field-label">The assignment brief <span class="font-normal text-fg-muted">(optional)</span></label>
                    <textarea id="plan-brief" class="input mt-2" rows="4" wire:model.live.debounce.500ms="brief" aria-describedby="plan-brief-hint"></textarea>
                    <p id="plan-brief-hint" class="field-hint">Paste the text of the brief or the rubric if you have it. Without it, the AI plans from the title.</p>
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
                    <pre class="briefing-text card-maker-prompt" tabindex="0" aria-label="The prompt" x-ref="prompt" wire:loading.class="opacity-60" wire:target="brief">{{ $prompt }}</pre>
                    <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                </section>

                <section class="space-y-2" aria-labelledby="plan-ai-2">
                    <h3 id="plan-ai-2" class="font-semibold"><span class="step-number" aria-hidden="true">2</span>Paste its reply here</h3>
                    <div class="field">
                        <label for="plan-reply" class="sr-only">The AI's reply</label>
                        <textarea id="plan-reply" class="input capture-paste" rows="5" wire:model="reply" aria-describedby="plan-reply-hint"></textarea>
                        <p id="plan-reply-hint" class="field-hint">The whole reply is fine: only the parts, steps, milestones and criteria are read.</p>
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
                $actions[] = [$steps === [] ? 'Delete part' : 'Delete part and its steps', 'trash-2', "remove('{$part->id}')", false];
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
                <form wire:submit="addStep('{{ $part->id }}')" novalidate class="plan-add">
                    <label for="plan-step-{{ $part->id }}" class="sr-only">Add a step to {{ $part->title }}</label>
                    <input id="plan-step-{{ $part->id }}" type="text" class="input" wire:model="stepText.{{ $part->id }}" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a step" autocomplete="off">
                    <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the step to {{ $part->title }}"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
                </form>
                @error('stepText.'.$part->id) <p class="field-error">{{ $message }}</p> @enderror
            </section>
        @endforeach

        @if ($loose !== [])
            <div class="space-y-1">
                @if ($parts !== [])
                    <h3 class="plan-subhead">Other steps</h3>
                @endif
                <ul class="plan-steps" role="list" aria-label="Steps outside a part">
                    @foreach ($loose as $step)
                        @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last, 'depth' => 1])
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="plan-adders">
            <form wire:submit="addStep('')" novalidate class="plan-add">
                <label for="plan-step-loose" class="sr-only">Add a step</label>
                <input id="plan-step-loose" type="text" class="input" wire:model="stepText.loose" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a step" autocomplete="off">
                <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the step"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
            </form>
            @error('stepText.loose') <p class="field-error">{{ $message }}</p> @enderror

            <div class="flex flex-wrap gap-2" x-show="! part">
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="part = true; $nextTick(() => $refs.partName.focus())"><x-icon name="folder-plus" class="size-4" />Add a part</button>
                @unless ($milestonesShown)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('showMilestones', true)"><x-icon name="flag" class="size-4" />Add a milestone</button>
                @endunless
                @unless ($teamShown)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('showTeam', true)"><x-icon name="users" class="size-4" />Share the work with a team</button>
                @endunless
            </div>
            <form wire:submit="addPart" novalidate class="plan-add" x-show="part" x-cloak x-on:keydown.escape="part = false">
                <label for="plan-part-name" class="sr-only">Name of the part</label>
                <input id="plan-part-name" type="text" class="input" x-ref="partName" wire:model="partText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="A part: a section or a deliverable" autocomplete="off">
                <label for="plan-part-marks" class="sr-only">Marks, as a percentage (optional)</label>
                <input id="plan-part-marks" type="number" class="input plan-marks-input" wire:model="partMarks" min="1" max="100" inputmode="numeric" placeholder="Marks %">
                <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" />Add part</button>
            </form>
            @error('partText') <p class="field-error">{{ $message }}</p> @enderror
            @error('partMarks') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </section>

    @if ($milestonesShown)
        <section class="question-panel space-y-3" aria-labelledby="milestones-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="milestones-heading" class="flex items-center gap-2 font-semibold"><x-icon name="flag" class="size-4 text-fg-muted" />Milestones
                    @if ($milestones !== [])
                        <span class="count-pill" title="{{ count(array_filter($milestones, fn ($m) => $m->done())) }} of {{ count($milestones) }} reached">{{ count(array_filter($milestones, fn ($m) => $m->done())) }}/{{ count($milestones) }}</span>
                    @endif
                </h2>
            </div>
            <p class="field-hint">Days to reach on the way, like “First draft done”. Tick one when you get there; a missed one shows in how it is going.</p>

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

            <form wire:submit="addMilestone" novalidate class="plan-add">
                <label for="plan-milestone" class="sr-only">Add a milestone</label>
                <input id="plan-milestone" type="text" class="input" wire:model="milestoneText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a milestone, like “First draft done”" autocomplete="off">
                <label for="plan-milestone-date" class="sr-only">Its day (optional)</label>
                <input id="plan-milestone-date" type="date" class="input plan-date-input" wire:model="milestoneDate">
                <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the milestone"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
            </form>
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
        <section class="question-panel space-y-3" aria-labelledby="team-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="team-heading" class="flex items-center gap-2 font-semibold"><x-icon name="users" class="size-4 text-fg-muted" />Team
                    @if ($plan->members !== [])
                        <span class="count-pill">{{ count($plan->members) }}</span>
                    @endif
                </h2>
            </div>
            <p class="field-hint">Who shares the work. Add the names of your group, give each person their parts and steps (in a step's details), and see who has what. It is only your own plan: they don't need an account and don't see it.</p>

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

            <form wire:submit="addMember" novalidate class="plan-add">
                <label for="plan-member-name" class="sr-only">Add a person</label>
                <input id="plan-member-name" type="text" class="input" wire:model="memberName" maxlength="{{ \App\Study\Plans::MAX_NAME }}" placeholder="Add a person" autocomplete="off">
                @unless ($anyMe)
                    <x-checkbox name="member-me" id="plan-member-me" label="This is me" wire:model="memberMe" />
                @endunless
                <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the person"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
            </form>
            @error('memberName') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    <section class="question-panel space-y-3" aria-labelledby="criteria-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="criteria-heading" class="flex items-center gap-2 font-semibold"><x-icon name="clipboard-check" class="size-4 text-fg-muted" />Marking criteria
                @if ($criteria['total'] > 0)
                    <span class="count-pill" title="{{ $criteria['met'] }} of {{ $criteria['total'] }} met">{{ $criteria['met'] }}/{{ $criteria['total'] }}</span>
                @endif
            </h2>
            @if ($criteria['total'] > 0)
                <p class="text-sm text-fg-muted">Your own check: about {{ $criteria['percent'] }}%</p>
            @endif
        </div>
        <p class="field-hint">What it's marked on, from the brief or the rubric. Check yourself honestly as you go, and what is "Not yet" is where to work next.</p>

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

        <form wire:submit="addCriterion" novalidate class="plan-add">
            <label for="plan-criterion" class="sr-only">Add a criterion</label>
            <input id="plan-criterion" type="text" class="input" wire:model="criterionText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a criterion, like “Use of sources”" autocomplete="off">
            <label for="plan-criterion-marks" class="sr-only">Marks, as a percentage (optional)</label>
            <input id="plan-criterion-marks" type="number" class="input plan-marks-input" wire:model="criterionMarks" min="1" max="100" inputmode="numeric" placeholder="Marks %">
            <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the criterion"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
        </form>
        @error('criterionText') <p class="field-error">{{ $message }}</p> @enderror
        @error('criterionMarks') <p class="field-error">{{ $message }}</p> @enderror
    </section>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
