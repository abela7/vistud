<?php

namespace App\Jobs;

use App\Study\FilePreviews;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes the PDF that shows a Word, PowerPoint or Excel file in the browser (App\Study\FilePreviews), on the queue's
 * worker and never inside a request (Gemini's report, 2026-10-03: a deck being converted held the only PHP process,
 * and Back waited). Queued when such a file is uploaded, and when its page asks for a preview that isn't made yet;
 * one job for a file at a time. When every conversion slot is taken, or LibreOffice can't be run at all, it comes
 * back a little later, for up to ten minutes; the page keeps asking while it waits. It carries only where the
 * file's bytes are kept: whoever queued it was allowed to see the file.
 */
final class MakeFilePreview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** LibreOffice gets five minutes for a file (FilePreviews::PREVIEW_SECONDS); the worker waits a little longer. */
    public int $timeout = 360;

    /** If a worker dies holding it, the file can be queued again after this long. */
    public int $uniqueFor = 900;

    public function __construct(public string $fileId, public string $storageKey, public string $extension) {}

    public function uniqueId(): string
    {
        return $this->fileId;
    }

    /** Tried again for as long as it can't be made yet, up to ten minutes. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(): void
    {
        $wait = FilePreviews::make($this->fileId, $this->storageKey, $this->extension);
        if ($wait !== null) {
            $this->release($wait);
        }
    }
}
