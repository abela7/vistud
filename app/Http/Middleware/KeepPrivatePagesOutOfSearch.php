<?php

namespace App\Http\Middleware;

use App\Appearance\Share;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response but the pages search engines may list (App\Appearance\Share::INDEXABLE) says noindex in a header
 * too, so a file, a download or a JSON answer that a crawler reaches is never listed, whatever is in it.
 */
final class KeepPrivatePagesOutOfSearch
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $name = $request->route()?->getName();
        if (! in_array($name, Share::INDEXABLE, true) && ! in_array($name, ['robots', 'sitemap', 'manifest'], true) && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
