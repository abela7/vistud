{{--
    A study session's page (App\Livewire\Workspaces\StudySession), chat first (docs/specs/vistud-2-blueprint.md §3.5.4):
    the page template's header (the module above the title, the clock and End in its action slot, the ⋯ menu), then the
    conversation filling the page with the rail beside it: the module's topics (tap to switch), its material, what this
    session saved. On a phone the rail is a sheet opened from the topic name. The questions board and the timeline are on
    the module's Questions and Sessions tabs. Ending the session is one screen (the `end` panel, then `done`).
--}}
@php
    use App\Study\SessionDetails;
    use App\Study\Topics;
    use Illuminate\Support\Str;

    $open = $session->isOpen();
    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
    $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing', 'mastered' => 'Mastered'];
    $stateIcons = ['running' => 'timer', 'paused' => 'pause', 'break' => 'coffee', 'ended' => 'square'];
    $studied = SessionDetails::duration($session->studySeconds).($session->breakSeconds > 0 ? ', with '.SessionDetails::duration($session->breakSeconds).' of breaks' : '');
    // The header's second word is the mode: the topic, the whole module (or the folder it is in), a quiz, a test, or free.
    $heading = match ($session->mode) {
        'module' => ($folder ? $folder->name : 'Whole module').($topic ? ' · '.$topic->name : ''),
        'quiz' => 'Quiz'.($topic ? ' · '.$topic->name : ($placeName ? ' · '.$placeName : '')),
        'test' => 'Test'.($placeName ? ' · '.$placeName : ''),
        'free' => 'Free study',
        default => $topic?->name ?? $placeName ?? 'Study session',
    };
    // Back goes to where the session is: its folder, its module, or the course.
    $backHref = match (true) {
        $folder !== null => route('workspaces.folders.show', [$workspaceId, $folder->id]),
        $module !== null => route('workspaces.modules.show', [$workspaceId, $module->id]),
        default => route('workspaces.show', $workspaceId),
    };
    $context = implode(' · ', array_filter([
        ($session->manual ? 'Logged for ' : 'Started ').$started->format('D j M, H:i'),
        $ended && ! $session->manual ? 'ended '.$ended->format('H:i') : null,
    ]));
@endphp
<div class="session-page"
    wire:ignore.self
    data-session-id="{{ $session->id }}"
    data-initial-open="{{ $open ? '1' : '0' }}"
    data-initial-note-url="{{ $sideNote['defaultUrl'] }}"
    data-initial-note-title="{{ $sideNote['defaultTitle'] }}"
    data-initial-notes="{{ json_encode(array_merge([['id' => 'default', 'title' => $sideNote['defaultTitle'], 'url' => $sideNote['defaultUrl'], 'isDefault' => true]], array_map(fn($n) => ['id' => $n['id'], 'title' => $n['title'], 'url' => $n['url'], 'isDefault' => false], $sideNote['items']))) }}"
    data-new-note-url="{{ $sideNote['newUrl'] }}"
    data-initial-file-url="{{ $sideFile['defaultUrl'] }}"
    data-initial-file-name="{{ $sideFile['defaultName'] }}"
    data-initial-files="{{ json_encode($sideFile['items']) }}"
    data-file-upload="{{ json_encode($sideFile['upload']) }}"
    x-data="{
        sessionId: '',
        fullscreen: true,
        railOpen: false,
        openPanes: ['chat'],
        splitPercent: 50,
        isDragging: false,
        noteUrl: '',
        noteTitle: '',
        noteItems: [],
        newNoteUrl: '',
        notePickerOpen: false,
        noteSearch: '',
        noteTab: 'notes',
        fileTab: 'files',
        activeSyncedNoteId: '',
        fileUrl: '',
        fileName: '',
        fileItems: [],
        fileUploadConfig: {},
        fileUploading: false,
        fileUploadError: '',
        filePickerOpen: false,
        fileSearch: '',
        fileScope: 'folder',
        fileDragOver: false,
        filteredNotes() {
            const q = (this.noteSearch || '').trim().toLowerCase();
            if (q === '') return this.noteItems;
            return this.noteItems.filter((n) => n.title.toLowerCase().includes(q));
        },
        filteredFiles() {
            const q = this.fileSearch.trim().toLowerCase();
            return this.fileItems.filter((f) => {
                if (this.fileScope === 'folder' && !f.inFolder && f.url !== this.fileUrl) {
                    return false;
                }
                if (q !== '' && !f.name.toLowerCase().includes(q) && !(f.type || '').toLowerCase().includes(q)) {
                    return false;
                }
                return true;
            });
        },
        hasOtherFiles() {
            return this.fileItems.some((f) => !f.inFolder);
        },

        destroy() {
            document.documentElement.removeAttribute('data-session-fullscreen');
        },

        init() {
            const el = this.$el;
            this.sessionId = el.dataset.sessionId || '';
            this.fullscreen = el.dataset.initialOpen === '1';
            const root = document.documentElement;
            root.toggleAttribute('data-session-fullscreen', this.fullscreen);
            this.$watch('fullscreen', (on) => root.toggleAttribute('data-session-fullscreen', on));
            this.noteUrl = el.dataset.initialNoteUrl || '';
            this.noteTitle = el.dataset.initialNoteTitle || '';
            this.newNoteUrl = el.dataset.newNoteUrl || '';
            try {
                this.noteItems = JSON.parse(el.dataset.initialNotes || '[]');
            } catch {
                this.noteItems = [];
            }
            this.fileUrl = el.dataset.initialFileUrl || '';
            this.fileName = el.dataset.initialFileName || '';
            try {
                this.fileItems = JSON.parse(el.dataset.initialFiles || '[]');
            } catch {
                this.fileItems = [];
            }
            try {
                this.fileUploadConfig = JSON.parse(el.dataset.fileUpload || '{}');
            } catch {
                this.fileUploadConfig = {};
            }
            try {
                const savedRail = localStorage.getItem('vistud-session-rail-open');
                this.railOpen = savedRail !== null ? savedRail === 'true' : window.innerWidth >= 1024;
                const saved = localStorage.getItem('vistud-session-split-percent');
                if (saved) {
                    const val = parseFloat(saved);
                    if (val >= 20 && val <= 80) this.splitPercent = val;
                }
                if (this.sessionId) {
                    const savedState = JSON.parse(sessionStorage.getItem('vistud-session-panes-' + this.sessionId) || 'null');
                    if (savedState) {
                        if (Array.isArray(savedState.openPanes) && savedState.openPanes.length >= 1 && savedState.openPanes.length <= 2) {
                            this.openPanes = savedState.openPanes;
                        }
                        if (savedState.fileUrl) this.fileUrl = savedState.fileUrl;
                        if (savedState.fileName) this.fileName = savedState.fileName;
                        if (savedState.noteUrl) this.noteUrl = savedState.noteUrl;
                        if (savedState.noteTitle) this.noteTitle = savedState.noteTitle;
                    }
                }
            } catch {}
            window.addEventListener('message', (e) => {
                if (e.data?.type === 'note-active' && e.data?.noteId) {
                    if (this.activeSyncedNoteId !== e.data.noteId) {
                        this.activeSyncedNoteId = e.data.noteId;
                        this.$wire.setActiveNote(e.data.noteId);
                    }
                }
            });
            if (window.innerWidth < 768 && this.openPanes.length > 1) {
                this.openPanes = [this.openPanes[this.openPanes.length - 1] || 'chat'];
            }
            window.addEventListener('resize', () => {
                if (window.innerWidth < 768 && this.openPanes.length > 1) {
                    this.openPanes = [this.openPanes[this.openPanes.length - 1] || 'chat'];
                    this.savePaneState();
                }
            });
            if (this.openPanes.includes('note')) {
                this.mountNoteFrame();
                if (this.noteUrl) this.syncActiveNote(this.noteUrl);
            }
            if (this.openPanes.includes('file')) this.mountFileFrame();
            this.$watch('fullscreen', () => {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('session-layout-changed')));
            });
        },

        notifyLayoutChanged() {
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('session-layout-changed')));
        },

        savePaneState() {
            if (!this.sessionId) return;
            try {
                sessionStorage.setItem('vistud-session-panes-' + this.sessionId, JSON.stringify({
                    openPanes: this.openPanes,
                    fileUrl: this.fileUrl,
                    fileName: this.fileName,
                    noteUrl: this.noteUrl,
                    noteTitle: this.noteTitle,
                }));
            } catch {}
            this.notifyLayoutChanged();
        },

        get noteOpen() {
            return this.openPanes.includes('note');
        },
        get fileOpen() {
            return this.openPanes.includes('file');
        },
        get chatOpen() {
            return this.openPanes.includes('chat');
        },
        get questionsOpen() {
            return this.openPanes.includes('questions');
        },
        get quizOpen() {
            return this.openPanes.includes('quiz');
        },
        get cardsOpen() {
            return this.openPanes.includes('cards');
        },
        askAi(prompt) {
            if (!this.isPaneOpen('chat')) {
                this.activatePane('chat');
            }
            window.Livewire?.dispatch('chat-send-prompt', { text: prompt });
        },
        isPaneOpen(pane) {
            return this.openPanes.includes(pane);
        },
        paneClasses(pane) {
            const idx = this.openPanes.indexOf(pane);
            return {
                'is-pane-hidden': idx === -1,
                'is-pane-single': this.openPanes.length === 1 && idx === 0,
                'is-pane-first': this.openPanes.length === 2 && idx === 0,
                'is-pane-second': this.openPanes.length === 2 && idx === 1,
            };
        },
        activatePane(pane) {
            if (!this.openPanes.includes(pane)) {
                if (window.innerWidth < 768) {
                    this.openPanes = [pane];
                } else if (this.openPanes.length < 2) {
                    this.openPanes = [...this.openPanes, pane];
                } else {
                    this.openPanes = [this.openPanes[0], pane];
                }
            }
            this.savePaneState();
            if (pane === 'note') {
                this.mountNoteFrame();
                if (this.noteUrl) this.syncActiveNote(this.noteUrl);
            } else if (pane === 'file') {
                this.mountFileFrame();
            }
        },
        minimizePane(pane) {
            if (!this.openPanes.includes(pane)) return;
            if (this.openPanes.length > 1) {
                this.openPanes = this.openPanes.filter((p) => p !== pane);
            } else {
                const fallback = pane === 'chat' ? 'note' : 'chat';
                this.openPanes = [fallback];
                if (fallback === 'note') this.mountNoteFrame();
            }
            this.savePaneState();
        },
        togglePane(pane) {
            if (window.innerWidth < 768) {
                if (this.openPanes.includes(pane)) {
                    if (pane !== 'chat') {
                        this.activatePane('chat');
                    }
                } else {
                    this.activatePane(pane);
                }
                return;
            }
            if (this.openPanes.includes(pane)) {
                this.minimizePane(pane);
            } else {
                this.activatePane(pane);
            }
        },
        swapPanes() {
            if (this.openPanes.length === 2) {
                this.openPanes = [this.openPanes[1], this.openPanes[0]];
                this.savePaneState();
            }
        },
        startDrag(event) {
            this.isDragging = true;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            const container = this.$refs.sessionWorkbench;
            const onMove = (e) => {
                const clientX = e.clientX ?? (e.touches && e.touches[0] ? e.touches[0].clientX : undefined);
                if (clientX === undefined || !container) return;
                const rect = container.getBoundingClientRect();
                if (!rect.width) return;
                const rawPercent = ((clientX - rect.left) / rect.width) * 100;
                const clamped = Math.min(Math.max(rawPercent, 20), 80);
                this.splitPercent = Math.round(clamped * 10) / 10;
                window.dispatchEvent(new CustomEvent('session-layout-changed'));
            };
            const onUp = () => {
                this.isDragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
                window.removeEventListener('touchmove', onMove);
                window.removeEventListener('touchend', onUp);
                this.saveSplit();
            };
            window.addEventListener('mousemove', onMove);
            window.addEventListener('mouseup', onUp, { once: true });
            window.addEventListener('touchmove', onMove, { passive: true });
            window.addEventListener('touchend', onUp, { once: true });
        },
        saveSplit() {
            try {
                localStorage.setItem('vistud-session-split-percent', String(this.splitPercent));
            } catch {}
            this.notifyLayoutChanged();
        },
        resetSplit() {
            this.splitPercent = 50;
            this.saveSplit();
        },
        toggleTopics() {
            this.railOpen = ! this.railOpen;
            try {
                localStorage.setItem('vistud-session-rail-open', String(this.railOpen));
            } catch {}
            this.notifyLayoutChanged();
        },
        syncActiveNote(url) {
            if (!url) return;
            const match = url.match(/\/notes\/([a-zA-Z0-9_-]+)/);
            if (match && match[1] && match[1] !== 'new' && match[1] !== 'ask-ai') {
                if (this.activeSyncedNoteId !== match[1]) {
                    this.activeSyncedNoteId = match[1];
                    this.$wire.setActiveNote(match[1]);
                }
            }
        },
        openNote(url = null, title = null) {
            let urlChanged = false;
            if (url) {
                const sep = url.includes('?') ? '&' : '?';
                let full = url;
                if (!full.includes('window=1')) full += sep + 'window=1';
                if (!full.includes('split=1')) full += (full.includes('?') ? '&' : '?') + 'split=1';
                if (this.noteUrl !== full) {
                    this.noteUrl = full;
                    urlChanged = true;
                }
                this.syncActiveNote(full);
            }
            if (title) {
                this.noteTitle = title;
            } else if (url) {
                const found = this.noteItems.find((n) => n.url === url);
                if (found) this.noteTitle = found.title;
            }
            this.notePickerOpen = false;
            this.filePickerOpen = false;
            this.activatePane('note');
            this.savePaneState();
            const slot = this.$refs.sessionNoteSlot;
            if (slot && urlChanged) {
                slot.replaceChildren();
            }
            this.mountNoteFrame();
        },
        toggleNotePane() {
            this.togglePane('note');
        },
        switchNote(url, title = null) {
            if (!url) return;
            const changed = this.noteUrl !== url;
            this.noteUrl = url;
            if (title) {
                this.noteTitle = title;
            } else {
                const found = this.noteItems.find((n) => n.url === url);
                if (found) this.noteTitle = found.title;
            }
            this.notePickerOpen = false;
            this.syncActiveNote(url);
            this.savePaneState();
            const slot = this.$refs.sessionNoteSlot;
            if (slot && changed) {
                slot.replaceChildren();
            }
            this.mountNoteFrame();
        },
        mountNoteFrame() {
            this.$nextTick(() => {
                if (!this.noteUrl) return;
                const slot = this.$refs.sessionNoteSlot;
                if (!slot) return;
                let frame = slot.querySelector('iframe');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.className = 'session-note-frame';
                    frame.title = 'Study note editor';
                    slot.appendChild(frame);
                }
                const toAbsolute = (u) => {
                    try { return new URL(u, window.location.href).href; } catch { return u; }
                };
                if (toAbsolute(frame.src) !== toAbsolute(this.noteUrl)) {
                    frame.src = this.noteUrl;
                }
            });
        },
        openFile(url = null, name = null) {
            let urlChanged = false;
            if (url) {
                const sep = url.includes('?') ? '&' : '?';
                let full = url;
                if (!full.includes('window=1')) full += sep + 'window=1';
                if (!full.includes('split=1')) full += (full.includes('?') ? '&' : '?') + 'split=1';
                if (this.fileUrl !== full) {
                    this.fileUrl = full;
                    urlChanged = true;
                }
            }
            if (name) {
                this.fileName = name;
            } else if (url) {
                const found = this.fileItems.find((item) => item.url === url);
                if (found) this.fileName = found.name;
            }
            this.notePickerOpen = false;
            this.filePickerOpen = false;
            this.activatePane('file');
            this.savePaneState();
            const slot = this.$refs.sessionFileSlot;
            if (slot && urlChanged) {
                slot.replaceChildren();
            }
            this.mountFileFrame();
        },
        toggleFilePane() {
            this.togglePane('file');
        },
        switchFile(url, name = '') {
            if (!url) return;
            const changed = this.fileUrl !== url;
            this.fileUrl = url;
            if (name) {
                this.fileName = name;
            } else {
                const found = this.fileItems.find((item) => item.url === url);
                if (found) this.fileName = found.name;
            }
            this.filePickerOpen = false;
            this.savePaneState();
            const slot = this.$refs.sessionFileSlot;
            if (slot && changed) {
                slot.replaceChildren();
            }
            this.mountFileFrame();
        },
        mountFileFrame() {
            this.$nextTick(() => {
                if (!this.fileUrl) return;
                const slot = this.$refs.sessionFileSlot;
                if (!slot) return;
                let frame = slot.querySelector('iframe');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.className = 'session-note-frame';
                    frame.title = 'Study file viewer';
                    slot.appendChild(frame);
                }
                const toAbsolute = (u) => {
                    try { return new URL(u, window.location.href).href; } catch { return u; }
                };
                if (toAbsolute(frame.src) !== toAbsolute(this.fileUrl)) {
                    frame.src = this.fileUrl;
                }
            });
        },
        async uploadSessionFile(event) {
            const files = event.target?.files ?? event.dataTransfer?.files;
            const file = files?.[0];
            if (event.target) event.target.value = '';
            if (!file || this.fileUploading) return;
            this.fileUploadError = '';
            if (file.size > this.fileUploadConfig.maxBytes) {
                this.fileUploadError = 'That file is too large to upload.';
                return;
            }
            this.fileUploading = true;
            try {
                const form = new FormData();
                form.append('file', file, file.name);
                form.append('place_type', this.fileUploadConfig.placeType);
                form.append('place_id', this.fileUploadConfig.placeId);
                const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
                const res = await fetch(this.fileUploadConfig.url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf,
                    },
                    body: form,
                    credentials: 'same-origin',
                });
                const payload = await res.json().catch(() => ({}));
                if (res.status === 201 && payload.url) {
                    const sep = payload.url.includes('?') ? '&' : '?';
                    const splitUrl = `${payload.url}${sep}window=1&split=1`;
                    const sizeStr = file.size > 1048576
                        ? (file.size / 1048576).toFixed(1) + ' MB'
                        : Math.round(file.size / 1024) + ' KB';
                    const entry = {
                        id: payload.id,
                        name: payload.name || file.name,
                        type: payload.kind || 'File',
                        size: sizeStr,
                        icon: 'file-text',
                        iconUrl: this.fileUploadConfig.defaultIconUrl || '',
                        inFolder: true,
                        used: true,
                        url: splitUrl,
                        pageUrl: payload.url,
                    };
                    this.fileItems = [entry, ...this.fileItems.filter((i) => i.id !== entry.id)];
                    this.fileUrl = splitUrl;
                    this.fileName = entry.name;
                    this.filePickerOpen = false;
                    this.activatePane('file');
                    this.$wire.fileUploaded(payload.id);
                } else {
                    const fields = payload?.error?.details?.fields ?? {};
                    this.fileUploadError = fields.file?.[0] ?? fields.name?.[0] ?? payload?.error?.message ?? 'Could not upload file.';
                }
            } catch {
                this.fileUploadError = 'Could not upload file. Check your connection.';
            } finally {
                this.fileUploading = false;
            }
        }
    }"
    x-bind:class="{ 'is-fullscreen': fullscreen }"
    x-on:mouseup.window="if (isDragging) { isDragging = false; document.body.style.cursor = ''; document.body.style.userSelect = ''; saveSplit(); }"
    x-on:touchend.window="if (isDragging) { isDragging = false; document.body.style.cursor = ''; document.body.style.userSelect = ''; saveSplit(); }"
    x-on:session-ensure-chat.window="if (!isPaneOpen('chat')) activatePane('chat')"
    x-on:session-toggle-note.window="toggleNotePane()"
    x-on:session-open-note.window="openNote($event.detail?.url, $event.detail?.title)"
    x-on:session-toggle-file.window="toggleFilePane()"
    x-on:session-open-file.window="openFile($event.detail?.url, $event.detail?.name)"
    x-on:session-file-deleted.window="
        const fid = $event.detail?.fileId;
        if (fid) {
            fileItems = fileItems.filter(f => f.id !== fid && !f.url?.includes('/files/' + fid));
            if (fileUrl && fileUrl.includes('/files/' + fid)) {
                fileUrl = '';
                fileName = '';
                const slot = $refs.sessionFileSlot;
                if (slot) slot.replaceChildren();
                minimizePane('file');
            }
            savePaneState();
        }
    "
    x-on:session-note-deleted.window="
        const nid = $event.detail?.noteId;
        if (nid) {
            noteItems = noteItems.filter(n => n.id !== nid && !n.url?.includes('/notes/' + nid));
            if (noteUrl && noteUrl.includes('/notes/' + nid)) {
                noteUrl = '';
                noteTitle = '';
                const slot = $refs.sessionNoteSlot;
                if (slot) slot.replaceChildren();
                minimizePane('note');
            }
            savePaneState();
        }
    "
    x-on:session-toggle-questions.window="togglePane('questions')"
    x-on:session-toggle-quiz.window="togglePane('quiz')"
    x-on:session-toggle-cards.window="togglePane('cards')">
    <x-page class="sr-only" :title="$heading" :back-href="$backHref" :back-to="$placeName ?? 'Course'" :eyebrow="$folder ? $module->title.' › '.$folder->name : $module?->title" :context="$context">
        <x-slot:menu>
            @if ($open)
                <button type="button" class="menu-item" wire:click="editTeaching"><x-icon name="message-square-text" class="size-4" />How the AI teaches</button>
                <button type="button" class="menu-item" wire:click="editPomodoro"><x-icon name="timer" class="size-4" />{{ $session->usesPomodoro() ? 'Pomodoro settings' : 'Use the Pomodoro clock' }}</button>
                @if ($module)
                    <button type="button" class="menu-item" wire:click="tellTutor"><x-icon name="message-square-text" class="size-4" />Tell the tutor about this module</button>
                @endif
                @if (! $session->usesPomodoro() && $session->state !== 'break')
                    <button type="button" class="menu-item" wire:click="takeBreak"><x-icon name="coffee" class="size-4" />Take a break</button>
                @endif
                @if ($copyPaste)
                    <button type="button" class="menu-item" wire:click="showBriefing"><x-icon name="clipboard-copy" class="size-4" />Prompt for another AI</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('capture-open')"><x-icon name="clipboard-paste" class="size-4" />Save from another AI</button>
                @endif
            @endif
            <button type="button" class="menu-item" wire:click="confirmDelete"><x-icon name="trash-2" class="size-4" />Delete session</button>
        </x-slot:menu>
        <x-slot:action>
            @if ($open && ! $session->usesPomodoro())
                <div class="session-clock-inline">
                    <p @class(['status-chip', 'status-understood' => $session->state === 'running', 'status-covered' => $session->state === 'break'])>
                        <x-icon :name="$stateIcons[$session->state]" class="size-3.5" />{{ $session->stateWords() }}
                    </p>
                    <p class="session-time-inline tabular-nums">
                        <span class="sr-only">Study time: {{ SessionDetails::duration($session->studySeconds) }}</span>
                        <span aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                    </p>
                    @if ($session->state === 'running')
                        <x-button icon="pause" wire:click="pause">Pause</x-button>
                    @elseif ($session->state === 'paused')
                        <x-button variant="primary" icon="play" wire:click="resume">Resume</x-button>
                    @else
                        <x-button variant="primary" icon="play" wire:click="resume">Back to studying</x-button>
                    @endif
                    <x-button icon="square" wire:click="confirmEnd">End</x-button>
                </div>
            @endif
        </x-slot:action>
    </x-page>

    <livewire:workspaces.session-capture :workspace-id="$workspaceId" :session-id="$session->id" :key="'capture-'.$session->id" />
    <livewire:workspaces.flashcard-editor :workspace-id="$workspaceId" :key="'flashcard-editor-'.$session->id" />
    <x-toast :message="$notice" />

    @if ($error)
        <x-alert tone="danger">{{ $error }}</x-alert>
    @endif

    @if ($awaySince)
        <x-alert tone="warning" title="Paused while you were away">
            <p>Nothing happened here after {{ $awaySince }}, so the clock stopped then. If you were studying away from the screen, count that time.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-button icon="check" wire:click="countAway">I was studying: count it</x-button>
                <x-button variant="ghost" icon="play" wire:click="resume">Resume from now</x-button>
            </div>
        </x-alert>
    @elseif ($session->pausedBy === 'long_break')
        <x-alert tone="info">Your break ran past an hour, so the session is paused. Resume when you're back.</x-alert>
    @endif

    @if ($open && $session->usesPomodoro())
        @include('livewire.workspaces.partials.pomodoro-clock')
    @endif

    <div class="session-focus-bar">
        <div class="session-focus-left">
            <a href="{{ $backHref }}" class="session-icon-btn shrink-0" title="Back to {{ $placeName ?? 'Course' }}" aria-label="Back to {{ $placeName ?? 'Course' }}">
                <x-icon name="arrow-left" class="size-3.5" />
            </a>
            @if ($open)
                <button type="button" class="session-focus-title min-w-0 truncate" x-on:click="toggleTopics()" title="Topics and material">
                    <span class="truncate">{{ $topic?->name ?? $heading }}</span>
                    @if (! $topic)
                        <span class="sr-only">Choose a topic</span>
                    @endif
                </button>

                @php
                    $tutorLevel = match ($tutoring['quiz'] ?? 'normal') {
                        'easy' => 'Beginner',
                        'exam' => 'Master',
                        default => 'Intermediate',
                    };
                    $activePlugins = $tutoring['plugins'] ?? [];
                @endphp
                <div class="relative shrink-0" x-data="{ tutorMenuOpen: false }" x-on:click.outside="tutorMenuOpen = false" x-on:keydown.escape.stop="tutorMenuOpen = false">
                    <button type="button"
                        class="session-focus-level"
                        x-bind:class="{ 'is-active': tutorMenuOpen }"
                        x-on:click="tutorMenuOpen = ! tutorMenuOpen"
                        x-bind:aria-expanded="tutorMenuOpen.toString()"
                        title="Tutor understanding level: {{ $tutorLevel }} · Click to adjust">
                        <x-icon name="sparkles" class="size-3 text-accent shrink-0" />
                        <span class="truncate">{{ $tutorLevel }}</span>
                        @if ($activePlugins !== [])
                            <span class="size-1.5 rounded-full bg-accent shrink-0" title="{{ count($activePlugins) }} study plugin(s) active"></span>
                        @endif
                        <x-icon name="chevron-down" class="size-3 text-fg-subtle shrink-0 transition-transform" x-bind:class="tutorMenuOpen && 'rotate-180'" />
                    </button>

                    <div class="session-tutor-popover" x-show="tutorMenuOpen" x-cloak x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                        <div class="session-tutor-popover-head">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <x-icon name="sparkles" class="size-3.5 text-accent shrink-0" />
                                <span class="text-xs font-semibold text-fg">AI Tutor Tuning</span>
                            </div>
                            <button type="button" class="session-tutor-popover-more" wire:click="editTeaching" x-on:click="tutorMenuOpen = false" title="Open full teaching settings">
                                <span>Settings</span>
                                <x-icon name="settings" class="size-3" />
                            </button>
                        </div>

                        {{-- Understanding Level (prominent) --}}
                        <div class="session-tutor-popover-section">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-fg uppercase tracking-wider">My understanding</span>
                                <span class="text-accent font-semibold">{{ $tutorLevel }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-1">
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['quiz'] ?? 'normal') === 'easy' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('quiz', 'easy')"
                                    title="Beginner: explain simply from first principles in plain terms with intuitive analogies">
                                    <span class="font-semibold">Beginner</span>
                                    <span class="text-xs text-fg-subtle truncate">First principles</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['quiz'] ?? 'normal') === 'normal' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('quiz', 'normal')"
                                    title="Intermediate: balanced depth and terminology with applied examples">
                                    <span class="font-semibold">Intermediate</span>
                                    <span class="text-xs text-fg-subtle truncate">Balanced depth</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['quiz'] ?? 'normal') === 'exam' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('quiz', 'exam')"
                                    title="Master: concise, high technical rigor, edge cases and deep problem solving">
                                    <span class="font-semibold">Master</span>
                                    <span class="text-xs text-fg-subtle truncate">Deep &amp; concise</span>
                                </button>
                            </div>
                        </div>

                        {{-- Teaching Style --}}
                        <div class="session-tutor-popover-section">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-fg uppercase tracking-wider">Teaching style</span>
                                <span class="text-accent font-semibold">{{ $tutoringChoices['method'][$tutoring['method'] ?? 'explain'][0] ?? 'Explain' }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-1">
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['method'] ?? 'explain') === 'explain' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('method', 'explain')"
                                    title="Explain first, then check understanding">
                                    <span>Explain</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['method'] ?? 'explain') === 'analogy' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('method', 'analogy')"
                                    title="Lead with intuitive real-world analogies">
                                    <span>Analogies</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['method'] ?? 'explain') === 'socratic' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('method', 'socratic')"
                                    title="Socratic: guides with questions so you derive insights">
                                    <span>Socratic</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['method'] ?? 'explain') === 'steps' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('method', 'steps')"
                                    title="Step by step: breaks down into small numbered steps">
                                    <span>Step by step</span>
                                </button>
                            </div>
                        </div>

                        {{-- Check Questions --}}
                        <div class="session-tutor-popover-section">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-fg uppercase tracking-wider">Check questions</span>
                                <span class="text-accent font-semibold">{{ ($tutoring['check_ins'] ?? 'section') === 'none' ? 'Off' : (($tutoring['check_ins'] ?? 'section') === 'section' ? 'Per section' : 'At end') }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-1">
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['check_ins'] ?? 'section') === 'none' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('check_ins', 'none')"
                                    title="Off: no check questions unless requested">
                                    <span>Off</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['check_ins'] ?? 'section') === 'section' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('check_ins', 'section')"
                                    title="Per section: one check question at the end of each section">
                                    <span>Per section</span>
                                </button>
                                <button type="button"
                                    class="session-tutor-choice-btn {{ ($tutoring['check_ins'] ?? 'section') === 'end' ? 'is-active' : '' }}"
                                    wire:click="setTutoringChoice('check_ins', 'end')"
                                    title="At end: short review quiz at the end of the session">
                                    <span>At end</span>
                                </button>
                            </div>
                        </div>

                        {{-- Study Plugins (Domain tools & formatting) --}}
                        <div class="session-tutor-popover-section">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-fg uppercase tracking-wider">Study plugins</span>
                                <span class="text-xs text-fg-subtle">
                                    {{ count($activePlugins) > 0 ? count($activePlugins).' active' : 'Subject tools' }}
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach (\App\Study\Plugins::all() as $p)
                                    @php $isActive = in_array($p->id, $activePlugins, true); @endphp
                                    <button type="button"
                                        class="session-tutor-plugin-card {{ $isActive ? 'is-active' : '' }}"
                                        wire:click="togglePlugin('{{ $p->id }}')"
                                        title="{{ $p->name }} ({{ $p->badge }}): {{ $p->description }}">
                                        <div class="flex items-center justify-between gap-1 w-full min-w-0">
                                            <div class="flex items-center gap-1.5 min-w-0">
                                                <x-icon name="{{ $p->icon }}" class="size-3.5 shrink-0 {{ $isActive ? 'text-accent' : 'text-fg-subtle' }}" />
                                                <span class="font-medium text-xs truncate">{{ $p->name }}</span>
                                            </div>
                                            <span class="size-1.5 rounded-full shrink-0 {{ $isActive ? 'bg-success' : 'bg-fg-subtle opacity-40' }}"></span>
                                        </div>
                                        <span class="text-xs text-fg-subtle truncate text-left w-full mt-0.5">{{ $p->badge }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Custom instructions (Do & Don't) preview or shortcut --}}
                        @if (! empty($tutoring['custom_do']) || ! empty($tutoring['custom_dont']))
                            <div class="session-tutor-popover-section session-tutor-popover-custom">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="font-semibold text-fg uppercase tracking-wider">Custom rules</span>
                                    <button type="button" class="text-accent hover:underline text-xs font-medium" wire:click="editCustomRules" x-on:click="tutorMenuOpen = false">Edit (1, 2, 3…)</button>
                                </div>
                                <div class="space-y-1">
                                    @if (! empty($tutoring['custom_do']))
                                        <div class="text-xs text-fg-muted truncate flex items-center gap-1.5" title="Do: {{ $tutoring['custom_do'] }}">
                                            <span class="inline-block size-1.5 rounded-full bg-success shrink-0"></span>
                                            <span class="truncate"><strong class="text-fg font-medium">Do:</strong> {{ $tutoring['custom_do'] }}</span>
                                        </div>
                                    @endif
                                    @if (! empty($tutoring['custom_dont']))
                                        <div class="text-xs text-fg-muted truncate flex items-center gap-1.5" title="Don't: {{ $tutoring['custom_dont'] }}">
                                            <span class="inline-block size-1.5 rounded-full bg-danger shrink-0"></span>
                                            <span class="truncate"><strong class="text-fg font-medium">Don't:</strong> {{ $tutoring['custom_dont'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="session-tutor-popover-section pt-1 pb-1">
                                <button type="button" class="session-tutor-popover-add-rules" wire:click="editCustomRules" x-on:click="tutorMenuOpen = false">
                                    <x-icon name="notebook-pen" class="size-3 text-accent shrink-0" />
                                    <span>Custom rules (1, 2, 3…)</span>
                                </button>
                            </div>
                        @endif

                        <div class="session-tutor-popover-foot">
                            <span class="inline-block size-1.5 rounded-full bg-success"></span>
                            <span>Live prompt tuning · survives model switches</span>
                        </div>
                    </div>
                </div>
            @else
                <span class="session-focus-title is-static min-w-0 truncate">{{ $heading }}</span>
                @if (isset($tutoring['quiz']))
                    <span class="session-focus-level opacity-75 cursor-default truncate shrink-0">
                        <x-icon name="sparkles" class="size-3 text-accent shrink-0" />
                        <span>{{ match ($tutoring['quiz'] ?? 'normal') { 'easy' => 'Beginner', 'exam' => 'Master', default => 'Intermediate' } }}</span>
                    </span>
                @endif
            @endif
        </div>
        <div class="session-pane-switcher" role="group" aria-label="Split screen views" wire:ignore>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('chat') }" x-on:click="togglePane('chat')" x-bind:aria-pressed="isPaneOpen('chat').toString()" title="Toggle AI Chat pane">
                <x-icon name="message-square-text" class="size-3.5" />
                <span>AI Chat</span>
            </button>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('note') }" x-on:click="togglePane('note')" x-bind:aria-pressed="isPaneOpen('note').toString()" title="Toggle Note pane" aria-label="Toggle Note pane">
                <x-icon name="notebook-pen" class="size-3.5" />
                <span>Note</span>
            </button>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('file') }" x-on:click="togglePane('file')" x-bind:aria-pressed="isPaneOpen('file').toString()" title="Toggle File pane" aria-label="Toggle File pane">
                <x-icon name="file-text" class="size-3.5" />
                <span>File</span>
            </button>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('questions') }" x-on:click="togglePane('questions')" x-bind:aria-pressed="isPaneOpen('questions').toString()" title="Toggle Questions pane" aria-label="Toggle Questions pane">
                <x-icon name="circle-help" class="size-3.5" />
                <span class="hidden xl:inline">Questions</span>
                <span class="xl:hidden">Q&amp;A</span>
                @if (($sideQuestions['pending'] ?? 0) > 0)
                    <span class="session-pane-badge">{{ $sideQuestions['pending'] }}</span>
                @endif
            </button>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('quiz') }" x-on:click="togglePane('quiz')" x-bind:aria-pressed="isPaneOpen('quiz').toString()" title="Toggle Quiz pane" aria-label="Toggle Quiz pane">
                <x-icon name="clipboard-check" class="size-3.5" />
                <span>Quiz</span>
                @if (($sideQuizzes['count'] ?? 0) > 0)
                    <span class="session-pane-badge">{{ $sideQuizzes['count'] }}</span>
                @endif
            </button>
            <button type="button" class="session-pane-btn" x-bind:class="{ 'is-active': isPaneOpen('cards') }" x-on:click="togglePane('cards')" x-bind:aria-pressed="isPaneOpen('cards').toString()" title="Toggle Flashcards pane" aria-label="Toggle Flashcards pane">
                <x-icon name="layers" class="size-3.5" />
                <span>Cards</span>
                @if (($sideCards['due'] ?? 0) > 0)
                    <span class="session-pane-badge">{{ $sideCards['due'] }}</span>
                @endif
            </button>
            <button type="button" class="session-pane-swap" x-show="openPanes.length === 2" x-cloak x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                <x-icon name="arrow-left-right" class="size-3.5" />
            </button>
        </div>
        <div class="session-focus-right">
            @if ($open)
                <button type="button" class="chat-toolbar-btn" data-session-tools-btn x-data="{ open: false }" x-on:session-tools-state.window="open = $event.detail.open" x-bind:class="{ 'is-active': open }" x-bind:aria-expanded="open.toString()" x-on:click.stop="$dispatch('session-toggle-tools')" title="Study tools" aria-label="Study tools">
                    <x-icon name="plus" class="size-3.5" />
                    <span>Tools</span>
                </button>
            @endif
            @if ($open && ! $session->usesPomodoro())
                <div class="session-focus-timer" x-show="fullscreen" x-cloak>
                    <span class="session-focus-dot" @class(['is-running' => $session->state === 'running']) aria-hidden="true"></span>
                    <span class="tabular-nums text-xs font-medium text-fg-muted" aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                    @if ($session->state === 'running')
                        <button type="button" class="session-icon-btn size-6" wire:click="pause" title="Pause timer" aria-label="Pause timer"><x-icon name="pause" class="size-3" /></button>
                    @else
                        <button type="button" class="session-icon-btn size-6" wire:click="resume" title="Resume timer" aria-label="Resume timer"><x-icon name="play" class="size-3" /></button>
                    @endif
                    <button type="button" class="session-icon-btn size-6" wire:click="confirmEnd" title="End session" aria-label="End session"><x-icon name="square" class="size-3" /></button>
                </div>
            @endif
            <button type="button" class="session-icon-btn" wire:ignore x-bind:class="{ 'is-active': railOpen }" x-on:click="toggleTopics()" x-bind:aria-pressed="railOpen.toString()" title="Toggle sidebar (topics, tutor controls &amp; material)" aria-label="Toggle sidebar">
                <x-icon name="list-checks" class="size-3.5" />
            </button>
            <button type="button" class="session-icon-btn" wire:ignore x-bind:class="{ 'is-active': fullscreen }" x-on:click="fullscreen = ! fullscreen" x-bind:aria-pressed="fullscreen.toString()" x-bind:title="fullscreen ? 'Exit full screen' : 'Full screen mode'" aria-label="Toggle full screen">
                <x-icon name="maximize-2" class="size-3.5" x-show="! fullscreen" />
                <x-icon name="minimize-2" class="size-3.5" x-show="fullscreen" x-cloak />
            </button>
            <div class="relative" x-data="{ menuOpen: false }" x-on:session-tools-state.window="if ($event.detail.open) menuOpen = false" x-on:click.outside="menuOpen = false" x-on:keydown.escape.stop="menuOpen = false">
                <button type="button" class="session-icon-btn" x-bind:class="{ 'is-active': menuOpen }" x-on:click="menuOpen = ! menuOpen" title="Session options" aria-label="Session options">
                    <x-icon name="ellipsis" class="size-3.5" />
                </button>
                <div class="row-menu right-0 top-full mt-1" x-show="menuOpen" x-cloak>
                    @if ($open)
                        <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="editTeaching"><x-icon name="message-square-text" class="size-4" /><span>How the AI teaches</span></button>
                        <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="openPlugins"><x-icon name="puzzle" class="size-4" /><span>Study plugins</span></button>
                        <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="editPomodoro"><x-icon name="timer" class="size-4" /><span>{{ $session->usesPomodoro() ? 'Pomodoro settings' : 'Use the Pomodoro clock' }}</span></button>
                        @if (! $session->usesPomodoro() && $session->state !== 'break')
                            <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="takeBreak"><x-icon name="coffee" class="size-4" /><span>Take a break</span></button>
                        @endif
                        <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="confirmResetTime"><x-icon name="rotate-ccw" class="size-4" /><span>Reset timer</span></button>
                        <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="confirmResetFull"><x-icon name="rotate-cw" class="size-4" /><span>Restart session</span></button>
                    @endif
                    <button type="button" class="menu-item" x-on:click="menuOpen = false" wire:click="confirmDelete"><x-icon name="trash-2" class="size-4" /><span>Delete session</span></button>
                </div>
            </div>
        </div>
    </div>

    <div class="session-layout"
        wire:ignore.self
        x-bind:class="{
            'has-rail': railOpen,
            'no-rail': ! railOpen,
            'has-split': openPanes.length === 2,
            'has-note': noteOpen,
            'has-file': fileOpen
        }">
        <div class="session-workbench"
            wire:ignore.self
            x-ref="sessionWorkbench"
            x-bind:class="{
                'has-split': openPanes.length === 2,
                'is-dragging': isDragging
            }"
            x-bind:style="'--split-percent: ' + splitPercent + '%;'">
            <div class="session-main"
                wire:ignore.self
                x-bind:class="paneClasses('chat')">
                <livewire:workspaces.tutor-chat :workspace-id="$workspaceId" :session-id="$session->id" :key="'chat-'.$session->id" />
                @if (! $open && ($session->summary || $session->checkpoint))
                    <section aria-labelledby="tutor-heading" class="overview-card space-y-3">
                        <h2 id="tutor-heading" class="font-semibold">From the tutor</h2>
                        @if ($session->summary)
                            <div class="space-y-1">
                                <h3 class="text-sm font-semibold text-fg-muted">Summary</h3>
                                <p class="break-words">{{ $session->summary }}</p>
                            </div>
                        @endif
                        @if ($session->checkpoint)
                            <div class="space-y-1">
                                <h3 class="text-sm font-semibold text-fg-muted">Where it stands</h3>
                                <p class="break-words">{{ $session->checkpoint }}</p>
                            </div>
                        @endif
                    </section>
                @endif
            </div>

            <div class="session-split-handle"
                wire:ignore
                x-show="openPanes.length === 2"
                x-cloak
                x-bind:class="{ 'is-active': isDragging }"
                role="separator"
                aria-orientation="vertical"
                x-bind:aria-valuenow="Math.round(splitPercent)"
                aria-valuemin="20"
                aria-valuemax="80"
                aria-label="Resize split screen panes"
                tabindex="0"
                title="Drag to resize · Double-click to reset (50/50)"
                x-on:mousedown.prevent="startDrag($event)"
                x-on:touchstart.passive="startDrag($event)"
                x-on:dblclick="resetSplit()"
                x-on:keydown.left.prevent="splitPercent = Math.max(20, Math.round(splitPercent - 5)); saveSplit()"
                x-on:keydown.right.prevent="splitPercent = Math.min(80, Math.round(splitPercent + 5)); saveSplit()">
                <div class="session-split-bar"></div>
            </div>

            <aside class="session-file-pane"
                wire:ignore
                x-cloak
                x-bind:class="paneClasses('file')"
                aria-label="Study file">
                <div class="session-note-head relative" x-on:click.outside="filePickerOpen = false" x-on:keydown.escape.stop="filePickerOpen = false">
                    <div class="session-note-title-wrap">
                        <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                            <x-icon name="file-text" class="size-3.5" />
                        </span>
                        <button type="button"
                            class="session-file-select-btn"
                            x-on:click="filePickerOpen = !filePickerOpen"
                            x-bind:aria-expanded="filePickerOpen.toString()"
                            title="Choose study file or upload a new one">
                            <span class="truncate" x-text="fileName || 'Class files'">Class files</span>
                            <x-icon name="chevron-down" class="size-3 text-fg-muted shrink-0 transition-transform" ::class="{ 'rotate-180': filePickerOpen }" />
                        </button>
                    </div>
                    <div class="session-note-actions">
                        <input type="file" x-ref="sessionFileInput" class="sr-only" accept="{{ $sideFile['upload']['accept'] }}" x-on:change="uploadSessionFile($event); filePickerOpen = false">
                        <button type="button"
                            class="chat-toolbar-btn session-file-upload-trigger"
                            :class="{ 'is-active': filePickerOpen }"
                            x-on:click="filePickerOpen = !filePickerOpen"
                            x-bind:aria-expanded="filePickerOpen.toString()"
                            title="Files and upload for {{ $placeName ?? 'this course' }}">
                            <x-icon name="file-up" class="size-3.5" />
                            <span x-text="fileUploading ? 'Uploading…' : 'Files & Upload'">Files &amp; Upload</span>
                            <x-icon name="chevron-down" class="size-3 text-fg-muted shrink-0 transition-transform" ::class="{ 'rotate-180': filePickerOpen }" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-show="openPanes.length === 2" x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                            <x-icon name="arrow-left-right" class="size-3.5" />
                        </button>
                        <a class="topbar-button size-7" x-show="fileUrl" x-bind:href="fileUrl.replace(/[&?]split=1/g, '').replace(/[&?]window=1/g, '')" target="_blank" rel="noopener" title="Open file in a new tab" aria-label="Open file in a new tab">
                            <x-icon name="external-link" class="size-3.5" />
                        </a>
                        <button type="button"
                            class="topbar-button size-7 text-fg-muted hover:text-danger hover:bg-danger-subtle transition-colors"
                            x-show="fileUrl"
                            x-on:click="
                                const match = fileUrl.match(/\/files\/([a-zA-Z0-9_-]+)/);
                                if (match && match[1] && confirm('Delete this file from the platform? This cannot be undone.')) {
                                    $wire.deleteMaterialFile(match[1]);
                                }
                            "
                            title="Delete this file from platform"
                            aria-label="Delete this file from platform">
                            <x-icon name="trash-2" class="size-3.5" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-on:click="minimizePane('file')" title="Minimize file pane" aria-label="Minimize file pane">
                            <x-icon name="minus" class="size-4" />
                        </button>
                    </div>

                    {{-- Dropdown popover for pre-uploaded files & upload --}}
                    <div class="session-file-popover"
                        x-show="filePickerOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-1 scale-[0.98]"
                        role="dialog"
                        aria-label="Files & upload">

                        <div class="session-file-popover-head">
                            <div class="session-material-tabs" role="tablist">
                                <button type="button"
                                    class="session-material-tab"
                                    :class="{ 'is-active': fileTab === 'files' }"
                                    x-on:click="fileTab = 'files'">
                                    <x-icon name="file-text" class="size-3.5" />
                                    <span>Files</span>
                                    <span class="session-material-count" x-text="fileItems.length"></span>
                                </button>
                                <button type="button"
                                    class="session-material-tab"
                                    :class="{ 'is-active': fileTab === 'notes' }"
                                    x-on:click="fileTab = 'notes'">
                                    <x-icon name="notebook-pen" class="size-3.5" />
                                    <span>Notes</span>
                                    <span class="session-material-count" x-text="noteItems.length"></span>
                                </button>
                            </div>
                            <button type="button" class="topbar-button size-6" x-on:click="filePickerOpen = false" title="Close" aria-label="Close">
                                <x-icon name="x" class="size-3.5" />
                            </button>
                        </div>

                        <!-- FILES VIEW -->
                        <div x-show="fileTab === 'files'">
                            <div class="session-file-popover-upload"
                                x-on:dragover.prevent="fileDragOver = true"
                                x-on:dragleave.prevent="fileDragOver = false"
                                x-on:drop.prevent="fileDragOver = false; uploadSessionFile($event); filePickerOpen = false"
                                :class="{ 'is-dragover': fileDragOver }">
                                <div class="flex items-center justify-between gap-2.5">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="size-7 rounded-md bg-accent-subtle text-accent flex items-center justify-center shrink-0">
                                            <x-icon name="file-up" class="size-3.5" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-fg truncate">Upload to this folder</p>
                                            <p class="text-[0.65rem] text-fg-muted truncate">Drop file here or click choose</p>
                                        </div>
                                    </div>
                                    <button type="button"
                                        class="btn btn-primary btn-sm shrink-0"
                                        x-on:click="$refs.sessionFileInput.click()"
                                        x-bind:disabled="fileUploading">
                                        <span x-text="fileUploading ? 'Uploading…' : 'Choose file'">Choose file</span>
                                    </button>
                                </div>
                                <template x-if="fileUploadError">
                                    <p class="text-xs text-danger mt-1.5" x-text="fileUploadError"></p>
                                </template>
                            </div>

                            <div class="session-file-popover-toolbar">
                                <div class="session-file-popover-search">
                                    <x-icon name="search" class="size-3.5 text-fg-muted shrink-0" />
                                    <input type="search"
                                        class="session-file-search-input"
                                        placeholder="Search files…"
                                        x-model="fileSearch"
                                        autocomplete="off">
                                    <button type="button" class="size-4 text-fg-muted hover:text-fg" x-show="fileSearch" x-on:click="fileSearch = ''">
                                        <x-icon name="x" class="size-3" />
                                    </button>
                                </div>

                                <template x-if="hasOtherFiles()">
                                    <div class="session-file-scope-pills" role="tablist">
                                        <button type="button"
                                            class="session-file-scope-pill"
                                            :class="{ 'is-active': fileScope === 'folder' }"
                                            x-on:click="fileScope = 'folder'">
                                            This folder
                                        </button>
                                        <button type="button"
                                            class="session-file-scope-pill"
                                            :class="{ 'is-active': fileScope === 'all' }"
                                            x-on:click="fileScope = 'all'">
                                            All course files
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="session-file-popover-list" role="menu">
                                <template x-for="f in filteredFiles()" :key="f.id || f.url">
                                    <button type="button"
                                        role="menuitem"
                                        class="session-file-popover-item"
                                        :class="{ 'is-active': f.url === fileUrl }"
                                        x-on:click="switchFile(f.url, f.name)">
                                        <span class="session-file-popover-icon">
                                            <svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false">
                                                <use :href="f.iconUrl || ('/icons.svg#' + (f.icon || 'file-text'))"></use>
                                            </svg>
                                        </span>
                                        <div class="session-file-popover-text min-w-0 flex-1">
                                            <p class="session-file-popover-name truncate" x-text="f.name"></p>
                                            <p class="session-file-popover-meta truncate">
                                                <span x-text="f.type || 'File'"></span>
                                                <template x-if="f.size">
                                                    <span x-text="' · ' + f.size"></span>
                                                </template>
                                                <template x-if="!f.inFolder">
                                                    <span class="text-fg-subtle"> · other folder</span>
                                                </template>
                                            </p>
                                        </div>
                                        <template x-if="f.url === fileUrl">
                                            <span class="session-file-active-badge">
                                                <x-icon name="check" class="size-3 text-accent" />
                                                <span>Open</span>
                                            </span>
                                        </template>
                                    </button>
                                </template>

                                <template x-if="filteredFiles().length === 0">
                                    <div class="session-file-empty-state">
                                        <x-icon name="folder-open" class="size-5 text-fg-muted" />
                                        <p class="text-xs text-fg-muted" x-text="fileSearch ? 'No files match \'' + fileSearch + '\'' : 'No files in this folder yet'"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- NOTES VIEW -->
                        <div x-show="fileTab === 'notes'">
                            <div class="session-file-popover-toolbar">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="session-file-popover-search flex-1">
                                        <x-icon name="search" class="size-3.5 text-fg-muted shrink-0" />
                                        <input type="search"
                                            class="session-file-search-input"
                                            placeholder="Search notes…"
                                            x-model="noteSearch"
                                            autocomplete="off">
                                        <button type="button" class="size-4 text-fg-muted hover:text-fg" x-show="noteSearch" x-on:click="noteSearch = ''">
                                            <x-icon name="x" class="size-3" />
                                        </button>
                                    </div>
                                    <a x-bind:href="newNoteUrl"
                                        class="btn btn-secondary btn-sm shrink-0"
                                        x-on:click="filePickerOpen = false"
                                        title="Create a new blank note">
                                        <x-icon name="plus" class="size-3.5" />
                                        <span>New note</span>
                                    </a>
                                </div>
                            </div>

                            <div class="session-file-popover-list" role="menu">
                                <template x-for="n in filteredNotes()" :key="n.id || n.url">
                                    <button type="button"
                                        role="menuitem"
                                        class="session-file-popover-item"
                                        :class="{ 'is-active': n.url === noteUrl }"
                                        x-on:click="openNote(n.url, n.title); filePickerOpen = false">
                                        <span class="session-file-popover-icon">
                                            <x-icon name="notebook-pen" class="size-4" />
                                        </span>
                                        <div class="session-file-popover-text min-w-0 flex-1">
                                            <p class="session-file-popover-name truncate" x-text="n.title"></p>
                                            <p class="session-file-popover-meta truncate">
                                                <span x-text="n.isDefault ? 'Session note' : 'Course note'"></span>
                                            </p>
                                        </div>
                                        <template x-if="n.url === noteUrl">
                                            <span class="session-file-active-badge">
                                                <x-icon name="check" class="size-3 text-accent" />
                                                <span>Open</span>
                                            </span>
                                        </template>
                                    </button>
                                </template>

                                <template x-if="filteredNotes().length === 0">
                                    <div class="session-file-empty-state">
                                        <x-icon name="notebook-pen" class="size-5 text-fg-muted" />
                                        <p class="text-xs text-fg-muted" x-text="noteSearch ? 'No notes match \'' + noteSearch + '\'' : 'No notes found'"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="session-file-popover-foot">
                            <span class="text-[0.6875rem] text-fg-muted" x-text="fileTab === 'files' ? (fileItems.length + ' file' + (fileItems.length === 1 ? '' : 's') + ' available') : (noteItems.length + ' note' + (noteItems.length === 1 ? '' : 's') + ' available')"></span>
                            <button type="button" class="text-[0.6875rem] text-link hover:underline" wire:click="openMaterial" x-on:click="filePickerOpen = false">
                                Browse all course material &rarr;
                            </button>
                        </div>
                    </div>
                </div>
                <div class="px-3 py-1.5 border-b border-border bg-surface text-xs text-danger" x-show="fileUploadError" x-cloak x-text="fileUploadError" role="alert"></div>
                <div class="session-note-body">
                    <div class="w-full h-full relative" x-show="fileUrl" x-ref="sessionFileSlot"></div>
                    <div class="session-file-empty" x-show="! fileUrl" x-on:dragover.prevent x-on:drop.prevent="uploadSessionFile($event)">
                        <span class="ws-chip size-10 rounded-lg" aria-hidden="true">
                            <x-icon name="folder-open" class="size-5" />
                        </span>
                        <p class="text-sm font-semibold text-fg">No file open yet</p>
                        <p class="text-xs text-fg-muted max-w-xs text-center">Upload a PDF, Word, PowerPoint, spreadsheet, text or image file to {{ $placeName ?? 'this course' }} to read it beside your AI chat or notes.</p>
                        <div class="flex flex-wrap items-center justify-center gap-2 mt-1">
                            <button type="button" class="btn btn-primary btn-sm" x-on:click="filePickerOpen = true">
                                <x-icon name="folder-open" class="size-4" />
                                <span>Choose or upload file</span>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="openMaterial">
                                <x-icon name="folder-open" class="size-4" />
                                <span>Browse course material</span>
                            </button>
                        </div>
                    </div>
                </div>
            </aside>

            <aside class="session-note-pane"
                wire:ignore
                x-cloak
                x-bind:class="paneClasses('note')"
                aria-label="Study note">
                <div class="session-note-head relative" x-on:click.outside="notePickerOpen = false" x-on:keydown.escape.stop="notePickerOpen = false">
                    <div class="session-note-title-wrap">
                        <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                            <x-icon name="notebook-pen" class="size-3.5" />
                        </span>
                        <button type="button"
                            class="session-file-select-btn"
                            x-on:click="notePickerOpen = !notePickerOpen; noteTab = 'notes'"
                            x-bind:aria-expanded="notePickerOpen.toString()"
                            title="Choose study note or switch to files">
                            <span class="truncate" x-text="noteTitle || 'Study notes'">Study notes</span>
                            <x-icon name="chevron-down" class="size-3 text-fg-muted shrink-0 transition-transform" ::class="{ 'rotate-180': notePickerOpen }" />
                        </button>
                    </div>
                    <div class="session-note-actions">
                        <button type="button"
                            class="chat-toolbar-btn session-file-upload-trigger"
                            :class="{ 'is-active': notePickerOpen && noteTab === 'files' }"
                            x-on:click="notePickerOpen = !notePickerOpen; noteTab = 'files'"
                            title="Class files & upload">
                            <x-icon name="file-text" class="size-3.5" />
                            <span>Files</span>
                            <x-icon name="chevron-down" class="size-3 text-fg-muted shrink-0 transition-transform" ::class="{ 'rotate-180': notePickerOpen && noteTab === 'files' }" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-show="openPanes.length === 2" x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                            <x-icon name="arrow-left-right" class="size-3.5" />
                        </button>
                        <a class="topbar-button size-7" x-bind:href="noteUrl.replace(/[&?]split=1/, '')" target="_blank" data-note-pane-pop-out title="Open in a window of its own" aria-label="Open in a window of its own">
                            <x-icon name="external-link" class="size-3.5" />
                        </a>
                        <button type="button"
                            class="topbar-button size-7 text-fg-muted hover:text-danger hover:bg-danger-subtle transition-colors"
                            x-show="noteUrl"
                            x-on:click="
                                const match = noteUrl.match(/\/notes\/([a-zA-Z0-9_-]+)/);
                                if (match && match[1] && confirm('Move this note to trash?')) {
                                    $wire.deleteMaterialNote(match[1]);
                                }
                            "
                            title="Move this note to trash"
                            aria-label="Move this note to trash">
                            <x-icon name="trash-2" class="size-3.5" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-on:click="minimizePane('note')" title="Minimize side note" aria-label="Minimize side note">
                            <x-icon name="minus" class="size-4" />
                        </button>
                    </div>

                    {{-- Popover for Notes & Files in the Note pane --}}
                    <div class="session-file-popover"
                        x-show="notePickerOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-1 scale-[0.98]"
                        role="dialog"
                        aria-label="Notes & files">

                        <div class="session-file-popover-head">
                            <div class="session-material-tabs" role="tablist">
                                <button type="button"
                                    class="session-material-tab"
                                    :class="{ 'is-active': noteTab === 'notes' }"
                                    x-on:click="noteTab = 'notes'">
                                    <x-icon name="notebook-pen" class="size-3.5" />
                                    <span>Notes</span>
                                    <span class="session-material-count" x-text="noteItems.length"></span>
                                </button>
                                <button type="button"
                                    class="session-material-tab"
                                    :class="{ 'is-active': noteTab === 'files' }"
                                    x-on:click="noteTab = 'files'">
                                    <x-icon name="file-text" class="size-3.5" />
                                    <span>Files</span>
                                    <span class="session-material-count" x-text="fileItems.length"></span>
                                </button>
                            </div>
                            <button type="button" class="topbar-button size-6" x-on:click="notePickerOpen = false" title="Close" aria-label="Close">
                                <x-icon name="x" class="size-3.5" />
                            </button>
                        </div>

                        <!-- NOTES VIEW -->
                        <div x-show="noteTab === 'notes'">
                            <div class="session-file-popover-toolbar">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="session-file-popover-search flex-1">
                                        <x-icon name="search" class="size-3.5 text-fg-muted shrink-0" />
                                        <input type="search"
                                            class="session-file-search-input"
                                            placeholder="Search notes…"
                                            x-model="noteSearch"
                                            autocomplete="off">
                                        <button type="button" class="size-4 text-fg-muted hover:text-fg" x-show="noteSearch" x-on:click="noteSearch = ''">
                                            <x-icon name="x" class="size-3" />
                                        </button>
                                    </div>
                                    <a x-bind:href="newNoteUrl"
                                        class="btn btn-secondary btn-sm shrink-0"
                                        x-on:click="notePickerOpen = false"
                                        title="Create a new blank note">
                                        <x-icon name="plus" class="size-3.5" />
                                        <span>New note</span>
                                    </a>
                                </div>
                            </div>

                            <div class="session-file-popover-list" role="menu">
                                <template x-for="n in filteredNotes()" :key="n.id || n.url">
                                    <button type="button"
                                        role="menuitem"
                                        class="session-file-popover-item"
                                        :class="{ 'is-active': n.url === noteUrl }"
                                        x-on:click="switchNote(n.url, n.title)">
                                        <span class="session-file-popover-icon">
                                            <x-icon name="notebook-pen" class="size-4" />
                                        </span>
                                        <div class="session-file-popover-text min-w-0 flex-1">
                                            <p class="session-file-popover-name truncate" x-text="n.title"></p>
                                            <p class="session-file-popover-meta truncate">
                                                <span x-text="n.isDefault ? 'Session note' : 'Course note'"></span>
                                            </p>
                                        </div>
                                        <template x-if="n.url === noteUrl">
                                            <span class="session-file-active-badge">
                                                <x-icon name="check" class="size-3 text-accent" />
                                                <span>Open</span>
                                            </span>
                                        </template>
                                    </button>
                                </template>

                                <template x-if="filteredNotes().length === 0">
                                    <div class="session-file-empty-state">
                                        <x-icon name="notebook-pen" class="size-5 text-fg-muted" />
                                        <p class="text-xs text-fg-muted" x-text="noteSearch ? 'No notes match \'' + noteSearch + '\'' : 'No notes found'"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- FILES VIEW -->
                        <div x-show="noteTab === 'files'">
                            <div class="session-file-popover-upload"
                                x-on:dragover.prevent="fileDragOver = true"
                                x-on:dragleave.prevent="fileDragOver = false"
                                x-on:drop.prevent="fileDragOver = false; uploadSessionFile($event); notePickerOpen = false"
                                :class="{ 'is-dragover': fileDragOver }">
                                <div class="flex items-center justify-between gap-2.5">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="size-7 rounded-md bg-accent-subtle text-accent flex items-center justify-center shrink-0">
                                            <x-icon name="file-up" class="size-3.5" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-fg truncate">Upload to this folder</p>
                                            <p class="text-[0.65rem] text-fg-muted truncate">Drop file here or click choose</p>
                                        </div>
                                    </div>
                                    <button type="button"
                                        class="btn btn-primary btn-sm shrink-0"
                                        x-on:click="$refs.sessionFileInput.click()"
                                        x-bind:disabled="fileUploading">
                                        <span x-text="fileUploading ? 'Uploading…' : 'Choose file'">Choose file</span>
                                    </button>
                                </div>
                                <template x-if="fileUploadError">
                                    <p class="text-xs text-danger mt-1.5" x-text="fileUploadError"></p>
                                </template>
                            </div>

                            <div class="session-file-popover-toolbar">
                                <div class="session-file-popover-search">
                                    <x-icon name="search" class="size-3.5 text-fg-muted shrink-0" />
                                    <input type="search"
                                        class="session-file-search-input"
                                        placeholder="Search files…"
                                        x-model="fileSearch"
                                        autocomplete="off">
                                    <button type="button" class="size-4 text-fg-muted hover:text-fg" x-show="fileSearch" x-on:click="fileSearch = ''">
                                        <x-icon name="x" class="size-3" />
                                    </button>
                                </div>

                                <template x-if="hasOtherFiles()">
                                    <div class="session-file-scope-pills" role="tablist">
                                        <button type="button"
                                            class="session-file-scope-pill"
                                            :class="{ 'is-active': fileScope === 'folder' }"
                                            x-on:click="fileScope = 'folder'">
                                            This folder
                                        </button>
                                        <button type="button"
                                            class="session-file-scope-pill"
                                            :class="{ 'is-active': fileScope === 'all' }"
                                            x-on:click="fileScope = 'all'">
                                            All course files
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="session-file-popover-list" role="menu">
                                <template x-for="f in filteredFiles()" :key="f.id || f.url">
                                    <button type="button"
                                        role="menuitem"
                                        class="session-file-popover-item"
                                        :class="{ 'is-active': f.url === fileUrl }"
                                        x-on:click="openFile(f.url, f.name); notePickerOpen = false">
                                        <span class="session-file-popover-icon">
                                            <svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false">
                                                <use :href="f.iconUrl || ('/icons.svg#' + (f.icon || 'file-text'))"></use>
                                            </svg>
                                        </span>
                                        <div class="session-file-popover-text min-w-0 flex-1">
                                            <p class="session-file-popover-name truncate" x-text="f.name"></p>
                                            <p class="session-file-popover-meta truncate">
                                                <span x-text="f.type || 'File'"></span>
                                                <template x-if="f.size">
                                                    <span x-text="' · ' + f.size"></span>
                                                </template>
                                                <template x-if="!f.inFolder">
                                                    <span class="text-fg-subtle"> · other folder</span>
                                                </template>
                                            </p>
                                        </div>
                                        <template x-if="f.url === fileUrl">
                                            <span class="session-file-active-badge">
                                                <x-icon name="check" class="size-3 text-accent" />
                                                <span>Open</span>
                                            </span>
                                        </template>
                                    </button>
                                </template>

                                <template x-if="filteredFiles().length === 0">
                                    <div class="session-file-empty-state">
                                        <x-icon name="folder-open" class="size-5 text-fg-muted" />
                                        <p class="text-xs text-fg-muted" x-text="fileSearch ? 'No files match \'' + fileSearch + '\'' : 'No files in this folder yet'"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="session-file-popover-foot">
                            <span class="text-[0.6875rem] text-fg-muted" x-text="noteTab === 'notes' ? (noteItems.length + ' note' + (noteItems.length === 1 ? '' : 's')) : (fileItems.length + ' file' + (fileItems.length === 1 ? '' : 's'))"></span>
                            <button type="button" class="text-[0.6875rem] text-link hover:underline" wire:click="openMaterial" x-on:click="notePickerOpen = false">
                                Browse all course material &rarr;
                            </button>
                        </div>
                    </div>
                </div>
                <div class="session-note-body" x-ref="sessionNoteSlot"></div>
            </aside>

            <aside class="session-questions-pane"
                wire:ignore.self
                x-cloak
                x-bind:class="paneClasses('questions')"
                aria-label="Study questions">
                <div class="session-note-head">
                    <div class="session-note-title-wrap">
                        <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                            <x-icon name="circle-help" class="size-3.5" />
                        </span>
                        <span class="text-xs font-semibold text-fg truncate">Questions</span>
                        @if ($sideQuestions['total'] > 0)
                            <span class="text-xs text-fg-muted">({{ $sideQuestions['pending'] }} open · {{ $sideQuestions['answered'] }} answered)</span>
                        @endif
                    </div>
                    <div class="session-note-actions">
                        <button type="button" class="chat-toolbar-btn" wire:click="newQuestion" title="Ask a new question">
                            <x-icon name="plus" class="size-3.5" />
                            <span>Question</span>
                        </button>
                        <button type="button" class="topbar-button size-7" x-show="openPanes.length === 2" x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                            <x-icon name="arrow-left-right" class="size-3.5" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-on:click="minimizePane('questions')" title="Minimize questions pane" aria-label="Minimize questions pane">
                            <x-icon name="minus" class="size-4" />
                        </button>
                    </div>
                </div>
                <div class="session-side-pane-body" x-data="{ qSearch: '', qFilter: 'all', matches(t, a) { if (!this.qSearch) return true; const s = this.qSearch.toLowerCase(); return (t && t.toLowerCase().includes(s)) || (a && a.toLowerCase().includes(s)); } }">
                    @if ($sideQuestions['total'] === 0)
                        <div class="session-file-empty">
                            <span class="ws-chip size-10 rounded-lg" aria-hidden="true">
                                <x-icon name="circle-help" class="size-5" />
                            </span>
                            <p class="text-sm font-semibold text-fg">No questions yet</p>
                            <p class="text-xs text-fg-muted max-w-xs text-center">Save questions you have while studying so you can get answers from the AI tutor or revisit them later.</p>
                            <button type="button" class="btn btn-primary btn-sm mt-1" wire:click="newQuestion">
                                <x-icon name="plus" class="size-4" />
                                <span>Ask a question</span>
                            </button>
                        </div>
                    @else
                        <div class="session-questions-toolbar">
                            <div class="session-search-box">
                                <x-icon name="search" class="size-3.5 text-fg-muted shrink-0" />
                                <input type="search" x-model="qSearch" placeholder="Search questions…" class="session-search-input" aria-label="Search questions">
                                <button type="button" x-show="qSearch" x-cloak x-on:click="qSearch = ''" class="session-search-clear" aria-label="Clear search">
                                    <x-icon name="x" class="size-3" />
                                </button>
                            </div>
                            <div class="session-filter-chips" role="tablist">
                                <button type="button" class="session-filter-chip" x-bind:class="{ 'is-active': qFilter === 'all' }" x-on:click="qFilter = 'all'">All ({{ $sideQuestions['total'] }})</button>
                                <button type="button" class="session-filter-chip" x-bind:class="{ 'is-active': qFilter === 'open' }" x-on:click="qFilter = 'open'">Open ({{ $sideQuestions['pending'] }})</button>
                                <button type="button" class="session-filter-chip" x-bind:class="{ 'is-active': qFilter === 'answered' }" x-on:click="qFilter = 'answered'">Answered ({{ $sideQuestions['answered'] }})</button>
                            </div>
                        </div>

                        <div class="session-items-list">
                            @foreach ($sideQuestions['items'] as $q)
                                <div class="session-item-card"
                                    wire:key="sq-{{ $q['id'] }}"
                                    x-data="{ editingAnswer: false, answerVal: @js($q['answer'] ?? ''), isGenerating: false }"
                                    x-show="(qFilter === 'all' || (qFilter === 'open' && @js($q['status']) !== 'answered') || (qFilter === 'answered' && @js($q['status']) === 'answered')) && matches(@js($q['text']), @js($q['answer'] ?? ''))">
                                    <div class="session-item-header">
                                        <div class="flex items-center gap-1.5 min-w-0">
                                            <span @class([
                                                'status-chip',
                                                'status-understood' => $q['status'] === 'answered',
                                                'status-confused' => $q['status'] === 'stuck',
                                                'status-covered' => $q['status'] === 'pending',
                                            ])>
                                                {{ $q['statusLabel'] }}
                                            </span>
                                            @if ($q['isThisSession'])
                                                <span class="text-[0.6875rem] font-medium text-accent">This session</span>
                                            @endif
                                        </div>
                                        <div class="session-item-actions">
                                            <button type="button" class="session-mini-btn is-ai" wire:loading.attr="disabled" wire:target="generateConciseAnswer('{{ $q['id'] }}')" wire:click="generateConciseAnswer('{{ $q['id'] }}')" title="Generate simple, concise answer with AI">
                                                <x-icon name="sparkles" class="size-3" />
                                                <span wire:loading.remove wire:target="generateConciseAnswer('{{ $q['id'] }}')">AI Answer</span>
                                                <span wire:loading wire:target="generateConciseAnswer('{{ $q['id'] }}')">Thinking…</span>
                                            </button>
                                            <button type="button" class="session-mini-btn" x-on:click="editingAnswer = !editingAnswer" title="Put answer on question">
                                                <x-icon name="pencil" class="size-3" />
                                                <span x-text="editingAnswer ? 'Close' : ('{{ $q['answer'] ? 'Edit answer' : 'Put answer' }}')"></span>
                                            </button>
                                            @if ($q['status'] !== 'answered')
                                                <button type="button" class="session-mini-btn" wire:click="resolveQuestion('{{ $q['id'] }}')" title="Mark answered">
                                                    <x-icon name="check" class="size-3" />
                                                    <span>Understood</span>
                                                </button>
                                            @endif
                                            <button type="button" class="session-mini-btn" x-on:click="askAi('Question: {{ addslashes($q['text']) }}. Can you explain this clearly?')" title="Ask AI tutor">
                                                <x-icon name="message-square-text" class="size-3" />
                                                <span>Ask in chat</span>
                                            </button>
                                            <button type="button" class="session-mini-btn text-danger" wire:click="deleteQuestion('{{ $q['id'] }}')" title="Delete question">
                                                <x-icon name="trash-2" class="size-3" />
                                            </button>
                                        </div>
                                    </div>
                                    <p class="session-item-text font-medium">{{ $q['text'] }}</p>
                                    @if ($q['answer'])
                                        <div class="session-item-answer" x-show="!editingAnswer">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-[0.6875rem] font-semibold text-fg-muted uppercase tracking-wider flex items-center gap-1">
                                                    <x-icon name="check" class="size-3 text-success" /> Answer
                                                </span>
                                                <button type="button" class="text-xs text-fg-muted hover:text-fg text-link" x-on:click="editingAnswer = true">Edit</button>
                                            </div>
                                            <p class="text-xs text-fg leading-relaxed">{{ $q['answer'] }}</p>
                                        </div>
                                    @endif
                                    <div class="session-inline-answer-box" x-show="editingAnswer" x-cloak>
                                        <label class="sr-only">Concise answer</label>
                                        <textarea x-model="answerVal" rows="2" placeholder="Write or paste a simple, concise answer…" class="session-inline-answer-input"></textarea>
                                        <div class="flex items-center justify-between gap-1.5 mt-1 flex-wrap">
                                            <button type="button" class="session-mini-btn is-ai" x-bind:disabled="isGenerating" x-on:click="isGenerating = true; $wire.draftConciseAnswer('{{ $q['id'] }}').then(ans => { if (ans) answerVal = ans; isGenerating = false; });" title="Draft a short, direct answer with AI">
                                                <x-icon name="sparkles" class="size-3" />
                                                <span x-text="isGenerating ? 'Drafting…' : 'Draft with AI'">Draft with AI</span>
                                            </button>
                                            <div class="flex items-center gap-1">
                                                <button type="button" class="btn btn-ghost btn-sm" x-on:click="editingAnswer = false">Cancel</button>
                                                <button type="button" class="btn btn-primary btn-sm" x-on:click="if (answerVal.trim()) { $wire.saveQuickAnswer('{{ $q['id'] }}', answerVal); editingAnswer = false; }">
                                                    <x-icon name="check" class="size-3" />
                                                    <span>Save</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </aside>

            <aside class="session-quiz-pane"
                wire:ignore.self
                x-cloak
                x-bind:class="paneClasses('quiz')"
                aria-label="Study quizzes">
                <div class="session-note-head">
                    <div class="session-note-title-wrap">
                        <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                            <x-icon name="clipboard-check" class="size-3.5" />
                        </span>
                        <span class="text-xs font-semibold text-fg truncate">Quizzes</span>
                        @if ($sideQuizzes['count'] > 0)
                            <span class="text-xs text-fg-muted">({{ $sideQuizzes['count'] }} taken · {{ $sideQuizzes['avgScore'] }}% avg)</span>
                        @endif
                    </div>
                    <div class="session-note-actions">
                        @if ($quizView === 'idle')
                            <button type="button" class="chat-toolbar-btn" wire:click="openQuizConfigure" title="Start a quiz with AI">
                                <x-icon name="sparkles" class="size-3.5" />
                                <span>Quiz me</span>
                            </button>
                        @elseif ($quizView !== 'generating' && $quizView !== 'grading')
                            <button type="button" class="topbar-button text-xs px-2 h-7" wire:click="cancelQuiz" title="Exit quiz">
                                <span>Exit</span>
                            </button>
                        @endif
                        <button type="button" class="topbar-button size-7" x-show="openPanes.length === 2" x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                            <x-icon name="arrow-left-right" class="size-3.5" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-on:click="minimizePane('quiz')" title="Minimize quiz pane" aria-label="Minimize quiz pane">
                            <x-icon name="minus" class="size-4" />
                        </button>
                    </div>
                </div>
                <div class="session-side-pane-body">
                    @if ($quizView === 'configure')
                        <!-- QUIZ SETUP / CONFIGURATION -->
                        <div class="session-quiz-config-card">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5">
                                    <x-icon name="sparkles" class="size-4 text-accent" />
                                    <span class="text-sm font-semibold text-fg">Customize Your Quiz</span>
                                </div>
                                <button type="button" class="topbar-button size-6" wire:click="cancelQuiz" title="Cancel">
                                    <x-icon name="x" class="size-3.5" />
                                </button>
                            </div>

                            <!-- Question Type / Format -->
                            <div>
                                <label class="session-quiz-group-label">Question Format</label>
                                <div class="session-quiz-pills-row">
                                    <button type="button"
                                        wire:click="$set('quizFormat', 'mix')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizFormat === 'mix'])>
                                        <x-icon name="layers" class="size-3.5" />
                                        <span>Mix (All types)</span>
                                    </button>
                                    <button type="button"
                                        wire:click="$set('quizFormat', 'best')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizFormat === 'best'])>
                                        <x-icon name="circle-check" class="size-3.5" />
                                        <span>Choose the best (MCQ)</span>
                                    </button>
                                    <button type="button"
                                        wire:click="$set('quizFormat', 'short')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizFormat === 'short'])>
                                        <x-icon name="align-left" class="size-3.5" />
                                        <span>Short answer</span>
                                    </button>
                                    <button type="button"
                                        wire:click="$set('quizFormat', 'write')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizFormat === 'write'])>
                                        <x-icon name="pencil" class="size-3.5" />
                                        <span>Write / Essay</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Number of Questions -->
                            <div>
                                <label class="session-quiz-group-label">Number of Questions</label>
                                <div class="session-quiz-pills-row">
                                    @foreach ([3, 5, 10] as $cnt)
                                        <button type="button"
                                            wire:click="$set('quizCount', {{ $cnt }})"
                                            @class(['session-quiz-pill-btn', 'is-active' => $quizCount === $cnt])>
                                            <span>{{ $cnt }} questions</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <!-- Level / Difficulty -->
                            <div>
                                <label class="session-quiz-group-label">Difficulty Level</label>
                                <div class="session-quiz-pills-row">
                                    <button type="button"
                                        wire:click="$set('quizLevel', 'easy')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizLevel === 'easy'])>
                                        <span>Easy (Foundations)</span>
                                    </button>
                                    <button type="button"
                                        wire:click="$set('quizLevel', 'medium')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizLevel === 'medium'])>
                                        <span>Medium (Applied)</span>
                                    </button>
                                    <button type="button"
                                        wire:click="$set('quizLevel', 'hard')"
                                        @class(['session-quiz-pill-btn', 'is-active' => $quizLevel === 'hard'])>
                                        <span>Hard (Analysis)</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center justify-between pt-2 border-t border-border">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelQuiz">
                                    Cancel
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" wire:click="startConfiguredQuiz" wire:loading.attr="disabled">
                                    <x-icon name="sparkles" class="size-3.5" />
                                    <span>Start Quiz</span>
                                </button>
                            </div>
                        </div>

                    @elseif ($quizView === 'generating')
                        <!-- GENERATING LOADER -->
                        <div class="session-file-empty">
                            <span class="ws-chip size-10 rounded-lg animate-pulse" aria-hidden="true">
                                <x-icon name="sparkles" class="size-5 text-accent" />
                            </span>
                            <p class="text-sm font-semibold text-fg">Generating your quiz with AI...</p>
                            <p class="text-xs text-fg-muted max-w-xs text-center">
                                Preparing {{ $quizCount }} questions on {{ $topic?->name ?? 'this topic' }} ({{ ucfirst($quizLevel) }} level).
                            </p>
                        </div>

                    @elseif ($quizView === 'taking' && ! empty($activeQuizQuestions))
                        <!-- ACTIVE QUIZ TAKING -->
                        <div class="session-quiz-active-wrap"
                            x-data="{
                                timerInterval: null,
                                elapsedSeconds: 0,
                                startTimer() {
                                    this.elapsedSeconds = 0;
                                    clearInterval(this.timerInterval);
                                    this.timerInterval = setInterval(() => { this.elapsedSeconds++; }, 1000);
                                },
                                stopTimer() {
                                    clearInterval(this.timerInterval);
                                    return this.elapsedSeconds;
                                },
                                formatTime(sec) {
                                    const m = Math.floor(sec / 60);
                                    const s = sec % 60;
                                    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                                }
                            }"
                            x-init="startTimer()"
                            x-on:beforeunload.window="clearInterval(this.timerInterval)">

                            <!-- Quiz Active Topbar with Timer -->
                            <div class="session-quiz-active-header">
                                <div class="flex items-center gap-2 min-w-0 flex-wrap">
                                    <span class="text-xs font-semibold text-fg whitespace-nowrap">Q{{ $activeQuizIndex + 1 }} of {{ count($activeQuizQuestions) }}</span>
                                    <span class="status-chip text-[0.6875rem] capitalize shrink-0 whitespace-nowrap">
                                        {{ $activeQuizQuestions[$activeQuizIndex]['type'] === 'best' ? 'Choose the best' : ($activeQuizQuestions[$activeQuizIndex]['type'] === 'short' ? 'Short answer' : 'Write') }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <div class="session-quiz-timer" title="Elapsed quiz time">
                                        <x-icon name="clock" class="size-3" />
                                        <span x-text="formatTime(elapsedSeconds)">00:00</span>
                                    </div>
                                    <button type="button" class="topbar-button size-6 shrink-0" wire:click="cancelQuiz" title="Cancel quiz">
                                        <x-icon name="x" class="size-3.5" />
                                    </button>
                                </div>
                            </div>

                            <!-- Question Jump Dots -->
                            <div class="session-quiz-dots-row">
                                @foreach ($activeQuizQuestions as $qDotIdx => $qDot)
                                    <button type="button"
                                        wire:click="goToQuizQuestion({{ $qDotIdx }})"
                                        @class([
                                            'session-quiz-dot-btn',
                                            'is-current' => $activeQuizIndex === $qDotIdx,
                                            'is-answered' => trim((string) ($activeQuizAnswers[$qDotIdx] ?? '')) !== '',
                                        ])
                                        title="Go to Question {{ $qDotIdx + 1 }}">
                                        {{ $qDotIdx + 1 }}
                                    </button>
                                @endforeach
                            </div>

                            <!-- Active Question Card -->
                            <div class="session-quiz-q-item flex-1 flex flex-col justify-between">
                                <div>
                                    <p class="text-sm font-medium text-fg leading-relaxed">
                                        {{ $activeQuizQuestions[$activeQuizIndex]['asked'] }}
                                    </p>

                                    <!-- MCQ Options -->
                                    @if ($activeQuizQuestions[$activeQuizIndex]['type'] === 'best')
                                        <div class="session-quiz-mcq-grid">
                                            @foreach ($activeQuizQuestions[$activeQuizIndex]['options'] as $optKey => $optText)
                                                <button type="button"
                                                    wire:click="selectQuizOption({{ $activeQuizIndex }}, '{{ $optKey }}')"
                                                    @class([
                                                        'session-quiz-mcq-btn',
                                                        'is-selected' => ($activeQuizAnswers[$activeQuizIndex] ?? '') === $optKey,
                                                    ])>
                                                    <span class="session-quiz-mcq-letter">{{ $optKey }}</span>
                                                    <span class="session-quiz-mcq-text">{{ $optText }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif ($activeQuizQuestions[$activeQuizIndex]['type'] === 'short')
                                        <div class="mt-3">
                                            <textarea
                                                wire:model.live.debounce.300ms="activeQuizAnswers.{{ $activeQuizIndex }}"
                                                class="session-inline-answer-input"
                                                rows="3"
                                                placeholder="Type your short answer (1-2 sentences)..."></textarea>
                                        </div>
                                    @else
                                        <div class="mt-3">
                                            <textarea
                                                wire:model.live.debounce.300ms="activeQuizAnswers.{{ $activeQuizIndex }}"
                                                class="session-inline-answer-input"
                                                rows="6"
                                                placeholder="Write your in-depth explanation here..."></textarea>
                                        </div>
                                    @endif
                                </div>

                                <!-- Step Navigation & Submit -->
                                <div class="flex items-center justify-between gap-2 pt-3 mt-3 border-t border-border flex-wrap">
                                    <button type="button"
                                        class="btn btn-secondary btn-sm"
                                        wire:click="prevQuizQuestion"
                                        @disabled($activeQuizIndex === 0)>
                                        ← Previous
                                    </button>
                                    <div class="flex items-center gap-2">
                                        @if ($activeQuizIndex < count($activeQuizQuestions) - 1)
                                            <button type="button" class="btn btn-secondary btn-sm" wire:click="nextQuizQuestion">
                                                Next →
                                            </button>
                                        @endif
                                        @if ($activeQuizIndex === count($activeQuizQuestions) - 1 || $this->allQuestionsAnswered())
                                            <button type="button"
                                                class="btn btn-primary btn-sm"
                                                x-on:click="$wire.submitQuiz(stopTimer())"
                                                wire:loading.attr="disabled">
                                                <x-icon name="circle-check" class="size-3.5" />
                                                <span>Submit & Grade</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                    @elseif ($quizView === 'grading')
                        <!-- GRADING LOADER -->
                        <div class="session-file-empty">
                            <span class="ws-chip size-10 rounded-lg animate-pulse" aria-hidden="true">
                                <x-icon name="circle-check" class="size-5 text-accent" />
                            </span>
                            <p class="text-sm font-semibold text-fg">Grading your answers with AI...</p>
                            <p class="text-xs text-fg-muted max-w-xs text-center">
                                Checking accuracy, explanations, and tracking your time performance.
                            </p>
                        </div>

                    @elseif ($quizView === 'reviewed' && $latestQuizResult)
                        <!-- REVIEW & RESULTS -->
                        <div class="flex flex-col gap-3">
                            <!-- Summary Banner -->
                            <div class="session-quiz-summary-banner">
                                <div class="flex items-start justify-between gap-2 flex-wrap">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span @class([
                                            'session-score-pill text-sm px-2.5 py-1 shrink-0',
                                            'is-high' => $latestQuizResult['score'] >= 80,
                                            'is-mid' => $latestQuizResult['score'] >= 50 && $latestQuizResult['score'] < 80,
                                            'is-low' => $latestQuizResult['score'] < 50,
                                        ])>
                                            {{ $latestQuizResult['score'] }}%
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold text-fg truncate">{{ $latestQuizResult['right'] }} of {{ $latestQuizResult['asked'] }} correct</p>
                                            <p class="text-[0.6875rem] text-fg-muted">Saved to study session records</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="text-xs font-semibold text-accent flex items-center justify-end gap-1">
                                            <x-icon name="clock" class="size-3" />
                                            <span>{{ $latestQuizResult['durationWords'] }}</span>
                                        </span>
                                        <span class="text-[0.6875rem] text-fg-muted">{{ $latestQuizResult['paceWords'] }}</span>
                                    </div>
                                </div>
                                <div class="text-xs text-fg-muted pt-2 border-t border-border flex items-center justify-between gap-2 flex-wrap">
                                    <span class="min-w-0 flex-1">{{ $latestQuizResult['paceFeedback'] }}</span>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button type="button" class="btn btn-primary btn-sm" wire:click="openQuizConfigure">
                                            <span>New Quiz</span>
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" wire:click="finishQuizReview">
                                            <span>History</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Question Breakdown -->
                            <div class="flex flex-col gap-2">
                                @foreach ($latestQuizResult['questions'] as $rIdx => $rq)
                                    <div class="session-quiz-q-item">
                                        <div class="flex items-start justify-between gap-2 min-w-0">
                                            <span class="text-xs font-semibold text-fg min-w-0 flex-1 leading-snug">Q{{ $rIdx + 1 }}: {{ $rq['asked'] }}</span>
                                            <span @class([
                                                'status-chip shrink-0 whitespace-nowrap self-start',
                                                'status-understood' => $rq['result'] === 'correct',
                                                'status-covered' => $rq['result'] === 'partial',
                                                'status-confused' => $rq['result'] === 'incorrect',
                                            ])>
                                                {{ ucfirst($rq['result']) }}
                                            </span>
                                        </div>
                                        <div class="text-xs text-fg-muted mt-1.5 break-words">
                                            <span class="font-medium text-fg">Your answer:</span> {{ $rq['answer'] }}
                                        </div>
                                        @if ($rq['result'] !== 'correct')
                                            <div class="text-xs text-success mt-1 break-words">
                                                <span class="font-medium">Correct answer:</span> {{ $rq['right'] }}
                                            </div>
                                        @endif
                                        <div class="session-quiz-explanation mt-1.5 break-words">
                                            <div class="flex items-center gap-1 text-[0.6875rem] font-semibold text-fg-muted">
                                                <x-icon name="sparkles" class="size-3 text-accent shrink-0" />
                                                <span>Why this is right & feedback:</span>
                                            </div>
                                            <p class="text-xs text-fg mt-0.5 break-words">{{ $rq['fix'] }}</p>
                                        </div>
                                        <div class="mt-2 pt-1.5 border-t border-border flex items-center justify-end flex-wrap gap-1.5">
                                            @if (isset($quizSavedAsQuestions[$rq['asked']]))
                                                <span class="text-[0.6875rem] font-medium text-success flex items-center gap-1 shrink-0">
                                                    <x-icon name="check" class="size-3" />
                                                    <span>Saved to Questions</span>
                                                </span>
                                            @else
                                                <button type="button"
                                                    class="session-mini-btn shrink-0"
                                                    wire:click="turnQuizQuestionIntoQuestion('{{ addslashes($rq['asked']) }}', '{{ addslashes($rq['right']) }}', '{{ addslashes($rq['topic'] ?? '') }}')"
                                                    title="Save this question to your Questions board for long-term review">
                                                    <x-icon name="bookmark" class="size-3" />
                                                    <span>Turn into Question</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                    @else
                        <!-- IDLE / HISTORY VIEW -->
                        <!-- Quick Launch Action Cards -->
                        <div class="session-quiz-top-actions">
                            <button type="button" class="session-quiz-launch-card" wire:click="startQuickRecap">
                                <div>
                                    <div class="flex items-center gap-1 text-xs font-semibold text-fg">
                                        <x-icon name="flame" class="size-3.5 text-accent" />
                                        <span>Quick Recap</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-fg-muted mt-1">3 rapid questions to test recall in 2 minutes.</p>
                                </div>
                                <span class="text-[0.6875rem] font-medium text-accent mt-2 flex items-center gap-0.5">
                                    <span>Start 3 Qs</span>
                                    <x-icon name="chevron-right" class="size-3" />
                                </span>
                            </button>
                            <button type="button" class="session-quiz-launch-card" wire:click="openQuizConfigure">
                                <div>
                                    <div class="flex items-center gap-1 text-xs font-semibold text-fg">
                                        <x-icon name="settings" class="size-3.5 text-accent" />
                                        <span>Custom Quiz</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-fg-muted mt-1">Choose format (MCQ, short, write), count & level.</p>
                                </div>
                                <span class="text-[0.6875rem] font-medium text-accent mt-2 flex items-center gap-0.5">
                                    <span>Configure</span>
                                    <x-icon name="chevron-right" class="size-3" />
                                </span>
                            </button>
                        </div>

                        <!-- History / Past Quizzes -->
                        @if ($sideQuizzes['count'] === 0)
                            <div class="session-file-empty">
                                <span class="ws-chip size-10 rounded-lg" aria-hidden="true">
                                    <x-icon name="clipboard-check" class="size-5" />
                                </span>
                                <p class="text-sm font-semibold text-fg">No quizzes completed yet</p>
                                <p class="text-xs text-fg-muted max-w-xs text-center">Take a Quick Recap or Custom Quiz with AI right here anytime!</p>
                            </div>
                        @else
                            <div class="session-items-list" x-data="{ expandedId: null }">
                                @foreach ($sideQuizzes['items'] as $quiz)
                                    <div class="session-quiz-card" wire:key="sqz-{{ $quiz['id'] }}">
                                        <div class="session-quiz-header" x-on:click="expandedId = expandedId === '{{ $quiz['id'] }}' ? null : '{{ $quiz['id'] }}'">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span @class([
                                                    'session-score-pill',
                                                    'is-high' => $quiz['score'] >= 80,
                                                    'is-mid' => $quiz['score'] >= 50 && $quiz['score'] < 80,
                                                    'is-low' => $quiz['score'] < 50,
                                                ])>
                                                    {{ $quiz['score'] }}%
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="text-xs font-semibold text-fg capitalize">{{ $quiz['kind'] }}</span>
                                                        @if ($quiz['isThisSession'])
                                                            <span class="text-[0.6875rem] font-medium text-accent">This session</span>
                                                        @endif
                                                    </div>
                                                    <span class="text-[0.6875rem] text-fg-muted">{{ $quiz['right'] }}/{{ $quiz['asked'] }} correct · {{ $quiz['durationWords'] }} ({{ $quiz['paceWords'] }}) · {{ $quiz['finishedAt'] }}</span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <button type="button" class="session-mini-btn" x-on:click.stop="askAi('Can you review the questions I missed from this {{ $quiz['kind'] }} and give me targeted practice?')" title="Review with AI">
                                                    <x-icon name="sparkles" class="size-3" />
                                                    <span>Review with AI</span>
                                                </button>
                                                <span class="topbar-button size-6 cursor-pointer" aria-hidden="true">
                                                    <x-icon name="chevron-down" class="size-3.5 transition-transform" x-bind:class="{ 'rotate-180': expandedId === '{{ $quiz['id'] }}' }" />
                                                </span>
                                            </div>
                                        </div>
                                        <div class="session-quiz-questions" x-show="expandedId === '{{ $quiz['id'] }}'" x-cloak>
                                            @foreach ($quiz['questions'] as $qIdx => $q)
                                                <div class="session-quiz-q-item">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <span class="text-xs font-medium text-fg">Q{{ $qIdx + 1 }}: {{ $q['asked'] }}</span>
                                                        <span @class([
                                                            'status-chip',
                                                            'status-understood' => $q['result'] === 'correct',
                                                            'status-covered' => $q['result'] === 'partial',
                                                            'status-confused' => $q['result'] === 'incorrect',
                                                        ])>
                                                            {{ ucfirst($q['result']) }}
                                                        </span>
                                                    </div>
                                                    @if ($q['answer'])
                                                        <p class="text-xs text-fg-muted mt-1"><span class="font-medium text-fg">Your answer:</span> {{ $q['answer'] }}</p>
                                                    @endif
                                                    @if ($q['right'] && $q['result'] !== 'correct')
                                                        <p class="text-xs text-success mt-0.5"><span class="font-medium">Correct:</span> {{ $q['right'] }}</p>
                                                    @endif
                                                    @if ($q['fix'])
                                                        <p class="text-xs text-accent mt-0.5"><span class="font-medium">Feedback:</span> {{ $q['fix'] }}</p>
                                                    @endif
                                                    <div class="mt-2 pt-1 border-t border-border flex items-center justify-end">
                                                        @if (isset($quizSavedAsQuestions[$q['asked']]))
                                                            <span class="text-[0.6875rem] font-medium text-success flex items-center gap-1">
                                                                <x-icon name="check" class="size-3" />
                                                                <span>Saved to Questions</span>
                                                            </span>
                                                        @else
                                                            <button type="button"
                                                                class="session-mini-btn"
                                                                wire:click="turnQuizQuestionIntoQuestion({{ json_encode($q['asked']) }}, {{ json_encode($q['right']) }}, {{ json_encode($q['topic'] ?? '') }})"
                                                                title="Save this question to your Questions board for long-term review">
                                                                <x-icon name="bookmark" class="size-3" />
                                                                <span>Turn into Question</span>
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </aside>

            <aside class="session-cards-pane"
                wire:ignore.self
                x-cloak
                x-bind:class="paneClasses('cards')"
                aria-label="Flashcards">
                <div class="session-note-head">
                    <div class="session-note-title-wrap">
                        <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                            <x-icon name="layers" class="size-3.5" />
                        </span>
                        <span class="text-xs font-semibold text-fg truncate">Flashcards</span>
                        @if ($sideCards['total'] > 0)
                            <span class="text-xs text-fg-muted">({{ $sideCards['total'] }} cards · {{ $sideCards['due'] }} due)</span>
                        @endif
                    </div>
                    <div class="session-note-actions">
                        <button type="button" class="chat-toolbar-btn" wire:click="newFlashcard" title="Add flashcard">
                            <x-icon name="plus" class="size-3.5" />
                            <span>Card</span>
                        </button>
                        <button type="button" class="topbar-button size-7" x-show="openPanes.length === 2" x-on:click="swapPanes()" title="Swap left and right panes" aria-label="Swap left and right panes">
                            <x-icon name="arrow-left-right" class="size-3.5" />
                        </button>
                        <button type="button" class="topbar-button size-7" x-on:click="minimizePane('cards')" title="Minimize cards pane" aria-label="Minimize cards pane">
                            <x-icon name="minus" class="size-4" />
                        </button>
                    </div>
                </div>
                <div class="session-side-pane-body" x-data="{
                    cardIdx: 0,
                    flipped: false,
                    totalCards: {{ $sideCards['total'] }},
                    init() {
                        const key = 'vistud-session-cards-' + (this.sessionId || '{{ $session->id }}');
                        try {
                            const saved = sessionStorage.getItem(key);
                            if (saved !== null) {
                                const idx = parseInt(saved, 10);
                                if (!isNaN(idx) && idx >= 0 && idx < this.totalCards) {
                                    this.cardIdx = idx;
                                }
                            }
                        } catch {}
                        this.$watch('cardIdx', (val) => {
                            try {
                                sessionStorage.setItem(key, String(val));
                            } catch {}
                        });
                    }
                }">
                    @if ($sideCards['total'] === 0)
                        <div class="session-file-empty">
                            <span class="ws-chip size-10 rounded-lg" aria-hidden="true">
                                <x-icon name="layers" class="size-5" />
                            </span>
                            <p class="text-sm font-semibold text-fg">No flashcards yet</p>
                            <p class="text-xs text-fg-muted max-w-xs text-center">Ask the AI tutor to make flashcards on what you are studying, or create your own to practice anytime.</p>
                            <div class="flex flex-wrap items-center justify-center gap-2 mt-1">
                                <button type="button" class="btn btn-primary btn-sm" x-on:click="askAi('Please make 5 flashcards covering the key concepts we just discussed.')">
                                    <x-icon name="sparkles" class="size-4" />
                                    <span>Make cards with AI</span>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" wire:click="newFlashcard">
                                    <x-icon name="plus" class="size-4" />
                                    <span>Create card</span>
                                </button>
                            </div>
                        </div>
                    @else
                        @php
                            $cardList = $sideCards['items'];
                        @endphp
                        <div class="flashcard-study-container">
                            <div class="flashcard-study-topbar">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium text-fg-muted">Card <span x-text="cardIdx + 1"></span> of {{ $sideCards['total'] }}</span>
                                    <button type="button" class="session-mini-btn" x-on:click="askAi('Please quiz me on these flashcards to test my recall.')" title="Quiz me with AI">
                                        <x-icon name="sparkles" class="size-3" />
                                        <span>Quiz with AI</span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" class="session-icon-btn size-6" x-on:click="flipped = false; cardIdx = (cardIdx - 1 + totalCards) % totalCards" title="Previous card" aria-label="Previous card">
                                        <x-icon name="chevron-left" class="size-3.5" />
                                    </button>
                                    <button type="button" class="session-icon-btn size-6" x-on:click="flipped = false; cardIdx = (cardIdx + 1) % totalCards" title="Next card" aria-label="Next card">
                                        <x-icon name="chevron-right" class="size-3.5" />
                                    </button>
                                </div>
                            </div>

                            @foreach ($cardList as $idx => $c)
                                <div class="flashcard-stage" x-show="cardIdx === {{ $idx }}" wire:key="fc-{{ $c['id'] }}" x-cloak>
                                    <div class="flashcard-flipper" x-bind:class="{ 'is-flipped': flipped }" x-on:click="flipped = !flipped" role="button" tabindex="0" x-on:keydown.space.prevent="flipped = !flipped" title="Click or press Space to flip">
                                        <div class="flashcard-face flashcard-front">
                                            <div class="flashcard-face-header">
                                                <span class="text-[0.6875rem] font-semibold text-fg-muted uppercase tracking-wider">Question</span>
                                                <span class="text-[0.6875rem] text-fg-subtle">Tap to flip ↷</span>
                                            </div>
                                            <div class="flashcard-face-content">
                                                <p class="flashcard-text">{{ $c['front'] }}</p>
                                            </div>
                                            <div class="flashcard-face-footer">
                                                <span class="status-chip">{{ $c['dueWords'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flashcard-face flashcard-back">
                                            <div class="flashcard-face-header">
                                                <span class="text-[0.6875rem] font-semibold text-accent uppercase tracking-wider">Answer</span>
                                                <span class="text-[0.6875rem] text-fg-subtle">Tap to flip ↶</span>
                                            </div>
                                            <div class="flashcard-face-content">
                                                <p class="flashcard-text">{{ $c['back'] }}</p>
                                            </div>
                                            <div class="flashcard-face-footer" x-on:click.stop>
                                                <button type="button" class="session-mini-btn" x-on:click="askAi('Can you explain this flashcard in depth: &quot;{{ addslashes($c['front']) }}&quot;? Answer: &quot;{{ addslashes($c['back']) }}&quot;')" title="Explain with AI">
                                                    <x-icon name="sparkles" class="size-3" />
                                                    <span>Explain with AI</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flashcard-ratings" x-on:click.stop>
                                        <span class="text-xs font-medium text-fg-muted mr-1">Rate recall:</span>
                                        <button type="button" class="btn btn-secondary btn-sm" wire:click="answerCard('{{ $c['id'] }}', 'incorrect')" x-on:click="flipped = false; cardIdx = (cardIdx + 1) % totalCards" title="Not yet (start over)">
                                            <span>Not yet</span>
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" wire:click="answerCard('{{ $c['id'] }}', 'partial')" x-on:click="flipped = false; cardIdx = (cardIdx + 1) % totalCards" title="Partly (same interval)">
                                            <span>Partly</span>
                                        </button>
                                        <button type="button" class="btn btn-primary btn-sm" wire:click="answerCard('{{ $c['id'] }}', 'correct')" x-on:click="flipped = false; cardIdx = (cardIdx + 1) % totalCards" title="Got it (longer interval)">
                                            <x-icon name="check" class="size-3.5" />
                                            <span>Got it</span>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </aside>
        </div>

        <div class="session-rail-backdrop" wire:ignore.self x-show="railOpen" x-cloak x-on:click="railOpen = false" aria-hidden="true"></div>
        <aside class="session-rail" wire:ignore.self x-show="railOpen" x-cloak x-on:keydown.escape.window="if (railOpen) railOpen = false" aria-label="Topics, material and what this session saved">
            <div class="session-rail-head" wire:ignore>
                <span class="text-xs font-semibold text-fg truncate">Topics &amp; material</span>
                <button type="button" class="topbar-button size-7" x-on:click="railOpen = false" title="Close topics and material" aria-label="Close topics and material">
                    <x-icon name="x" class="size-4" />
                </button>
            </div>
            <div class="session-rail-scroll">
                @include('livewire.workspaces.partials.session-rail', ['suffix' => 'side'])
            </div>
        </aside>
    </div>

    {{-- The rail as a sheet, for a phone. --}}
    <dialog id="session-rail-sheet" class="modal" aria-labelledby="session-rail-title"
        wire:ignore.self
        x-data
        x-on:session-rail-open.window="$el.open || $el.showModal()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            <div class="modal-head">
                <h2 id="session-rail-title" class="min-w-0 flex-1 text-lg font-semibold">{{ $placeName ?? 'This session' }}</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
            </div>
            <div class="rail-body px-5 pb-5">
                @include('livewire.workspaces.partials.session-rail', ['suffix' => 'sheet'])
            </div>
        </div>
    </dialog>

    <dialog id="session-dialog" class="modal" aria-labelledby="session-dialog-title"
        wire:ignore.self
        x-data
        x-on:session-dialog-open.window="$el.open || $el.showModal()"
        x-on:session-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate @class(['modal-panel', 'modal-panel-wide' => in_array($mode, ['briefing', 'custom_rules', 'plugins'], true)]) wire:key="session-dialog-{{ $mode }}" @if ($mode === 'briefing') x-data="{ copied: false }" @endif>
                <div class="modal-head">
                    <h2 id="session-dialog-title" class="min-w-0 flex-1 text-lg font-semibold" tabindex="-1" autofocus>{{ ['end' => 'End this session?', 'done' => 'Session ended', 'delete' => 'Delete this session?', 'reset_time' => 'Reset timer?', 'reset_full' => 'Restart session?', 'pomodoro' => 'Session clock', 'teaching' => 'How the AI teaches', 'custom_rules' => 'Custom Tutor Rules (Do & Don\'t)', 'plugins' => 'Study Plugins Library', 'briefing' => 'Prompt for another AI', 'material' => 'Material for the tutor', 'topic' => 'What this session is about', 'question' => 'Ask a question', 'tell' => 'Tell the tutor about this module'][$mode] ?? 'Settings' }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'briefing')
                        <p class="text-sm text-fg-muted">Copy this into any AI, or download it and attach it. It tells the AI how to teach you and where you are.</p>
                        @if ($briefing->trimmed)
                            <x-alert tone="info" :live="false">Some things were left out to keep it short. The most relevant come first.</x-alert>
                        @endif
                        <pre class="briefing-text" tabindex="0" aria-label="Prompt text" x-ref="text">{{ $briefing->markdown }}</pre>
                        <p class="text-sm text-fg-muted">About {{ number_format($briefing->tokens()) }} tokens · <button type="button" class="text-link" wire:click="editTeaching">How the AI teaches</button></p>
                        <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                    @elseif ($mode === 'material')
                        @php
                            $lower = fn ($item) => Str::lower($item['name']);
                            $every = array_map($lower, [...array_merge([], ...array_column($groups, 'items')), ...$alsoUsed]);
                        @endphp
                        <div class="space-y-4" x-data="{ q: '', has(name) { const q = this.q.trim().toLowerCase(); return q === '' || name.includes(q); } }">
                            @if ($every !== [])
                                <div class="search-field">
                                    <x-icon name="search" class="size-4" />
                                    <label for="material-search" class="sr-only">Search {{ $placeName ? 'the notes and files in '.$placeName : 'notes and files' }}</label>
                                    <input id="material-search" type="search" class="input" placeholder="Search {{ $placeName ?? 'notes and files' }}" x-model="q" autocomplete="off">
                                </div>
                                @if ($open)
                                    <p class="text-sm text-fg-muted">What you use, the tutor reads, and it goes in the prompt for another AI.</p>
                                @endif
                            @else
                                <div class="empty-place">
                                    <span class="item-icon" aria-hidden="true"><x-icon name="folder-open" class="size-5" /></span>
                                    <p class="font-medium">{{ $placeName ? 'Nothing in '.$placeName.' yet' : 'No notes or files yet' }}</p>
                                </div>
                            @endif
                            @if ($alsoUsed !== [])
                                <section class="space-y-2" x-show="@js(array_map($lower, $alsoUsed)).some((n) => has(n))">
                                    <h3 class="section-title">Also given to the AI</h3>
                                    <ul class="item-list" role="list" aria-label="Also given to the AI">
                                        @foreach ($alsoUsed as $item)
                                            @include('livewire.workspaces.partials.material-row')
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                            @foreach ($groups as $group)
                                <section class="material-group space-y-2" wire:key="group-{{ $group['key'] }}" style="--depth: {{ min($group['depth'], 4) }}" x-show="@js(array_map($lower, $group['items'])).some((n) => has(n))">
                                    @if ($group['title'] !== null)
                                        <h3 class="material-group-title"><x-icon name="folder" class="size-4" />{{ $group['title'] }}</h3>
                                    @endif
                                    <ul class="item-list" role="list" aria-label="{{ $group['title'] ?? 'In '.$placeName }}">
                                        @foreach ($group['items'] as $item)
                                            @include('livewire.workspaces.partials.material-row')
                                        @endforeach
                                    </ul>
                                </section>
                            @endforeach
                            @if ($every !== [])
                                <p class="text-fg-muted" x-show="! @js($every).some((n) => has(n))" x-cloak>Nothing matches.</p>
                            @endif
                        </div>
                    @elseif ($mode === 'topic')
                        @php
                            $isHere = fn ($t) => $inside !== null ? $t->in($inside) : ($module && $t->moduleId === $module->id);
                            $here = array_values(array_filter($courseTopics, $isHere));
                            $elsewhere = array_values(array_filter($courseTopics, fn ($t) => ! $isHere($t)));
                        @endphp
                        <p class="text-sm text-fg-muted">The tutor teaches from it, and the flashcards, questions and key points you save go to it. The tutor can set it too, when you agree.</p>
                        <div class="field">
                            <label for="session-topic" class="field-label">Topic</label>
                            <select id="session-topic" class="input" wire:model.live="topicChoice" @error('topicChoice') aria-invalid="true" @enderror>
                                <option value="">No topic</option>
                                @if ($here !== [])
                                    <optgroup label="{{ $placeName }}">
                                        @foreach ($here as $t)
                                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if ($elsewhere !== [])
                                    <optgroup label="{{ $here !== [] ? 'Other topics' : 'Topics' }}">
                                        @foreach ($elsewhere as $t)
                                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                <option value="new">A new topic…</option>
                            </select>
                            @error('topicChoice') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        @if ($topicChoice === 'new')
                            <div class="field">
                                <label for="session-new-topic" class="field-label">Name</label>
                                <input id="session-new-topic" type="text" class="input" wire:model="newTopic" maxlength="{{ Topics::MAX_NAME }}" autofocus aria-describedby="session-new-topic-hint" @error('newTopic') aria-invalid="true" @enderror>
                                <p id="session-new-topic-hint" class="field-hint">{{ $placeName ? 'Added to '.$placeName.'.' : 'Added to the course.' }} Short, like a chapter heading.</p>
                                @error('newTopic') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @elseif ($mode === 'teaching')
                        @include('livewire.workspaces.partials.teaching-fields')
                        <p class="text-sm text-fg-muted">The prompt asks for this from now on. An AI you already gave it to needs the new prompt, or to be told.</p>
                    @elseif ($mode === 'custom_rules')
                        @include('livewire.workspaces.partials.custom-rules-editor')
                    @elseif ($mode === 'plugins')
                        @include('livewire.workspaces.partials.session-plugins-library')
                    @elseif ($mode === 'pomodoro')
                        @include('livewire.workspaces.partials.pomodoro-fields')
                        <p class="text-sm text-fg-muted">{{ $session->usesPomodoro() ? 'Time already studied stays, and the current phase keeps its progress with the new lengths.' : 'Time already studied stays. The first focus period starts counting now.' }}</p>
                    @elseif ($mode === 'end')
                        <p>You studied {{ $studied }}.</p>
                        @if ($touched !== [])
                            <fieldset class="space-y-3">
                                <legend class="field-label mb-1">Where you stand</legend>
                                @foreach ($touched as $t)
                                    @php $given = $activity['statuses'][$t->id] ?? null; @endphp
                                    <div class="end-topic" wire:key="end-topic-{{ $t->id }}">
                                        <p class="font-medium break-words">{{ $t->name }}</p>
                                        <p class="text-sm text-fg-muted">
                                            @if ($given)
                                                {{ $given['applied'] ? 'The tutor marked it' : 'The tutor suggests' }} {{ $statusWords[$given['to']] ?? $given['to'] }}{{ ($given['reason'] ?? null) ? ': '.$given['reason'] : '.' }}
                                            @else
                                                {{ $t->status !== null ? 'Now: '.($statusWords[$t->status] ?? $t->status).($t->byTutor() ? ' (marked by the tutor)' : '').'.' : 'No status yet.' }}
                                            @endif
                                        </p>
                                        <div class="segmented" role="radiogroup" aria-label="Where you stand on {{ $t->name }}">
                                            @foreach (['' => 'Leave it', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing'] as $value => $word)
                                                <label>
                                                    <input type="radio" name="end-status-{{ $t->id }}" value="{{ $value }}" wire:model="statuses.{{ $t->id }}">
                                                    <span>{{ $word }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </fieldset>
                        @endif
                        @if ($activity['saved'] !== [] || $activity['quizzes'] > 0)
                            <p class="text-sm text-fg-muted">Saved in this session: {{ implode(', ', array_filter([\App\Livewire\Workspaces\TutorChat::savedWords($activity['saved']), $activity['quizzes'] > 0 ? Str::plural('quiz or test', $activity['quizzes'], prependCount: true) : null])) }}.</p>
                        @endif
                        @if ($chatted)
                            <p class="text-sm text-fg-muted">The chat's summary and where it stopped are saved with the session, for the next one to start from.</p>
                        @endif
                    @elseif ($mode === 'done')
                        <p>You studied {{ $studied }}.</p>
                        @if ($session->summary)
                            <div class="space-y-1">
                                <h3 class="text-sm font-semibold text-fg-muted">Summary</h3>
                                <p class="break-words">{{ $session->summary }}</p>
                            </div>
                        @elseif ($chatted)
                            <p class="text-sm text-fg-muted">The tutor's summary couldn't be written just now. It will be saved the next time you open this session.</p>
                        @endif
                    @elseif ($mode === 'question')
                        <div class="field">
                            <label for="session-question" class="field-label">Your question</label>
                            <textarea id="session-question" class="input" rows="3" wire:model="questionText" placeholder="What do you want to understand or remember?" maxlength="{{ \App\Study\Questions::MAX_TEXT }}" autofocus @error('questionText') aria-invalid="true" @enderror></textarea>
                            <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                                <p class="field-hint">{{ $topic ? 'Kept with '.$topic->name.($module ? ' in '.$module->title : '').'.' : ($module ? 'Kept in '.$module->title.'.' : 'Kept in this course.') }}</p>
                                <button type="button" class="btn btn-secondary btn-sm" wire:click="answerQuestionWithAi" wire:loading.attr="disabled" wire:target="answerQuestionWithAi">
                                    <x-icon name="sparkles" class="size-3.5" wire:loading.remove wire:target="answerQuestionWithAi" />
                                    <x-icon name="loader-circle" class="size-3.5 animate-spin" wire:loading wire:target="answerQuestionWithAi" />
                                    <span wire:loading.remove wire:target="answerQuestionWithAi">{{ $questionAnswer !== '' ? 'Refresh AI answer' : 'Get answer with AI' }}</span>
                                    <span wire:loading wire:target="answerQuestionWithAi">Getting concise answer…</span>
                                </button>
                            </div>
                            @error('questionText') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        @if ($questionAnswer !== '')
                            <div class="session-qa-card">
                                <div class="session-qa-head">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-fg">
                                        <x-icon name="sparkles" class="size-3.5 text-accent" />
                                        <span>Question &amp; AI answer ready to save</span>
                                    </span>
                                    <span class="status-chip status-answered">Answered</span>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-xs font-semibold text-fg-muted">Question</p>
                                    <p class="text-sm font-semibold text-fg break-words">{{ $questionText }}</p>
                                </div>
                                <div class="field">
                                    <label for="session-question-answer" class="field-label text-xs">Concise answer</label>
                                    <textarea id="session-question-answer" class="input text-sm" rows="4" wire:model="questionAnswer" maxlength="{{ \App\Study\Questions::MAX_ANSWER }}" @error('questionAnswer') aria-invalid="true" @enderror></textarea>
                                    @error('questionAnswer') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    @elseif ($mode === 'tell')
                        <div class="field">
                            <label for="session-module-note" class="field-label">What should the tutor know about {{ $module?->title }}?</label>
                            <textarea id="session-module-note" class="input" rows="5" wire:model="moduleNote" maxlength="{{ \App\Study\Instructions::MAX_TEXT }}" autofocus @error('moduleNote') aria-invalid="true" @enderror></textarea>
                            <p class="field-hint">For example: the exam covers only the first half, or use the lecturer's slides.</p>
                            @error('moduleNote') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($mode === 'delete')
                        <p class="text-fg-muted">Its {{ SessionDetails::duration($session->studySeconds) }} of study time comes off your totals. What you recorded during it stays. This can't be undone.</p>
                    @elseif ($mode === 'reset_time')
                        <p class="text-fg-muted">Reset the session clock back to 00:00. Your chat conversation, notes, and course material will remain completely untouched.</p>
                    @elseif ($mode === 'reset_full')
                        <div class="space-y-2">
                            <p class="text-fg-muted">Restart this session with a clean slate. This will clear the chat history and tutor memory for this session, and reset the timer back to 00:00.</p>
                            <p class="text-sm text-fg-subtle">Your course notes, uploaded files, and module topics are safe and will not be deleted.</p>
                        </div>
                    @else
                        <p class="text-fg-muted">Its {{ SessionDetails::duration($session->studySeconds) }} of study time comes off your totals. What you recorded during it stays. This can't be undone.</p>
                    @endif
                </div>
                <div class="modal-actions">
                    @if ($mode === 'briefing')
                        <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                        <a href="{{ route('workspaces.sessions.briefing', [$workspaceId, $session->id]) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" />Download</a>
                        <x-button variant="primary" icon="copy" x-on:click="
                            const text = $refs.text.textContent;
                            const done = () => { copied = true; setTimeout(() => copied = false, 2500); };
                            const select = () => {
                                const range = document.createRange();
                                range.selectNodeContents($refs.text);
                                const selection = window.getSelection();
                                selection.removeAllRanges();
                                selection.addRange(range);
                                try { if (document.execCommand('copy')) done(); } catch (e) {}
                            };
                            navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(text).then(done, select) : select();
                        "><span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span></x-button>
                    @elseif ($mode === 'material' || $mode === 'plugins')
                        <x-button variant="primary" x-on:click="$el.closest('dialog').close()">Done</x-button>
                    @elseif ($mode === 'done')
                        <a href="{{ $backHref }}" class="btn btn-primary" wire:navigate>Done</a>
                    @elseif ($mode === 'question')
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        @if ($questionAnswer !== '')
                            <x-button type="button" variant="secondary" wire:click="keepQuestionOnly" wire:loading.attr="aria-busy" wire:target="keepQuestionOnly,save">Keep question only</x-button>
                            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save question &amp; answer</x-button>
                        @else
                            <x-button type="button" variant="secondary" icon="sparkles" wire:click="answerQuestionWithAi" wire:loading.attr="aria-busy" wire:target="answerQuestionWithAi" busy-label="Answering…">Get answer with AI</x-button>
                            <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Keep question</x-button>
                        @endif
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">{{ $mode === 'end' ? 'Keep studying' : 'Cancel' }}</x-button>
                        <x-button type="submit" :variant="$mode === 'delete' || $mode === 'reset_full' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="{{ $mode === 'end' ? 'Ending…' : 'Saving…' }}">{{ ['end' => 'End session', 'delete' => 'Delete', 'reset_time' => 'Reset timer', 'reset_full' => 'Restart session', 'pomodoro' => 'Save', 'teaching' => 'Save', 'custom_rules' => 'Save rules', 'topic' => 'Save', 'question' => 'Keep question', 'tell' => 'Save'][$mode] ?? 'Save' }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
