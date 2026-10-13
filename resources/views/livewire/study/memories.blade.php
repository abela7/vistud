{{--
    What the tutor remembers about the student (App\Livewire\Study\Memories): one window on every student page, opened
    from a chat's "Remembered" chip, Settings, or a course's Instructions for the AI. Every line by where it holds, each
    to change or delete, and a line to add one. Changes are kept at once.
--}}
<div>
    <dialog id="memories-dialog" class="modal" aria-labelledby="memories-dialog-title"
        wire:ignore.self
        x-data
        x-on:memories-dialog-open.window="$el.open || $el.showModal()"
        x-on:close="$wire.open && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($open)
            <div class="modal-panel memories-panel" wire:key="memories-panel">
                <div class="modal-head">
                    <div class="min-w-0 flex-1">
                        <h2 id="memories-dialog-title" class="text-lg font-semibold">What your tutor remembers</h2>
                        <p class="text-sm text-fg-muted">Short notes it keeps to teach you better. Only you and your tutor see them; changes are kept at once.</p>
                    </div>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="memories-body">
                    @unless ($remembers)
                        <p class="memory-off"><x-icon name="info" class="size-4 shrink-0" /><span>Remembering is off: your tutor keeps nothing new and doesn't read these. <a href="{{ route('settings', ['part' => 'ai']) }}#tutor" class="quiet-link">Turn it on</a></span></p>
                    @endunless

                    @if ($count === 0)
                        <div class="memories-empty">
                            <span class="chat-empty-badge" aria-hidden="true"><x-icon name="brain" class="size-5" /></span>
                            <p class="font-medium">Nothing yet</p>
                            <p class="text-sm text-fg-muted">When you tell your tutor something it should keep in mind, like what you find hard or when your exam is, it notes it here. You can add your own too.</p>
                        </div>
                    @else
                        @foreach ($groups as $group)
                            <section class="memory-group" aria-label="{{ $group['title'] }}" wire:key="memory-group-{{ $loop->index }}-{{ $group['title'] }}">
                                <h3 class="memory-group-title">{{ $group['title'] }}@if ($group['here'])<span class="memory-group-here">this course</span>@endif</h3>
                                <ul class="memory-list" role="list">
                                    @foreach ($group['items'] as $memory)
                                        <li @class(['memory-item', 'is-highlighted' => $highlight === $memory->id]) wire:key="memory-{{ $memory->id }}"
                                            @if ($highlight === $memory->id) x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))" @endif>
                                            @if ($editId === $memory->id)
                                                <div class="memory-edit">
                                                    <label for="memory-edit-text" class="sr-only">What your tutor remembers</label>
                                                    <textarea id="memory-edit-text" class="input" rows="2" maxlength="{{ \App\Study\TutorMemories::MAX_TEXT }}" wire:model="editText" x-on:keydown.enter.prevent="$wire.save()" x-on:keydown.escape.stop.prevent="$wire.cancel()" x-init="$nextTick(() => { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) })"></textarea>
                                                    @error('editText') <p class="field-error">{{ $message }}</p> @enderror
                                                    <div class="memory-edit-row">
                                                        @if ($this->movable($memory))
                                                            <x-checkbox name="editEverywhere" id="memory-edit-everywhere" label="For all my courses" wire:model="editEverywhere" />
                                                        @else
                                                            <span></span>
                                                        @endif
                                                        <div class="flex gap-2">
                                                            <x-button size="sm" wire:click="cancel">Cancel</x-button>
                                                            <x-button size="sm" variant="primary" wire:click="save" wire:loading.attr="aria-busy" wire:target="save">Save</x-button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <x-icon :name="$memory->by === 'tutor' ? 'brain' : 'user-round'" class="memory-icon size-4 shrink-0" />
                                                <div class="memory-body">
                                                    <p class="memory-text">{{ $memory->text }}</p>
                                                    <p class="memory-meta">{{ $memory->source($zone) }}</p>
                                                </div>
                                                <div class="memory-actions">
                                                    <button type="button" class="memory-action" wire:click="edit('{{ $memory->id }}')" aria-label="Change: {{ $memory->text }}" title="Change"><x-icon name="pencil" class="size-4" /></button>
                                                    <button type="button" class="memory-action is-danger" wire:click="forget('{{ $memory->id }}')" wire:confirm="Delete this? Your tutor will no longer know it." aria-label="Delete: {{ $memory->text }}" title="Delete"><x-icon name="trash-2" class="size-4" /></button>
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    @endif
                </div>

                <form class="memories-add" wire:submit="add" novalidate>
                    <label for="memory-new" class="field-label">Add something for your tutor to remember</label>
                    <div class="memory-add-row">
                        <input id="memory-new" class="input" type="text" maxlength="{{ \App\Study\TutorMemories::MAX_TEXT }}" wire:model="newText" placeholder="e.g. Exam on 14 January" autocomplete="off" />
                        <x-button type="submit" size="sm" variant="primary" icon="plus" wire:loading.attr="aria-busy" wire:target="add">Add</x-button>
                    </div>
                    @error('newText') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($courseName !== null)
                        <x-checkbox name="newEverywhere" id="memory-new-everywhere" label="For all my courses, not only {{ $courseName }}" wire:model="newEverywhere" />
                    @else
                        <p class="text-xs text-fg-muted">For all your courses.</p>
                    @endif
                </form>
            </div>
        @endif
    </dialog>
</div>
