{{--
    The full picture of an assignment (App\Livewire\Workspaces\AssignmentPicture): what the student was given about it
    (its sources, and the files in its folder), and what the AI understands from it, the questions to ask the teacher
    first. Every point says where it came from. A change to the sources updates the understanding by itself; the
    student says it is right, or corrects it.
--}}
@php
    use App\Study\AssignmentSources;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    // The parts of the understanding, in the order a student needs them, with what each is called.
    $parts = [
        'deliverables' => ['What to hand in', 'file-text'],
        'requirements' => ['Must do', 'list-checks'],
        'constraints' => ['Limits', 'ruler'],
        'criteria' => ['What it is marked on', 'clipboard-check'],
        'teacher_expects' => ['What the teacher wants to see', 'eye'],
        'dates' => ['Dates', 'calendar'],
        'modules' => ['Modules it draws on', 'layers'],
        'topics' => ['Topics it draws on', 'tag'],
        'best_practices' => ['Good practice', 'lightbulb'],
        'assumptions' => ['How the AI reads it', 'brain'],
        'risks' => ['Risks', 'triangle-alert'],
        'gaps' => ['Missing or unclear', 'circle-help'],
    ];
    $confidence = ['high' => 'Full picture', 'medium' => 'Mostly clear', 'low' => 'Much still unclear'];
@endphp
<section class="question-panel picture" aria-labelledby="picture-heading" @if ($reading) wire:poll.3s="check" @endif>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="picture-heading" class="panel-title"><x-icon name="sparkles" class="size-4" />The full picture</h2>
            <p class="panel-hint">Everything you were given about it, and what the AI understands from it. Every model that helps you with it reads this first.</p>
        </div>
        <a href="{{ route('workspaces.assignments.work', [$workspaceId, $activityId]) }}" class="quiet-link flex items-center gap-1.5 text-xs">
            <x-icon name="sparkles" class="size-3.5" />
            <span>Work on it with the tutor</span>
        </a>
    </div>

    {{-- What the student was given --}}
    <div class="picture-block" aria-labelledby="picture-sources-heading">
        <div class="picture-block-head">
            <h3 id="picture-sources-heading" class="picture-block-title">What you were given</h3>
            @unless ($adding)
                <button type="button" class="btn btn-secondary btn-sm" wire:click="add('brief')"><x-icon name="plus" class="size-4" />Add</button>
            @endunless
        </div>

        @if ($written === [] && $files === [] && ! $adding)
            <div class="picture-empty">
                <p class="text-sm text-fg-muted">Give the AI everything you have: the brief, the marking criteria, the teacher's emails and announcements, and what they said in class. Files in this assignment's folder are read too.</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['brief', 'rubric', 'email', 'feedback'] as $start)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="add('{{ $start }}')"><x-icon name="plus" class="size-4" />{{ $kinds[$start] }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($written !== [] || $files !== [])
            <ul class="picture-sources" role="list">
                @foreach ($files as $file)
                    <li class="picture-source" wire:key="picture-file-{{ $file->id }}">
                        <span class="picture-kind"><x-icon :name="$file->icon()" class="size-3.5" />File</span>
                        <a href="{{ route('workspaces.files.show', [$workspaceId, $file->id]) }}" class="picture-source-name">{{ $file->fileName() }}</a>
                        <span class="picture-source-meta">in its folder</span>
                    </li>
                @endforeach
                @foreach ($written as $source)
                    <li class="picture-source" wire:key="picture-source-{{ $source->id }}" x-data="{ sure: false }">
                        <span class="picture-kind">{{ $source->kindLabel() }}</span>
                        <span class="picture-source-name">{{ $source->title ?? Str::limit($source->text, 80) }}</span>
                        @if ($source->receivedOn)
                            <span class="picture-source-meta">{{ Carbon::parse($source->receivedOn)->format('j M') }}</span>
                        @endif
                        <span class="picture-source-actions" x-show="! sure">
                            <button type="button" class="quiet-link" wire:click="edit('{{ $source->id }}')">Edit<span class="sr-only"> {{ $source->name() }}</span></button>
                            <button type="button" class="quiet-link" x-on:click="sure = true">Delete<span class="sr-only"> {{ $source->name() }}</span></button>
                        </span>
                        <span class="picture-source-actions" x-show="sure" x-cloak role="alert">
                            <span class="text-sm">Delete it?</span>
                            <button type="button" class="btn btn-danger btn-sm" wire:click="delete('{{ $source->id }}')">Delete</button>
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="sure = false">Keep it</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($adding)
            <form class="picture-form" wire:submit="save" novalidate aria-label="{{ $editing ? 'Change what you were given' : 'Add what you were given' }}">
                <div class="picture-form-row">
                    <div class="field">
                        <label for="picture-kind" class="field-label">What it is</label>
                        <select id="picture-kind" class="input mt-1" wire:model="kind">
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="picture-title" class="field-label">Title <span class="font-normal text-fg-muted">(optional)</span></label>
                        <input id="picture-title" type="text" class="input mt-1" wire:model="title" maxlength="{{ AssignmentSources::MAX_TITLE }}" placeholder="Email from Dr. Smith, 3 Oct">
                        @error('title') <p class="field-error mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="picture-date" class="field-label">When it came <span class="font-normal text-fg-muted">(optional)</span></label>
                        <input id="picture-date" type="date" class="input mt-1" wire:model="receivedOn">
                        @error('receivedOn') <p class="field-error mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="field">
                    <label for="picture-text" class="field-label">What it says</label>
                    <textarea id="picture-text" class="input mt-1" rows="8" wire:model="text" maxlength="{{ AssignmentSources::MAX_TEXT }}" placeholder="Paste it as it is: the whole brief, the email, the rubric, or what the teacher said."></textarea>
                    @error('text') <p class="field-error mt-1">{{ $message }}</p> @enderror
                    @error('kind') <p class="field-error mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Keeping…">{{ $editing ? 'Save' : 'Add' }}</x-button>
                    <x-button variant="ghost" wire:click="cancel">Cancel</x-button>
                </div>
            </form>
        @endif
    </div>

    {{-- What the AI understands --}}
    <div class="picture-block" aria-labelledby="picture-understanding-heading">
        <div class="picture-block-head">
            <h3 id="picture-understanding-heading" class="picture-block-title">What the AI understands</h3>
            @if ($understanding && ! $reading)
                <div class="flex flex-wrap gap-2">
                    @if (! $understanding->confirmed() && ! $stale)
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="confirm"><x-icon name="circle-check" class="size-4" />This is right</button>
                    @endif
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('correcting', true)"><x-icon name="pencil" class="size-4" />Correct it</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="understand"><x-icon name="rotate-cw" class="size-4" />Read again</button>
                </div>
            @endif
        </div>

        @if ($problemText)
            <x-alert tone="danger">{{ $problemText }}</x-alert>
        @endif

        @if ($reading)
            <p class="picture-reading" role="status"><x-icon name="loader-circle" class="size-4 animate-spin" />The AI is reading everything. This can take a minute; you can leave the page.</p>
        @elseif ($understanding === null)
            @if ($written !== [] || $files !== [])
                <div class="picture-empty">
                    <p class="text-sm text-fg-muted">Not read yet.</p>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="understand"><x-icon name="sparkles" class="size-4" />Understand it</button>
                </div>
            @else
                <p class="text-sm text-fg-muted">Once you add what you were given, the AI writes down what it understands here.</p>
            @endif
        @endif

        @if ($correcting)
            <form class="picture-form" wire:submit="correct" novalidate aria-label="Correct the AI">
                <div class="field">
                    <label for="picture-correction" class="field-label">What did the AI get wrong, or miss?</label>
                    <textarea id="picture-correction" class="input mt-1" rows="3" wire:model="correction" maxlength="{{ AssignmentSources::MAX_TEXT }}" placeholder="The report is 2,000 words, not 1,500. The teacher said in class that diagrams count."></textarea>
                    @error('correction') <p class="field-error mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-button type="submit" variant="primary">Send</x-button>
                    <x-button variant="ghost" wire:click="cancel">Cancel</x-button>
                </div>
            </form>
        @endif

        @if ($understanding)
            @php $level = $understanding->text('confidence') ?: 'low'; @endphp
            <p class="picture-status">
                <span @class(['picture-confidence', "is-{$level}"])>{{ $confidence[$level] ?? $confidence['low'] }}</span>
                <span>Version {{ $understanding->revision }} · {{ Carbon::parse($understanding->createdAt)->diffForHumans() }}@if ($understanding->model) · {{ Str::afterLast($understanding->model, '/') }}@endif</span>
                @if ($stale)
                    <span class="picture-stale"><x-icon name="circle-alert" class="size-4" />Out of date: what you were given has changed</span>
                @elseif ($understanding->confirmed())
                    <span class="picture-confirmed"><x-icon name="circle-check" class="size-4" />You said it's right</span>
                @endif
            </p>

            @if ($understanding->text('summary') !== '')
                <p class="picture-summary">{{ $understanding->text('summary') }}</p>
            @endif
            @if ($understanding->text('goal') !== '')
                <p class="text-sm"><span class="font-semibold">The goal:</span> {{ $understanding->text('goal') }}</p>
            @endif
            @if ($understanding->revision > 1 && $understanding->changes)
                <p class="picture-changes"><span class="font-semibold">What changed:</span> {{ $understanding->changes }}</p>
            @endif

            @if ($understanding->items('questions') !== [])
                <section class="picture-questions" aria-labelledby="picture-questions-heading">
                    <h4 id="picture-questions-heading" class="picture-part-title"><x-icon name="message-square-text" class="size-4" />Ask your teacher</h4>
                    <ul role="list">
                        @foreach ($understanding->items('questions') as $item)
                            <li>{{ $item['text'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <div class="picture-parts">
                @foreach ($parts as $part => [$heading, $icon])
                    @if ($understanding->items($part) !== [])
                        <section class="picture-part" aria-label="{{ $heading }}">
                            <h4 class="picture-part-title"><x-icon :name="$icon" class="size-4" />{{ $heading }}</h4>
                            <ul role="list">
                                @foreach ($understanding->items($part) as $item)
                                    @php $from = $understanding->sourceName($item['source'] ?? null); @endphp
                                    <li class="picture-item">
                                        @switch($part)
                                            @case('deliverables')
                                                <span>{{ $item['what'] }}</span>
                                                @php $more = array_filter([$item['format'] ?? '', $item['length'] ?? '', ($item['on'] ?? null) ? 'by '.Carbon::parse($item['on'])->format('j M') : '']); @endphp
                                                @if ($more !== [])
                                                    <span class="picture-item-more">{{ implode(' · ', $more) }}</span>
                                                @endif
                                                @break
                                            @case('criteria')
                                                <span>{{ $item['criterion'] }}@if ($item['weight'] ?? null) <span class="picture-weight">{{ $item['weight'] }}%</span>@endif</span>
                                                @if (($item['looks_like'] ?? '') !== '')
                                                    <span class="picture-item-more">Full marks: {{ $item['looks_like'] }}</span>
                                                @endif
                                                @break
                                            @case('dates')
                                                <span>{{ $item['what'] }}@if ($item['on'] ?? null) <span class="picture-item-more">{{ Carbon::parse($item['on'])->format('D j M') }}</span>@endif</span>
                                                @break
                                            @case('modules')
                                                <a href="{{ route('workspaces.modules.show', [$workspaceId, $item['id']]) }}" class="tile-link">{{ $item['module'] }}</a>
                                                @if (($item['why'] ?? '') !== '')
                                                    <span class="picture-item-more">{{ $item['why'] }}</span>
                                                @endif
                                                @break
                                            @case('topics')
                                                <a href="{{ route('workspaces.topics.show', [$workspaceId, $item['id']]) }}" class="tile-link">{{ $item['topic'] }}</a>
                                                @if (($item['why'] ?? '') !== '')
                                                    <span class="picture-item-more">{{ $item['why'] }}</span>
                                                @endif
                                                @break
                                            @default
                                                <span>{{ $item['text'] }}</span>
                                        @endswitch
                                        @if ($from)
                                            <span @class(['picture-from', 'is-inferred' => ($item['source'] ?? '') === 'inferred'])>{{ ($item['source'] ?? '') === 'inferred' ? 'the AI\'s reading' : 'from '.$from }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
            </div>

            <button type="button" class="quiet-link" wire:click="$toggle('showHistory')" aria-expanded="{{ $showHistory ? 'true' : 'false' }}">
                <x-icon name="history" class="size-4" />{{ $showHistory ? 'Hide' : 'Show' }} earlier versions
            </button>
            @if ($showHistory)
                <ol class="picture-history" role="list">
                    @foreach ($history as $version)
                        <li>
                            <span class="font-medium">Version {{ $version->revision }}</span>
                            <span class="text-fg-muted">· {{ Carbon::parse($version->createdAt)->format('j M, H:i') }}@if ($version->confirmed()) · you said it was right @endif</span>
                            @if ($version->changes)
                                <p class="text-sm text-fg-muted">{{ $version->changes }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        @endif
    </div>

    <x-toast :message="$notice" :tone="$noticeTone" />
</section>
