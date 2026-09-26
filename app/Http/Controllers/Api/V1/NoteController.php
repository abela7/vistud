<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\Gone;
use App\Study\Notes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET and PUT /api/v1/notes/{id} (docs/api/openapi.json): the note editor
 * reads a note and autosaves it (ADR 0003 §5.3). PUT only updates: notes
 * are created on the workspace screens, so a save can never bring a
 * deleted note back.
 */
class NoteController
{
    public function __construct(private Notes $notes, private PrincipalFactory $principals) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $note = $this->notes->open($this->principals->fromRequest($request), $id);
        if ($note->trashedAt !== null) {
            throw new Gone('trashed');
        }

        return response()->json([
            'id' => $note->id,
            'workspace' => $note->workspaceId,
            'module' => $note->moduleId,
            'folder' => $note->folderId,
            'title' => $note->title,
            'version' => $note->version,
            'doc' => $note->doc,
            'updated_at' => $note->updatedAt,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // The raw body: the request middleware trims strings, which would
        // change the spaces in the note's text.
        $input = json_decode($request->getContent(), true);
        $saved = $this->notes->save($this->principals->fromRequest($request), $id, is_array($input) ? $input : []);

        return response()->json(['version' => $saved->version, 'saved_at' => $saved->savedAt]);
    }
}
