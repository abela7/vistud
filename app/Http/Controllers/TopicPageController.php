<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A topic on a dedicated page of its own: /courses/{workspace}/topics/{topic}.
 * Allows students to deep dive: watch & update progress, ask questions on this topic,
 * chat with the AI tutor specifically about this topic, edit & manage topic, and access
 * its notes and files. Another student's or another workspace's topic answers 404.
 */
class TopicPageController
{
    public function __invoke(
        Request $request,
        PrincipalFactory $principals,
        Workspaces $workspaces,
        Topics $topics,
        Modules $modules,
        Folders $folders,
        string $workspace,
        string $topic,
    ): View {
        $by = $principals->fromRequest($request);
        $ws = $workspaces->find($by, $workspace);
        $topicDetails = $topics->find($by, $topic);
        $topicDetails->workspaceId === $ws->id || throw new NotFound;

        $module = $topicDetails->moduleId !== null ? $modules->find($by, $topicDetails->moduleId) : null;
        $folder = $topicDetails->folderId !== null ? $folders->find($by, $topicDetails->folderId) : null;

        return view('workspaces.topic', [
            'workspace' => $ws,
            'topic' => $topicDetails,
            'module' => $module,
            'folder' => $folder,
        ]);
    }
}
