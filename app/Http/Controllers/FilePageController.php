<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\FilePreviews;
use App\Study\Files;
use App\Study\Folders;
use App\Study\MarkdownPreview;
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
            array_unshift($trail, [$folder->name, route('workspaces.folders.show', [$details->id, $folder->id])]);
        }
        if ($found->moduleId !== null) {
            array_unshift($trail, [$modules->find($by, $found->moduleId)->title, route('workspaces.modules.show', [$details->id, $found->moduleId])]);
        }

        $text = $found->trashedAt === null ? $files->textPreview($by, $found->id) : null;
        $place = $found->folderId !== null ? "folder:{$found->folderId}" : ($found->moduleId !== null ? "module:{$found->moduleId}" : null);

        return view('workspaces.file', [
            'workspace' => $details,
            'inModule' => $found->moduleId !== null,
            'file' => $found,
            'trail' => $trail,
            'text' => $text,
            // Word, PowerPoint and Excel: shown as a PDF made by LibreOffice, when it's on this computer.
            'office' => FilePreviews::converts($found),
            'officePreview' => FilePreviews::converts($found) && $found->trashedAt === null && FilePreviews::converter() !== null ? route('files.preview', $found->id) : null,
            // A Markdown file is shown as it was meant to look; any text file can open as a new note in the same place.
            'markdown' => $text !== null && in_array($found->extension, ['md', 'markdown'], true) ? MarkdownPreview::html($text) : null,
            'openAsNote' => $text !== null ? route('workspaces.notes.create', [$details->id, 'from' => "file:{$found->id}"] + ($place === null ? [] : ['in' => $place])) : null,
            // Notes beside the file: a new note in the same place, in a window of its own (resources/js/note-window.js).
            'takeNotes' => $found->trashedAt === null ? route('workspaces.notes.create', [$details->id] + ($place === null ? [] : ['in' => $place]) + ['window' => 1]) : null,
        ]);
    }
}
