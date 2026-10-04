<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Reviewing flashcards: /courses/{workspace}/flashcards/review, with
 * ?module= and ?topic= (an id, or none) and ?early=1 to practise cards not
 * due yet. Another student's workspace answers 404; an unknown module or
 * topic reviews them all. The page itself is
 * App\Livewire\Workspaces\FlashcardReview.
 */
class FlashcardReviewController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Topics $topics, Modules $modules, string $workspace): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $topic = $request->query('topic');
        $topic = match (true) {
            $topic === 'none' => '',
            is_string($topic) && collect($topics->list($by, $details->id))->contains('id', $topic) => $topic,
            default => null,
        };

        $module = $request->query('module');
        $module = match (true) {
            $module === 'none' => '',
            is_string($module) && collect($modules->list($by, $details->id))->contains('id', $module) => $module,
            default => null,
        };

        return view('workspaces.review', ['workspace' => $details, 'topicId' => $topic, 'moduleId' => $module, 'early' => $request->boolean('early')]);
    }
}
