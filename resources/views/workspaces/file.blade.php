{{--
    A file (App\Http\Controllers\FilePageController): a large preview where
    the browser can show it (PDF, images, text, and Word, PowerPoint and Excel
    as a PDF made by LibreOffice), and its download. The bytes
    come only from files.content, after the owner check.
--}}
@php
    use Illuminate\Support\Carbon;

    $content = route('files.content', $file->id);
    $openWith = ['document' => 'Word, Pages or LibreOffice', 'slides' => 'PowerPoint, Keynote or LibreOffice', 'spreadsheet' => 'Excel, Numbers or LibreOffice'][$file->kind] ?? 'an app on your device';
    $hasPreview = $file->trashedAt === null && ($file->previewable() || $officePreview !== null || $markdown !== null);
@endphp
<x-layouts.app :title="$file->fileName().' · '.$workspace->name" :workspace="$workspace" :section="$inModule ? 'modules' : 'notes'">
    <div
        class="file-page"
        x-data="{
            isFullscreen: false,
            splitOpen: false,
            splitPercent: 55,
            isDragging: false,
            noteSrc: '',

            init() {
                const saved = localStorage.getItem('vistud-file-split-percent');
                if (saved) {
                    const val = parseFloat(saved);
                    if (val >= 20 && val <= 80) this.splitPercent = val;
                }
            },

            toggleFullscreen() {
                if (this.isFullscreen) {
                    this.exitFullscreen();
                } else {
                    this.enterFullscreen();
                }
            },

            enterFullscreen() {
                this.isFullscreen = true;
                document.body.style.overflow = 'hidden';
                const root = this.$root;
                if (root && root.requestFullscreen && !document.fullscreenElement) {
                    root.requestFullscreen().catch(() => {});
                }
            },

            exitFullscreen() {
                this.isFullscreen = false;
                document.body.style.overflow = '';
                if (document.fullscreenElement && document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                }
            },

            syncFullscreen() {
                const root = this.$root;
                const active = Boolean(document.fullscreenElement === root || (document.fullscreenElement && root && root.contains(document.fullscreenElement)));
                this.isFullscreen = active;
                if (!active) {
                    document.body.style.overflow = '';
                }
            },

            openSplit() {
                this.splitOpen = true;
                if (!this.noteSrc) {
                    this.noteSrc = '{{ $takeNotes }}';
                }
                this.$nextTick(() => {
                    const slot = this.$refs.noteSlot;
                    if (slot && !slot.querySelector('iframe')) {
                        const frame = document.createElement('iframe');
                        const sep = this.noteSrc.includes('?') ? '&' : '?';
                        frame.src = this.noteSrc.includes('split=1') ? this.noteSrc : `${this.noteSrc}${sep}split=1`;
                        frame.className = 'absolute inset-0 w-full h-full border-0';
                        frame.title = 'Note editor';
                        slot.appendChild(frame);
                    }
                });
            },

            closeSplit() {
                this.splitOpen = false;
            },

            toggleNotes(event) {
                if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0)) return;
                if (window.innerWidth >= 1024 || this.isFullscreen) {
                    if (event) event.preventDefault();
                    if (this.splitOpen) {
                        this.closeSplit();
                    } else {
                        this.openSplit();
                    }
                }
            },

            startDrag(event) {
                this.isDragging = true;
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
                const container = this.$refs.splitContainer;
                const onMove = (e) => {
                    const clientX = e.clientX ?? (e.touches && e.touches[0] ? e.touches[0].clientX : undefined);
                    if (clientX === undefined) return;
                    const rect = container.getBoundingClientRect();
                    if (!rect.width) return;
                    const rawPercent = ((clientX - rect.left) / rect.width) * 100;
                    const clamped = Math.min(Math.max(rawPercent, 20), 80);
                    this.splitPercent = Math.round(clamped * 10) / 10;
                };
                const onUp = () => {
                    this.isDragging = false;
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    window.removeEventListener('touchmove', onMove);
                    window.removeEventListener('touchend', onUp);
                    localStorage.setItem('vistud-file-split-percent', this.splitPercent);
                };
                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp, { once: true });
                window.addEventListener('touchmove', onMove, { passive: true });
                window.addEventListener('touchend', onUp, { once: true });
            },

            resetSplit() {
                this.splitPercent = 50;
                localStorage.setItem('vistud-file-split-percent', 50);
            }
        }"
        x-on:fullscreenchange.window="syncFullscreen()"
        x-on:webkitfullscreenchange.window="syncFullscreen()"
        x-on:keydown.escape.window="if (isFullscreen) { exitFullscreen(); }"
        x-on:mouseup.window="if (isDragging) { isDragging = false; localStorage.setItem('vistud-file-split-percent', splitPercent); }"
        x-on:touchend.window="if (isDragging) { isDragging = false; localStorage.setItem('vistud-file-split-percent', splitPercent); }"
        x-bind:class="isFullscreen && 'is-fullscreen'"
    >
        @php
            [$backTo, $backUrl] = $trail !== [] ? end($trail) : [$inModule ? 'Modules' : 'Notes & files', route('workspaces.show', $inModule ? [$workspace->id, 'modules'] : [$workspace->id, 'notes'])];
        @endphp
        {{-- On a narrow screen the buttons go under the name: the name never shrinks to nothing, and nothing scrolls sideways. --}}
        <header class="file-toolbar flex flex-wrap items-center justify-between gap-x-3 gap-y-2 min-w-0 py-0.5">
            <div class="flex min-w-0 basis-48 items-center gap-2 flex-1">
                <x-back :href="$backUrl" :to="$backTo" compact class="size-8 shrink-0" />
                <nav aria-label="Where this file is" class="hidden min-w-0 max-w-[50%] items-center overflow-hidden xl:flex">
                    <ol class="breadcrumbs text-xs text-fg-muted font-medium flex flex-nowrap items-center gap-1.5 min-w-0 whitespace-nowrap">
                        <li class="shrink-0"><a class="hover:text-fg transition-colors" href="{{ route('workspaces.show', $inModule ? [$workspace->id, 'modules'] : [$workspace->id, 'notes']) }}">{{ $inModule ? 'Modules' : 'Notes & files' }}</a></li>
                        @foreach ($trail as [$label, $url])
                            <li class="flex items-center gap-1.5 min-w-0">
                                <x-icon name="chevron-right" class="size-3.5 shrink-0 text-fg-subtle" />
                                @if ($url)
                                    <a class="hover:text-fg transition-colors truncate max-w-[100px] md:max-w-[140px]" href="{{ $url }}">{{ $label }}</a>
                                @else
                                    <span class="truncate max-w-[100px] md:max-w-[140px]">{{ $label }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <x-icon name="chevron-right" class="size-3.5 shrink-0 text-fg-subtle ml-1.5 mr-2" />
                </nav>
                <div class="flex min-w-[min(8rem,50%)] flex-1 items-center gap-1.5">
                    <span class="ws-chip size-6 rounded shrink-0" aria-hidden="true">
                        <x-icon :name="$file->icon()" class="size-3.5" />
                    </span>
                    <h1 class="text-xs sm:text-sm font-semibold text-fg truncate" title="{{ $file->fileName() }}">
                        {{ $file->fileName() }}
                    </h1>
                    <span class="hidden lg:inline text-xs text-fg-muted font-normal shrink-0" title="Uploaded {{ Carbon::parse($file->uploadedAt)->format('j M Y') }}">
                        · {{ $file->humanSize() }}
                    </span>
                </div>
            </div>
            <div class="ml-auto flex max-w-full flex-wrap items-center justify-end gap-1.5 sm:gap-2">
                @if ($file->trashedAt === null)
                    @if ($hasPreview)
                        <button
                            type="button"
                            class="btn btn-ghost btn-sm p-1.5 sm:px-2.5"
                            x-on:click="toggleFullscreen()"
                            x-bind:title="isFullscreen ? 'Exit full screen (Esc)' : 'View in full screen'"
                            x-bind:aria-label="isFullscreen ? 'Exit full screen' : 'View in full screen'"
                            data-fullscreen-button
                        >
                            <span x-show="! isFullscreen" class="inline-flex items-center gap-1.5">
                                <x-icon name="maximize-2" class="size-4" />
                                <span class="max-md:sr-only text-xs">Full screen</span>
                            </span>
                            <span x-show="isFullscreen" class="inline-flex items-center gap-1.5" x-cloak>
                                <x-icon name="minimize-2" class="size-4" />
                                <span class="max-md:sr-only text-xs">Exit full screen</span>
                                <kbd class="hidden sm:inline-block px-1 py-0.5 text-[10px] uppercase font-mono rounded bg-surface-sunken border border-border text-fg-muted leading-none">Esc</kbd>
                            </span>
                        </button>
                    @endif
                    @if ($officePreview !== null)
                        <a class="btn btn-ghost btn-sm p-1.5 sm:px-2.5" href="{{ $officePreview }}" target="_blank" rel="noopener" title="Open in a new tab">
                            <x-icon name="external-link" class="size-4" />
                            <span class="max-md:sr-only text-xs">Open in a new tab</span>
                        </a>
                    @elseif ($file->previewable())
                        <a class="btn btn-ghost btn-sm p-1.5 sm:px-2.5" href="{{ $content }}" target="_blank" rel="noopener" title="Open in a new tab">
                            <x-icon name="external-link" class="size-4" />
                            <span class="max-md:sr-only text-xs">Open in a new tab</span>
                        </a>
                    @endif
                    @if ($takeNotes !== null)
                        <a
                            class="btn btn-sm transition-colors"
                            x-bind:class="splitOpen ? 'btn-secondary ring-1 ring-accent' : 'btn-primary'"
                            href="{{ $takeNotes }}" target="_blank" data-note-window
                            x-on:click="toggleNotes($event)"
                            x-bind:title="splitOpen ? 'Close notes pane' : 'Take notes beside this file (Split screen)'"
                            data-split-toggle
                        >
                            <x-icon name="notebook-pen" class="size-4" />
                            <span x-show="! splitOpen" class="text-xs">Take notes</span>
                            <span x-show="splitOpen" class="text-xs" x-cloak>Close notes</span>
                        </a>
                    @endif
                    @if ($openAsNote !== null)
                        <a class="btn btn-secondary btn-sm" href="{{ $openAsNote }}" title="A new note made from this file; the file stays as it is">
                            <x-icon name="file-plus" class="size-4" />
                            <span class="max-sm:sr-only text-xs">Open as a note</span>
                        </a>
                    @endif
                    <a class="btn btn-secondary btn-sm" href="{{ route('files.content', [$file->id, 'download' => 1]) }}" title="Download {{ $file->fileName() }}">
                        <x-icon name="download" class="size-4" />
                        <span class="max-sm:sr-only text-xs">Download</span>
                    </a>
                @endif
                <livewire:workspaces.file-actions :file-id="$file->id" />
            </div>
        </header>

        <div
            class="file-workspace"
            x-ref="splitContainer"
            x-bind:class="{ 'has-split': splitOpen, 'is-dragging': isDragging }"
        >
            {{-- Document Pane (Left) --}}
            <div
                class="file-pane-doc"
                x-bind:style="splitOpen ? 'width: ' + splitPercent + '%;' : 'width: 100%;'"
            >
                @if ($file->trashedAt !== null)
                    <x-alert tone="warning" title="This file is in the trash">
                        It can't be opened while it's there. Restore it to use it again; otherwise it's deleted 30 days after it was trashed.
                    </x-alert>
                @elseif ($file->kind === 'pdf')
                    {{-- data-pdf-src: the browser's own PDF viewer, or PDF.js where it can't show a PDF inside the page (resources/js/app.js). --}}
                    <iframe class="file-preview" data-pdf-src="{{ $content }}" title="{{ $file->fileName() }}"></iframe>
                @elseif ($officePreview !== null)
                    {{-- Word, PowerPoint or Excel as a PDF made by LibreOffice (App\Study\FilePreviews): a few seconds the first time. Asked for once. --}}
                    <div class="file-preview file-preview-office" x-data="{ ready: false }" x-init="ready = $el.querySelector('[data-shown]') !== null" x-on:pdf-shown="ready = true">
                        <p class="file-preview-wait" x-show="! ready" role="status">
                            <x-icon name="loader-circle" class="size-5 animate-spin" />Preparing the preview. The first time takes a few seconds.
                        </p>
                        <iframe data-pdf-src="{{ $officePreview }}" data-pdf-made title="{{ $file->fileName() }}" x-bind:class="! ready && 'opacity-0'"></iframe>
                    </div>
                @elseif ($office)
                    <section class="file-preview file-preview-none">
                        <span class="ws-chip size-14"><x-icon :name="$file->icon()" class="size-7" /></span>
                        <h2 class="text-lg font-semibold">{{ $file->typeLabel() }} files show here once LibreOffice is on this computer</h2>
                        <p class="max-w-md text-fg-muted">LibreOffice is free (libreoffice.org). Once it's installed, reload this page. Until then, download the file to open it in {{ $openWith }}.</p>
                        <a class="btn btn-primary" href="{{ route('files.content', [$file->id, 'download' => 1]) }}"><x-icon name="download" class="size-4" />Download</a>
                    </section>
                @elseif ($file->kind === 'image')
                    <div class="file-preview file-preview-image">
                        <img src="{{ $content }}" alt="{{ $file->fileName() }}">
                    </div>
                @elseif ($markdown !== null)
                    {{-- App\Study\MarkdownPreview: raw HTML stripped, unsafe links dropped. Formulas are drawn by resources/js/formulas.js. --}}
                    <div class="file-preview file-markdown note-prose" data-formulas aria-label="{{ $file->fileName() }}">{!! $markdown !!}</div>
                @elseif ($file->kind === 'text')
                    <pre class="file-preview file-text" tabindex="0" aria-label="{{ $file->fileName() }}">{{ $text }}</pre>
                @else
                    <section class="file-preview file-preview-none">
                        <span class="ws-chip size-14"><x-icon :name="$file->icon()" class="size-7" /></span>
                        <h2 class="text-lg font-semibold">No preview for {{ $file->typeLabel() }} files yet</h2>
                        <p class="max-w-md text-fg-muted">Download it to open it in {{ $openWith }}.</p>
                        <a class="btn btn-primary" href="{{ route('files.content', [$file->id, 'download' => 1]) }}"><x-icon name="download" class="size-4" />Download</a>
                    </section>
                @endif
            </div>

            @if ($takeNotes !== null)
                {{-- Resizable Splitter Handle (Center) --}}
                <div
                    x-show="splitOpen"
                    x-cloak
                    class="file-split-handle"
                    role="separator"
                    aria-orientation="vertical"
                    x-bind:aria-valuenow="Math.round(splitPercent)"
                    aria-valuemin="20"
                    aria-valuemax="80"
                    aria-label="Resize document and notes panes"
                    tabindex="0"
                    title="Drag to resize · Double-click to reset (50/50)"
                    x-on:mousedown.prevent="startDrag($event)"
                    x-on:touchstart.passive="startDrag($event)"
                    x-on:dblclick="resetSplit()"
                    x-on:keydown.left.prevent="splitPercent = Math.max(20, Math.round(splitPercent - 5)); localStorage.setItem('vistud-file-split-percent', splitPercent)"
                    x-on:keydown.right.prevent="splitPercent = Math.min(80, Math.round(splitPercent + 5)); localStorage.setItem('vistud-file-split-percent', splitPercent)"
                >
                    <div class="file-split-bar"></div>
                </div>

                {{-- Note Pane (Right) --}}
                <div
                    x-show="splitOpen"
                    x-cloak
                    data-note-pane
                    x-on:note-pane-moved="closeSplit(); $refs.noteSlot.replaceChildren(); noteSrc = $event.detail.url"
                    class="file-pane-note"
                    x-bind:style="'width: calc(' + (100 - splitPercent) + '% - 12px);'"
                >
                    <div class="note-pane-header flex items-center justify-between px-3 py-1.5 border-b border-border bg-surface shrink-0 min-w-0">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span class="ws-chip size-6 rounded shrink-0">
                                <x-icon name="notebook-pen" class="size-3.5" />
                            </span>
                            <span class="shrink-0 whitespace-nowrap text-xs font-semibold text-fg">Notes</span>
                            <span class="text-[11px] text-fg-muted font-normal truncate hidden sm:inline">
                                · Beside {{ $file->fileName() }}
                            </span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button
                                type="button"
                                class="topbar-button size-7"
                                x-on:click="resetSplit()"
                                title="Reset split to 50/50"
                            >
                                <x-icon name="arrow-left-right" class="size-3.5" />
                            </button>
                            {{-- Saves the pane's note, then opens that same note in a window of its own (resources/js/note-window.js). --}}
                            <a
                                class="topbar-button size-7"
                                href="{{ $takeNotes }}"
                                target="_blank"
                                data-note-pane-pop-out
                                title="Open this note in a window of its own"
                                aria-label="Open this note in a window of its own"
                            >
                                <x-icon name="external-link" class="size-3.5" />
                            </a>
                            <button
                                type="button"
                                class="topbar-button size-7"
                                x-on:click="closeSplit()"
                                title="Close note pane"
                            >
                                <x-icon name="x" class="size-4" />
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 min-h-0 relative bg-canvas" x-ref="noteSlot"></div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
