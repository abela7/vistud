@php
    use App\Study\Activities;
    use App\Study\AssignmentLog;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $confidenceLabels = ['high' => 'Full picture', 'medium' => 'Mostly clear', 'low' => 'Much still unclear'];
    $stageLabels = [
        'understand' => 'Understand',
        'plan' => 'Plan',
        'do' => 'Do',
        'review' => 'Review',
        'submit' => 'Submit',
    ];
    $stageIcons = [
        'understand' => 'lightbulb',
        'plan' => 'list-todo',
        'do' => 'pencil',
        'review' => 'clipboard-check',
        'submit' => 'upload',
    ];
    $kindBadges = [
        'decision' => ['Decision', 'purple'],
        'progress' => ['Progress', 'blue'],
        'advice' => ['Advice', 'amber'],
        'feedback' => ['Feedback', 'emerald'],
        'risk' => ['Risk', 'red'],
    ];
    $currentStage = $activity->stage;
@endphp

{{-- 1. STAGE STEPPER --}}
<section class="rail-section" aria-labelledby="rail-stage-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-stage-{{ $suffix }}" class="rail-title">Stage</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body" x-show="openSection">
        <ol class="space-y-1.5" role="list" aria-label="Assignment stages">
            @foreach ($stages as $index => $stage)
                @php
                    $isCurrent = $currentStage === $stage;
                    $stepNum = $index + 1;
                @endphp
                <li wire:key="rail-stage-{{ $suffix }}-{{ $stage }}">
                    <button type="button"
                        class="w-full flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors text-left {{ $isCurrent ? 'bg-accent/10 text-accent font-semibold' : 'text-fg-muted hover:text-fg hover:bg-surface-sunken' }}"
                        @if ($isCurrent)
                            aria-current="step"
                        @else
                            x-on:click="$dispatch('stage-confirm', { stage: '{{ $stage }}' })"
                        @endif>
                        <span class="size-5 rounded-full flex items-center justify-center shrink-0 text-[10px] font-bold {{ $isCurrent ? 'bg-accent text-accent-contrast' : 'bg-surface-sunken text-fg-muted' }}">
                            {{ $stepNum }}
                        </span>
                        <span class="flex-1 min-w-0 truncate">{{ $stageLabels[$stage] ?? ucfirst($stage) }}</span>
                        @if ($isCurrent)
                            <span class="text-[10px] uppercase tracking-wider font-bold text-accent shrink-0">Current</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- 2. THE PICTURE SUMMARY --}}
<section class="rail-section" aria-labelledby="rail-picture-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-picture-{{ $suffix }}" class="rail-title">The picture</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body space-y-3" x-show="openSection">
        @if ($understanding)
            @php $level = $understanding->text('confidence') ?: 'low'; @endphp
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="picture-confidence is-{{ $level }}">{{ $confidenceLabels[$level] ?? 'Clear' }}</span>
                <span class="text-fg-muted">Version {{ $understanding->revision }}</span>
                @if ($understanding->confirmed())
                    <span class="text-success font-medium flex items-center gap-1"><x-icon name="circle-check" class="size-3" />Confirmed</span>
                @endif
            </div>

            @if ($stale)
                <div class="p-2 rounded-lg bg-warning-subtle text-xs text-warning space-y-1.5" role="alert">
                    <p class="font-medium flex items-center gap-1.5">
                        <x-icon name="circle-alert" class="size-3.5 shrink-0" />
                        <span>Out of date: what you were given has changed</span>
                    </p>
                    <button type="button" class="btn btn-secondary btn-xs" wire:click="understand" @disabled($reading)>
                        <x-icon name="rotate-cw" class="size-3" />Read again
                    </button>
                </div>
            @endif

            @if ($reading)
                <p class="text-xs text-fg-muted flex items-center gap-1.5" role="status">
                    <x-icon name="loader-circle" class="size-3.5 animate-spin" />
                    <span>The AI is updating its understanding...</span>
                </p>
            @endif

            @if (! $understanding->confirmed())
                <div class="p-2.5 rounded-lg bg-accent-subtle text-xs space-y-2">
                    <p class="text-fg-muted">Clarify anything in the chat. Agreed facts are saved only after you approve them, then the AI updates this picture.</p>
                    @if (! $stale && ! $reading)
                        <button type="button" class="btn btn-primary btn-sm" wire:click="confirmUnderstanding">
                            <x-icon name="circle-check" class="size-3.5" />This is right
                        </button>
                    @endif
                </div>
            @elseif ($currentStage === 'understand')
                <button type="button" class="btn btn-primary btn-sm w-full justify-center" x-on:click="$dispatch('stage-confirm', { stage: 'plan' })">
                    Continue to planning<x-icon name="arrow-right" class="size-3.5" />
                </button>
            @endif

            @if ($understanding->text('goal') !== '')
                <div class="text-xs space-y-1">
                    <span class="font-semibold text-fg">Goal</span>
                    <p class="text-fg-muted">{{ $understanding->text('goal') }}</p>
                </div>
            @endif

            @php $critItems = $understanding->items('criteria'); @endphp
            @if ($critItems !== [])
                <div class="space-y-1.5 text-xs">
                    <span class="font-semibold text-fg">Criteria ({{ count($critItems) }})</span>
                    <ul class="space-y-1" role="list">
                        @foreach ($critItems as $c)
                            <li class="p-1.5 rounded bg-surface-sunken flex items-start justify-between gap-2 text-xs">
                                <span class="min-w-0 flex-1 truncate">{{ $c['criterion'] ?? $c['title'] ?? $c['text'] ?? 'Criterion' }}</span>
                                @if (! empty($c['weight']))
                                    <span class="font-semibold shrink-0 text-fg-muted">{{ $c['weight'] }}%</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php $questions = $understanding->items('questions'); @endphp
            @if ($questions !== [])
                <div class="p-2.5 rounded-lg bg-warning-subtle text-xs space-y-1.5">
                    <span class="font-semibold text-warning flex items-center gap-1.5">
                        <x-icon name="message-square-text" class="size-3.5" />Ask your teacher
                    </span>
                    <ul class="space-y-1 text-fg-muted list-disc list-inside" role="list">
                        @foreach ($questions as $q)
                            <li>{{ is_array($q) ? ($q['text'] ?? '') : $q }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="pt-1">
                <a href="{{ route('workspaces.assignments.show', [$workspaceId, $activityId]) }}" class="quiet-link text-xs flex items-center gap-1">
                    <span>See everything</span>
                    <x-icon name="arrow-right" class="size-3" />
                </a>
            </div>
        @else
            <p class="rail-empty text-xs">Not read yet. Drop a file in the chat, or paste what you were told.</p>
            @if (! $reading)
                <button type="button" class="btn btn-primary btn-sm" wire:click="understand">
                    <x-icon name="sparkles" class="size-3.5" />Understand it
                </button>
            @else
                <p class="text-xs text-fg-muted flex items-center gap-1.5" role="status">
                    <x-icon name="loader-circle" class="size-3.5 animate-spin" />
                    <span>The AI is reading your assignment...</span>
                </p>
            @endif
        @endif
    </div>
</section>

{{-- 3. THE PLAN --}}
<section class="rail-section" aria-labelledby="rail-plan-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-plan-{{ $suffix }}" class="rail-title">Plan</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body space-y-3" x-show="openSection">
        <div class="space-y-1.5">
            <div class="flex items-center justify-between text-xs">
                <span class="font-medium">Progress</span>
                <span class="font-bold tabular-nums">{{ $planProgress }}%</span>
            </div>
            <div class="h-1.5 w-full bg-surface-sunken rounded-full overflow-hidden">
                <div class="h-full bg-accent transition-all duration-300" style="width: {{ $planProgress }}%"></div>
            </div>
            @php
                $healthState = is_array($planHealth) ? ($planHealth['state'] ?? null) : (is_string($planHealth) ? $planHealth : null);
            @endphp
            @if ($healthState)
                <div class="flex items-center justify-between pt-1 text-xs">
                    <span class="text-fg-muted">Health</span>
                    <span class="badge badge-sm {{ $healthState === 'on track' || $healthState === 'on_track' ? 'badge-success' : ($healthState === 'tight' || $healthState === 'at_risk' ? 'badge-warning' : 'badge-danger') }}">
                        {{ str_replace('_', ' ', $healthState) }}
                    </span>
                </div>
            @endif
        </div>

        @if ($sections !== [] || ($standaloneSteps ?? []) !== [])
            <ul class="space-y-2 text-xs" role="list">
                @foreach ($sections as $sec)
                    @php $secSteps = $plan->steps($sec->id); @endphp
                    <li class="p-2 rounded-lg bg-surface-sunken space-y-1.5" wire:key="rail-sec-{{ $suffix }}-{{ $sec->id }}">
                        <div class="flex items-center justify-between gap-2">
                            <button type="button" wire:click="cyclePlanItem('{{ $sec->id }}')" class="min-w-0 flex-1 text-left font-medium hover:text-accent break-words">
                                {{ $sec->title }}@if ($sec->weight !== null) <span class="text-fg-muted">({{ $sec->weight }}%)</span>@endif
                            </button>
                            <button type="button" wire:click="cyclePlanItem('{{ $sec->id }}')" class="text-[10px] uppercase font-semibold px-1.5 py-0.5 rounded bg-surface text-fg-muted shrink-0">
                                {{ str_replace('_', ' ', $plan->stateOf($sec)) }}
                            </button>
                        </div>
                        @if ($secSteps !== [])
                            <ul class="space-y-1 pl-2 border-l border-border/60" role="list">
                                @foreach ($secSteps as $step)
                                    <li class="flex items-center justify-between gap-2" wire:key="rail-step-{{ $suffix }}-{{ $step->id }}">
                                        <button type="button" wire:click="cyclePlanItem('{{ $step->id }}')" class="min-w-0 flex-1 text-left break-words {{ $step->state === 'done' ? 'line-through text-fg-muted' : 'text-fg' }}">
                                            {{ $step->title }}
                                        </button>
                                        <button type="button" wire:click="cyclePlanItem('{{ $step->id }}')" class="text-[10px] uppercase font-semibold text-fg-muted shrink-0">
                                            {{ str_replace('_', ' ', $step->state) }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
                @foreach ($standaloneSteps ?? [] as $step)
                    <li class="flex items-center justify-between p-1.5 rounded bg-surface-sunken gap-2" wire:key="rail-step-free-{{ $suffix }}-{{ $step->id }}">
                        <button type="button" wire:click="cyclePlanItem('{{ $step->id }}')" class="min-w-0 flex-1 text-left break-words {{ $step->state === 'done' ? 'line-through text-fg-muted' : 'text-fg' }}">
                            {{ $step->title }}
                        </button>
                        <button type="button" wire:click="cyclePlanItem('{{ $step->id }}')" class="text-[10px] uppercase font-semibold text-fg-muted shrink-0">
                            {{ str_replace('_', ' ', $step->state) }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="rail-empty text-xs">No plan sections created yet. Ask the AI in chat to build your plan and pick a tracking method.</p>
        @endif

        @if (($criteria ?? []) !== [])
            <div class="space-y-1.5 pt-1 text-xs">
                <span class="font-semibold text-fg">Marking criteria tracker ({{ count($criteria) }})</span>
                <ul class="space-y-1" role="list">
                    @foreach ($criteria as $criterion)
                        <li class="flex items-center justify-between p-1.5 rounded bg-surface-sunken gap-2" wire:key="rail-crit-{{ $suffix }}-{{ $criterion->id }}">
                            <button type="button" wire:click="cyclePlanItem('{{ $criterion->id }}')" class="min-w-0 flex-1 text-left break-words {{ $criterion->state === 'met' ? 'text-success font-medium' : 'text-fg' }}">
                                {{ $criterion->title }}@if ($criterion->weight !== null) <span class="text-fg-muted">({{ $criterion->weight }}%)</span>@endif
                            </button>
                            <button type="button" wire:click="cyclePlanItem('{{ $criterion->id }}')" class="text-[10px] uppercase font-semibold text-fg-muted shrink-0">
                                {{ str_replace('_', ' ', $criterion->state) }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (($assignmentNotes ?? []) !== [])
            <div class="space-y-1.5 pt-1 text-xs">
                <span class="font-semibold text-fg">Saved assignment notes ({{ count($assignmentNotes) }})</span>
                <ul class="space-y-1" role="list">
                    @foreach ($assignmentNotes as $note)
                        <li>
                            <a href="{{ route('workspaces.notes.show', [$workspaceId, $note->id]) }}" class="flex items-center gap-1.5 p-1.5 rounded bg-surface-sunken hover:text-accent">
                                <x-icon name="notebook-pen" class="size-3.5 shrink-0" />
                                <span class="break-words">{{ $note->displayTitle() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="pt-1">
            <a href="{{ route('workspaces.assignments.show', [$workspaceId, $activityId]) }}" class="quiet-link text-xs flex items-center gap-1">
                <span>Open plan</span>
                <x-icon name="arrow-right" class="size-3" />
            </a>
        </div>
    </div>
</section>

{{-- 4. THE LOG --}}
<section class="rail-section" aria-labelledby="rail-log-{{ $suffix }}" x-data="{ openSection: true, showAll: false }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-log-{{ $suffix }}" class="rail-title">Log</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body space-y-3" x-show="openSection">
        {{-- Add entry form --}}
        <form wire:submit.prevent="addLog" class="p-2.5 rounded-lg bg-surface-sunken space-y-2 text-xs" aria-label="Add log note">
            <div class="flex items-center gap-2">
                <select wire:model="newLogKind" class="input input-sm text-xs flex-1">
                    @foreach (AssignmentLog::KINDS as $k)
                        <option value="{{ $k }}">{{ ucfirst($k) }}</option>
                    @endforeach
                </select>
                <select wire:model="newLogStage" class="input input-sm text-xs flex-1">
                    <option value="">Stage: {{ ucfirst($currentStage) }}</option>
                    @foreach ($stages as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <textarea wire:model="newLogText" class="input w-full text-xs" rows="2" maxlength="500" placeholder="Note a decision, progress or risk..."></textarea>
            @error('newLogText') <p class="field-error text-[11px]">{{ $message }}</p> @enderror
            <div class="flex justify-end">
                <x-button type="submit" size="sm" variant="primary">Add note</x-button>
            </div>
        </form>

        {{-- Entries list --}}
        @if ($logs !== [])
            <ul class="space-y-2 text-xs" role="list">
                @foreach ($logs as $i => $entry)
                    <li wire:key="rail-log-{{ $suffix }}-{{ $entry->id }}"
                        class="p-2 rounded-lg bg-surface-sunken space-y-1.5"
                        x-show="showAll || {{ $i }} < 10"
                        x-data="{ sure: false }">
                        <div class="flex items-center justify-between gap-1 text-[11px]">
                            <div class="flex items-center gap-1.5">
                                <span class="badge badge-sm font-semibold uppercase">{{ $entry->stage }}</span>
                                <span class="text-fg-muted">·</span>
                                <span class="text-fg-muted font-medium">{{ ucfirst($entry->kind) }}</span>
                            </div>
                            <span class="text-fg-subtle">{{ Carbon::parse($entry->createdAt)->diffForHumans(short: true) }}</span>
                        </div>
                        <p class="text-fg leading-relaxed">{{ $entry->text }}</p>
                        <div class="flex items-center justify-between pt-1 border-t border-border/40 text-[11px] text-fg-subtle">
                            <span>{{ $entry->by === 'tutor' ? 'Tutor' : 'You' }}</span>
                            <div>
                                <button type="button" class="quiet-link text-xs" x-show="! sure" x-on:click="sure = true">Delete</button>
                                <span x-show="sure" x-cloak class="inline-flex items-center gap-1 text-[11px]">
                                    <span>Delete it?</span>
                                    <button type="button" class="btn btn-danger btn-xs" wire:click="deleteLog('{{ $entry->id }}')">Delete</button>
                                    <button type="button" class="btn btn-ghost btn-xs" x-on:click="sure = false">Keep</button>
                                </span>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if (count($logs) > 10)
                <button type="button" class="rail-more text-xs" x-on:click="showAll = ! showAll">
                    <span x-text="showAll ? 'Show fewer' : 'Show all ({{ count($logs) }})'"></span>
                </button>
            @endif
        @else
            <p class="rail-empty text-xs">No entries in the log yet.</p>
        @endif
    </div>
</section>
