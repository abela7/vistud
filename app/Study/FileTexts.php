<?php

namespace App\Study;

use App\Jobs\ExtractFileText;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Files\PrivateFiles;
use App\Platform\Files\TempWorkspace;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * What a file says, page by page, for the tutor (docs/specs/study-memory.md §6): a PDF's pages; a PowerPoint's
 * slides (with the speaker's notes), a Word document's pages and an Excel workbook's sheets read straight from the
 * file (App\Study\OfficeReader), so they need no LibreOffice; the old kinds (.doc, .ppt, .xls, OpenDocument) the
 * same way, through the modern copy LibreOffice makes of them, or through their preview PDF where it can't
 * (App\Study\FilePreviews); a text file cut into parts. With each: what it is called, what each page
 * is, and what the file says about itself (its author, dates, page and slide counts). Read the first time it is
 * asked for and kept beside the file on the files disk (texts/{id}.json), deleted with it. A picture has no text:
 * it is seen, when attached in the chat.
 */
final class FileTexts
{
    /** The most of one page that is kept, in characters. */
    public const PAGE_CHARS = 6_000;

    /** A Word or text file is cut into parts about this long. */
    public const PART_CHARS = 3_000;

    /** Files bigger than this aren't read (the parser holds a PDF in memory). */
    public const MAX_BYTES = 40 * 1024 * 1024;

    /** A PDF up to this size is read inside the request; a bigger one on the queue (App\Jobs\ExtractFileText). */
    public const INLINE_PDF_BYTES = 4 * 1024 * 1024;

    /** Bumped when what is kept changes, so each file is read again the next time it is asked for. */
    private const VERSION = 3;

    public function __construct(private Files $files, private FilePreviews $previews) {}

    public function of(Principal $by, string $id): FileText
    {
        [$file, $key] = $this->files->content($by, $id);
        if ($file->kind === 'image') {
            return new FileText($file, FileText::PICTURE);
        }
        $disk = Files::disk();
        $textKey = self::key($key);
        if (($kept = self::kept($file, $textKey)) !== null) {
            return $kept;
        }
        if ($file->size > self::MAX_BYTES) {
            return new FileText($file, FileText::NONE);
        }
        // A big PDF is read on the queue's worker, never inside a request: an AI app's call has 240 seconds, and a
        // page waits for nobody (docs/specs/mcp-connections-plan.md M6.2). Queued once per file; with the sync queue
        // it has been read by now.
        if ($file->extension === 'pdf' && $file->size > (int) config('vistud.files.inline_pdf_bytes', self::INLINE_PDF_BYTES)) {
            ExtractFileText::dispatch($file->id, $key, Guard::learner($by)->learnerId);

            return self::kept($file, $textKey)
                ?? new FileText($file, $disk->exists("{$textKey}.failed") ? FileText::NONE : FileText::PREPARING);
        }

        $local = null;
        try {
            $path = function () use (&$local, $by, $key) {
                return ($local ??= self::local(Guard::learner($by), $key))->path.'/input';
            };
            $read = match (true) {
                $file->extension === 'pdf' => self::pdf($path()),
                $file->extension === 'pptx' => OfficeReader::pptx($path()),
                $file->extension === 'docx' => OfficeReader::docx($path()),
                $file->extension === 'xlsx' => OfficeReader::xlsx($path()),
                $file->kind === 'text' => new OfficeRead(self::parts((string) $this->files->textPreview($by, $id, 400_000)), 'part'),
                FilePreviews::converts($file) => $this->viaPreview($by, $id),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('A file could not be read for the tutor.', ['file' => $file->id, 'code' => 'parse_failed']);
            $read = null;
        } finally {
            $local?->close();
        }
        if ($read === FilePreviews::PREPARING) {
            return new FileText($file, FileText::PREPARING);
        }
        if (! $read instanceof OfficeRead) {
            return new FileText($file, FileText::NONE);
        }
        $pages = array_values(array_map(fn (string $page) => mb_substr(self::tidy($page), 0, self::PAGE_CHARS), $read->pages));
        $captions = count($read->captions) === count($pages) ? array_map(fn (string $caption) => mb_substr(self::tidy($caption), 0, 160), $read->captions) : [];
        $disk->put($textKey, (string) json_encode(['v' => self::VERSION, 'unit' => $read->unit, 'pages' => $pages, 'captions' => $captions, 'facts' => $read->facts]));

        return new FileText($file, FileText::READY, $pages, $read->unit, $captions, $read->facts);
    }

    /**
     * Reads a big PDF and keeps its text beside it, for App\Jobs\ExtractFileText. A PDF that can't be read leaves a
     * `.failed` mark, so the tutor is told there is no text instead of "preparing" for ever.
     */
    public static function extractPdf(string $storageKey, LearnerScope $scope): void
    {
        $disk = Files::disk();
        $textKey = self::key($storageKey);
        $local = null;
        try {
            $local = self::local($scope, $storageKey);
            $read = self::pdf($local->path.'/input');
            $pages = array_map(fn (string $page) => mb_substr(self::tidy($page), 0, self::PAGE_CHARS), $read->pages);
            $disk->put($textKey, (string) json_encode(['v' => self::VERSION, 'unit' => $read->unit, 'pages' => $pages, 'captions' => [], 'facts' => $read->facts]));
            $disk->delete("{$textKey}.failed");
        } catch (Throwable $e) {
            Log::warning('A file could not be read for the tutor.', ['key' => $storageKey, 'code' => 'parse_failed']);
            $disk->put("{$textKey}.failed", '1');
        } finally {
            $local?->close();
        }
    }

    /**
     * What was read from the file before, when it is still of this version; null otherwise. It reads the disk, so a
     * second call can find what a job wrote since the first.
     *
     * @phpstan-impure
     */
    private static function kept(FileDetails $file, string $textKey): ?FileText
    {
        $disk = Files::disk();
        if (! $disk->exists($textKey)) {
            return null;
        }
        $kept = json_decode((string) $disk->get($textKey), true);
        if (! is_array($kept) || ($kept['v'] ?? null) !== self::VERSION || ! is_array($kept['pages'] ?? null)) {
            return null;
        }

        return new FileText(
            $file,
            FileText::READY,
            array_values(array_map('strval', $kept['pages'])),
            (string) ($kept['unit'] ?? 'page'),
            array_values(array_map('strval', (array) ($kept['captions'] ?? []))),
            is_array($kept['facts'] ?? null) ? $kept['facts'] : [],
        );
    }

    /** Deletes what was read from the file at this storage key (called when the file is deleted). */
    public static function forget(string $storageKey): void
    {
        Files::disk()->delete(self::key($storageKey));
        Files::disk()->delete(self::key($storageKey).'.failed');
    }

    private static function key(string $storageKey): string
    {
        return str_replace('/files/', '/texts/', $storageKey).'.json';
    }

    /** A PDF's pages, with its title, author and dates when it says them. */
    private static function pdf(string $path): OfficeRead
    {
        $limit = ini_get('memory_limit');
        if ($limit !== false && $limit !== '-1' && self::bytes($limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        try {
            $pdf = (new Parser)->parseFile($path);
            $pages = array_map(fn ($page) => (string) $page->getText(), $pdf->getPages());

            return new OfficeRead($pages, 'page', facts: self::pdfFacts($pdf->getDetails()));
        } finally {
            if ($limit !== false) {
                @ini_set('memory_limit', $limit);
            }
        }
    }

    /**
     * What a PDF says about itself.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private static function pdfFacts(array $details): array
    {
        $say = fn (string $key) => trim((string) (is_array($details[$key] ?? null) ? implode(', ', $details[$key]) : ($details[$key] ?? '')));
        $date = function (string $key) use ($say): string {
            try {
                return $say($key) !== '' ? (new DateTimeImmutable($say($key)))->format('Y-m-d H:i') : '';
            } catch (Throwable) {
                return '';
            }
        };

        return array_filter([
            'title' => mb_substr($say('Title'), 0, 150),
            'author' => mb_substr($say('Author'), 0, 150),
            'created' => $date('CreationDate'),
            'modified' => $date('ModDate'),
            'application' => mb_substr($say('Creator'), 0, 80),
        ], fn (string $value) => $value !== '');
    }

    /**
     * An old Word, PowerPoint or Excel file (.doc, .ppt, .xls, OpenDocument): through the modern copy LibreOffice makes
     * of it, read as one (its sheets and formulas, its slides in order); through its preview PDF's text where that
     * copy can't be made.
     */
    private function viaPreview(Principal $by, string $id): OfficeRead|string|null
    {
        $modern = $this->previews->modern($by, $id);
        if ($modern === FilePreviews::PREPARING) {
            return FilePreviews::PREPARING;
        }
        if (is_array($modern)) {
            [$key, $extension] = $modern;
            $temp = self::local(Guard::learner($by), $key);
            $path = $temp->path.'/input';
            try {
                $read = match ($extension) {
                    'docx' => OfficeReader::docx($path),
                    'pptx' => OfficeReader::pptx($path),
                    default => OfficeReader::xlsx($path),
                };
                // LibreOffice made the copy, not the program that made the student's file.
                $facts = $read->facts;
                unset($facts['application']);

                return new OfficeRead($read->pages, $read->unit, $read->captions, $facts);
            } catch (Throwable $e) {
                Log::warning('The modern copy of a file could not be read; its PDF is read instead.', ['file' => $id, 'code' => 'parse_failed']);
            } finally {
                $temp->close();
            }
        }

        $pdf = $this->previews->pdf($by, $id);

        return match ($pdf) {
            null => null,
            FilePreviews::PREPARING => FilePreviews::PREPARING,
            default => $this->readPreview(Guard::learner($by), $pdf),
        };
    }

    private function readPreview(LearnerScope $scope, string $pdfKey): OfficeRead
    {
        $temp = self::local($scope, $pdfKey);
        $path = $temp->path.'/input';
        try {
            // What the program that made the PDF says is no use: it is LibreOffice, not the student's file.
            return new OfficeRead($this->pdf($path)->pages, 'page');
        } finally {
            $temp->close();
        }
    }

    /** A scoped decrypted input, closed by its processing caller. */
    private static function local(LearnerScope $scope, string $key): TempWorkspace
    {
        return PrivateFiles::temporary($scope, $key);
    }

    /**
     * A long text cut into parts of about PART_CHARS, at a line where possible.
     *
     * @return list<string>
     */
    public static function parts(string $text): array
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
