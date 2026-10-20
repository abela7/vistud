<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Errors\NotFound;
use App\Study\FilePreviews;
use App\Study\NoteExports;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A note as a file to keep, share, or open elsewhere (the owner's reviews,
 * 2026-09-28 and 2026-10-01): /courses/{workspace}/notes/{note}/export/pdf
 * and /export/docx are PDF and Word, made by LibreOffice when it is on this
 * computer (App\Study\NoteExports); /export/md is Markdown (the title as its
 * heading, then App\Study\NoteDoc::markdown), /export/txt the same as plain
 * text. Only the owner's note, in that workspace; anything else is 404, like
 * a missing one. Named after the note's title.
 */
class NoteExportController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Notes $notes, string $workspace, string $note, string $format): Response
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $opened = $notes->open($by, $note);
        if ($opened->workspaceId !== $details->id) {
            throw new NotFound;
        }

        [$type, $office] = NoteExports::FORMATS[$format];
        if ($office) {
            // LibreOffice takes a few seconds, more the first time.
            @set_time_limit(300);
        }
        $body = NoteExports::make(Guard::learner($by), $opened, $format);
        if ($body === null) {
            return response(FilePreviews::converter() === null
                ? 'PDF and Word copies need LibreOffice on this computer. Markdown and text work without it.'
                : 'This note couldn\'t be made into a '.($format === 'pdf' ? 'PDF' : 'Word document').'. Try Markdown or text.', 422, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ]);
        }

        $title = $opened->displayTitle();

        return new StreamedResponse(function () use ($body) {
            $stream = null;
            try {
                $stream = fopen($body->path.'/result', 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('The export could not be opened.');
                }
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        throw new \RuntimeException('The export could not be read.');
                    }
                    echo $chunk;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $body->close();
            }
        }, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) filesize($body->path.'/result'),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::fileName($title, $format), self::asciiName($title, $format)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The title as a file name: no characters a file system refuses, and not too long. */
    private static function fileName(string $title, string $format): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $title)));

        return mb_substr($name !== '' ? $name : 'Untitled note', 0, 100).'.'.$format;
    }

    /** The same for browsers that take only ASCII. */
    private static function asciiName(string $title, string $format): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', ' ', Str::ascii(self::fileName($title, $format))));

        return $name === '' || $name === ".{$format}" ? "note.{$format}" : $name;
    }
}
