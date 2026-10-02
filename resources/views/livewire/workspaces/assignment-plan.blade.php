{{--
    An assignment's plan (App\Livewire\Workspaces\AssignmentPlan): how far it has got, its parts with their steps,
    the steps outside every part, and the marking criteria to check oneself against. One plan for any kind of
    assignment: start from a starter or an AI's reply, or add things by hand.
--}}
@php
    $tones = ['ok' => 'blue', 'tight' => 'amber', 'late' => 'red'];
    $tone = $tones[$pace['tone'] ?? 'ok'] ?? 'blue';
    $parts = $plan->parts();
    $loose = $plan->steps();
    $items = $plan->criteria();
    $states = ['not_yet' => 'Not yet', 'partly' => 'Partly', 'met' => 'Met'];
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
                        <p id="plan-reply-hint" class="field-hint">The whole reply is fine: only the parts, steps and criteria are read.</p>
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
                $doneSteps = count(array_filter($steps, fn ($s) => $s->done()));
            @endphp
            <section wire:key="plan-part-{{ $part->id }}" class="plan-part" aria-label="Part: {{ $part->title }}">
                <div class="plan-part-head">
                    @if ($editing === $part->id)
                        @include('livewire.workspaces.partials.plan-edit', ['item' => $part, 'marks' => true])
                    @else
                        @if ($steps === [])
                            <button type="button" class="task-check" wire:click="setState('{{ $part->id }}', '{{ $part->done() ? 'todo' : 'done' }}')" aria-pressed="{{ $part->done() ? 'true' : 'false' }}">
                                <x-icon :name="$part->done() ? 'circle-check' : 'circle'" class="size-5" />
                                <span class="sr-only">Done: {{ $part->title }}</span>
                            </button>
                        @endif
                        <h3 @class(['plan-part-title', 'text-fg-muted line-through' => $steps === [] && $part->done()])>{{ $part->title }}</h3>
                        @if ($part->weight !== null)
                            <span class="plan-marks-chip">{{ $part->weight }}%</span>
                        @endif
                        @if ($steps !== [])
                            <span class="plan-part-count tabular-nums">{{ $doneSteps }}/{{ count($steps) }}</span>
                        @endif
                        @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$part->id, 'label' => $part->title, 'items' => [
                            ['Rename or give marks', 'pencil', "startEdit('{$part->id}')", false],
                            ['Move up', 'arrow-up', "move('{$part->id}', 'up')", $loop->first],
                            ['Move down', 'arrow-down', "move('{$part->id}', 'down')", $loop->last],
                            [$steps === [] ? 'Delete part' : 'Delete part and its steps', 'trash-2', "remove('{$part->id}')", false],
                        ]])
                    @endif
                </div>
                @if ($steps !== [])
                    <ul class="plan-steps" role="list" aria-label="Steps of {{ $part->title }}">
                        @foreach ($steps as $step)
                            @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last])
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
                        @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last])
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

            <button type="button" class="btn btn-ghost btn-sm" x-show="! part" x-on:click="part = true; $nextTick(() => $refs.partName.focus())"><x-icon name="folder-plus" class="size-4" />Add a part</button>
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
                            @include('livewire.workspaces.partials.plan-edit', ['item' => $criterion, 'marks' => true])
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
