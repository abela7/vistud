<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A workspace's pages: /workspaces/{workspace}/{section?}. The service finds
 * the workspace first, so another student's ID answers 404 before anything
 * is drawn, exactly like one that doesn't exist. The Overview also gathers
 * what to pick up again (docs/specs/study-memory.md §3): the latest study
 * session, the notes edited last, and whether a session is open; and, to show
 * the modules and how far each one's topics are understood, the topics.
 */
class WorkspacePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, Topics $topics, Notes $notes, Sessions $sessions, string $workspace, string $section = 'overview'): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $data = ['workspace' => $details, 'section' => $section];

        if ($section === 'overview') {
            $recent = $notes->list($by, $details->id);
            usort($recent, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
            $places = ["workspace:{$details->id}" => null];
            $moduleList = $modules->list($by, $details->id);
            foreach ($moduleList as $module) {
                $places["module:{$module->id}"] = $module->title;
            }
            $topicList = $topics->list($by, $details->id);
            $moduleProgress = [];
            foreach ($topicList as $topic) {
                if ($topic->moduleId !== null) {
                    $moduleProgress[$topic->moduleId]['total'] = ($moduleProgress[$topic->moduleId]['total'] ?? 0) + 1;
                    $moduleProgress[$topic->moduleId]['done'] = ($moduleProgress[$topic->moduleId]['done'] ?? 0) + (int) in_array($topic->shown(), ['understood', 'mastered'], true);
                }
            }
            $hour = CarbonImmutable::now($sessions->timezone($by))->hour;
            $name = trim(explode(' ', (string) $request->user()?->name)[0] ?? '');

            $data += [
                'greeting' => ($hour < 5 ? 'Good night' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'))).($name !== '' ? ", {$name}" : ''),
                'recent' => array_slice($recent, 0, 3),
                'places' => $places,
                'moduleCount' => count($moduleList),
                'modules' => $moduleList,
                'moduleProgress' => $moduleProgress,
                'lastSession' => $sessions->list($by, $details->id, 1)[0] ?? null,
                'topicNames' => collect($topicList)->pluck('name', 'id')->all(),
                'openSession' => $sessions->current($by),
            ];
        }

        return view('workspaces.show', $data);
    }
}
