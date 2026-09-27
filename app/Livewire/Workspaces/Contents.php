<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\LinkDetails;
use App\Study\Links;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
    use Notices, WithFileUploads;

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

    public string $url = '';

    /** A module's instructions for the assistant. */
    public string $instructions = '';

    /** Where a moved folder, note or file goes: workspace:{id}, module:{id} or folder:{id}. */
    public string $destination = '';

    /** Files chosen in the upload dialog, waiting in Livewire's temporary storage. */
    public array $uploads = [];

    /** The files that couldn't be uploaded, and why: [name, reason]. */
    #[Locked]
    public array $uploadErrors = [];

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

    private PrincipalFactory $principals;

    public function boot(Modules $modules, Folders $folders, Notes $notes, Files $files, Links $links, Instructions $instructions, Workspaces $workspaces, Topics $topics, Sessions $sessions, Questions $questions, PrincipalFactory $principals): void
    {
        $this->topics = $topics;
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
        $this->redirectRoute('workspaces.notes.create', [$this->workspaceId] + ($placeType === 'workspace' ? [] : ['in' => "{$placeType}:{$placeId}"]));
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
    }

    // ---------- Saving ----------

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();
        $this->error = null;
        // Deleting the page's own module or folder leaves for the place around it.
        $leaving = $this->mode === 'delete' && $this->placeId !== null && $this->targetId === $this->placeId ? $this->parentUrl() : null;

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
                ['upload', true] => $this->uploaded($by),
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
        // Some files failed, or none was chosen: the dialog stays open to say which.
        if ($this->uploadErrors !== [] || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($leaving !== null) {
            session()->flash('workspace-notice', $this->notice);
            $this->redirect($leaving);

            return;
        }

        $this->close();
        $this->dispatch('structure-dialog-close');
    }

    /** The dialog closed. */
    public function close(): void
    {
        foreach ($this->uploads as $upload) {
            if ($upload instanceof TemporaryUploadedFile) {
                $upload->delete();
            }
        }
        $this->reset('mode', 'creating', 'targetType', 'targetId', 'title', 'startsOn', 'endsOn', 'name', 'url', 'instructions', 'destination', 'error', 'uploads', 'uploadErrors');
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
            'moveOptions' => $this->mode === 'move' ? $this->moveOptions($workspace->name, $modules, $folders) : [],
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
                'studied' => $this->moduleSessions($by, $module->id), 'topicNames' => $topicNames, 'questions' => $questions,
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

        return ['place' => $folder, 'placeName' => $folder->name, 'key' => "folder:{$folder->id}", 'trail' => $trail, 'studied' => [], 'topicNames' => [], 'questions' => null];
    }

    /** @return list<SessionDetails> the module's latest study sessions: in it, or on one of its topics */
    private function moduleSessions(Principal $by, string $moduleId): array
    {
        $inModule = [];
        foreach ($this->topics->list($by, $this->workspaceId) as $topic) {
            $inModule[$topic->id] = $topic->moduleId === $moduleId;
        }
        $sessions = array_filter($this->sessions->list($by, $this->workspaceId, 50),
            fn ($s) => $s->moduleId === $moduleId || ($s->moduleId === null && ($inModule[$s->topicId] ?? false)));

        return array_slice(array_values($sessions), 0, 5);
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

    /** Each chosen file, checked and kept; the ones that fail are listed with the reason, and stay out. */
    private function uploaded(Principal $by): ?string
    {
        $this->uploadErrors = [];
        $uploads = array_values(array_filter($this->uploads, fn ($u) => $u instanceof TemporaryUploadedFile));
        if ($uploads === []) {
            $this->addError('uploads', 'Choose at least one file.');

            return null;
        }
        if (count($uploads) > 10) {
            $this->addError('uploads', 'Upload up to 10 files at a time.');

            return null;
        }

        $done = 0;
        foreach ($uploads as $upload) {
            $name = $upload->getClientOriginalName();
            try {
                $this->files->upload($by, (string) $this->targetType, (string) $this->targetId, $upload->getRealPath(), $name);
                $done++;
            } catch (Unprocessable $e) {
                $this->uploadErrors[] = [$name, $e->details['fields']['file'][0] ?? $e->getMessage()];
            } catch (Conflict $e) {
                $this->uploadErrors[] = [$name, $e->getMessage()];
            } finally {
                $upload->delete();
            }
        }
        $this->uploads = [];

        return $done === 0 ? null : $done.' '.($done === 1 ? 'file' : 'files').' uploaded.';
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
