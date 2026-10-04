<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Questions;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A question on a page of its own, /courses/{workspace}/questions/{question},
 * and a new one, /courses/{workspace}/questions/new (`module` and `topic` say
 * where it starts, `from` the page to go back to). Another student's question,
 * or one from another workspace, answers 404 like a missing one. The page itself
 * is App\Livewire\Workspaces\QuestionPage (the owner's review, 2026-09-29).
 */
class QuestionPageController
{
    public function show(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Questions $questions, string $workspace, string $question): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $asked = $questions->find($by, $question);
        $asked->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.question', [
            'workspace' => $details,
            'question' => $asked,
            'status' => $this->text($request, 'status'),
            'from' => $this->text($request, 'from'),
        ]);
    }

    public function create(Request $request, PrincipalFactory $principals, Workspaces $workspaces, string $workspace): View
    {
        $details = $workspaces->find($principals->fromRequest($request), $workspace);

        return view('workspaces.question', [
            'workspace' => $details,
            'question' => null,
            'module' => $this->text($request, 'module'),
            'topic' => $this->text($request, 'topic'),
            'from' => $this->text($request, 'from'),
        ]);
    }

    /** A query value that is one piece of text, or null (`?from[]=x` is nothing). */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
