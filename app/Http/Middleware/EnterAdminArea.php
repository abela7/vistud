<?php

namespace App\Http\Middleware;

use App\Identity\Areas;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Area;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.area`. Reaching any admin route while in the student area
 * counts as entering the admin area, which needs a recent password
 * confirmation (ADR 0003 §10.3). Once inside, later admin requests pass;
 * protected actions still check the confirmation themselves.
 */
class EnterAdminArea
{
    public function __construct(private PrincipalFactory $principals, private Areas $areas) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        if ($session->get(PrincipalFactory::AREA_KEY) !== Area::Admin->value) {
            $this->areas->enter($this->principals->fromRequest($request), Area::Admin);
            $session->put(PrincipalFactory::AREA_KEY, Area::Admin->value);
            $this->principals->forget($request);
        }

        return $next($request);
    }
}
