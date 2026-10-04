<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A note: /courses/{workspace}/notes/{note}. Another student's note, or
 * a note that belongs to another workspace, answers 404 like a missing one.
 * The editor on the page saves through the JSON API, not through here.
 * /courses/{workspace}/notes/new?in=module:{id} (or folder:{id}; the top
 * level without) is the editor for a note that doesn't exist yet: it is made
 * by its first words (POST /api/v1/notes), and never if none are written.
 * With &from=file:{id} it starts by importing that Markdown or text file;
 * with &title=… it starts with that title, and is made straight away.
 * With ?window=1 either is only the note, in a window of its own beside the
 * study material (resources/js/note-window.js); a note in the trash opens
 * on its page instead, where it can be restored.
 */
class NotePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Notes $notes, Modules $modules, Folders $folders, string $workspace, string $note): View|RedirectResponse
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $opened = $notes->open($by, $note);
        if ($opened->workspaceId !== $details->id) {
            throw new NotFound;
        }

        $window = $request->boolean('window');
        if ($window && $opened->trashedAt !== null) {
            return redirect()->route('workspaces.notes.show', [$details->id, $opened->id]);
        }

        return view('workspaces.note', ['workspace' => $details, 'note' => $opened, 'window' => $window, 'trail' => $this->trail($details->id, $opened->moduleId, $opened->folderId, $modules, $folders, $by), 'inModule' => $opened->moduleId !== null]);
    }

    public function create(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, Folders $folders, Files $files, string $workspace): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        // ?from=file:{id}: the editor starts by importing that Markdown or text file (its owner's, in this workspace).
        $import = null;
        $from = (string) $request->query('from', '');
        if (str_starts_with($from, 'file:')) {
            $file = $files->find($by, substr($from, 5));
            if ($file->workspaceId !== $details->id || $file->kind !== 'text' || $file->trashedAt !== null) {
                throw new NotFound;
            }
            $import = ['url' => route('files.content', $file->id), 'name' => $file->name, 'markdown' => in_array($file->extension, ['md', 'markdown'], true)];
        }
        [$type, $id] = array_pad(explode(':', (string) $request->query('in', 'workspace'), 2), 2, '');
        [$moduleId, $folderId, $inWorkspace] = [null, null, $details->id];
        if ($type === 'module') {
            $module = $modules->find($by, $id);
            [$moduleId, $inWorkspace] = [$module->id, $module->workspaceId];
        } elseif ($type === 'folder') {
            $folder = $folders->find($by, $id);
            [$moduleId, $folderId, $inWorkspace] = [$folder->moduleId, $folder->id, $folder->workspaceId];
        }
        if ($inWorkspace !== $details->id) {
            throw new NotFound;
        }
        $place = $folderId !== null ? ['folder', $folderId] : ($moduleId !== null ? ['module', $moduleId] : ['workspace', $details->id]);

        // &title=…: a note that starts with its title (the notes beside a file), so it is made as soon as it opens.
        $title = mb_substr(trim((string) $request->query('title', '')), 0, Notes::MAX_TITLE);

        return view('workspaces.note', [
            'workspace' => $details, 'note' => null, 'place' => $place, 'import' => $import, 'window' => $request->boolean('window'), 'startTitle' => $title,
            'trail' => $this->trail($details->id, $moduleId, $folderId, $modules, $folders, $by), 'inModule' => $moduleId !== null,
        ]);
    }

    /** @return list<array{0: string, 1: string}> where a note is: its module, then its folders from the top down */
    private function trail(string $workspaceId, ?string $moduleId, ?string $folderId, Modules $modules, Folders $folders, $by): array
    {
        $trail = [];
        for (; $folderId !== null; $folderId = $folder->parentId) {
            $folder = $folders->find($by, $folderId);
            array_unshift($trail, [$folder->name, route('workspaces.folders.show', [$workspaceId, $folder->id])]);
        }
        if ($moduleId !== null) {
            array_unshift($trail, [$modules->find($by, $moduleId)->title, route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'notes'])]);
        }

        return $trail;
    }
}
