{{--
    Study Plugins Library modal partial:
    Allows students to browse and install/enable pre-made domain plugins
    (Math & Physics, Coding, Biology, Law, Languages) with real-time toggle.
--}}
@php
    $allPlugins = \App\Study\Plugins::all();
    $activePluginIds = $plugins ?? ($tutoring['plugins'] ?? []);
@endphp

<div class="space-y-4">
    <div class="space-y-1">
        <p class="text-sm text-fg-muted">
            Pre-made subject plugins give the AI tutor specialized reasoning frameworks, step-by-step formatting (such as KaTeX math formulas), and problem-solving rules.
        </p>
        <div class="flex items-center gap-2 text-xs text-fg-subtle">
            <span class="inline-block size-1.5 rounded-full bg-success"></span>
            <span>Zero-overhead: uninstalled plugins add exactly 0 tokens to the prompt.</span>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($allPlugins as $p)
            @php $isInstalled = in_array($p->id, $activePluginIds, true); @endphp
            <div class="flex flex-col justify-between p-3.5 rounded-xl border transition-all {{ $isInstalled ? 'border-accent bg-surface-raised shadow-xs' : 'border-border bg-surface' }}">
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="flex items-center justify-center size-8 rounded-lg border {{ $isInstalled ? 'border-accent bg-accent-subtle text-accent' : 'border-border bg-surface-raised text-fg-muted' }} shrink-0">
                                <x-icon name="{{ $p->icon }}" class="size-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <h3 class="text-sm font-semibold text-fg truncate">{{ $p->name }}</h3>
                                    <span class="text-xs px-1.5 py-0.5 rounded-full font-medium {{ $isInstalled ? 'bg-accent-subtle text-accent' : 'bg-surface border border-border text-fg-subtle' }}">{{ $p->category }}</span>
                                </div>
                                <p class="text-xs text-fg-muted font-medium truncate">{{ $p->badge }}</p>
                            </div>
                        </div>

                        <button type="button"
                            wire:click="togglePlugin('{{ $p->id }}')"
                            class="btn {{ $isInstalled ? 'btn-primary' : 'btn-secondary' }} btn-sm shrink-0 flex items-center gap-1.5 text-xs">
                            <x-icon name="{{ $isInstalled ? 'check' : 'plus' }}" class="size-3.5" />
                            <span>{{ $isInstalled ? 'Installed' : 'Install' }}</span>
                        </button>
                    </div>

                    <p class="text-xs text-fg-muted leading-relaxed">
                        {{ $p->description }}
                    </p>

                    <div class="space-y-1 pt-1 border-t border-divider text-xs text-fg-subtle">
                        <p class="font-semibold text-fg uppercase tracking-wider">Features &amp; Framework:</p>
                        <ul class="space-y-0.5 pl-3 list-disc marker:text-accent">
                            @foreach ($p->promptInstructions as $rule)
                                <li>{{ Str::limit($rule, 110) }}</li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($p->toolClasses !== [])
                        <div class="flex items-center gap-1.5 pt-1 border-t border-divider text-xs">
                            <span class="font-semibold text-fg">Tools:</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($p->toolClasses as $toolClass)
                                    @php $toolName = (new $toolClass)->name(); @endphp
                                    <span class="px-1.5 py-0.5 rounded font-mono border border-border bg-surface text-accent font-medium">{{ $toolName }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                @if ($p->starterQuestions !== [])
                    <div class="mt-2.5 pt-2 border-t border-divider">
                        <span class="text-xs text-fg-subtle font-medium">Starter prompts:</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach ($p->starterQuestions as $q)
                                <span class="text-xs px-1.5 py-0.5 rounded-md border border-border bg-surface text-fg-muted">{{ $q }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between text-xs p-2.5 rounded-lg border border-border bg-surface-raised">
        <span class="text-fg-muted">
            Installed plugins: <strong class="text-fg">{{ count($activePluginIds) }}</strong> of {{ count($allPlugins) }}
        </span>
        <span class="text-xs text-fg-subtle">Saved automatically</span>
    </div>
</div>
