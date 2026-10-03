{{--
    An assignment's plan (App\Livewire\Workspaces\AssignmentPlan), built by the student: sections, each with its
    weight out of 100, shown as a list (or as cards) of how far each has got. A section opens on a page of its own
    (App\Livewire\Workspaces\SectionPage) with its tasks, files and notes; a new one is made on its own page too
    (App\Livewire\Workspaces\SectionForm). A strip on top says how far the whole plan is, whether the weights add
    up, and how it is going. An AI's plan and the pre-made plans are there, quietly; the whole plan can be cleared.
--}}
@php
    $tones = ['ok' => 'blue', 'tight' => 'amber', 'late' => 'red'];
    $tone = $tones[$pace['tone'] ?? 'ok'] ?? 'blue';
    $parts = $plan->parts();
    $loose = $plan->steps();
    $items = $plan->criteria();
    $milestones = $plan->milestones();
    $blank = $plan->empty();
    $weights = $plan->weights();
    $weighted = $plan->weighted();
    $pct = fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    $milestonesShown = $showMilestones || $milestones !== [];
    $teamShown = $showTeam || $plan->members !== [];
    $criteriaShown = $showCriteria || $items !== [];
    $states = ['not_yet' => 'Not yet', 'partly' => 'Partly', 'met' => 'Met'];
    $icons = ['todo' => 'circle', 'doing' => 'circle-dot', 'stuck' => 'circle-alert', 'done' => 'circle-check'];
@endphp
<div class="plan space-y-4" x-data="{
    view: (() => { try { return localStorage.getItem('vistud.sections.view') === 'cards' ? 'cards' : 'list'; } catch (e) { return 'list'; } })(),
    show(view) { this.view = view; try { localStorage.setItem('vistud.sections.view', view); } catch (e) {} },
}">
    @if ($progress->total > 0)
        <section @class(['question-panel', 'plan-progress', "ws-colour-{$tone}"]) aria-label="Progress">
            <div class="plan-progress-row">
                <p class="plan-percent tabular-nums">{{ $progress->percent }}%</p>
                <div class="meter plan-progress-meter" role="progressbar" aria-label="Plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress->percent }}">
                    <span style="width: {{ $progress->percent }}%"></span>
                </div>
                <p class="text-sm text-fg-muted tabular-nums">{{ $progress->done }} of {{ $progress->total }} done</p>
            </div>
            <div class="plan-facts">
                @if (! $weighted && $parts !== [])
                    <span class="plan-fact"><x-icon name="scale" class="size-4" />No weights yet · each section counts the same</span>
                @elseif (! $weighted)
                @elseif ($weights['over'] > 0)
                    <span class="plan-fact ws-colour-red"><x-icon name="triangle-alert" class="size-4" />Weights add up to {{ $weights['given'] }}%: {{ $weights['over'] }}% too many</span>
                @elseif ($weights['left'] > 0 && $weights['unweighted'] === 0)
                    <span class="plan-fact ws-colour-amber"><x-icon name="circle-alert" class="size-4" />Weights add up to {{ $weights['given'] }}%: {{ $weights['left'] }}% is in no section</span>
                @elseif ($weights['left'] > 0)
                    <span class="plan-fact"><x-icon name="scale" class="size-4" />{{ $weights['given'] }}% given · {{ $weights['left'] }}% shared by {{ $weights['unweighted'] === 1 ? 'the section' : 'the '.$weights['unweighted'].' sections' }} without a weight</span>
                @else
                    <span class="plan-fact ws-colour-green"><x-icon name="circle-check" class="size-4" />Weights add up to 100%</span>
                @endif
                @if ($pace)
                    <span class="plan-pace"><x-icon :name="$pace['tone'] === 'ok' ? 'hourglass' : 'circle-alert'" class="size-4 shrink-0" />{{ $pace['words'] }}</span>
                @endif
                <x-workspace.plan-health :health="$health" reasons />
            </div>
            @if ($progress->complete() && $assignment->status !== 'done')
                <div class="plan-complete">
                    <p class="font-medium">Everything in your plan is ticked.</p>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="markDone"><x-icon name="circle-check" class="size-4" />Mark the assignment done</button>
                </div>
            @endif
        </section>
    @endif

    <div class="plan-toolbar">
        <div class="plan-toolbar-title">
            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
            <h2 id="plan-heading" class="panel-title">
                Sections
                @if ($parts !== [])
                    <span class="count-pill">{{ count($parts) }}</span>
                @endif
            </h2>
        </div>
        <div class="plan-toolbar-actions">
            @unless ($blank)
                <div class="segmented segmented-sm" role="group" aria-label="Show the sections as">
                    <button type="button" class="segmented-option is-current" x-bind:class="{ 'is-current': view === 'list' }" x-bind:aria-pressed="(view === 'list').toString()" aria-pressed="true" x-on:click="show('list')"><x-icon name="list" class="size-4" />List</button>
                    <button type="button" class="segmented-option" x-bind:class="{ 'is-current': view === 'cards' }" x-bind:aria-pressed="(view === 'cards').toString()" aria-pressed="false" x-on:click="show('cards')"><x-icon name="layout-grid" class="size-4" />Cards</button>
                </div>
            @endunless
            <button type="button" class="quiet-link" wire:click="toggleAi" aria-expanded="{{ $aiOpen ? 'true' : 'false' }}"><x-icon name="brain" class="size-4" />Plan with an AI</button>
            <a href="{{ route('workspaces.assignments.sections.create', [$workspaceId, $activityId]) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4" />New section</a>
            @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-head', 'label' => 'the plan', 'items' => array_values(array_filter([
                ['Add a pre-made plan', 'layout-grid', 'toggleStarters', false],
                $blank ? null : ['Clear the plan', 'trash-2', 'askClear', false],
            ]))])
        </div>
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
        <div class="plan-ai question-panel space-y-4" x-data="{ copied: false }">
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

    @if ($blank)
        <p class="text-sm text-fg-muted">Split the work into sections with New section, and give each a weight out of 100. Each section has its own page for its tasks, files and notes.</p>
    @endif

    @if ($parts !== [])
        <ul class="plan-grid is-list" x-bind:class="{ 'is-list': view === 'list' }" role="list" aria-labelledby="plan-heading">
            @foreach ($parts as $part)
                @php
                    $steps = $plan->steps($part->id);
                    $standing = $plan->standing($part);
                    $partState = $plan->stateOf($part);
                    $kept = $part->folderId === null ? [] : ($counts[$part->folderId] ?? []);
                    $actions = [];
                    if ($steps === []) {
                        foreach ([['doing', 'Start it', 'play'], ['stuck', 'I am stuck', 'circle-alert'], ['todo', 'Back to to do', 'circle'], ['done', 'Mark done', 'circle-check']] as [$to, $text, $icon]) {
                            if ($partState !== $to) {
                                $actions[] = [$text, $icon, "setState('{$part->id}', '{$to}')", false];
                            }
                        }
                    }
                    $actions[] = ['Edit', 'pencil', null, false, route('workspaces.assignments.sections.edit', [$workspaceId, $activityId, $part->id])];
                    if ($part->folderId !== null) {
                        $actions[] = ['Open its folder', 'folder-open', null, false, route('workspaces.folders.show', [$workspaceId, $part->folderId])];
                    }
                    $actions[] = ['Move earlier', 'arrow-up', "move('{$part->id}', 'up')", $loop->first];
                    $actions[] = ['Move later', 'arrow-down', "move('{$part->id}', 'down')", $loop->last];
                    $actions[] = [$steps === [] ? 'Delete section' : 'Delete section and its tasks', 'trash-2', "remove('{$part->id}')", false];
                    $words = array_values(array_filter([
                        $steps !== [] ? $standing['done'].'/'.$standing['total'].' '.($standing['total'] === 1 ? 'task' : 'tasks') : ($part->done() ? 'Done' : 'No tasks yet'),
                        $pct($standing['earned']).' of '.$pct($standing['share']).'%',
                        $part->when(),
                        ($kept['files'] ?? 0) > 0 ? $kept['files'].' '.(($kept['files'] ?? 0) === 1 ? 'file' : 'files') : null,
                        ($kept['notes'] ?? 0) > 0 ? $kept['notes'].' '.(($kept['notes'] ?? 0) === 1 ? 'note' : 'notes') : null,
                    ]));
                @endphp
                <li wire:key="plan-part-{{ $part->id }}" @class(['plan-section', 'is-done' => $partState === 'done', 'is-stuck' => $partState === 'stuck'])>
                    <div class="plan-section-head">
                        @if ($steps === [])
                            <button type="button" class="task-check is-{{ $partState }}" wire:click="setState('{{ $part->id }}', '{{ $partState === 'done' ? 'todo' : 'done' }}')" aria-pressed="{{ $partState === 'done' ? 'true' : 'false' }}">
                                <x-icon :name="$icons[$partState]" class="size-5" />
                                <span class="sr-only">Done: {{ $part->title }}</span>
                            </button>
                        @endif
                        <h3 class="plan-part-title"><a href="{{ route('workspaces.assignments.sections.show', [$workspaceId, $activityId, $part->id]) }}" class="tile-link">{{ $part->title }}</a></h3>
                        <span @class(['plan-weight-chip', 'is-unset' => $part->weight === null]) title="Its weight in the assignment">{{ $part->weight === null ? 'No weight' : $part->weight.'%' }}</span>
                        @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$part->id, 'label' => $part->title, 'items' => $actions])
                    </div>
                    <div class="plan-section-standing">
                        <div class="meter" role="progressbar" aria-label="Progress of {{ $part->title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $standing['percent'] }}"><span style="width: {{ $standing['percent'] }}%"></span></div>
                        <p class="plan-section-words tabular-nums">
                            @if ($partState === 'stuck')<span class="plan-section-stuck"><x-icon name="circle-alert" class="size-3.5" />Stuck</span>@endif
                            @foreach ($words as $word)<span>{{ $word }}</span>@endforeach
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($loose !== [])
        <section class="question-panel plan-loose space-y-3" aria-labelledby="plan-loose-heading">
            <h3 id="plan-loose-heading" class="font-semibold">Other tasks <span class="text-sm font-normal text-fg-muted">· not in a section</span></h3>
            <ul class="plan-steps" role="list" aria-label="Tasks outside a section">
                @foreach ($loose as $step)
                    @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last, 'depth' => 1])
                @endforeach
            </ul>
            <x-workspace.quiet-add label="Task" submit="addStep('')">
                <label for="plan-step-loose" class="sr-only">Add a task</label>
                <input id="plan-step-loose" type="text" class="input" wire:model="stepText.loose" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="What is the task?" autocomplete="off">
            </x-workspace.quiet-add>
            @error('stepText.loose') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    @endif

    @unless ($blank)
        @if (! $milestonesShown || ! $teamShown || ! $criteriaShown)
            <section class="question-panel space-y-4" aria-labelledby="plan-also-heading">
                <div class="panel-head">
                    <span class="item-icon" aria-hidden="true"><x-icon name="plus" class="size-5" /></span>
                    <div class="panel-head-text">
                        <h2 id="plan-also-heading" class="panel-title">Also track</h2>
                        <p class="panel-hint">Optional. Add only what this assignment needs.</p>
                    </div>
                </div>
                <div class="track-tiles">
                    @unless ($milestonesShown)
                        <button type="button" class="track-tile" wire:click="$set('showMilestones', true)">
                            <span class="item-icon" aria-hidden="true"><x-icon name="flag" class="size-5" /></span>
                            <span class="min-w-0"><span class="track-tile-name">Milestones</span><span class="track-tile-hint">Days you want to reach</span></span>
                        </button>
                    @endunless
                    @unless ($teamShown)
                        <button type="button" class="track-tile" wire:click="$set('showTeam', true)">
                            <span class="item-icon" aria-hidden="true"><x-icon name="users" class="size-5" /></span>
                            <span class="min-w-0"><span class="track-tile-name">Team</span><span class="track-tile-hint">Who does which part</span></span>
                        </button>
                    @endunless
                    @unless ($criteriaShown)
                        <button type="button" class="track-tile" wire:click="$set('showCriteria', true)">
                            <span class="item-icon" aria-hidden="true"><x-icon name="clipboard-check" class="size-5" /></span>
                            <span class="min-w-0"><span class="track-tile-name">Marking criteria</span><span class="track-tile-hint">What it is marked on</span></span>
                        </button>
                    @endunless
                </div>
            </section>
        @endif
    @endunless

    @if ($milestonesShown || $teamShown || $criteriaShown)
        <div class="plan-extras">
        @if ($milestonesShown)
            @php
                // In date order, the ones without a day last: a timeline to the deadline.
                $timeline = collect($milestones)->sortBy(fn ($m) => [$m->dueOn === null ? 1 : 0, (string) $m->dueOn, $m->position])->values()->all();
                $reached = count(array_filter($milestones, fn ($m) => $m->done()));
                $next = collect($timeline)->first(fn ($m) => ! $m->done());
                $day = fn (string $date) => \Carbon\CarbonImmutable::parse($date);
            @endphp
            <section class="plan-extra question-panel" aria-labelledby="milestones-heading">
                <div class="panel-head">
                    <span class="item-icon" aria-hidden="true"><x-icon name="flag" class="size-5" /></span>
                    <div class="panel-head-text">
                        <h3 id="milestones-heading" class="panel-title">Milestones</h3>
                        <p class="panel-hint">
                            @if ($milestones === [])
                                Dates you want to reach on the way to the deadline.
                            @else
                                {{ $reached }} of {{ count($milestones) }} reached{{ $next ? ' · next: '.$next->title : '' }}
                            @endif
                        </p>
                    </div>
                    @if ($milestones === [])
                        <div class="panel-head-actions">
                            <button type="button" class="topbar-button size-9" wire:click="$set('showMilestones', false)" title="Close: nothing added yet">
                                <x-icon name="x" class="size-4" /><span class="sr-only">Close milestones</span>
                            </button>
                        </div>
                    @endif
                </div>

                @if ($timeline !== [])
                    <ol class="extra-list" role="list" aria-label="Milestones">
                        @foreach ($timeline as $milestone)
                            @php
                                $days = $milestone->dueOn === null ? null : (int) $day($today)->diffInDays($day($milestone->dueOn), false);
                                [$when, $tone] = match (true) {
                                    $milestone->done() => ['Reached', 'is-done'],
                                    $days === null => ['No date yet', ''],
                                    $days < 0 => [$days === -1 ? 'Missed yesterday' : 'Missed '.abs($days).' days ago', 'is-late'],
                                    $days === 0 => ['Today', 'is-soon'],
                                    $days === 1 => ['Tomorrow', 'is-soon'],
                                    default => ["In {$days} days", ''],
                                };
                            @endphp
                            <li wire:key="plan-milestone-{{ $milestone->id }}" @class(['extra-row', 'milestone', $tone])>
                                @if ($editing === $milestone->id)
                                    @include('livewire.workspaces.partials.plan-edit', ['item' => $milestone])
                                @else
                                    <span class="milestone-date" aria-hidden="true">
                                        @if ($milestone->dueOn !== null)
                                            <span class="milestone-month">{{ $day($milestone->dueOn)->format('M') }}</span>
                                            <span class="milestone-day">{{ $day($milestone->dueOn)->format('j') }}</span>
                                        @else
                                            <x-icon name="calendar" class="size-4" />
                                        @endif
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="extra-title">{{ $milestone->title }}</p>
                                        <p class="extra-meta">
                                            @if ($tone === 'is-late')<x-icon name="circle-alert" class="size-3.5" />@endif
                                            {{ $milestone->dueOn !== null ? $day($milestone->dueOn)->format('D j M').' · ' : '' }}{{ $when }}
                                        </p>
                                    </div>
                                    <button type="button" class="task-check is-{{ $milestone->done() ? 'done' : 'todo' }}" wire:click="setState('{{ $milestone->id }}', '{{ $milestone->done() ? 'pending' : 'achieved' }}')" aria-pressed="{{ $milestone->done() ? 'true' : 'false' }}" title="{{ $milestone->done() ? 'Reached' : 'Mark it reached' }}">
                                        <x-icon :name="$milestone->done() ? 'circle-check' : 'circle'" class="size-6" />
                                        <span class="sr-only">Reached: {{ $milestone->title }}</span>
                                    </button>
                                    @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$milestone->id, 'label' => $milestone->title, 'items' => [
                                        ['Edit', 'pencil', "startEdit('{$milestone->id}')", false],
                                        ['Delete', 'trash-2', "remove('{$milestone->id}')", false],
                                    ]])
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                <form wire:submit="addMilestone" novalidate class="extra-add">
                    <label for="plan-milestone" class="sr-only">Add a milestone</label>
                    <input id="plan-milestone" type="text" class="input extra-add-name" wire:model="milestoneText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a milestone, like “First draft done”" autocomplete="off">
                    <label for="plan-milestone-date" class="sr-only">Its day (optional)</label>
                    <input id="plan-milestone-date" type="date" class="input extra-add-small" wire:model="milestoneDate">
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" />Add</button>
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
            <section class="plan-extra question-panel" aria-labelledby="team-heading">
                <div class="panel-head">
                    <span class="item-icon" aria-hidden="true"><x-icon name="users" class="size-5" /></span>
                    <div class="panel-head-text">
                        <h3 id="team-heading" class="panel-title">Team
                            @if ($plan->members !== [])
                                <span class="count-pill">{{ count($plan->members) }}</span>
                            @endif
                        </h3>
                        <p class="panel-hint">The people sharing the work, and how far each has got.</p>
                    </div>
                    @if ($plan->members === [])
                        <div class="panel-head-actions">
                            <button type="button" class="topbar-button size-9" wire:click="$set('showTeam', false)" title="Close: nothing added yet">
                                <x-icon name="x" class="size-4" /><span class="sr-only">Close team</span>
                            </button>
                        </div>
                    @endif
                </div>
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
            @php
                $stateIcons = ['not_yet' => 'circle', 'partly' => 'circle-dot', 'met' => 'circle-check'];
            @endphp
            <section class="plan-extra question-panel" aria-labelledby="criteria-heading">
                <div class="panel-head">
                    <span class="item-icon" aria-hidden="true"><x-icon name="clipboard-check" class="size-5" /></span>
                    <div class="panel-head-text">
                        <h3 id="criteria-heading" class="panel-title">Marking criteria</h3>
                        <p class="panel-hint">
                            @if ($criteria['total'] === 0)
                                What your work is marked on. Check each one as you go.
                            @else
                                {{ $criteria['met'] }} of {{ $criteria['total'] }} met · about {{ $criteria['percent'] }}% ready
                            @endif
                        </p>
                    </div>
                    @if ($items === [])
                        <div class="panel-head-actions">
                            <button type="button" class="topbar-button size-9" wire:click="$set('showCriteria', false)" title="Close: nothing added yet">
                                <x-icon name="x" class="size-4" /><span class="sr-only">Close marking criteria</span>
                            </button>
                        </div>
                    @endif
                </div>
                @if ($criteria['total'] > 0)
                    <div class="meter" role="progressbar" aria-label="How ready it is against the marking criteria" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $criteria['percent'] }}"><span style="width: {{ $criteria['percent'] }}%"></span></div>
                @endif

                @if ($items !== [])
                    <ul class="extra-list" role="list" aria-label="Marking criteria">
                        @foreach ($items as $criterion)
                            <li wire:key="plan-criterion-{{ $criterion->id }}" class="extra-row criterion is-{{ $criterion->state }}">
                                @if ($editing === $criterion->id)
                                    @include('livewire.workspaces.partials.plan-edit', ['item' => $criterion])
                                @else
                                    <span class="criterion-icon" aria-hidden="true"><x-icon :name="$stateIcons[$criterion->state] ?? 'circle'" class="size-5" /></span>
                                    <div class="criterion-text">
                                        <p class="extra-title">{{ $criterion->title }}</p>
                                        <p class="extra-meta">{{ $criterion->weight !== null ? $criterion->weight.'% of the marks' : 'No marks given' }}</p>
                                    </div>
                                    <div class="criterion-actions">
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
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addCriterion" novalidate class="extra-add">
                    <label for="plan-criterion" class="sr-only">Add a criterion</label>
                    <input id="plan-criterion" type="text" class="input extra-add-name" wire:model="criterionText" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a criterion, like “Use of sources”" autocomplete="off">
                    <label for="plan-criterion-marks" class="sr-only">Its marks, as a percentage (optional)</label>
                    <input id="plan-criterion-marks" type="number" class="input extra-add-small" wire:model="criterionMarks" min="1" max="100" inputmode="numeric" placeholder="Marks %">
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" />Add</button>
                </form>
                @error('criterionText') <p class="field-error">{{ $message }}</p> @enderror
                @error('criterionMarks') <p class="field-error">{{ $message }}</p> @enderror
            </section>
        @endif

        </div>
    @endif

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
