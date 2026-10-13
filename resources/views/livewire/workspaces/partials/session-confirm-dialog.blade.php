{{--
    One confirmation dialog for the study session, instead of the browser's confirm().
    Anything on the page asks with:
        $dispatch('session-confirm', { kind: 'note' | 'file' | 'card' | 'quiz', id: '…', name: 'Title shown to the student' })
    Once confirmed, it says so with 'session-confirmed' ({ kind, id }), for the page to update what it shows.
    Cancel has the focus, so Enter alone never deletes anything.
--}}
<dialog id="session-confirm-dialog" class="modal modal-confirm" aria-labelledby="session-confirm-title" aria-describedby="session-confirm-text"
    wire:ignore
    x-data="{
        ask: null,
        kinds: {
            note: {
                title: 'Move this note to trash?',
                text: 'It leaves your notes and goes to the trash, where you can restore it.',
                action: 'Move to trash',
                method: 'deleteMaterialNote',
            },
            file: {
                title: 'Delete this file?',
                text: 'It is removed from the platform for good. This cannot be undone.',
                action: 'Delete file',
                method: 'deleteMaterialFile',
            },
            quiz: {
                title: 'Delete this quiz or test?',
                text: 'It is deleted for good, and your answers in it stop counting for their topics.',
                action: 'Delete',
                method: 'deleteQuiz',
            },
            card: {
                title: 'Delete this flashcard?',
                text: 'It is deleted for good, and your answers to it stop counting for its topic.',
                action: 'Delete card',
                method: 'deleteCard',
            },
        },
        open(detail) {
            if (! detail?.id || ! this.kinds[detail.kind]) return;
            this.ask = { ...this.kinds[detail.kind], kind: detail.kind, id: detail.id, name: detail.name || '' };
            $el.open || $el.showModal();
        },
        confirm() {
            const ask = this.ask;
            $el.close();
            if (! ask) return;
            $wire[ask.method](ask.id);
            $dispatch('session-confirmed', { kind: ask.kind, id: ask.id });
        },
    }"
    x-on:session-confirm.window="open($event.detail)"
    x-on:close="ask = null"
    x-on:click="$event.target === $el && $el.close()">
    <div class="modal-panel">
        <div class="modal-head modal-confirm-head">
            <span class="modal-confirm-icon" aria-hidden="true"><x-icon name="triangle-alert" class="size-5" /></span>
            <div class="min-w-0 flex-1 space-y-1">
                <h2 id="session-confirm-title" class="text-lg font-semibold" x-text="ask?.title"></h2>
                <p id="session-confirm-text" class="text-sm text-fg-muted" x-text="ask?.text"></p>
            </div>
        </div>
        <div class="px-5" x-show="ask?.name">
            <p class="modal-confirm-name" x-text="ask?.name"></p>
        </div>
        <div class="modal-actions">
            <x-button autofocus x-on:click="$el.closest('dialog').close()">Cancel</x-button>
            <x-button variant="danger" icon="trash-2" x-on:click="confirm()"><span x-text="ask?.action"></span></x-button>
        </div>
    </div>
</dialog>
