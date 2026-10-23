{{--
    Getting your own key, step by step (the owner's ask, 2026-10-23): a side panel (the whole screen on a phone), one
    step at a time with Back and Next and a dot for each step. OpenRouter's pages open in a new tab; the key is pasted
    and tried here (EngineSettings::saveGuideKey), then a model is chosen (chooseGuideModel). Steps live in the page,
    not the server: only the last two touch it.
--}}
@php
    $steps = 7;
    $hasKey = $keyState === 'own';
@endphp
{{-- ?guide=key (ViStud AI's link, OwnKeyAnswerTool) opens it at once. --}}
<div x-data="{ open: false, step: 1, steps: {{ $steps }} }"
    x-init="if (new URLSearchParams(location.search).get('guide') === 'key') { open = true; step = {{ $hasKey ? 7 : 1 }} }"
    x-on:key-guide-open.window="open = true; step = {{ $hasKey ? 7 : 1 }}"
    x-on:keydown.escape.window="open = false">
    <div class="key-guide-scrim" x-show="open" x-cloak x-transition.opacity x-on:click="open = false" aria-hidden="true"></div>
    <section class="key-guide" x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="key-guide-title"
        x-transition:enter="key-guide-move" x-transition:enter-start="is-out" x-transition:leave="key-guide-move" x-transition:leave-end="is-out"
        x-trap.noscroll="open">
        <header class="key-guide-head">
            <div>
                <p class="key-guide-kicker">Your own AI key · step <span x-text="step"></span> of {{ $steps }}</p>
                <h2 id="key-guide-title" class="key-guide-title">
                    <span x-show="step === 1">Make an OpenRouter account</span>
                    <span x-show="step === 2" x-cloak>Add a little credit</span>
                    <span x-show="step === 3" x-cloak>Create your key</span>
                    <span x-show="step === 4" x-cloak>Set a spending limit</span>
                    <span x-show="step === 5" x-cloak>Copy it and keep it safe</span>
                    <span x-show="step === 6" x-cloak>Paste it here</span>
                    <span x-show="step === 7" x-cloak>Choose your tutor</span>
                </h2>
            </div>
            <button type="button" class="key-guide-close" x-on:click="open = false" aria-label="Close the guide"><x-icon name="x" class="size-5" /></button>
        </header>
        <ol class="key-guide-dots" aria-hidden="true">
            @for ($i = 1; $i <= $steps; $i++)
                <li :class="{ 'is-done': step > {{ $i }}, 'is-now': step === {{ $i }} }"></li>
            @endfor
        </ol>

        <div class="key-guide-body">
            {{-- 1 --}}
            <div x-show="step === 1" class="key-guide-step">
                <p>OpenRouter is one account for every AI model. ViStud uses it to talk to the AI with your own key, so you pay only for what you use.</p>
                <a href="https://openrouter.ai" class="btn btn-primary key-guide-go" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" class="size-4" />Open openrouter.ai</a>
                <p class="key-guide-tip">Press <strong>Sign up</strong> (top right) and sign up with Google or your email. Then come back here.</p>
            </div>
            {{-- 2 --}}
            <div x-show="step === 2" x-cloak class="key-guide-step">
                <p>The AI is paid as you go. A few dollars lasts a long time: a study session usually costs a few cents.</p>
                <a href="https://openrouter.ai/settings/credits" class="btn btn-primary key-guide-go" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" class="size-4" />Open Credits</a>
                <p class="key-guide-tip">Press <strong>Add credits</strong> and add <strong>$5</strong>. You can always add more later.</p>
            </div>
            {{-- 3 --}}
            <div x-show="step === 3" x-cloak class="key-guide-step">
                <p>A key is the password ViStud uses to ask the AI for you.</p>
                <a href="https://openrouter.ai/settings/keys" class="btn btn-primary key-guide-go" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" class="size-4" />Open Keys</a>
                <p class="key-guide-tip">Press <strong>Create key</strong> and name it <strong>ViStud</strong>. Don't press Create yet: the next step is in the same box.</p>
            </div>
            {{-- 4 --}}
            <div x-show="step === 4" x-cloak class="key-guide-step">
                <p>In the same box, find <strong>Credit limit</strong> and type an amount, like <strong>5</strong>.</p>
                <div class="key-guide-callout"><x-icon name="shield-check" class="size-5" /><span>The key can then never spend more than that, even if someone else got hold of it. You can raise it later.</span></div>
                <p class="key-guide-tip">Then press <strong>Create</strong>.</p>
            </div>
            {{-- 5 --}}
            <div x-show="step === 5" x-cloak class="key-guide-step">
                <p>OpenRouter shows the key <strong>only once</strong>. It starts with <code>sk-or-</code>. Press <strong>Copy</strong>.</p>
                <div class="key-guide-callout is-warning"><x-icon name="key-round" class="size-5" />
                    <span>Keep it in a password manager (Bitwarden, Google Password Manager, iCloud Keychain). <strong>Never</strong> send it in a chat, email or screenshot, and never paste it into another AI.</span></div>
                <p class="key-guide-tip">Lost it? Delete it at OpenRouter and make a new one.</p>
            </div>
            {{-- 6 --}}
            <div x-show="step === 6" x-cloak class="key-guide-step">
                <p>Paste your key below and press <strong>Save and try</strong>. ViStud keeps it encrypted and shows only its last four characters again.</p>
                @if ($hasKey)
                    <div class="key-guide-callout is-success"><x-icon name="circle-check" class="size-5" /><span>Your key ending <strong>{{ $choices->ownKeyHint }}</strong> is saved{{ ($result['ok'] ?? null) === true ? ' and works' : '' }}.</span></div>
                @endif
                <div class="space-y-2" x-on:keydown.enter.prevent="$wire.saveGuideKey()">
                    <x-field name="key" :label="$hasKey ? 'Replace it (optional)' : 'Your key'" type="password" wire:model="key" autocomplete="off" :revealable="true" placeholder="sk-or-…" />
                    <x-button type="button" variant="primary" icon="check" wire:click="saveGuideKey" wire:loading.attr="aria-busy" wire:target="saveGuideKey" busy-label="Checking…">Save and try</x-button>
                </div>
                @if ($result && ! $result['ok'])
                    <x-alert tone="danger" title="It doesn't work yet">{{ $result['message'] }}</x-alert>
                @endif
            </div>
            {{-- 7 --}}
            <div x-show="step === 7" x-cloak class="key-guide-step">
                <p>Pick the model that teaches you. You can change it any time in <strong>Your models</strong>.</p>
                <ul class="key-guide-picks" role="list">
                    @foreach ($guidePicks as $pick)
                        <li>
                            <button type="button" @class(['key-guide-pick', 'is-chosen' => $tutorModel === $pick['id']]) wire:click="chooseGuideModel('{{ $pick['id'] }}')" @disabled(! $hasKey)>
                                <span class="key-guide-pick-name">{{ $pick['name'] }}@if ($tutorModel === $pick['id'])<x-icon name="circle-check" class="size-4" />@endif</span>
                                <span class="key-guide-pick-why">{{ $pick['why'] }}</span>
                                @if ($pick['price'])<span class="key-guide-pick-price">{{ $pick['price'] }}</span>@endif
                            </button>
                        </li>
                    @endforeach
                </ul>
                @unless ($hasKey)
                    <p class="key-guide-tip">Save your key first (step 6).</p>
                @endunless
            </div>
        </div>

        <footer class="key-guide-foot">
            <x-button type="button" variant="ghost" icon="chevron-left" x-show="step > 1" x-on:click="step--">Back</x-button>
            <span class="flex-1"></span>
            <x-button type="button" variant="primary" x-show="step < steps" x-bind:disabled="step === 6 && ! {{ $hasKey ? 'true' : 'false' }}" x-on:click="step++">Next<x-icon name="chevron-right" class="size-4" /></x-button>
            <x-button type="button" variant="primary" icon="check" x-show="step === steps" x-cloak x-on:click="open = false">Done</x-button>
        </footer>
    </section>
</div>
