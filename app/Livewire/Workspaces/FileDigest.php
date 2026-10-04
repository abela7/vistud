<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Errors\AppError;
use App\Study\FileDigests;
use App\Study\FileReading;
use App\Study\Files;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Read by the AI" on a file's page (docs/specs/vistud-2-blueprint.md §3.5.3): the summary, the topics and the outline the
 * reader wrote for the file as it is now; "Reading…" while a run is going (the block asks again every few seconds, and only
 * then); or *Read now* when it hasn't been read. A picture is never sent, so it has no block. The file's id is locked.
 */
final class FileDigest extends Component
{
    use Notices;

    #[Locked]
    public string $fileId;

    private Files $files;

    private FileDigests $digests;

    private FileReading $reading;

    private PrincipalFactory $principals;

    public function boot(Files $files, FileDigests $digests, FileReading $reading, PrincipalFactory $principals): void
    {
        $this->files = $files;
        $this->digests = $digests;
        $this->reading = $reading;
        $this->principals = $principals;
    }

    public function readNow(): void
    {
        try {
            $this->reading->readNow($this->principals->fromRequest(request()), $this->fileId);
        } catch (AppError $e) {
            $this->notify($e->getMessage(), 'warning');
        }
    }

    public function render(): View
    {
        $by = $this->principals->fromRequest(request());
        $file = $this->files->find($by, $this->fileId);
        $read = $this->digests->states($by, [$file])[$file->id] ?? ['state' => 'unread', 'digest' => null];

        return view('livewire.workspaces.file-digest', ['file' => $file, 'state' => $read['state'], 'digest' => $read['digest']]);
    }
}
