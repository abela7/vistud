<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What a workspace holds (docs/specs/workspaces.md, steps 2 and 3), for two
 * of its sections: `modules` (its modules, with the folders and notes inside
 * each) and `notes` (Notes & files: recent notes, what sits outside every
 * module, and the trash). One dialog serves every form. The services check
 * everything; the IDs the dialog acts on are locked.
 */
final class Contents extends Component
{
    #[Locked]
    public string $workspaceId;

    /** modules or notes: which section this is. */
    #[Locked]
    public string $view = 'modules';

    /** module · folder · move · delete, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public bool $creating = false;

    /** module, folder or note: what the dialog acts on, or (creating a folder) where it goes. */
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

    /** Where a moved folder or note goes: workspace:{id}, module:{id} or folder:{id}. */
    public string $destination = '';

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $error = null;

    private Modules $modules;

    private Folders $folders;

    private Notes $notes;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Modules $modules, Folders $folders, Notes $notes, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->modules = $modules;
        $this->folders = $folders;
        $this->notes = $notes;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $view = 'modules'): void
    {
        $this->workspaceId = $workspaceId;
        $this->view = $view === 'notes' ? 'notes' : 'modules';
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

    public function confirmDelete(string $type, string $id): void
    {
        $this->open('delete', targetType: in_array($type, ['folder', 'note'], true) ? $type : 'module', targetId: $id);
    }

    // ---------- Notes, straight away ----------

    /** A new, empty note, opened in the editor. */
    public function newNote(string $placeType, string $placeId): void
    {
        $note = $this->notes->create($this->principal(), $placeType, $placeId);
        $this->redirectRoute('workspaces.notes.show', [$note->workspaceId, $note->id]);
    }

    /** Into the trash: nothing to confirm, it can be restored. */
    public function trashNote(string $id): void
    {
        $by = $this->principal();
        $note = $this->notes->find($by, $id);
        $this->notes->trash($by, $note->id);
        $this->notice = "“{$note->displayTitle()}” is in the trash.";
    }

    public function restoreNote(string $id): void
    {
        $note = $this->notes->restore($this->principal(), $id);
        $this->notice = "“{$note->displayTitle()}” is restored.";
    }

    public function toggleTrash(): void
    {
        $this->showTrash = ! $this->showTrash;
    }

    // ---------- Saving ----------

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();
        $this->error = null;

        try {
            $this->notice = match ([$this->mode, $this->creating]) {
                ['module', true] => $this->modules->create($by, $this->workspaceId, $this->moduleInput())->title.' is added.',
                ['module', false] => $this->modules->update($by, (string) $this->targetId, $this->moduleInput())->title.' is saved.',
                ['folder', true] => $this->folders->create($by, (string) $this->targetType, (string) $this->targetId, $this->name)->name.' is added.',
                ['folder', false] => $this->renamed($by),
                ['move', false] => $this->moved($by),
                ['delete', false] => $this->deleted($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['starts_on' => 'startsOn', 'ends_on' => 'endsOn'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (Conflict $e) {
            $this->error = $e->getMessage();

            return;
        } catch (NotFound) {
            $this->error = 'That no longer exists. Close this and try again.';

            return;
        }

        $this->close();
        $this->dispatch('structure-dialog-close');
    }

    /** The dialog closed. */
    public function close(): void
    {
        $this->reset('mode', 'creating', 'targetType', 'targetId', 'title', 'startsOn', 'endsOn', 'name', 'destination', 'error');
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

        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->siblingsKey()][] = $folder;
        }
        $notesIn = [];
        foreach ($notes as $note) {
            $notesIn[$note->placeKey()][] = $note;
        }
        $counts = [];
        foreach ([...$folders, ...$notes] as $item) {
            if ($item->moduleId !== null) {
                $counts[$item->moduleId][$item instanceof NoteDetails ? 'notes' : 'folders'] = ($counts[$item->moduleId][$item instanceof NoteDetails ? 'notes' : 'folders'] ?? 0) + 1;
            }
        }

        $data = [
            'workspace' => $workspace,
            'modules' => $modules,
            'children' => $children,
            'notesIn' => $notesIn,
            'counts' => $counts,
            'target' => $this->targetName($modules, $folders, $notes),
            'moveOptions' => $this->mode === 'move' ? $this->moveOptions($workspace->name, $modules, $folders) : [],
        ];

        if ($this->view === 'notes') {
            $recent = $notes;
            usort($recent, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
            $data += [
                'noteCount' => count($notes),
                'recent' => array_slice($recent, 0, 5),
                'places' => $this->placeNames($modules, $folders),
                'trash' => $this->notes->trashed($by, $this->workspaceId),
            ];
        }

        return view("livewire.workspaces.{$this->view}", $data);
    }

    // ---------- Helpers ----------

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

    private function moved(Principal $by): string
    {
        [$type, $id] = array_pad(explode(':', $this->destination, 2), 2, '');
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
            default => [$this->modules->find($by, $id)->title, fn () => $this->modules->delete($by, $id)],
        };
        $delete();

        return "{$name} is deleted.";
    }

    /** The name of what the dialog is about, for its heading. */
    private function targetName(array $modules, array $folders, array $notes): ?string
    {
        if ($this->targetId === null) {
            return null;
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
        if ($this->targetType === 'folder') {
            $moving = collect($folders)->firstWhere('id', $this->targetId);
            if ($moving === null) {
                return [];
            }
            $inside = [$moving->id => true];
            foreach ($folders as $folder) {
                if ($folder->parentId !== null && isset($inside[$folder->parentId])) {
                    $inside[$folder->id] = true;
                }
            }
            $height = max(array_map(fn ($f) => $f->depth, array_filter($folders, fn ($f) => isset($inside[$f->id])))) - $moving->depth;
        }
        $tooDeep = fn (int $depth) => $this->targetType === 'folder' && $depth + 1 + $height > Folders::MAX_DEPTH;

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
