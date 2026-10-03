<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\FilePreviews;
use App\Study\Files;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /files/{file}/preview: a Word, PowerPoint or Excel file as a PDF, for the browser's PDF viewer on the file's
 * page (App\Study\FilePreviews). The PDF is made on the queue's worker (App\Jobs\MakeFilePreview); until it is
 * there this answers 202 with Retry-After at once, and the page asks again. Only for its owner: anyone else gets
 * 404, like a missing file, and a trashed one 410.
 */
class FilePreviewController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Files $files, FilePreviews $previews, string $file): Response
    {
        // With the sync queue (tests) the PDF is made in this request, and a long deck takes more than PHP's 30 seconds.
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
        if ($key === FilePreviews::PREPARING) {
            // Queued or being made: the page asks again (resources/js/app.js), so no request waits here.
            return response('The preview is being prepared.', 202, $headers + [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => '2',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }
        if ($key === null) {
            return response("This file can't be shown here. Download it to open it on your computer.", 422, $headers + [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }

        return Files::disk()->response($key, "{$details->name}.pdf", $headers + ['Content-Type' => 'application/pdf'], 'inline');
    }
}
