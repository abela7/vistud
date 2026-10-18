<?php

namespace App\Jobs;

use App\Study\FileTexts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a big PDF's text for the tutor on the queue's worker, never inside a request (docs/specs/mcp-connections-plan.md
 * M6.2): an AI app's call has 240 seconds, and the in-app chat waits for nobody. Queued by App\Study\FileTexts::of() the
 * first time a PDF over FileTexts::INLINE_PDF_BYTES is asked for; one job for a file at a time. It carries only where
 * the file's bytes are kept: whoever queued it was allowed to read the file.
 */
final class ExtractFileText implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A long PDF gets five minutes. */
    public int $timeout = 300;

    /** If a worker dies holding it, the file can be queued again after this long. */
    public int $uniqueFor = 600;

    public function __construct(public string $fileId, public string $storageKey) {}

    public function uniqueId(): string
    {
        return $this->fileId;
    }

    public function handle(): void
    {
        FileTexts::extractPdf($this->storageKey);
    }
}
