<?php

namespace App\Http\Controllers;

use App\Appearance\Share;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * robots.txt, the sitemap and the web app manifest (App\Appearance\Share): made from the site's own address, so they
 * are right on any server.
 */
final class ShareController
{
    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /$', 'Allow: /login', 'Allow: /privacy', 'Allow: /invitation', 'Allow: /class-link', ...array_map(fn (string $path) => "Disallow: {$path}", Share::PRIVATE), '', 'Sitemap: '.route('sitemap')];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function sitemap(): Response
    {
        $urls = array_map(fn (string $name) => '<url><loc>'.e(route($name)).'</loc></url>', Share::INDEXABLE);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** What a phone needs to put ViStud on its home screen. */
    public function manifest(): JsonResponse
    {
        $bar = Share::barColors();

        return response()->json([
            'name' => Share::NAME,
            'short_name' => Share::NAME,
            'description' => Share::DESCRIPTION,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $bar['light'],
            'theme_color' => $bar['light'],
            'icons' => [
                ['src' => asset('brand/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => asset('brand/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=86400'], JSON_UNESCAPED_SLASHES);
    }
}
