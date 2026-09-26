<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A file's page: /workspaces/{workspace}/files/{file}, with a large preview
 * where the browser can show the file, and its download. Another student's
 * file, or one from another workspace, answers 404 like a missing one.
 */
class FilePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Files $files, Modules $modules, Folders $folders, string $workspace, string $file): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $found = $files->find($by, $file);
        if ($found->workspaceId !== $details->id) {
            throw new NotFound;
        }

        $trail = [];
        for ($folderId = $found->folderId; $folderId !== null; $folderId = $folder->parentId) {
            $folder = $folders->find($by, $folderId);
            array_unshift($trail, [$folder->name, null]);
        }
        if ($found->moduleId !== null) {
            array_unshift($trail, [$modules->find($by, $found->moduleId)->title, route('workspaces.show', [$details->id, 'modules'])]);
        }

        return view('workspaces.file', [
            'workspace' => $details,
            'file' => $found,
            'trail' => $trail,
            'text' => $found->trashedAt === null ? $files->textPreview($by, $found->id) : null,
        ]);
    }
}
