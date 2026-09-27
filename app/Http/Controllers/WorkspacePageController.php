<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A workspace's pages: /workspaces/{workspace}/{section?}. The service finds
 * the workspace first, so another student's ID answers 404 before anything
 * is drawn, exactly like one that doesn't exist. The Overview also gathers
 * "where am I" (docs/specs/study-memory.md §3): topics by status, what's
 * confusing, open questions, the notes to pick up again, the flashcards due,
 * and whether a study session is open.
 */
class WorkspacePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, Topics $topics, Questions $questions, Notes $notes, Sessions $sessions, Flashcards $flashcards, string $workspace, string $section = 'overview'): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $data = ['workspace' => $details, 'section' => $section, 'modules' => []];

        if ($section === 'overview') {
            $topicList = $topics->list($by, $details->id);
            $counts = array_fill_keys(['not_started', 'covered', 'understood', 'confused', 'mastered'], 0);
            foreach ($topicList as $topic) {
                $counts[$topic->shown()]++;
            }
            $recent = $notes->list($by, $details->id);
            usort($recent, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);

            $data = [
                'modules' => $modules->list($by, $details->id),
                'topicCount' => count($topicList),
                'counts' => $counts,
                'confused' => array_values(array_filter($topicList, fn ($t) => $t->shown() === 'confused')),
                'openQuestions' => array_values(array_filter($questions->list($by, $details->id), fn ($q) => $q->shown() === 'open')),
                'recent' => array_slice($recent, 0, 3),
                'openSession' => $sessions->current($by),
                'cards' => $flashcards->counts($by, $details->id),
            ] + $data;
        }

        return view('workspaces.show', $data);
    }
}
