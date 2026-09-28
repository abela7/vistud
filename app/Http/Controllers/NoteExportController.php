<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A note as a file to keep, or to open elsewhere (the owner's review,
 * 2026-09-28): /workspaces/{workspace}/notes/{note}/export/md is Markdown
 * (the title as its heading, then App\Study\NoteDoc::markdown, which any
 * Markdown reader shows), /export/txt the same as plain text for Notepad.
 * Only the owner's note, in that workspace; anything else is 404, like a
 * missing one. Named after the note's title.
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

        $title = $opened->displayTitle();
        [$body, $type] = $format === 'md'
            ? ['# '.$title."\n\n".NoteDoc::markdown($opened->doc), 'text/markdown; charset=utf-8']
            : [$title."\n\n".NoteDoc::plain($opened->doc), 'text/plain; charset=utf-8'];
        $body = rtrim($body)."\n";

        return response($body, 200, [
            'Content-Type' => $type,
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
