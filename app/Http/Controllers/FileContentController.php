<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Files;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file's bytes: /files/{file}/content, shown in the browser (PDF, images,
 * text) or downloaded (everything else, or with ?download=1). Only for its
 * owner: anyone else gets 404, like a missing file, and a trashed one 410.
 * The type sent is the one the file was checked as, never guessed, and what
 * is shown can't run anything or be framed by another site.
 */
class FileContentController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Files $files, string $file): StreamedResponse
    {
        [$details, $key] = $files->content($principals->fromRequest($request), $file);
        $download = $request->boolean('download') || ! $details->previewable();

        $headers = [
            // Text shows as plain text in the browser, whatever its kind.
            'Content-Type' => match (true) {
                $details->kind === 'text' && ! $download => 'text/plain; charset=utf-8',
                $details->kind === 'text' => "{$details->mime}; charset=utf-8",
                default => $details->mime,
            },
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ];
        if ($details->kind !== 'pdf') {
            // An image or text on its own gets no scripts, forms or plugins. (A
            // browser's PDF viewer needs its plugin, so PDFs rely on its sandbox.)
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox";
        }

        return Files::disk()->response($key, $details->fileName(), $headers, $download ? 'attachment' : 'inline');
    }
}
