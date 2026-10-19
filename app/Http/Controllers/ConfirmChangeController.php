<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Mcp\Confirmations;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The page where the student says Yes to a change a chat app asked for (docs/specs/mcp-connections-plan.md §3.4, M6.5).
 * Opening it (GET) only shows the change, so a link preview or a model that fetches the page changes nothing. The
 * change runs at the POST, with the page's CSRF token, once; for what lands in every future prompt or cannot be undone
 * the password is asked first. Anything not this student's, used, expired or voided answers as not found.
 */
class ConfirmChangeController
{
    public function show(Request $request, PrincipalFactory $principals, Confirmations $confirmations, string $id): View
    {
        $by = $principals->fromRequest($request);

        return view('mcp.confirm', ['change' => $confirmations->show($by, $id), 'done' => null, 'problem' => null, 'needsPassword' => false]);
    }

    public function store(Request $request, PrincipalFactory $principals, Confirmations $confirmations, string $id): View|RedirectResponse
    {
        $by = $principals->fromRequest($request);
        if ($confirmations->needsPassword($by, $id) && ! $by->hasRecentPasswordConfirmation()) {
            $request->session()->put('url.intended', route('connections.confirm', $id));

            return redirect()->route('password.confirm');
        }
        $change = $confirmations->show($by, $id);
        try {
            $done = $confirmations->run($by, $id);
        } catch (NotFound $e) {
            throw $e;
        } catch (AppError $e) {
            return view('mcp.confirm', ['change' => $change, 'done' => null, 'problem' => $e->getMessage(), 'needsPassword' => false]);
        }

        return view('mcp.confirm', ['change' => $change, 'done' => $done, 'problem' => null, 'needsPassword' => false]);
    }
}
