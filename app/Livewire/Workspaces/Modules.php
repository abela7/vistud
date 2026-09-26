<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\Modules as ModuleService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A workspace's Modules section (docs/specs/workspaces.md, step 2): its
 * modules in order, and the folders inside each. One dialog serves every
 * form: a module, a folder, moving a folder, and deleting. The services
 * check everything; the IDs the dialog acts on are locked.
 */
final class Modules extends Component
{
    #[Locked]
    public string $workspaceId;

    /** module · folder · move · delete, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public bool $creating = false;

    /** module or folder: what the dialog acts on, or (creating a folder) where it goes. */
    #[Locked]
    public ?string $targetType = null;

    #[Locked]
    public ?string $targetId = null;

    public string $title = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $name = '';

    /** Where a moved folder goes: module:{id} or folder:{id}. */
    public string $destination = '';

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $error = null;

    private ModuleService $modules;

    private Folders $folders;

    private PrincipalFactory $principals;

    public function boot(ModuleService $modules, Folders $folders, PrincipalFactory $principals): void
    {
        $this->modules = $modules;
        $this->folders = $folders;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
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

    public function newFolder(string $parentType, string $parentId): void
    {
        $this->open('folder', creating: true, targetType: $parentType === 'folder' ? 'folder' : 'module', targetId: $parentId);
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
        $this->destination = $folder->parentId === null ? "module:{$folder->moduleId}" : "folder:{$folder->parentId}";
    }

    public function confirmDelete(string $type, string $id): void
    {
        $this->open('delete', targetType: $type === 'folder' ? 'folder' : 'module', targetId: $id);
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
        $modules = $this->modules->list($by, $this->workspaceId);
        $folders = $this->folders->tree($by, $this->workspaceId);

        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->siblingsKey()][] = $folder;
        }

        return view('livewire.workspaces.modules', [
            'modules' => $modules,
            'children' => $children,
            'counts' => array_count_values(array_map(fn ($f) => $f->moduleId, $folders)),
            'target' => $this->targetName($modules, $folders),
            'moveOptions' => $this->mode === 'move' ? $this->moveOptions($modules, $folders) : [],
        ]);
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

    private function renamed(Principal $by): string
    {
        $this->folders->rename($by, (string) $this->targetId, $this->name);

        return $this->folders->find($by, (string) $this->targetId)->name.' is renamed.';
    }

    private function moved(Principal $by): string
    {
        [$type, $id] = array_pad(explode(':', $this->destination, 2), 2, '');
        $this->folders->move($by, (string) $this->targetId, $type, $id);

        return $this->folders->find($by, (string) $this->targetId)->name.' is moved.';
    }

    private function deleted(Principal $by): string
    {
        if ($this->targetType === 'folder') {
            $name = $this->folders->find($by, (string) $this->targetId)->name;
            $this->folders->delete($by, (string) $this->targetId);
        } else {
            $name = $this->modules->find($by, (string) $this->targetId)->title;
            $this->modules->delete($by, (string) $this->targetId);
        }

        return "{$name} is deleted.";
    }

    /** The name of what the dialog is about, for its heading. */
    private function targetName(array $modules, array $folders): ?string
    {
        if ($this->targetId === null) {
            return null;
        }
        foreach ($this->targetType === 'folder' ? $folders : $modules as $item) {
            if ($item->id === $this->targetId) {
                return $item->name ?? $item->title;
            }
        }

        return null;
    }

    /**
     * Every place a folder could move to, with the ones that can't take it
     * (itself, inside itself, or too deep) disabled. The service checks again.
     *
     * @return list<array{value: string, label: string, depth: int, disabled: bool}>
     */
    private function moveOptions(array $modules, array $folders): array
    {
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

        $options = [];
        foreach ($modules as $module) {
            $options[] = ['value' => "module:{$module->id}", 'label' => $module->title, 'depth' => 0, 'disabled' => 1 + $height > Folders::MAX_DEPTH];
            foreach ($folders as $folder) {
                if ($folder->moduleId === $module->id) {
                    $options[] = [
                        'value' => "folder:{$folder->id}",
                        'label' => $folder->name,
                        'depth' => $folder->depth,
                        'disabled' => isset($inside[$folder->id]) || $folder->depth + 1 + $height > Folders::MAX_DEPTH,
                    ];
                }
            }
        }

        return $options;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
