@php
    use App\Study\Activities;
    use Illuminate\Support\Carbon;

    $backUrl = route('workspaces.assignments.show', [$workspaceId, $activityId]);
    $eyebrow = implode(' · ', array_filter([
        $workspace->name,
        $moduleTitle ?? null,
        $activity->kindLabel(),
    ]));

    $context = implode(' · ', array_filter([
        $activity->dueOn !== null ? 'Due ' . $activity->dueWords() : 'No deadline yet',
        $activity->dueOn !== null ? $activity->timeLeft() : null,
    ]));
@endphp

<div class="assignment-companion-page space-y-4"
    @if ($reading) wire:poll.3s="check" @endif
    x-data="{
        railOpen: false,
        init() {
            try {
                this.railOpen = localStorage.getItem('vistud-assignment-rail-open') === 'true';
            } catch {}
            this.$watch('railOpen', (open) => {
                try {
                    localStorage.setItem('vistud-assignment-rail-open', String(open));
                } catch {}
            });
        },
        toggleRail() {
            if (window.innerWidth < 768) {
                const sheet = document.getElementById('session-rail-sheet');
                if (sheet) {
                    sheet.open ? sheet.close() : sheet.showModal();
                }
                return;
            }
            this.railOpen = ! this.railOpen;
        }
    }">

    {{-- Top header bar --}}
    <x-page :title="$activity->title" :back-href="$backUrl" back-to="Assignment" :eyebrow="$eyebrow" :context="$context">
        <x-slot:menu>
            <button type="button" class="menu-item" x-on:click="$dispatch('clear-chat-confirm')">
                <x-icon name="rotate-ccw" class="size-4" />Start the chat over
            </button>
            <a href="{{ $backUrl }}" class="menu-item">
                <x-icon name="external-link" class="size-4" />Open the assignment page
            </a>
        </x-slot:menu>
        <x-slot:action>
            <button type="button" class="btn btn-secondary btn-sm"
                x-on:click="toggleRail()"
                x-bind:aria-pressed="railOpen.toString()"
                title="Toggle companion details"
                aria-label="Toggle details">
                <x-icon name="list-checks" class="size-4" />
                <span class="max-sm:sr-only">Details</span>
            </button>
        </x-slot:action>
    </x-page>

    {{-- Main content: Chat + Rail --}}
    <div class="flex gap-4 items-start relative min-h-[calc(100vh-12rem)]">
        {{-- Chat column --}}
        <div class="flex-1 min-w-0">
            <livewire:workspaces.tutor-chat :workspace-id="$workspaceId" :activity-id="$activityId" :key="'companion-chat-'.$activityId" />
        </div>

        {{-- Desktop Rail --}}
        <div class="session-rail-backdrop" wire:ignore.self x-show="railOpen" x-cloak x-on:click="railOpen = false" aria-hidden="true"></div>
        <aside class="session-rail" wire:ignore.self x-show="railOpen" x-cloak x-on:keydown.escape.window="if (railOpen) railOpen = false" aria-label="Assignment companion details">
            <div class="session-rail-head" wire:ignore>
                <span class="text-xs font-semibold text-fg truncate">Assignment companion</span>
                <button type="button" class="topbar-button size-7" x-on:click="railOpen = false" title="Close details" aria-label="Close details">
                    <x-icon name="x" class="size-4" />
                </button>
            </div>
            <div class="session-rail-scroll">
                @include('livewire.workspaces.partials.assignment-companion-rail', ['suffix' => 'side'])
            </div>
        </aside>
    </div>

    {{-- Mobile sheet for rail --}}
    <dialog id="session-rail-sheet" class="modal" aria-labelledby="session-rail-title"
        wire:ignore.self
        x-data
        x-on:session-rail-open.window="$el.open || $el.showModal()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            <div class="modal-head">
                <h2 id="session-rail-title" class="min-w-0 flex-1 text-lg font-semibold">Assignment companion</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" class="size-4" /></button>
            </div>
            <div class="rail-body px-5 pb-5">
                @include('livewire.workspaces.partials.assignment-companion-rail', ['suffix' => 'sheet'])
            </div>
        </div>
    </dialog>

    {{-- Stage change confirm dialog --}}
    <dialog id="stage-confirm-dialog" class="modal modal-confirm" aria-labelledby="stage-confirm-title" aria-describedby="stage-confirm-text"
        wire:ignore.self
        x-data="{
            targetStage: null,
            open(stage) {
                this.targetStage = stage;
                $el.showModal();
            },
            confirm() {
                if (this.targetStage) {
                    $wire.setStage(this.targetStage);
                }
                $el.close();
            }
        }"
        x-on:stage-confirm.window="open($event.detail.stage)"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            <div class="modal-head modal-confirm-head">
                <span class="modal-confirm-icon" aria-hidden="true"><x-icon name="triangle-alert" class="size-5" /></span>
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="stage-confirm-title" class="text-lg font-semibold">Move assignment stage?</h2>
                    <p id="stage-confirm-text" class="text-sm text-fg-muted">Move this assignment to the <span class="font-semibold text-fg" x-text="targetStage"></span> stage?</p>
                </div>
            </div>
            <div class="modal-actions">
                <x-button autofocus x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="primary" x-on:click="confirm()">Move stage</x-button>
            </div>
        </div>
    </dialog>

    {{-- Clear chat confirm dialog --}}
    <dialog id="clear-chat-dialog" class="modal modal-confirm" aria-labelledby="clear-chat-title" aria-describedby="clear-chat-text"
        wire:ignore.self
        x-data="{
            open() {
                $el.showModal();
            },
            confirm() {
                $wire.startOver();
                $el.close();
            }
        }"
        x-on:clear-chat-confirm.window="open()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            <div class="modal-head modal-confirm-head">
                <span class="modal-confirm-icon" aria-hidden="true"><x-icon name="triangle-alert" class="size-5" /></span>
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="clear-chat-title" class="text-lg font-semibold">Start this chat over?</h2>
                    <p id="clear-chat-text" class="text-sm text-fg-muted">The conversation is cleared. What it cost stays counted.</p>
                </div>
            </div>
            <div class="modal-actions">
                <x-button autofocus x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="danger" icon="trash-2" x-on:click="confirm()">Start over</x-button>
            </div>
        </div>
    </dialog>
</div>
