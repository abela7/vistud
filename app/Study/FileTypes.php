<?php

namespace App\Study;

use ZipArchive;

/**
 * The files a student can upload, and the check that a file really is what
 * its name says (docs/specs/workspaces.md step 4). The bytes decide, not the
 * name or the browser: a program renamed to .pdf, an Office file with macros
 * or a PDF with scripts is refused. Nothing here opens or runs a file; later
 * readers (search, the AI connections) get only files that passed, and must
 * still treat their contents as untrusted data.
 */
final class FileTypes
{
    /** extension => [kind, MIME type served, label] */
    public const TYPES = [
        'pdf' => ['pdf', 'application/pdf', 'PDF'],
        'docx' => ['document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Word document'],
        'doc' => ['document', 'application/msword', 'Word document'],
        'odt' => ['document', 'application/vnd.oasis.opendocument.text', 'Document'],
        'pptx' => ['slides', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'PowerPoint'],
        'ppt' => ['slides', 'application/vnd.ms-powerpoint', 'PowerPoint'],
        'odp' => ['slides', 'application/vnd.oasis.opendocument.presentation', 'Presentation'],
        'xlsx' => ['spreadsheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel'],
        'xls' => ['spreadsheet', 'application/vnd.ms-excel', 'Excel'],
        'ods' => ['spreadsheet', 'application/vnd.oasis.opendocument.spreadsheet', 'Spreadsheet'],
        'txt' => ['text', 'text/plain', 'Text'],
        'md' => ['text', 'text/markdown', 'Markdown'],
        'csv' => ['text', 'text/csv', 'CSV'],
        'png' => ['image', 'image/png', 'Image'],
        'jpg' => ['image', 'image/jpeg', 'Image'],
        'gif' => ['image', 'image/gif', 'Image'],
        'webp' => ['image', 'image/webp', 'Image'],
    ];

    /** Office Open XML: the part every real file of the type has. */
    private const OOXML_MAIN = ['docx' => 'word/document.xml', 'pptx' => 'ppt/presentation.xml', 'xlsx' => 'xl/workbook.xml'];

    /** For the upload dialog: what can be uploaded. */
    public static function accept(): string
    {
        return implode(',', array_map(fn ($ext) => ".{$ext}", [...array_keys(self::TYPES), 'jpeg']));
    }

    /** The extension a name ends in, lower-case, with .jpeg as .jpg. */
    public static function extension(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * Checks the file at $path is a real, safe $extension file. Returns
     * [kind, MIME type, label], or refuses with a 422 on the `file` field.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function inspect(string $path, string $extension): array
    {
        $type = self::TYPES[$extension] ?? null;
        if ($type === null) {
            Input::refuse(['file' => 'This kind of file can\'t be uploaded. Use PDF, Word, PowerPoint, Excel, OpenDocument, text or an image (PNG, JPG, GIF, WebP).']);
        }

        $problem = match ($extension) {
            'pdf' => self::pdf($path),
            'docx', 'pptx', 'xlsx' => self::ooxml($path, self::OOXML_MAIN[$extension]),
            'odt', 'odp', 'ods' => self::odf($path, $type[1]),
            'doc', 'ppt', 'xls' => self::ole($path),
            'txt', 'md', 'csv' => self::text($path),
            default => self::image($path, $type[1]),
        };
        if ($problem !== null) {
            Input::refuse(['file' => $problem]);
        }

        return $type;
    }

    private static function pdf(string $path): ?string
    {
        $bytes = (string) file_get_contents($path);
        if (! str_contains(substr($bytes, 0, 1024), '%PDF-')) {
            return 'This file isn\'t really a PDF.';
        }
        // Scripts and launch actions: a PDF that runs things isn't taken.
        // Best effort (compressed object streams can hide them); nothing
        // here ever opens a PDF, and browsers show it in their own sandbox.
        if (preg_match('#/(JavaScript|JS|Launch)[\s/<(\[]#', $bytes) === 1) {
            return 'This PDF contains scripts, which ViStud doesn\'t accept. Print it to a new PDF and upload that.';
        }

        return null;
    }

    private static function ooxml(string $path, string $mainPart): ?string
    {
        $names = self::zipEntries($path);
        if ($names === null) {
            return 'This file isn\'t really a '.self::label($mainPart).'.';
        }
        if (! in_array('[Content_Types].xml', $names, true) || ! in_array($mainPart, $names, true)) {
            return 'This file isn\'t really a '.self::label($mainPart).'.';
        }
        foreach ($names as $name) {
            if (str_ends_with(strtolower($name), 'vbaproject.bin') || str_contains(strtolower($name), '/activex/')) {
                return 'This file contains macros, which ViStud doesn\'t accept. Save it without macros (as .docx, .pptx or .xlsx) and upload that.';
            }
        }

        return null;
    }

    private static function odf(string $path, string $mime): ?string
    {
        $names = self::zipEntries($path);
        // The first entry is `mimetype`, stored uncompressed, holding the type.
        $head = (string) file_get_contents($path, length: 30 + 8 + strlen($mime));
        if ($names === null || substr($head, 30, 8) !== 'mimetype' || substr($head, 38) !== $mime || ! in_array('content.xml', $names, true)) {
            return 'This file isn\'t really an OpenDocument file of that kind.';
        }
        foreach ($names as $name) {
            if (str_starts_with($name, 'Basic/') || str_starts_with($name, 'Scripts/')) {
                return 'This file contains macros, which ViStud doesn\'t accept. Save it without macros and upload that.';
            }
        }

        return null;
    }

    /** The older Office formats (.doc, .ppt, .xls): a compound file, without macro storage. */
    private static function ole(string $path): ?string
    {
        $bytes = (string) file_get_contents($path);
        if (! str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'This file isn\'t really an Office file of that kind.';
        }
        $utf16 = fn (string $name) => implode("\0", str_split($name))."\0";
        if (str_contains($bytes, $utf16('_VBA_PROJECT')) || str_contains($bytes, $utf16('Macros'))) {
            return 'This file contains macros, which ViStud doesn\'t accept. Save it without macros (as .docx, .pptx or .xlsx) and upload that.';
        }

        return null;
    }

    private static function text(string $path): ?string
    {
        $bytes = (string) file_get_contents($path);

        return str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')
            ? 'This isn\'t a plain text file. Text files must be UTF-8.'
            : null;
    }

    private static function image(string $path, string $mime): ?string
    {
        $info = @getimagesize($path);

        return is_array($info) && ($info['mime'] ?? null) === $mime ? null : 'This file isn\'t really an image of that kind.';
    }

    /** The names inside a ZIP file, or null when it isn't one (or this server can't read ZIP files). */
    private static function zipEntries(string $path): ?array
    {
        if (! class_exists(ZipArchive::class) || ! str_starts_with((string) file_get_contents($path, length: 4), "PK\x03\x04")) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    private static function label(string $mainPart): string
    {
        return ['word/document.xml' => 'Word document', 'ppt/presentation.xml' => 'PowerPoint file', 'xl/workbook.xml' => 'Excel file'][$mainPart];
    }
}
