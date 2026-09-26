<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Study\Files;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The file page's actions: move the file to the trash, or restore it. The file's ID is locked. */
final class FileActions extends Component
{
    #[Locked]
    public string $fileId;

    private Files $files;

    private PrincipalFactory $principals;

    public function boot(Files $files, PrincipalFactory $principals): void
    {
        $this->files = $files;
        $this->principals = $principals;
    }

    public function trash(): void
    {
        $by = $this->principals->fromRequest(request());
        $file = $this->files->find($by, $this->fileId);
        $this->files->trash($by, $file->id);

        session()->flash('workspace-notice', "“{$file->fileName()}” is in the trash. You can restore it there for ".Files::TRASH_DAYS.' days.');
        $this->redirectRoute('workspaces.show', [$file->workspaceId, 'notes']);
    }

    public function restore(): void
    {
        $file = $this->files->restore($this->principals->fromRequest(request()), $this->fileId);

        $this->redirectRoute('workspaces.files.show', [$file->workspaceId, $file->id]);
    }

    public function render(): View
    {
        return view('livewire.workspaces.file-actions', [
            'file' => $this->files->find($this->principals->fromRequest(request()), $this->fileId),
        ]);
    }
}
