<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Livewire\Study\PinnedNotes;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\LinkDetails;
use App\Study\Links;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What a workspace holds (docs/specs/workspaces.md, steps 2 and 3), as places
 * you open: `modules` (the modules as cards), `module` and `folder` (one
 * place: its folders, then its notes, files and links; a module also lists
 * its study sessions) and `notes` (Notes & files: what sits outside every
 * module, and the trash). One dialog serves every form. The services check
 * everything; the place and the IDs the dialog acts on are locked.
 */
final class Contents extends Component
{
    use BulkActions, Notices;

    /** What the last bulk trash took, for its Undo. Only the server sets it. @var list<string> */
    #[Locked]
    public array $lastTrashed = [];

    #[Locked]
    public string $workspaceId;

    /** modules, notes, module or folder: which page this is. */
    #[Locked]
    public string $view = 'modules';

    /** The module or folder a `module` or `folder` page shows. */
    #[Locked]
    public ?string $placeId = null;

    /** module · folder · file (renaming) · link · instructions · upload · move · delete, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public bool $creating = false;

    /** module, folder, note, file or link: what the dialog acts on, or (creating a folder or link, uploading) where it goes. */
    #[Locked]
    public ?string $targetType = null;

    #[Locked]
    public ?string $targetId = null;

    /** Notes & files: the trash is showing. */
    #[Locked]
    public bool $showTrash = false;

    public string $title = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $name = '';

    /** A topic to add to the module, from the Topics list on its page. */
    public string $topicName = '';

    public string $url = '';

    /** A module's instructions for the assistant. */
    public string $instructions = '';

    /** Where a moved folder, note or file goes: workspace:{id}, module:{id} or folder:{id}. */
    public string $destination = '';

    #[Locked]
    public ?string $error = null;

    private Modules $modules;

    private Folders $folders;

    private Notes $notes;

    private Files $files;

    private Links $links;

    private Instructions $instructionTexts;

    private Workspaces $workspaces;

    private Topics $topics;

    private Questions $questions;

    private Sessions $sessions;

    private Flashcards $flashcards;

    private PrincipalFactory $principals;

    public function boot(Modules $modules, Folders $folders, Notes $notes, Files $files, Links $links, Instructions $instructions, Workspaces $workspaces, Topics $topics, Sessions $sessions, Questions $questions, Flashcards $flashcards, PrincipalFactory $principals): void
    {
        $this->topics = $topics;
        $this->flashcards = $flashcards;
        $this->questions = $questions;
        $this->sessions = $sessions;
        $this->links = $links;
        $this->instructionTexts = $instructions;
        $this->modules = $modules;
        $this->folders = $folders;
        $this->notes = $notes;
        $this->files = $files;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $view = 'modules', ?string $placeId = null): void
    {
        $this->workspaceId = $workspaceId;
        $this->view = in_array($view, ['notes', 'module', 'folder'], true) ? $view : 'modules';
        if (in_array($this->view, ['module', 'folder'], true)) {
            $place = $this->view === 'module' ? $this->modules->find($this->principal(), (string) $placeId) : $this->folders->find($this->principal(), (string) $placeId);
            $place->workspaceId === $workspaceId || throw new NotFound;
            $this->placeId = $place->id;
        }
    }

    /** "Study this": the start dialog (App\Livewire\Workspaces\StudyTime), in this module. */
    public function studyHere(): void
    {
        $this->dispatch('study-start', moduleId: $this->placeModuleId());
    }

    /** Studies one of the module's topics: the start panel, with the module and the topic chosen. */
    public function studyTopic(string $topicId): void
    {
        $this->dispatch('study-start', moduleId: $this->placeModuleId(), topicId: $topicId);
    }

    /** Adds a topic to the module (a name the course has already is left as it is). */
    public function addTopic(): void
    {
        $this->resetErrorBag('topicName');
        if ($this->view !== 'module' || $this->placeId === null) {
            return;
        }
        $by = $this->principal();
        $name = trim($this->topicName);
        if (collect($this->topics->list($by, $this->workspaceId))->contains(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name))) {
            $this->addError('topicName', 'The course has a topic with that name already.');

            return;
        }
        try {
            $topic = $this->topics->create($by, $this->workspaceId, $name, $this->placeId);
        } catch (Unprocessable $e) {
            $fields = $e->details['fields'] ?? [];
            $this->addError('topicName', $fields === [] ? $e->getMessage() : reset($fields)[0]);

            return;
        }
        $this->topicName = '';
        $this->notify("“{$topic->name}” is added.");
    }

    // ---------- Opening the dialog ----------

    public function newModule(): void
    {
        $this->open('module', creating: true);
    }

    public function editModule(string $id): void
    {
        $module = $this->modules->find($this->principal(), $id);
        $this->open('module', targetType: 'module', targetId: $module->id);
        [$this->title, $this->startsOn, $this->endsOn] = [$module->title, (string) $module->startsOn, (string) $module->endsOn];
    }

    /** A new folder at the top level (`workspace`), in a module or in a folder. */
    public function newFolder(string $parentType, string $parentId): void
    {
        $this->open('folder', creating: true, targetType: in_array($parentType, ['workspace', 'module', 'folder'], true) ? $parentType : 'module', targetId: $parentId);
    }

    public function renameFolder(string $id): void
    {
        $folder = $this->folders->find($this->principal(), $id);
        $this->open('folder', targetType: 'folder', targetId: $folder->id);
        $this->name = $folder->name;
    }

    public function moveFolder(string $id): void
    {
        $folder = $this->folders->find($this->principal(), $id);
        $this->open('move', targetType: 'folder', targetId: $folder->id);
        $this->destination = $folder->parentId !== null ? "folder:{$folder->parentId}" : $this->placeValue($folder->moduleId, null);
    }

    public function moveNote(string $id): void
    {
        $note = $this->notes->find($this->principal(), $id);
        $this->open('move', targetType: 'note', targetId: $note->id);
        $this->destination = $this->placeValue($note->moduleId, $note->folderId);
    }

    public function moveFile(string $id): void
    {
        $file = $this->files->find($this->principal(), $id);
        $this->open('move', targetType: 'file', targetId: $file->id);
        $this->destination = $this->placeValue($file->moduleId, $file->folderId);
    }

    public function renameFile(string $id): void
    {
        $file = $this->files->find($this->principal(), $id);
        $this->open('file', targetType: 'file', targetId: $file->id);
        $this->name = $file->name;
    }

    /** A web link at the top level (`workspace`), in a module or in a folder. */
    public function newLink(string $placeType, string $placeId): void
    {
        $this->open('link', creating: true, targetType: in_array($placeType, ['workspace', 'module', 'folder'], true) ? $placeType : 'module', targetId: $placeId);
    }

    public function editLink(string $id): void
    {
        $link = $this->links->find($this->principal(), $id);
        $this->open('link', targetType: 'link', targetId: $link->id);
        [$this->name, $this->url] = [$link->title, $link->url];
    }

    public function moveLink(string $id): void
    {
        $link = $this->links->find($this->principal(), $id);
        $this->open('move', targetType: 'link', targetId: $link->id);
        $this->destination = $this->placeValue($link->moduleId, $link->folderId);
    }

    /** What the assistant should know when studying this module. */
    public function editInstructions(string $moduleId): void
    {
        $module = $this->modules->find($this->principal(), $moduleId);
        $this->open('instructions', targetType: 'module', targetId: $module->id);
        $this->instructions = $this->instructionTexts->get($this->principal(), "module:{$module->id}");
    }

    /**
     * The upload dialog sent its files (POST /api/v1/files, one at a time, from resources/js/uploader.js): the list
     * is drawn again with them, and with none refused the dialog closes and says how many.
     */
    public function uploadsFinished(int $uploaded, int $refused): void
    {
        if ($uploaded > 0) {
            $this->notify(($uploaded === 1 ? '1 file' : "{$uploaded} files").' uploaded.');
        }
        if ($refused === 0) {
            $this->close();
            $this->dispatch('structure-dialog-close');
        }
    }

    /** Upload files to the top level (`workspace`), a module or a folder. */
    public function uploadFiles(string $placeType, string $placeId): void
    {
        $this->open('upload', creating: true, targetType: in_array($placeType, ['workspace', 'module', 'folder'], true) ? $placeType : 'module', targetId: $placeId);
    }

    public function confirmDelete(string $type, string $id): void
    {
        $this->open('delete', targetType: in_array($type, ['folder', 'note', 'file', 'link'], true) ? $type : 'module', targetId: $id);
    }

    // ---------- Notes, straight away ----------

    /** The editor for a new note in this place: nothing is kept until it has a title or some text. */
    public function newNote(string $placeType, string $placeId): void
    {
        $placeType = in_array($placeType, ['module', 'folder'], true) ? $placeType : 'workspace';
        $this->redirectRoute('workspaces.notes.create', [$this->workspaceId] + ($placeType === 'workspace' ? [] : ['in' => "{$placeType}:{$placeId}"]), navigate: true);
    }

    /** Into the trash: nothing to confirm, it can be restored. */
    public function trashNote(string $id): void
    {
        $by = $this->principal();
        $note = $this->notes->find($by, $id);
        $this->notes->trash($by, $note->id);
        $this->notice = "“{$note->displayTitle()}” is in the trash.";
    }

    /** Pinned or not: a button for the note in the corner of every page (App\Livewire\Study\PinnedNotes). */
    public function pinNote(string $id): void
    {
        $by = $this->principal();
        try {
            $note = $this->notes->pin($by, $id);
            $this->notice = "“{$note->displayTitle()}” is pinned. Its button is in the corner of every page.";
        } catch (Conflict $full) {
            $this->notify($full->getMessage(), 'info');
        }
        $this->dispatch('pins-changed')->to(PinnedNotes::class);
    }

    public function unpinNote(string $id): void
    {
        $note = $this->notes->unpin($this->principal(), $id);
        $this->notice = "“{$note->displayTitle()}” is unpinned.";
        $this->dispatch('pins-changed')->to(PinnedNotes::class);
    }

    /** A note was unpinned from the corner: each row's menu says Pin or Unpin again. */
    #[On('pins-changed')]
    public function pinsChanged(): void {}

    public function restoreNote(string $id): void
    {
        $note = $this->notes->restore($this->principal(), $id);
        $this->notice = "“{$note->displayTitle()}” is restored.";
    }

    public function trashFile(string $id): void
    {
        $by = $this->principal();
        $file = $this->files->find($by, $id);
        $this->files->trash($by, $file->id);
        $this->notice = "“{$file->fileName()}” is in the trash.";
    }

    public function restoreFile(string $id): void
    {
        $file = $this->files->restore($this->principal(), $id);
        $this->notice = "“{$file->fileName()}” is restored.";
    }

    public function toggleTrash(): void
    {
        $this->showTrash = ! $this->showTrash;
        $this->dispatch('selection-clear');
    }

    public function openBulkMove(array $keys): void
    {
        $this->open('bulk-move', creating: false, targetType: 'bulk');
        $this->bulkKeys = $keys;
        $this->destination = "workspace:{$this->workspaceId}";
    }

    public function openBulkDelete(array $keys): void
    {
        $this->open('bulk-delete', creating: false, targetType: 'bulk');
        $this->bulkKeys = $keys;
    }

    public function openBulkDestroy(array $keys): void
    {
        $this->open('bulk-destroy', creating: false, targetType: 'bulk');
        $this->bulkKeys = $keys;
    }

    #[On('bulk-undo')]
    public function undoTrash(): void
    {
        if ($this->lastTrashed === []) {
            return;
        }
        $by = $this->principal();
        $restored = 0;
        foreach ($this->lastTrashed as $key) {
            [$type, $id] = explode(':', $key, 2);
            try {
                if ($type === 'note') {
                    $this->notes->restore($by, $id);
                    $restored++;
                } elseif ($type === 'file') {
                    $this->files->restore($by, $id);
                    $restored++;
                }
            } catch (NotFound) {
                // Gone or already restored
            }
        }
        $this->lastTrashed = [];
        $this->notify($restored === 1 ? '1 item is restored.' : "{$restored} items are restored.");
    }

    protected function allowedBulkTypes(): array
    {
        return ['folder', 'note', 'file', 'link', 'module'];
    }

    protected function performBulkAction(string $action, array $items, array $payload): void
    {
        $by = $this->principal();
        $done = 0;
        $skipped = [];

        if ($action === 'trash') {
            $trashedKeys = [];
            foreach ($items as [$type, $id, $key]) {
                try {
                    if ($type === 'note') {
                        $this->notes->trash($by, $id);
                        $trashedKeys[] = $key;
                        $done++;
                    } elseif ($type === 'file') {
                        $this->files->trash($by, $id);
                        $trashedKeys[] = $key;
                        $done++;
                    }
                } catch (NotFound) {
                    // Ignored (other student or missing)
                }
            }
            $this->lastTrashed = $trashedKeys;
            $msg = $done === 1 ? '1 item moved to the trash.' : "{$done} items moved to the trash.";
            $this->notify($msg, 'success', 'Undo', 'bulk-undo');

            return;
        }

        if ($action === 'restore') {
            foreach ($items as [$type, $id]) {
                try {
                    if ($type === 'note') {
                        $this->notes->restore($by, $id);
                        $done++;
                    } elseif ($type === 'file') {
                        $this->files->restore($by, $id);
                        $done++;
                    }
                } catch (NotFound) {
                    // Ignored
                }
            }
            $msg = $done === 1 ? '1 item is restored.' : "{$done} items are restored.";
            $this->notify($msg, 'success');

            return;
        }

        if ($action === 'destroy') {
            foreach ($items as [$type, $id]) {
                try {
                    if ($type === 'note') {
                        $this->notes->destroy($by, $id);
                        $done++;
                    } elseif ($type === 'file') {
                        $this->files->destroy($by, $id);
                        $done++;
                    }
                } catch (NotFound) {
                    // Ignored
                }
            }
            $msg = $done === 1 ? '1 item is deleted.' : "{$done} items are deleted.";
            $this->notify($msg, 'success');

            return;
        }

        if ($action === 'delete') {
            foreach ($items as [$type, $id]) {
                try {
                    if ($type === 'folder') {
                        $this->folders->delete($by, $id);
                        $done++;
                    } elseif ($type === 'link') {
                        $this->links->delete($by, $id);
                        $done++;
                    } elseif ($type === 'note') {
                        $this->notes->destroy($by, $id);
                        $done++;
                    } elseif ($type === 'file') {
                        $this->files->destroy($by, $id);
                        $done++;
                    } elseif ($type === 'module') {
                        $this->modules->delete($by, $id);
                        $done++;
                    }
                } catch (Conflict) {
                    // A folder or module with things in it, or a note or file that isn't in the trash.
                    try {
                        $skipped[] = match ($type) {
                            'folder' => "1 skipped: “{$this->folders->find($by, $id)->name}” isn't empty.",
                            'module' => "1 skipped: “{$this->modules->find($by, $id)->title}” isn't empty.",
                            'note' => "1 skipped: “{$this->notes->find($by, $id)->displayTitle()}” isn't in the trash.",
                            'file' => "1 skipped: “{$this->files->find($by, $id)->fileName()}” isn't in the trash.",
                            default => '1 skipped.',
                        };
                    } catch (NotFound) {
                        $skipped[] = '1 skipped.';
                    }
                } catch (NotFound) {
                    // Ignored
                }
            }
            $parts = [];
            if ($done > 0) {
                $parts[] = $done === 1 ? '1 item is deleted.' : "{$done} items are deleted.";
            }
            if ($skipped !== []) {
                $parts[] = implode(' ', $skipped);
            }
            $this->notify(implode(' ', $parts) ?: 'Nothing was deleted.', 'success');

            return;
        }

        if ($action === 'move') {
            $destination = (string) ($payload['destination'] ?? '');
            [$destType, $destId] = array_pad(explode(':', $destination, 2), 2, '');
            foreach ($items as [$type, $id]) {
                try {
                    if ($type === 'folder') {
                        $this->folders->move($by, $id, $destType, $destId);
                        $done++;
                    } elseif ($type === 'note') {
                        $this->notes->move($by, $id, $destType, $destId);
                        $done++;
                    } elseif ($type === 'file') {
                        $this->files->move($by, $id, $destType, $destId);
                        $done++;
                    } elseif ($type === 'link') {
                        $this->links->move($by, $id, $destType, $destId);
                        $done++;
                    }
                } catch (Conflict) {
                    try {
                        $name = match ($type) {
                            'folder' => $this->folders->find($by, $id)->name,
                            'note' => $this->notes->find($by, $id)->displayTitle(),
                            'file' => $this->files->find($by, $id)->fileName(),
                            'link' => $this->links->find($by, $id)->title,
                            default => 'Item',
                        };
                        $skipped[] = "1 skipped: “{$name}” can't be moved there.";
                    } catch (NotFound) {
                        $skipped[] = "1 skipped: can't be moved there.";
                    }
                } catch (NotFound) {
                    // Ignored
                }
            }
            $parts = [];
            if ($done > 0) {
                $parts[] = $done === 1 ? '1 item is moved.' : "{$done} items are moved.";
            }
            if ($skipped !== []) {
                $parts[] = implode(' ', $skipped);
            }
            $this->notify(implode(' ', $parts) ?: 'Nothing was moved.', 'success');

            return;
        }
    }

    // ---------- Saving ----------

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();
        $this->error = null;
        // Deleting the page's own module or folder leaves for the place around it.
        $leaving = $this->mode === 'delete' && $this->placeId !== null && $this->targetId === $this->placeId ? $this->parentUrl() : null;

        if ($this->mode === 'bulk-move') {
            $this->bulk('move', $this->bulkKeys, ['destination' => $this->destination]);
            $this->close();
            $this->dispatch('structure-dialog-close');
            $this->dispatch('selection-clear');

            return;
        }
        if ($this->mode === 'bulk-delete') {
            $this->bulk('delete', $this->bulkKeys);
            $this->close();
            $this->dispatch('structure-dialog-close');
            $this->dispatch('selection-clear');

            return;
        }
        if ($this->mode === 'bulk-destroy') {
            $this->bulk('destroy', $this->bulkKeys);
            $this->close();
            $this->dispatch('structure-dialog-close');
            $this->dispatch('selection-clear');

            return;
        }

        try {
            $this->notice = match ([$this->mode, $this->creating]) {
                ['module', true] => $this->modules->create($by, $this->workspaceId, $this->moduleInput())->title.' is added.',
                ['module', false] => $this->modules->update($by, (string) $this->targetId, $this->moduleInput())->title.' is saved.',
                ['folder', true] => $this->folders->create($by, (string) $this->targetType, (string) $this->targetId, $this->name)->name.' is added.',
                ['folder', false] => $this->renamed($by),
                ['file', false] => $this->renamedFile($by),
                ['link', true] => '“'.$this->links->add($by, (string) $this->targetType, (string) $this->targetId, ['title' => $this->name, 'url' => $this->url])->title.'” is added.',
                ['link', false] => '“'.$this->links->update($by, (string) $this->targetId, ['title' => $this->name, 'url' => $this->url])->title.'” is saved.',
                ['instructions', false] => $this->savedInstructions($by),
                ['move', false] => $this->moved($by),
                ['delete', false] => $this->deleted($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['starts_on' => 'startsOn', 'ends_on' => 'endsOn', 'title' => $this->mode === 'link' ? 'name' : 'title', 'text' => 'instructions'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (Conflict $e) {
            $this->error = $e->getMessage();

            return;
        } catch (NotFound) {
            $this->error = 'That no longer exists. Close this and try again.';

            return;
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($leaving !== null) {
            session()->flash('workspace-notice', $this->notice);
            $this->redirect($leaving, navigate: true);

            return;
        }

        $this->close();
        $this->dispatch('structure-dialog-close');
    }

    /** The dialog closed. */
    public function close(): void
    {
        $this->reset('mode', 'creating', 'targetType', 'targetId', 'title', 'startsOn', 'endsOn', 'name', 'url', 'instructions', 'destination', 'error', 'bulkKeys');
        $this->resetErrorBag();
    }

    // ---------- Ordering ----------

    /** Drag and drop (wire:sort): the module moved to $position. */
    public function sortModules(string $item, int $position): void
    {
        $this->modules->move($this->principal(), $item, $position);
    }

    public function moveModuleBy(string $id, int $step): void
    {
        $ids = array_map(fn ($m) => $m->id, $this->modules->list($this->principal(), $this->workspaceId));
        $index = array_search($id, $ids, true);
        if ($index !== false) {
            $this->modules->move($this->principal(), $id, max(0, $index + $step));
        }
    }

    public function moveFolderBy(string $id, int $step): void
    {
        $folder = $this->folders->find($this->principal(), $id);
        $siblings = array_values(array_filter(
            $this->folders->tree($this->principal(), $this->workspaceId),
            fn (FolderDetails $f) => $f->siblingsKey() === $folder->siblingsKey(),
        ));
        $index = array_search($id, array_map(fn ($f) => $f->id, $siblings), true);
        if ($index !== false) {
            $this->folders->reorder($this->principal(), $id, max(0, $index + $step));
        }
    }

    public function render(): View
    {
        $by = $this->principal();
        $workspace = $this->workspaces->find($by, $this->workspaceId);
        $modules = $this->modules->list($by, $this->workspaceId);
        $folders = $this->folders->tree($by, $this->workspaceId);
        $notes = $this->notes->list($by, $this->workspaceId);
        $files = $this->files->list($by, $this->workspaceId);
        $links = $this->links->list($by, $this->workspaceId);

        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->siblingsKey()][] = $folder;
        }
        $notesIn = [];
        foreach ($notes as $note) {
            $notesIn[$note->placeKey()][] = $note;
        }
        $filesIn = [];
        foreach ($files as $file) {
            $filesIn[$file->placeKey()][] = $file;
        }
        $linksIn = [];
        foreach ($links as $link) {
            $linksIn[$link->placeKey()][] = $link;
        }
        $counts = [];
        foreach ([...$folders, ...$notes, ...$files, ...$links] as $item) {
            if ($item->moduleId !== null) {
                $kind = match (true) {
                    $item instanceof NoteDetails => 'notes',
                    $item instanceof FileDetails => 'files',
                    $item instanceof LinkDetails => 'links',
                    default => 'folders',
                };
                $counts[$item->moduleId][$kind] = ($counts[$item->moduleId][$kind] ?? 0) + 1;
            }
        }

        $data = [
            'workspace' => $workspace,
            'modules' => $modules,
            'children' => $children,
            'notesIn' => $notesIn,
            'filesIn' => $filesIn,
            'linksIn' => $linksIn,
            'maxUpload' => Files::maxBytes(),
            'counts' => $counts,
            'target' => $this->targetName($modules, $folders, $notes, $files),
            'moveOptions' => in_array($this->mode, ['move', 'bulk-move'], true) ? $this->moveOptions($workspace->name, $modules, $folders) : [],
            'bulkKeys' => $this->bulkKeys,
        ];

        if ($this->view === 'modules') {
            $data += ['progress' => $this->moduleProgress($by)];
        }
        if (in_array($this->view, ['module', 'folder'], true)) {
            $data += $this->placeData($by, $modules, $folders);
        }
        if (in_array($this->view, ['module', 'folder', 'notes'], true)) {
            $data['itemCounts'] = $this->itemCounts($folders, $notes, $files, $links);
        }

        if ($this->view === 'notes') {
            $recent = $notes;
            usort($recent, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
            $data += [
                'noteCount' => count($notes),
                'fileCount' => count($files),
                'linkCount' => count($links),
                'recent' => array_slice($recent, 0, 5),
                'places' => $this->placeNames($modules, $folders),
                'trash' => $this->notes->trashed($by, $this->workspaceId),
                'trashedFiles' => $this->files->trashed($by, $this->workspaceId),
            ];
        }

        return view(in_array($this->view, ['module', 'folder'], true) ? 'livewire.workspaces.place-page' : "livewire.workspaces.{$this->view}", $data);
    }

    // ---------- Helpers ----------

    /** The module this page is in: the module itself, or the folder's. */
    private function placeModuleId(): ?string
    {
        return match ($this->view) {
            'module' => $this->placeId,
            'folder' => $this->folders->find($this->principal(), (string) $this->placeId)->moduleId,
            default => null,
        };
    }

    /** Where to go when this page's own module or folder is deleted. */
    private function parentUrl(): string
    {
        if ($this->view === 'module') {
            return route('workspaces.show', [$this->workspaceId, 'modules']);
        }
        $folder = $this->folders->find($this->principal(), (string) $this->placeId);

        return match (true) {
            $folder->parentId !== null => route('workspaces.folders.show', [$this->workspaceId, $folder->parentId]),
            $folder->moduleId !== null => route('workspaces.modules.show', [$this->workspaceId, $folder->moduleId]),
            default => route('workspaces.show', [$this->workspaceId, 'notes']),
        };
    }

    /**
     * A module or folder page: the place, the path to it (each [label, url]),
     * its key, and for a module its study sessions.
     */
    private function placeData(Principal $by, array $modules, array $folders): array
    {
        $byId = collect($folders)->keyBy('id');
        if ($this->view === 'module') {
            $module = collect($modules)->firstWhere('id', $this->placeId) ?? throw new NotFound;
            $trail = [[__('Modules'), route('workspaces.show', [$this->workspaceId, 'modules'])]];

            $topicNames = collect($this->topics->list($by, $this->workspaceId))->pluck('name', 'id')->all();

            // The module's questions have their own page; here, how many are open and stuck.
            $asked = $this->questions->list($by, $this->workspaceId, $module->id);
            $questions = [
                'open' => count(array_filter($asked, fn ($q) => $q->status !== 'answered')),
                'stuck' => count(array_filter($asked, fn ($q) => $q->status === 'stuck')),
            ];

            return [
                'place' => $module, 'placeName' => $module->title, 'key' => "module:{$module->id}", 'trail' => $trail,
                'topicNames' => $topicNames, 'questions' => $questions,
                'sessionsCount' => count($this->sessions->forModule($by, $this->workspaceId, $module->id)),
                // The module's flashcards are in the deck, shown by module: how many, and how many are due.
                'cards' => $cards = $this->flashcards->counts($by, $this->workspaceId, null, $module->id),
                // Its topics, in order, each with its status and its cards.
                'moduleTopics' => array_values(array_filter($this->topics->list($by, $this->workspaceId), fn ($t) => $t->moduleId === $module->id)),
                'topicCards' => $cards['topics'],
            ];
        }

        $folder = $byId[$this->placeId] ?? throw new NotFound;
        $trail = [];
        for ($parent = $folder->parentId; $parent !== null && isset($byId[$parent]); $parent = $byId[$parent]->parentId) {
            array_unshift($trail, [$byId[$parent]->name, route('workspaces.folders.show', [$this->workspaceId, $parent])]);
        }
        $module = $folder->moduleId !== null ? collect($modules)->firstWhere('id', $folder->moduleId) : null;
        array_unshift($trail, ...($module !== null
            ? [[__('Modules'), route('workspaces.show', [$this->workspaceId, 'modules'])], [$module->title, route('workspaces.modules.show', [$this->workspaceId, $module->id])]]
            : [[__('Notes & files'), route('workspaces.show', [$this->workspaceId, 'notes'])]]));

        return ['place' => $folder, 'placeName' => $folder->name, 'key' => "folder:{$folder->id}", 'trail' => $trail, 'topicNames' => [], 'questions' => null, 'sessionsCount' => 0, 'cards' => null, 'moduleTopics' => [], 'topicCards' => []];
    }

    /** @return array<string, array{done: int, total: int}> module id => its topics understood (or mastered), of all */
    private function moduleProgress(Principal $by): array
    {
        $progress = [];
        foreach ($this->topics->list($by, $this->workspaceId) as $topic) {
            if ($topic->moduleId !== null) {
                $progress[$topic->moduleId]['total'] = ($progress[$topic->moduleId]['total'] ?? 0) + 1;
                $progress[$topic->moduleId]['done'] = ($progress[$topic->moduleId]['done'] ?? 0) + (int) in_array($topic->shown(), ['understood', 'mastered'], true);
            }
        }

        return $progress;
    }

    /** @return array<string, int> folder id => how many things are directly inside it */
    private function itemCounts(array $folders, array $notes, array $files, array $links): array
    {
        $counts = [];
        foreach ([...$notes, ...$files, ...$links] as $item) {
            if ($item->folderId !== null) {
                $counts[$item->folderId] = ($counts[$item->folderId] ?? 0) + 1;
            }
        }
        foreach ($folders as $folder) {
            if ($folder->parentId !== null) {
                $counts[$folder->parentId] = ($counts[$folder->parentId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function open(string $mode, bool $creating = false, ?string $targetType = null, ?string $targetId = null): void
    {
        $this->close();
        [$this->mode, $this->creating, $this->targetType, $this->targetId] = [$mode, $creating, $targetType, $targetId];
        $this->dispatch('structure-dialog-open');
    }

    private function moduleInput(): array
    {
        return ['title' => $this->title, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn];
    }

    private function placeValue(?string $moduleId, ?string $folderId): string
    {
        return match (true) {
            $folderId !== null => "folder:{$folderId}",
            $moduleId !== null => "module:{$moduleId}",
            default => "workspace:{$this->workspaceId}",
        };
    }

    private function renamed(Principal $by): string
    {
        $this->folders->rename($by, (string) $this->targetId, $this->name);

        return $this->folders->find($by, (string) $this->targetId)->name.' is renamed.';
    }

    private function renamedFile(Principal $by): string
    {
        $this->files->rename($by, (string) $this->targetId, $this->name);

        return '“'.$this->files->find($by, (string) $this->targetId)->fileName().'” is renamed.';
    }

    private function savedInstructions(Principal $by): string
    {
        $this->instructionTexts->set($by, "module:{$this->targetId}", $this->instructions);

        return 'The instructions for '.$this->modules->find($by, (string) $this->targetId)->title.' are saved.';
    }

    private function moved(Principal $by): string
    {
        [$type, $id] = array_pad(explode(':', $this->destination, 2), 2, '');
        if ($this->targetType === 'file') {
            $this->files->move($by, (string) $this->targetId, $type, $id);

            return '“'.$this->files->find($by, (string) $this->targetId)->fileName().'” is moved.';
        }
        if ($this->targetType === 'link') {
            $this->links->move($by, (string) $this->targetId, $type, $id);

            return '“'.$this->links->find($by, (string) $this->targetId)->title.'” is moved.';
        }
        if ($this->targetType === 'note') {
            $this->notes->move($by, (string) $this->targetId, $type, $id);

            return '“'.$this->notes->find($by, (string) $this->targetId)->displayTitle().'” is moved.';
        }
        $this->folders->move($by, (string) $this->targetId, $type, $id);

        return $this->folders->find($by, (string) $this->targetId)->name.' is moved.';
    }

    private function deleted(Principal $by): string
    {
        $id = (string) $this->targetId;
        [$name, $delete] = match ($this->targetType) {
            'folder' => [$this->folders->find($by, $id)->name, fn () => $this->folders->delete($by, $id)],
            'note' => ['“'.$this->notes->find($by, $id)->displayTitle().'”', fn () => $this->notes->destroy($by, $id)],
            'file' => ['“'.$this->files->find($by, $id)->fileName().'”', fn () => $this->files->destroy($by, $id)],
            'link' => ['“'.$this->links->find($by, $id)->title.'”', fn () => $this->links->delete($by, $id)],
            default => [$this->modules->find($by, $id)->title, fn () => $this->modules->delete($by, $id)],
        };
        $delete();

        return "{$name} is deleted.";
    }

    /** The name of what the dialog is about, for its heading. */
    private function targetName(array $modules, array $folders, array $notes, array $files): ?string
    {
        if ($this->targetId === null) {
            return null;
        }
        if ($this->targetType === 'file') {
            return $this->files->find($this->principal(), $this->targetId)->fileName();
        }
        if ($this->targetType === 'link') {
            return $this->links->find($this->principal(), $this->targetId)->title;
        }
        if ($this->targetType === 'workspace') {
            return 'the top level';
        }
        if ($this->targetType === 'note') {
            foreach ($notes as $note) {
                if ($note->id === $this->targetId) {
                    return $note->displayTitle();
                }
            }

            // A note in the trash, about to be deleted for good.
            return $this->notes->find($this->principal(), $this->targetId)->displayTitle();
        }
        foreach ($this->targetType === 'folder' ? $folders : $modules as $item) {
            if ($item->id === $this->targetId) {
                return $item->name ?? $item->title;
            }
        }

        return null;
    }

    /**
     * Every place a folder or note could move to: the top level, each module,
     * and every folder, in tree order. For a folder, the places that can't
     * take it (itself, inside itself, or too deep) are disabled. The service
     * checks again.
     *
     * @return list<array{value: string, label: string, depth: int, disabled: bool}>
     */
    private function moveOptions(string $workspaceName, array $modules, array $folders): array
    {
        $inside = [];
        $height = 0;
        $movingFolderIds = [];
        if ($this->targetType === 'folder' && $this->targetId !== null) {
            $movingFolderIds[] = $this->targetId;
        } elseif ($this->mode === 'bulk-move') {
            foreach ($this->bulkKeys as $key) {
                if (str_starts_with($key, 'folder:')) {
                    $movingFolderIds[] = substr($key, 7);
                }
            }
        }

        if ($movingFolderIds !== []) {
            foreach ($movingFolderIds as $fid) {
                $inside[$fid] = true;
            }
            foreach ($folders as $folder) {
                if ($folder->parentId !== null && isset($inside[$folder->parentId])) {
                    $inside[$folder->id] = true;
                }
            }
            $movingFolders = array_filter($folders, fn ($f) => in_array($f->id, $movingFolderIds, true));
            $insideFolders = array_filter($folders, fn ($f) => isset($inside[$f->id]));
            $minDepth = $movingFolders === [] ? 0 : min(array_map(fn ($f) => $f->depth, $movingFolders));
            $maxDepth = $insideFolders === [] ? 0 : max(array_map(fn ($f) => $f->depth, $insideFolders));
            $height = max(0, $maxDepth - $minDepth);
        }
        $tooDeep = fn (int $depth) => $movingFolderIds !== [] && $depth + 1 + $height > Folders::MAX_DEPTH;

        $options = [['value' => "workspace:{$this->workspaceId}", 'label' => "{$workspaceName} (not in a module)", 'depth' => 0, 'icon' => 'folder', 'disabled' => $tooDeep(0)]];
        $folderOptions = function (?string $moduleId) use ($folders, $inside, $tooDeep): array {
            $list = [];
            foreach ($folders as $folder) {
                if ($folder->moduleId === $moduleId) {
                    $list[] = ['value' => "folder:{$folder->id}", 'label' => $folder->name, 'depth' => $folder->depth, 'icon' => 'folder', 'disabled' => isset($inside[$folder->id]) || $tooDeep($folder->depth)];
                }
            }

            return $list;
        };
        array_push($options, ...$folderOptions(null));
        foreach ($modules as $module) {
            $options[] = ['value' => "module:{$module->id}", 'label' => $module->title, 'depth' => 0, 'icon' => 'layers', 'disabled' => $tooDeep(0)];
            array_push($options, ...$folderOptions($module->id));
        }

        return $options;
    }

    /** @return array<string, string> placeKey => "Week 1: Cells › Labs", for showing where a note is */
    private function placeNames(array $modules, array $folders): array
    {
        $names = ["workspace:{$this->workspaceId}" => 'Not in a module'];
        foreach ($modules as $module) {
            $names["module:{$module->id}"] = $module->title;
        }
        foreach ($folders as $folder) {
            $parent = $folder->parentId !== null ? "folder:{$folder->parentId}" : $this->placeValue($folder->moduleId, null);
            $names["folder:{$folder->id}"] = ($folder->moduleId === null && $folder->parentId === null ? '' : ($names[$parent] ?? '').' › ').$folder->name;
        }

        return $names;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
