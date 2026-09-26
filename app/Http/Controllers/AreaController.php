<?php

namespace App\Http\Controllers;

use App\Identity\Areas;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Area;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Switching area. The Areas service decides; this stores the choice in the session. */
class AreaController
{
    public function update(Request $request, string $area, PrincipalFactory $principals, Areas $areas): Response
    {
        $target = Area::from($area);
        $areas->enter($principals->fromRequest($request), $target);
        $request->session()->put(PrincipalFactory::AREA_KEY, $target->value);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect($target === Area::Admin ? '/admin' : '/');
    }
}
