{{--
    A file (App\Http\Controllers\FilePageController): a large preview where
    the browser can show it (PDF, images, text), and its download. The bytes
    come only from files.content, after the owner check.
--}}
@php
    use Illuminate\Support\Carbon;

    $content = route('files.content', $file->id);
    $openWith = ['document' => 'Word, Pages or LibreOffice', 'slides' => 'PowerPoint, Keynote or LibreOffice', 'spreadsheet' => 'Excel, Numbers or LibreOffice'][$file->kind] ?? 'an app on your device';
@endphp
<x-layouts.app :title="$file->fileName().' · '.$workspace->name" :workspace="$workspace" section="notes">
    <div class="file-page">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <nav aria-label="Where this file is" class="min-w-0">
                <ol class="breadcrumbs">
                    <li><a href="{{ route('workspaces.show', [$workspace->id, 'notes']) }}">{{ $workspace->name }}</a></li>
                    @foreach ($trail as [$label, $url])
                        <li>
                            <x-icon name="chevron-right" class="size-4 shrink-0 text-fg-subtle" />
                            @if ($url)
                                <a href="{{ $url }}">{{ $label }}</a>
                            @else
                                <span>{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                @if ($file->trashedAt === null)
                    @if ($file->previewable())
                        <a class="btn btn-ghost" href="{{ $content }}" target="_blank" rel="noopener"><x-icon name="external-link" class="size-4" /><span class="max-sm:sr-only">Open in a new tab</span></a>
                    @endif
                    <a class="btn btn-secondary" href="{{ route('files.content', [$file->id, 'download' => 1]) }}"><x-icon name="download" class="size-4" />Download</a>
                @endif
                <livewire:workspaces.file-actions :file-id="$file->id" />
            </div>
        </div>

        <div class="flex min-w-0 items-center gap-3">
            <span class="ws-chip size-12"><x-icon :name="$file->icon()" class="size-6" /></span>
            <div class="min-w-0">
                <h1 class="text-xl font-semibold break-words sm:text-2xl">{{ $file->fileName() }}</h1>
                <p class="text-sm text-fg-muted">{{ $file->typeLabel() }} · {{ $file->humanSize() }} · uploaded {{ Carbon::parse($file->uploadedAt)->format('j M Y') }}</p>
            </div>
        </div>

        @if ($file->trashedAt !== null)
            <x-alert tone="warning" title="This file is in the trash">
                It can't be opened while it's there. Restore it to use it again; otherwise it's deleted 30 days after it was trashed.
            </x-alert>
        @elseif ($file->kind === 'pdf')
            <iframe class="file-preview" src="{{ $content }}" title="{{ $file->fileName() }}"></iframe>
        @elseif ($file->kind === 'image')
            <div class="file-preview file-preview-image">
                <img src="{{ $content }}" alt="{{ $file->fileName() }}">
            </div>
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
</x-layouts.app>
