<?php

namespace App\Study;

use App\Platform\Access\LearnerScope;
use App\Platform\Files\PrivateFiles;
use App\Platform\Files\TempWorkspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A note as a file to keep or share (the owner's review, 2026-10-01): PDF and Word are made by LibreOffice from
 * the note as a page, Markdown and plain text here (App\Study\NoteDoc). The page is the note's Markdown drawn by
 * MarkdownPreview, which keeps no raw HTML, with the note's own pictures put into it; a picture from the web
 * is left out (its description stays), so LibreOffice never fetches anything.
 */
final class NoteExports
{
    /** @var array<string, array{0: string, 1: bool}> format => [MIME type, made by LibreOffice] */
    public const FORMATS = [
        'pdf' => ['application/pdf', true],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', true],
        'md' => ['text/markdown; charset=utf-8', false],
        'txt' => ['text/plain; charset=utf-8', false],
    ];

    /** A picture on the page is at most this wide (an A4 page's text width), so it never runs off the page. */
    private const MAX_PICTURE_PX = 640;

    /** @return list<string> the formats this computer can make: PDF and Word only where LibreOffice is */
    public static function available(): array
    {
        $office = FilePreviews::converter() !== null;

        return array_keys(array_filter(self::FORMATS, fn (array $format) => $office || ! $format[1]));
    }

    /** The note as a $format file; null when LibreOffice isn't on this computer or couldn't make it. */
    public static function make(LearnerScope $scope, NoteDetails $note, string $format): ?TempWorkspace
    {
        $title = $note->displayTitle();
        $doc = $note->doc ?? NoteDoc::empty();
        $converter = FilePreviews::converter();
        if (self::FORMATS[$format][1] && $converter === null) {
            return null;
        }

        $temp = new TempWorkspace;
        $work = $temp->path;
        File::ensureDirectoryExists("{$work}/in");
        try {
            if ($format === 'md' || $format === 'txt') {
                $body = $format === 'md' ? '# '.$title."\n\n".NoteDoc::markdown($doc) : $title."\n\n".NoteDoc::plain($doc);
                File::put($work.'/result', rtrim($body)."\n");

                return $temp;
            }
            if ($converter === null) {
                $temp->close();

                return null;
            }
            file_put_contents("{$work}/in/note.html", self::page($scope, $title, $doc));
            $filter = $format === 'pdf' ? 'pdf:writer_pdf_Export' : 'docx:MS Word 2007 XML';
            $made = FilePreviews::convert($converter, $work, "{$work}/in/note.html", $filter, 'HTML (StarWriter)', $problem);
            if ($made !== null) {
                File::move($made, $work.'/result');

                return $temp;
            }
        } catch (Throwable $e) {
            $problem = 'conversion_failed';
        }
        $temp->close();
        Log::warning('A note could not be exported.', ['note' => $note->id, 'format' => $format, 'code' => 'conversion_failed']);

        return null;
    }

    /** The note as a page for LibreOffice, with its own pictures in it. */
    private static function page(LearnerScope $scope, string $title, array $doc): string
    {
        $body = MarkdownPreview::html(NoteDoc::markdown($doc));
        $body = (string) preg_replace_callback('/<img\s[^>]*>/i', function (array $match) use ($scope): string {
            preg_match('/\ssrc="([^"]*)"/i', $match[0], $src);
            preg_match('/\salt="([^"]*)"/i', $match[0], $alt);
            $alt = $alt[1] ?? '';
            $found = preg_match('#^/notes/images/([A-Za-z0-9-]{1,64})$#', html_entity_decode($src[1] ?? ''), $id) === 1
                ? NoteImages::find($scope->learnerId, $id[1])
                : null;
            if ($found === null) {
                return $alt === '' ? '' : "<em>[{$alt}]</em>";
            }
            // In the page itself: LibreOffice would only link a picture beside it, and a linked picture is never loaded.
            $bytes = PrivateFiles::bytes($scope, $found[0], 10 * 1024 * 1024);
            $width = min(self::MAX_PICTURE_PX, (int) (@getimagesizefromstring($bytes)[0] ?? self::MAX_PICTURE_PX)) ?: self::MAX_PICTURE_PX;

            return '<img src="data:'.$found[1].';base64,'.base64_encode($bytes)."\" alt=\"{$alt}\" width=\"{$width}\">";
        }, $body);
        // LibreOffice draws a table's lines from these, not from the style sheet.
        $body = str_replace('<table>', '<table border="1" cellpadding="5" cellspacing="0" width="100%">', $body);
        $title = e($title);

        return <<<HTML
            <!doctype html>
            <html>
            <head>
            <meta charset="utf-8">
            <title>{$title}</title>
            <style>
            body { font-family: Calibri, Carlito, Arial, sans-serif; font-size: 11pt; line-height: 1.4; }
            h1 { font-size: 20pt; }
            pre, code { font-family: Consolas, "Liberation Mono", monospace; font-size: 10pt; }
            </style>
            </head>
            <body>
            <h1>{$title}</h1>
            {$body}
            </body>
            </html>
            HTML;
    }
}
