<?php

namespace App\Study;

use App\Platform\Access\Principal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * What a file says, page by page, for the tutor (docs/specs/study-memory.md §6): a PDF's pages; a PowerPoint's
 * slides (with the speaker's notes) and a Word document's text read straight from the file, so they need no
 * LibreOffice; other Word, PowerPoint and Excel files through their preview PDF (App\Study\FilePreviews); a text
 * file cut into parts. Read the first time it is asked for and kept beside the file on the files disk
 * (texts/{id}.json), deleted with it. A picture has no text: it is seen, when attached in the chat.
 */
final class FileTexts
{
    /** The most of one page that is kept, in characters. */
    public const PAGE_CHARS = 6_000;

    /** A Word or text file is cut into parts about this long. */
    public const PART_CHARS = 3_000;

    /** Files bigger than this aren't read (the parser holds a PDF in memory). */
    public const MAX_BYTES = 40 * 1024 * 1024;

    private const VERSION = 1;

    public function __construct(private Files $files, private FilePreviews $previews) {}

    public function of(Principal $by, string $id): FileText
    {
        [$file, $key] = $this->files->content($by, $id);
        if ($file->kind === 'image') {
            return new FileText($file, FileText::PICTURE);
        }
        $disk = Files::disk();
        $textKey = self::key($key);
        if ($disk->exists($textKey)) {
            $kept = json_decode((string) $disk->get($textKey), true);
            if (is_array($kept) && ($kept['v'] ?? null) === self::VERSION && is_array($kept['pages'] ?? null)) {
                return new FileText($file, FileText::READY, array_values(array_map('strval', $kept['pages'])), (string) ($kept['unit'] ?? 'page'));
            }
        }
        if ($file->size > self::MAX_BYTES) {
            return new FileText($file, FileText::NONE);
        }

        $local = null;
        try {
            $path = fn () => $local ??= self::local($key);
            [$pages, $unit] = match (true) {
                $file->extension === 'pdf' => [$this->pdf($path()), 'page'],
                $file->extension === 'pptx' => [$this->slides($path()), 'slide'],
                $file->extension === 'docx' => [self::parts($this->word($path())), 'part'],
                $file->kind === 'text' => [self::parts((string) $this->files->textPreview($by, $id, 400_000)), 'part'],
                FilePreviews::converts($file) => $this->viaPreview($by, $id),
                default => [null, 'page'],
            };
        } catch (Throwable $e) {
            Log::warning('A file could not be read for the tutor.', ['file' => $file->id, 'problem' => Str::limit($e->getMessage(), 300)]);
            [$pages, $unit] = [null, 'page'];
        } finally {
            if ($local !== null && str_starts_with($local, sys_get_temp_dir())) {
                @unlink($local);
            }
        }
        if ($pages === FilePreviews::PREPARING) {
            return new FileText($file, FileText::PREPARING);
        }
        if (! is_array($pages)) {
            return new FileText($file, FileText::NONE);
        }
        $pages = array_values(array_map(fn (string $page) => mb_substr(self::tidy($page), 0, self::PAGE_CHARS), $pages));
        $disk->put($textKey, (string) json_encode(['v' => self::VERSION, 'unit' => $unit, 'pages' => $pages]));

        return new FileText($file, FileText::READY, $pages, $unit);
    }

    /** Deletes what was read from the file at this storage key (called when the file is deleted). */
    public static function forget(string $storageKey): void
    {
        Files::disk()->delete(self::key($storageKey));
    }

    private static function key(string $storageKey): string
    {
        return str_replace('/files/', '/texts/', $storageKey).'.json';
    }

    /** @return list<string> */
    private function pdf(string $path): array
    {
        $limit = ini_get('memory_limit');
        if ($limit !== false && $limit !== '-1' && self::bytes($limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        try {
            return array_map(fn ($page) => (string) $page->getText(), (new Parser)->parseFile($path)->getPages());
        } finally {
            if ($limit !== false) {
                @ini_set('memory_limit', $limit);
            }
        }
    }

    /**
     * A PowerPoint's slides in order, each with its speaker's notes.
     *
     * @return list<string>
     */
    private function slides(string $path): array
    {
        $zip = self::open($path);
        try {
            $slides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string) $zip->getNameIndex($i), $m) === 1) {
                    $slides[(int) $m[1]] = $m[0];
                }
            }
            ksort($slides);
            $pages = [];
            foreach ($slides as $number => $name) {
                $text = self::paragraphs(self::read($zip, $name), 'a');
                $rels = self::read($zip, "ppt/slides/_rels/slide{$number}.xml.rels");
                if (preg_match('#Target="\.\./notesSlides/(notesSlide\d+\.xml)"#', $rels, $n) === 1) {
                    // The notes slide repeats the slide's number placeholder; what's left is the speaker's words.
                    $notes = trim((string) preg_replace('/^\d+$/m', '', self::paragraphs(self::read($zip, "ppt/notesSlides/{$n[1]}"), 'a')));
                    if ($notes !== '') {
                        $text .= "\n\nSpeaker's notes: ".$notes;
                    }
                }
                $pages[] = $text;
            }

            return $pages;
        } finally {
            $zip->close();
        }
    }

    private function word(string $path): string
    {
        $zip = self::open($path);
        try {
            return self::paragraphs(self::read($zip, 'word/document.xml'), 'w');
        } finally {
            $zip->close();
        }
    }

    /**
     * Another Word, PowerPoint or Excel file: through its preview PDF, made by LibreOffice.
     *
     * @return array{0: list<string>|string|null, 1: string}
     */
    private function viaPreview(Principal $by, string $id): array
    {
        $pdf = $this->previews->pdf($by, $id);

        return match ($pdf) {
            null => [null, 'page'],
            FilePreviews::PREPARING => [FilePreviews::PREPARING, 'page'],
            default => [$this->readPreview($pdf), 'page'],
        };
    }

    /** @return list<string> */
    private function readPreview(string $pdfKey): array
    {
        $path = self::local($pdfKey);
        try {
            return $this->pdf($path);
        } finally {
            if (str_starts_with($path, sys_get_temp_dir())) {
                @unlink($path);
            }
        }
    }

    /** A path to the bytes at $key: the file itself on a local disk, otherwise a copy in the temporary folder. */
    private static function local(string $key): string
    {
        $disk = Files::disk();
        try {
            $path = $disk->path($key);
            if (is_file($path)) {
                return $path;
            }
        } catch (Throwable) {
            // Not a local disk.
        }
        $copy = tempnam(sys_get_temp_dir(), 'vistud-text-');
        $in = $disk->readStream($key);
        $out = fopen($copy, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $copy;
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Not a readable Office file.');
        }

        return $zip;
    }

    /** One part of an Office file, if it isn't unreasonably big once unpacked. */
    private static function read(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > 20 * 1024 * 1024) {
            return '';
        }

        return (string) $zip->getFromName($name);
    }

    /** The text of an Office XML part, a line per paragraph: `a` for PowerPoint's, `w` for Word's. */
    private static function paragraphs(string $xml, string $ns): string
    {
        $lines = [];
        preg_match_all('#<'.$ns.':p\b[^>]*>(.*?)</'.$ns.':p>#s', $xml, $paragraphs);
        foreach ($paragraphs[1] as $paragraph) {
            $paragraph = (string) preg_replace('#<'.$ns.':(tab|br)\b[^>]*/>#', ' ', $paragraph);
            preg_match_all('#<'.$ns.':t(?:\s[^>]*)?>(.*?)</'.$ns.':t>#s', $paragraph, $runs);
            $lines[] = html_entity_decode(implode('', $runs[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return implode("\n", $lines);
    }

    /**
     * A long text cut into parts of about PART_CHARS, at a line where possible.
     *
     * @return list<string>
     */
    private static function parts(string $text): array
    {
        $text = self::tidy($text);
        $parts = [];
        while (mb_strlen($text) > self::PART_CHARS) {
            $cut = mb_strrpos(mb_substr($text, 0, self::PART_CHARS), "\n");
            $cut = $cut === false || $cut < self::PART_CHARS / 2 ? self::PART_CHARS : $cut;
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }

        return [...$parts, $text];
    }

    /** Spaces and blank lines as a reader expects them. */
    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    private static function bytes(string $limit): int
    {
        $number = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
