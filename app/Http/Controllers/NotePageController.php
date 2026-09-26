<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A note: /workspaces/{workspace}/notes/{note}. Another student's note, or
 * a note that belongs to another workspace, answers 404 like a missing one.
 * The editor on the page saves through the JSON API, not through here.
 */
class NotePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Notes $notes, Modules $modules, Folders $folders, string $workspace, string $note): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $opened = $notes->open($by, $note);
        if ($opened->workspaceId !== $details->id) {
            throw new NotFound;
        }

        // Where the note is: its module, then its folders from the top down.
        $trail = [];
        for ($folderId = $opened->folderId; $folderId !== null; $folderId = $folder->parentId) {
            $folder = $folders->find($by, $folderId);
            array_unshift($trail, [$folder->name, null]);
        }
        if ($opened->moduleId !== null) {
            array_unshift($trail, [$modules->find($by, $opened->moduleId)->title, route('workspaces.show', [$details->id, 'modules'])]);
        }

        return view('workspaces.note', ['workspace' => $details, 'note' => $opened, 'trail' => $trail]);
    }
}
