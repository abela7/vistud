<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\FilePreviews;
use App\Study\Files;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /files/{file}/preview: a Word, PowerPoint or Excel file as a PDF, for the browser's PDF viewer on the file's
 * page (App\Study\FilePreviews). Made the first time, which takes a few seconds. Only for its owner: anyone else
 * gets 404, like a missing file, and a trashed one 410.
 */
class FilePreviewController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Files $files, FilePreviews $previews, string $file): Response
    {
        // LibreOffice may take a while with a long deck; the PHP default of 30 seconds is too short.
        set_time_limit(180);
        $by = $principals->fromRequest($request);
        $details = $files->find($by, $file);
        $key = $previews->pdf($by, $file);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ];
        if ($key === null) {
            return response("This file can't be shown here. Download it to open it on your computer.", 422, $headers + [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }

        return Files::disk()->response($key, "{$details->name}.pdf", $headers + ['Content-Type' => 'application/pdf'], 'inline');
    }
}
