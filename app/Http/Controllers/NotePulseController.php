<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Mcp\NoteApps;
use App\Platform\Errors\NotFound;
use App\Study\Notes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The note page asking, every 30 seconds while a chat app is working, whether the note has a newer version and who
 * wrote it (docs/specs/mcp-connections-plan.md §3.7). Carries no text: the editor fetches the version itself.
 */
class NotePulseController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Notes $notes, NoteApps $apps, string $workspace, string $note): JsonResponse
    {
        $by = $principals->fromRequest($request);
        $found = $notes->find($by, $note);
        if ($found->workspaceId !== $workspace) {
            throw new NotFound;
        }

        return response()->json([
            'version' => $found->version,
            'app' => $apps->wrote($by, $found->id, $found->version),
            'active' => $apps->active($by),
        ])->header('Cache-Control', 'no-store');
    }
}
