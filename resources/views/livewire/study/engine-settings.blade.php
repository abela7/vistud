{{--
    A student's AI settings (App\Livewire\Study\EngineSettings). Each major part is an accordion, with one line of
    where it stands: their key (pasted once, tried at once, shown again only by its last four characters), the models
    worth paying for, trying the models, what the month has cost, a model for each of the three roles, how the tutor
    works and the limits. Nothing is cut short: long words wrap. Hints are one line (DESIGN.md §5.4).
--}}
@php
    use Illuminate\Support\Carbon;
    $own = \App\Engine\Providers::name($choices->provider ?? 'openrouter');
@endphp
<div class="engine-page">
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>

    @include('livewire.study.partials.recommended-models')

    <x-accordion id="key" title="Your key" icon="key-round" :open="$keyState === 'none' || (bool) $result || $confirmingRemoval">
        <x-slot:status>
            @if ($keyState === 'own')
                <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />Set</span> {{ $own }} key ending {{ $choices->ownKeyHint }}
            @elseif ($keyState === 'shared')
                <span class="badge"><x-icon name="users" class="size-3.5" />Using the owner's</span>
            @else
                <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Not set up</span>
            @endif
        </x-slot:status>

        @if ($keyState === 'shared' && $shared !== null)
            <p class="acc-note">You're using the shared key, up to {{ $choices::dollars($shared['daily_micros']) }} a day, with {{ count($shared['models']) === 1 ? 'one model' : count($shared['models']).' models to choose from' }}. Add your own key to choose any model, with no daily limit.</p>
        @elseif ($keyState === 'shared')
            <p class="acc-note">The owner's key is in use. Paste your own to pay yourself.</p>
        @endif
        @if ($result)
            <x-alert :tone="$result['ok'] ? 'success' : 'danger'" :title="$result['ok'] ? 'It works' : 'It doesn\'t work yet'">{{ $result['message'] }}</x-alert>
        @endif
        @if ($confirmingRemoval)
            <div class="plan-confirm" role="alert">
                <p class="font-medium">Remove your key? {{ $setupUrl !== null || $keyState === 'own' ? 'Your AI chats stop until you add a key.' : '' }}</p>
                <div class="flex flex-wrap gap-2">
                    <x-button variant="danger" size="sm" wire:click="removeKey">Remove it</x-button>
                    <x-button size="sm" wire:click="keepKey">Keep it</x-button>
                </div>
            </div>
        @endif

        <fieldset class="space-y-2">
            <legend class="sr-only">Where is your key from?</legend>
            <div class="choice-grid">
                <label class="choice-card">
                    <input type="radio" class="sr-only" name="key-source" value="openrouter" wire:model.live="source">
                    <span class="item-icon" aria-hidden="true"><x-icon name="network" class="size-5" /></span>
                    <span class="choice-text">
                        <span class="choice-title">OpenRouter</span>
                        <span class="choice-hint">One key for every model</span>
                    </span>
                    <x-icon name="circle-check" class="choice-mark size-5" />
                </label>
                <label class="choice-card">
                    <input type="radio" class="sr-only" name="key-source" value="own" wire:model.live="source">
                    <span class="item-icon" aria-hidden="true"><x-icon name="key" class="size-5" /></span>
                    <span class="choice-text">
                        <span class="choice-title">A provider's own key</span>
                        <span class="choice-hint">OpenAI, Anthropic, Google and more</span>
                    </span>
                    <x-icon name="circle-check" class="choice-mark size-5" />
                </label>
            </div>
        </fieldset>

        @if ($source === 'openrouter' && $keyState !== 'own')
            <p class="acc-note">Get a key at <a href="https://openrouter.ai" class="text-link" target="_blank" rel="noopener">openrouter.ai</a> (add a few dollars of credit), paste it, and press <strong>Save and try</strong>.</p>
        @endif

        <form wire:submit="saveKey" novalidate class="space-y-4">
            <div class="key-fields">
                @if ($source === 'own')
                    <div class="field">
                        <label for="key-provider" class="field-label">Provider</label>
                        <select id="key-provider" class="input" wire:model="provider">
                            <option value="own">Find out for me</option>
                            @foreach ($providers as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('provider') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <x-field name="key" :label="$keyState === 'own' ? 'Replace your key' : 'Your key'" type="password" wire:model="key" autocomplete="off" :revealable="true" :placeholder="$source === 'own' ? 'Paste it here' : 'sk-or-…'" hint="Kept encrypted." />
            </div>
            <div class="acc-actions">
                <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveKey" busy-label="Checking…">Save and try</x-button>
                @if ($keyState !== 'none')
                    <x-button icon="play" wire:click="test" wire:loading.attr="aria-busy" wire:target="test" busy-label="Trying…">Try it</x-button>
                @endif
                @if ($keyState === 'own')
                    <x-button variant="danger" icon="trash-2" wire:click="askRemove">Remove</x-button>
                @endif
            </div>
        </form>
        @if ($keyState === 'none' && $setupUrl)
            <p class="acc-note">Admin: <a href="{{ $setupUrl }}" class="text-link">set up one key for admin accounts</a>.</p>
        @endif
    </x-accordion>

    @if ($keyState === 'own' || $trouble)
        <x-accordion id="try-models" title="Try your models" icon="flask-conical" :open="(bool) $trouble || (bool) $checks || $trial !== []">
            <x-slot:status>
                @if ($trouble)
                    <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Last problem</span>
                @else
                    Check that each model answers
                @endif
            </x-slot:status>

            @if ($trouble)
                <x-alert tone="warning" :title="'Last problem · '.ucfirst($trouble['role']).($trouble['model'] !== '' ? ' · '.$trouble['model'] : '').' · '.Carbon::parse($trouble['at'])->diffForHumans()">{{ $trouble['message'] }}</x-alert>
            @endif

            @if ($keyState === 'own')
                <div class="acc-actions">
                    <x-button icon="play" wire:click="checkModels" wire:loading.attr="aria-busy" wire:target="checkModels" busy-label="Trying each…">Try all my models</x-button>
                </div>
            @endif

            @if ($checks)
                <ul class="model-checks" role="list" aria-label="Your models, tried">
                    @foreach ($checks as $label => $check)
                        <li class="model-check" data-ok="{{ $check['ok'] ? 'yes' : 'no' }}">
                            <x-icon :name="$check['ok'] ? 'circle-check' : 'circle-alert'" class="model-check-mark size-5" />
                            <div class="min-w-0 flex-1">
                                <p class="model-check-head"><span class="font-medium text-fg">{{ $label }}</span> <code>{{ $check['model'] }}</code>
                                    @if ($check['ms'] > 0) <span class="text-fg-muted">· {{ number_format($check['ms'] / 1000, 1) }} s</span> @endif
                                </p>
                                <p class="model-check-said">{{ $check['ok'] ? ($check['reply'] !== '' ? 'Answered: “'.$check['reply'].'”' : $check['message']) : $check['message'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($keyState === 'own')
                <div class="try-chat">
                    @if ($trial !== [])
                        <div class="try-chat-log" role="log" aria-label="Your tries">
                            @foreach ($trial as $turn)
                                <p class="try-chat-you">{{ $turn['you'] }}</p>
                                <div class="try-chat-reply" data-ok="{{ $turn['ok'] ? 'yes' : 'no' }}">
                                    <p class="try-chat-meta"><code>{{ $turn['model'] }}</code>@if ($turn['ms'] > 0) · {{ number_format($turn['ms'] / 1000, 1) }} s @endif @if ($turn['cost'] !== null) · {{ $choices::dollars($turn['cost']) }} @endif</p>
                                    <p class="whitespace-pre-line">{{ $turn['ok'] && $turn['reply'] !== '' ? $turn['reply'] : $turn['message'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <form wire:submit="sendTry" novalidate class="try-chat-form">
                        <label for="try-model" class="sr-only">Model to try</label>
                        <input id="try-model" type="text" class="input try-chat-model" wire:model="tryModel" list="engine-models" autocomplete="off" placeholder="{{ $choices->tutorModel !== '' ? $choices->tutorModel : 'Model' }}">
                        <label for="try-text" class="sr-only">Message</label>
                        <input id="try-text" type="text" class="input try-chat-text" wire:model="tryText" maxlength="500" autocomplete="off" placeholder="Say hello">
                        <x-button type="submit" variant="primary" icon="arrow-up" wire:loading.attr="aria-busy" wire:target="sendTry" busy-label="Asking…">Send</x-button>
                        @if ($trial !== [])
                            <x-button variant="ghost" icon="x" wire:click="clearTry">Clear</x-button>
                        @endif
                    </form>
                </div>
            @endif
        </x-accordion>
    @endif


    <form wire:submit="save" novalidate class="engine-form-stack">
        <datalist id="engine-models">
            @foreach ($models as $model)
                <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->priceWords() }}</option>
            @endforeach
        </datalist>

        <x-accordion id="models" title="Your models" icon="brain">
            <x-slot:status>{{ $tutorModel !== '' ? 'Tutor: '.$tutorModel : 'Choose a tutor' }}</x-slot:status>
            @if ($shared !== null)
                {{-- On the shared key: only the models the owner allowed, a purpose each, and which of them the chat offers. --}}
                <p class="acc-note">You're on the shared key. Choose which of its models does each job, and which you can switch to in a chat.</p>
                <div class="model-fields">
                    @foreach ([['tutorModel', 'Tutor', $roles[0]->does()], ['readerModel', 'Reader', $roles[1]->does()], ['helperModel', 'Helper', $roles[2]->does()]] as [$field, $label, $does])
                        <div class="space-y-1.5">
                            <label for="field-{{ $field }}" class="field-label">{{ $label }}</label>
                            <select id="field-{{ $field }}" class="input" wire:model.live="{{ $field }}" aria-describedby="field-{{ $field }}-hint">
                                @foreach ($sharedList as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}{{ $option['price'] ? ' · '.$option['price'] : '' }}</option>
                                @endforeach
                            </select>
                            <p id="field-{{ $field }}-hint" class="text-sm text-fg-muted">{{ $does }}</p>
                            @error($field)
                                <p class="field-error"><x-icon name="circle-alert" class="mt-0.5 size-4" /><span>{{ $message }}</span></p>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="fav-block">
                    <h3 class="fav-title">In the chat</h3>
                    <p class="acc-note">Switch on the models you want to pick from while you study. Your tutor is always there.</p>
                    <ul class="fav-list" role="list">
                        @foreach ($sharedList as $option)
                            <li class="fav-item" wire:key="shared-{{ $option['id'] }}">
                                @if ($option['id'] === $tutorModel)
                                    <x-checkbox :name="'shared-'.$loop->index" :label="$option['name']" :hint="$option['id'].' · your tutor, always in the chat'" :checked="true" disabled />
                                @else
                                    <x-checkbox :name="'shared-'.$loop->index" :label="$option['name']" :hint="$option['id'].($option['price'] ? ' · '.$option['price'] : '')" :value="$option['id']" wire:model.live="favoriteModels" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
            @if ($keyState !== 'none' && $models === [])
                <p class="acc-note">The list didn't load. Type a model's id, or press <em>Try it</em> to see why.</p>
            @endif
            <div class="model-fields">
                <x-field name="tutorModel" label="Tutor" wire:model.live.debounce.500ms="tutorModel" list="engine-models" autocomplete="off" placeholder="openai/gpt-4.1-mini" :hint="$tutorWords ?? $roles[0]->does()" />
                <x-field name="readerModel" label="Reader (optional)" wire:model.live.debounce.500ms="readerModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash" :hint="$readerWords ?? $roles[1]->does()" />
                <x-field name="helperModel" label="Helper (optional)" wire:model.live.debounce.500ms="helperModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash-lite" :hint="$helperWords ?? $roles[2]->does()" />
                <x-field name="fallbackModel" label="If the tutor fails (optional)" wire:model.live.debounce.500ms="fallbackModel" list="engine-models" autocomplete="off" placeholder="anthropic/claude-haiku-4.5" :hint="$fallbackWords ?? 'Tried when the tutor is down.'" />
            </div>

            <div class="fav-block">
                <h3 class="fav-title">Favorite models in chat</h3>
                @if ($favDetails !== [])
                    <ul class="fav-list" role="list">
                        @foreach ($favDetails as $fav)
                            <li class="fav-item">
                                <div class="fav-text">
                                    <span class="fav-name">{{ $fav['name'] }}</span>
                                    <span class="fav-id">{{ $fav['id'] }} @if ($fav['price']) · {{ $fav['price'] }} @endif</span>
                                </div>
                                <x-button size="sm" variant="danger" icon="trash-2" wire:click="removeFavoriteModel('{{ $fav['id'] }}')" aria-label="Remove {{ $fav['name'] }}">Remove</x-button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="fav-add">
                    <label for="new-fav-model" class="sr-only">Add model id</label>
                    <input id="new-fav-model" type="text" class="input" wire:model="newFavoriteModel" list="engine-models" placeholder="Model id" autocomplete="off">
                    <x-button icon="plus" wire:click="addFavoriteModel" wire:loading.attr="aria-busy" wire:target="addFavoriteModel">Add to chat</x-button>
                </div>
                <div class="fav-suggest">
                    <span class="fav-suggest-label">Popular recommendations:</span>
                    @foreach ($suggested as $sugId => $sugName)
                        @if (! in_array($sugId, $favoriteModels, true))
                            <button type="button" class="badge fav-chip" wire:click="addFavoriteModel('{{ $sugId }}')">
                                <x-icon name="plus" class="size-3 text-accent" />
                                <span>{{ $sugName }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
            @endif
        </x-accordion>

        <x-accordion id="tutor" title="How the tutor works" icon="languages">
            <x-slot:status>Your language and what it may do</x-slot:status>
            <datalist id="engine-languages">
                @foreach (['English', 'Amharic', 'Afaan Oromo', 'Tigrinya', 'Somali', 'Arabic', 'French', 'Spanish', 'Portuguese', 'Swahili', 'Hindi', 'Chinese'] as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <x-field name="language" label="Teach me in (optional)" wire:model="language" list="engine-languages" autocomplete="off" placeholder="Amharic" hint="Empty: the language you write in." />
            {{-- The model the microphone's recording goes to (App\Engine\Dictation). --}}
            <div class="space-y-1.5">
                <label for="field-dictationModel" class="field-label">Voice typing</label>
                <select id="field-dictationModel" class="input" wire:model="dictationModel" aria-describedby="field-dictationModel-hint">
                    @foreach (\App\Engine\Dictation::modelsFor($shared) as $id => $model)
                        <option value="{{ $id }}">{{ $model['name'] }}</option>
                    @endforeach
                </select>
                <p id="field-dictationModel-hint" class="text-sm text-fg-muted">{{ \App\Engine\Dictation::MODELS[$dictationModel]['hint'] ?? '' }} The 🎤 in the chat records, and this writes it down.</p>
            </div>
            <x-checkbox name="askTopics" label="Ask me before adding or switching topics" hint="Off: the tutor keeps them and says so." wire:model="askTopics" />
            <x-checkbox name="tutorMarksTopics" label="Let the tutor mark topics" hint="It marks what you've covered. You can undo it." wire:model="tutorMarksTopics" />
            <div class="memory-setting">
                <x-checkbox name="tutorRemembers" label="Let the tutor remember things about you" hint="Short notes, like what you find hard. You see and change them." wire:model="tutorRemembers" />
                <x-button size="sm" icon="brain" x-on:click="Livewire.dispatch('memories-open')">See what it remembers</x-button>
            </div>
            <x-checkbox name="autoReadFiles" label="Read my files automatically" hint="Each new file is read once." wire:model="autoReadFiles" />
            <x-checkbox name="copyPasteAi" label="I use another AI by copy-paste" hint="Shows the copy and paste tools." wire:model="copyPasteAi" />
        </x-accordion>

        <x-accordion id="limits" title="Limits and privacy" icon="shield-check">
            <x-slot:status>{{ $choices->sessionCapMicros > 0 ? $choices::dollars($choices->sessionCapMicros).' a session' : 'No session limit' }} · {{ $choices->monthCapMicros > 0 ? $choices::dollars($choices->monthCapMicros).' a month' : 'no monthly limit' }}</x-slot:status>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sessionCap" label="Most a session may cost ($)" type="text" inputmode="decimal" wire:model="sessionCap" hint="0 for no limit." />
                <x-field name="monthCap" label="Most a month may cost ($)" type="text" inputmode="decimal" wire:model="monthCap" hint="0 for no limit." />
            </div>
            <x-checkbox name="noTraining" label="Keep my words out of model training" hint="Only providers that promise this are used." wire:model="noTraining" />
            <x-checkbox name="consent" label="Send my study material to the model's provider" hint="Only when I use the AI." wire:model="consent" />
        </x-accordion>

        <div class="acc-actions">
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
        </div>
    </form>

    <x-accordion id="usage" title="Usage this month" icon="trending-up">
        <x-slot:status>
            @if ($choices->monthCapMicros > 0)
                {{ $choices->spent($usage['total']) }} of {{ $choices::dollars($choices->monthCapMicros) }}, all three together
            @else
                No monthly limit
            @endif
        </x-slot:status>
        <dl class="grid grid-cols-3 gap-2 sm:gap-3" data-usage>
            @foreach ($roles as $role)
                <div class="usage-tile">
                    <dt>{{ $role->label() }}</dt>
                    <dd data-usage-role="{{ $role->value }}">{{ $choices->spent($usage[$role->value]) }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($cachedShare !== null)
            <p class="acc-note" data-usage-cached>{{ $cachedShare }}% of what was sent to the AI this month was reused from the last message, which costs about a tenth of the price.</p>
        @endif
    </x-accordion>

    {{-- Tokens: today, this week, this month and all time, by area, course and model (App\Engine\TokenUsage). --}}
    <livewire:study.token-usage-panel />
</div>
