<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Briefings;
use App\Study\Sessions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A study session's briefing as a Markdown file, to attach to any AI
 * (docs/specs/study-memory.md §4.3). Another student's session, or one from
 * another workspace, answers 404 like a missing one.
 */
class SessionBriefingController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Sessions $sessions, Briefings $briefings, string $workspace, string $session): Response
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $found = $sessions->find($by, $session);
        if ($found->workspaceId !== $details->id) {
            throw new NotFound;
        }
        $briefing = $briefings->forSession($by, $found->id);
        $date = CarbonImmutable::parse($found->startedAt)->setTimezone($sessions->timezone($by))->format('Y-m-d');

        return response($briefing->markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$briefing->filename($date).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
