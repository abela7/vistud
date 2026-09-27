<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\Gone;
use App\Study\Input;
use App\Study\Notes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET and PUT /api/v1/notes/{id} (docs/api/openapi.json): the note editor
 * reads a note and autosaves it (ADR 0003 §5.3). POST /api/v1/notes makes a
 * new note with its first words: New note opens an empty editor, and nothing
 * is kept until there's a title or some text. PUT only updates, so a save
 * can never bring a deleted note back.
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

    public function store(Request $request): JsonResponse
    {
        // The raw body, as for PUT: the text's spaces stay as they are.
        $input = json_decode($request->getContent(), true);
        $input = is_array($input) ? $input : [];
        $place = is_array($input['place'] ?? null) ? $input['place'] : [];
        $type = in_array($place['type'] ?? null, ['workspace', 'module', 'folder'], true) ? $place['type'] : null;
        $placeId = is_string($place['id'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $place['id']) ? $place['id'] : null;
        Input::refuse($type === null || $placeId === null ? ['place' => 'Send where the note goes.'] : []);
        $note = $this->notes->createWritten($this->principals->fromRequest($request), $type, $placeId, $input);

        return response()->json([
            'id' => $note->id,
            'version' => $note->version,
            'url' => route('workspaces.notes.show', [$note->workspaceId, $note->id]),
            'save_url' => route('api.v1.notes.update', $note->id),
        ], 201);
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
