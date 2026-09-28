<?php

namespace App\Http\Controllers;

use App\Appearance\Icons;
use Illuminate\Http\Response;

/**
 * /icons.svg: every icon, once (App\Appearance\Icons). Its address carries a
 * version, so the browser keeps it for a year and fetches it again only
 * when the icons change. Public, like the login page that uses it; it holds
 * nothing but drawings.
 */
class IconSpriteController
{
    public function __invoke(): Response
    {
        return response(Icons::sprite(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
