{{--
    "Read by the AI" on a file's page (App\Livewire\Workspaces\FileDigest). Nothing for a picture (it is never sent) or a
    file in the trash. Asks again every few seconds only while it is being read.
--}}
<div @if ($state === 'reading') wire:poll.3s @endif>
    @if ($file->trashedAt === null && $file->kind !== 'image')
        <section class="digest-panel" aria-labelledby="file-digest-title">
            <h2 id="file-digest-title" class="digest-panel-title">Read by the AI</h2>
            @if ($state === 'read' && $digest)
                <p>{{ $digest->summary }}</p>
                @if ($digest->topics !== [])
                    <p class="text-fg-muted">{{ implode(' · ', $digest->topics) }}</p>
                @endif
                @if ($digest->outline !== [])
                    <details class="digest-outline">
                        <summary>Outline</summary>
                        <ol>
                            @foreach ($digest->outline as $line)
                                <li>{{ $line['heading'] }} <span class="text-fg-subtle">· {{ $line['page'] }}</span></li>
                            @endforeach
                        </ol>
                    </details>
                @endif
                <p class="text-fg-subtle text-xs">Written from the file as it is now. Change the file and it is read again.</p>
            @elseif ($state === 'reading')
                <p role="status" class="inline-flex items-center gap-2 text-fg-muted"><x-icon name="loader-circle" class="size-4 animate-spin" />Reading…</p>
            @elseif ($state === 'skipped')
                <p class="text-fg-muted">There are no words in this file to read.</p>
            @else
                <p class="text-fg-muted">The AI hasn't read this file yet. Reading lets it find what the file covers and suggest topics for the module.</p>
                <x-button icon="book-open" wire:click="readNow" wire:loading.attr="aria-busy" wire:target="readNow" busy-label="Starting…">Read now</x-button>
            @endif
        </section>
    @endif
    <x-toast :message="$notice" :tone="$noticeTone" :action-label="$noticeActionLabel" :action-event="$noticeActionEvent" :action-payload="$noticeActionPayload" />
</div>
