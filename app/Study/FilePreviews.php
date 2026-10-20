<?php

namespace App\Study;

use App\Jobs\MakeFilePreview;
use App\Jobs\StoredFileScope;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Word, PowerPoint and Excel files shown in the browser (the owner's review, 2026-09-30). LibreOffice turns the
 * file into a PDF (a few seconds), and the PDF is kept beside it on the files disk, so the browser's own PDF viewer
 * shows it. The PDF is made on the queue's worker (App\Jobs\MakeFilePreview), as soon as the file is uploaded or
 * when its page first asks: never inside a request, so no page waits for LibreOffice. LibreOffice runs with a fresh profile for each file, with
 * macros off and links never updated, so a document can't run anything or pull in anything from outside; the
 * file was checked by FileTypes before it was kept. Without LibreOffice on this computer there is no preview,
 * and the file page says how to get one.
 */
final class FilePreviews
{
    /** The kinds that are turned into a PDF to show. */
    public const KINDS = ['document', 'slides', 'spreadsheet'];

    /** The old kinds of file that are also turned into the modern kind, for the tutor to read: extension → [extension, LibreOffice's format]. */
    private const MODERN = [
        'doc' => ['docx', 'docx:MS Word 2007 XML'],
        'odt' => ['docx', 'docx:MS Word 2007 XML'],
        'ppt' => ['pptx', 'pptx:Impress MS PowerPoint 2007 XML'],
        'odp' => ['pptx', 'pptx:Impress MS PowerPoint 2007 XML'],
        'xls' => ['xlsx', 'xlsx:Calc MS Excel 2007 XML'],
        'ods' => ['xlsx', 'xlsx:Calc MS Excel 2007 XML'],
    ];

    /** How long LibreOffice gets for a note shared as PDF or Word, which the student waits for. */
    private const TIMEOUT_SECONDS = 120;

    /** How long it gets for a preview, made in the background (a slow computer took more than 120 seconds on a deck). */
    public const PREVIEW_SECONDS = 300;

    /** pdf(): the PDF is being made, or waits for a free slot; ask again in a moment. */
    public const PREPARING = 'preparing';

    /** Why a conversion didn't run: every slot was taken (not the file's fault). */
    private const BUSY = 'busy: too many conversions at once';

    public function __construct(private Files $files) {}

    public static function converts(FileDetails $file): bool
    {
        return in_array($file->kind, self::KINDS, true);
    }

    /** LibreOffice's soffice on this computer, or null. */
    public static function converter(): ?string
    {
        $configured = trim((string) config('vistud.files.office'));
        if ($configured === 'none') {
            return null;
        }
        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }
        foreach ([
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        $finder = new ExecutableFinder;

        return $finder->find('soffice') ?? $finder->find('libreoffice');
    }

    /**
     * The file as a PDF to show: its key on the files disk once it is made. PREPARING while it is being made (it is
     * queued now if it isn't yet): the page asks again shortly, so no request ever waits for LibreOffice. Null when
     * it can't be made here (no LibreOffice, or LibreOffice couldn't read it). Another student's file is 404, a
     * trashed one 410.
     */
    public function pdf(Principal $by, string $id): ?string
    {
        [$file, $key] = $this->files->content($by, $id);
        if (! self::converts($file)) {
            return null;
        }
        $disk = Files::disk();
        $pdfKey = self::key($key);
        if ($disk->exists($pdfKey)) {
            return $pdfKey;
        }
        if ($disk->exists("{$pdfKey}.failed") || self::converter() === null) {
            return null;
        }

        // Queued once for a file (the job is unique); with the sync queue it has been made by now.
        MakeFilePreview::dispatch($file->id, $key, $file->extension, Guard::learner($by)->learnerId);

        return match (true) {
            $disk->exists($pdfKey) => $pdfKey,
            $disk->exists("{$pdfKey}.failed") => null,
            default => self::PREPARING,
        };
    }

    /**
     * A Word, PowerPoint or Excel file just uploaded: its PDF is queued now, so it is ready when the file is opened.
     * Not with the sync queue, which would make it inside the upload: then its page makes it when first opened.
     */
    public static function prepare(FileDetails $file, string $key): void
    {
        if (self::converts($file) && self::converter() !== null && config('queue.default') !== 'sync') {
            MakeFilePreview::dispatch($file->id, $key, $file->extension, StoredFileScope::learnerId($key));
        }
    }

    /**
     * Makes the PDF of the file whose bytes are at $key and keeps it beside them (run by App\Jobs\MakeFilePreview).
     * Null when there is nothing more to do: made, made already, being made by another worker, or not to be made (no
     * LibreOffice, the file gone, or LibreOffice ran and couldn't read it, or took too long, which is noted so it
     * isn't tried on every visit). Otherwise the seconds to wait before trying again: every conversion slot is taken,
     * or LibreOffice couldn't be run at all, which is not the file's fault and is logged.
     */
    public static function make(string $id, string $key, string $extension): ?int
    {
        $disk = Files::disk();
        $pdfKey = self::key($key);
        // One conversion of a file at a time.
        $lock = Cache::lock("vistud:preview:{$pdfKey}", self::PREVIEW_SECONDS + 30);
        if (! $lock->get()) {
            return null;
        }
        try {
            // The PDF to show, and for an old kind of file its modern copy for the tutor: whichever isn't made yet.
            $modern = self::MODERN[$extension] ?? null;
            $wanted = array_filter(
                [$pdfKey => self::pdfFormat($extension), ...($modern === null ? [] : [self::modernKey($key, $modern[0]) => $modern[1]])],
                fn (string $target) => ! $disk->exists($target) && ! $disk->exists("{$target}.failed"),
                ARRAY_FILTER_USE_KEY,
            );
            if ($wanted === [] || ! $disk->exists($key) || ($converter = self::converter()) === null) {
                return null;
            }

            $work = storage_path('app/private/previews-work/'.Str::random(20));
            File::ensureDirectoryExists("{$work}/in");
            try {
                $source = "{$work}/in/source.{$extension}";
                $in = $disk->readStream($key);
                $out = fopen($source, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);

                foreach ($wanted as $target => $format) {
                    $problem = null;
                    try {
                        $made = self::convert($converter, $work, $source, $format, null, $problem, waitSeconds: 0, timeoutSeconds: self::PREVIEW_SECONDS);
                    } catch (ProcessTimedOutException) {
                        [$made, $problem] = [null, 'LibreOffice took more than '.intdiv(self::PREVIEW_SECONDS, 60).' minutes.'];
                    }
                    // A PDF starts %PDF-; a .docx, .pptx or .xlsx is a ZIP file, which starts PK.
                    if ($made !== null && str_starts_with((string) file_get_contents($made, length: 5), $target === $pdfKey ? '%PDF-' : 'PK')) {
                        $stream = fopen($made, 'rb');
                        $disk->writeStream($target, $stream);
                        fclose($stream);
                        @unlink($made);

                        continue;
                    }
                    // Every slot taken is not the file's fault: the job comes back shortly for what is left.
                    if ($problem === self::BUSY) {
                        return 3;
                    }
                    // Not tried again on every visit: a file LibreOffice can't read won't read next time either.
                    $problem ??= 'nothing made';
                    Log::warning('A file preview could not be made.', ['file' => $id, 'made' => pathinfo($target, PATHINFO_EXTENSION), 'problem' => Str::limit($problem, 500)]);
                    $disk->put("{$target}.failed", Str::limit($problem, 500));
                }

                return null;
            } catch (Throwable $e) {
                // LibreOffice couldn't be started, or a file couldn't be written: this computer's fault, not the file's.
                Log::warning('A file preview could not be made; it is tried again shortly.', ['file' => $id, 'problem' => Str::limit($e->getMessage(), 500)]);

                return 30;
            } finally {
                File::deleteDirectory($work);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * An old Word, PowerPoint or Excel file (.doc, .ppt, .xls, or OpenDocument) as the modern kind (.docx, .pptx,
     * .xlsx), made by LibreOffice beside its preview, so the tutor reads it as one (App\Study\OfficeReader): its sheets,
     * row numbers and formulas, a deck's slides in order. [its key, its extension] once made; PREPARING while it is
     * being made (queued now if it isn't yet); null when it can't be made here, or the file needs no copy.
     *
     * @return array{0: string, 1: string}|string|null
     */
    public function modern(Principal $by, string $id): array|string|null
    {
        [$file, $key] = $this->files->content($by, $id);
        $modern = self::MODERN[$file->extension] ?? null;
        if ($modern === null) {
            return null;
        }
        $disk = Files::disk();
        $target = self::modernKey($key, $modern[0]);
        if (! $disk->exists($target) && ! $disk->exists("{$target}.failed") && self::converter() !== null) {
            // Queued once for a file (the job is unique); with the sync queue it has been made by now.
            MakeFilePreview::dispatch($file->id, $key, $file->extension, Guard::learner($by)->learnerId);
        }

        return match (true) {
            $disk->exists($target) => [$target, $modern[0]],
            $disk->exists("{$target}.failed") || self::converter() === null => null,
            default => self::PREPARING,
        };
    }

    private static function modernKey(string $storageKey, string $extension): string
    {
        return str_replace('/files/', '/previews/', $storageKey).'.'.$extension;
    }

    /**
     * What to turn a file into for its preview. A spreadsheet's sheet is one page, however wide or long, as Excel in a
     * browser shows it, instead of paper pages that cut its columns in two (and so the page number is the sheet's).
     */
    private static function pdfFormat(string $extension): string
    {
        return (FileTypes::TYPES[$extension][0] ?? '') === 'spreadsheet'
            ? 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"true"}}'
            : 'pdf';
    }

    /**
     * The previews that could not be made, newest first: what LibreOffice said, by the key of the note beside the
     * file. For `php artisan vistud:doctor` and `vistud:previews:retry`.
     *
     * @return array<string, string>
     */
    public static function failures(): array
    {
        $disk = Files::disk();
        $failed = array_filter($disk->allFiles('learners'), fn (string $path) => str_ends_with($path, '.pdf.failed') && str_contains($path, '/previews/'));
        usort($failed, fn ($a, $b) => $disk->lastModified($b) <=> $disk->lastModified($a));

        return array_combine($failed, array_map(fn ($path) => trim((string) $disk->get($path)), $failed)) ?: [];
    }

    /** Forgets every preview that could not be made, so each is tried again when its file is next opened. How many. */
    public static function retryFailed(): int
    {
        $failed = array_keys(self::failures());
        Files::disk()->delete($failed);

        return count($failed);
    }

    /**
     * LibreOffice turns $source, in the folder $work (which it fills, and the caller deletes), into $format: 'pdf',
     * or a format with its filter ('docx:MS Word 2007 XML'). A fresh profile each time, with macros off and links
     * never updated. Waits up to $waitSeconds for a free slot, and gives LibreOffice $timeoutSeconds. The file it
     * made, or null, with $problem saying why not (BUSY when no slot came free).
     */
    public static function convert(string $converter, string $work, string $source, string $format, ?string $inputFilter, ?string &$problem, int $waitSeconds = 30, int $timeoutSeconds = self::TIMEOUT_SECONDS): ?string
    {
        $slot = self::slot($waitSeconds);
        if ($slot === null) {
            $problem = self::BUSY;

            return null;
        }
        try {
            return self::run($converter, $work, $source, $format, $inputFilter, $problem, $timeoutSeconds);
        } finally {
            $slot->release();
        }
    }

    /**
     * One of the few conversions allowed at once (vistud.files.office_at_once): each LibreOffice takes about
     * 200 MB for a few seconds, so many students opening slides together never take all the memory. Waits up to
     * $waitSeconds for one to come free; null when none does.
     */
    private static function slot(int $waitSeconds): ?Lock
    {
        $slots = max(1, (int) config('vistud.files.office_at_once', 2));
        $until = microtime(true) + $waitSeconds;
        do {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $lock = Cache::lock("vistud:office-slot:{$slot}", self::PREVIEW_SECONDS + 30);
                if ($lock->get()) {
                    return $lock;
                }
            }
            if (microtime(true) >= $until) {
                return null;
            }
            usleep(250_000);
        } while (true);

        return null;
    }

    private static function run(string $converter, string $work, string $source, string $format, ?string $inputFilter, ?string &$problem, int $timeoutSeconds): ?string
    {
        File::ensureDirectoryExists("{$work}/out");
        File::ensureDirectoryExists("{$work}/profile/user");
        file_put_contents("{$work}/profile/user/registrymodifications.xcu", self::settings());

        $process = new Process([
            $converter, '-env:UserInstallation='.self::fileUrl("{$work}/profile"),
            '--headless', '--norestore', '--nolockcheck', '--nodefault', '--nologo',
            ...($inputFilter === null ? [] : ["--infilter={$inputFilter}"]),
            '--convert-to', $format, '--outdir', "{$work}/out", $source,
        ], null, PHP_OS_FAMILY === 'Windows' ? null : ['HOME' => $work]);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        $made = "{$work}/out/".pathinfo($source, PATHINFO_FILENAME).'.'.explode(':', $format)[0];
        if (is_file($made) && filesize($made) > 0) {
            return $made;
        }
        $problem = trim($process->getErrorOutput().' '.$process->getOutput()) ?: "no {$format}";

        return null;
    }

    /** Forgets the preview of a file whose bytes are at $storageKey, and its modern copy (the file is deleted). */
    public static function forget(string $storageKey): void
    {
        $keys = [self::key($storageKey), self::key($storageKey).'.failed'];
        foreach (['docx', 'pptx', 'xlsx'] as $extension) {
            $keys[] = self::modernKey($storageKey, $extension);
            $keys[] = self::modernKey($storageKey, $extension).'.failed';
        }
        Files::disk()->delete($keys);
    }

    private static function key(string $storageKey): string
    {
        return str_replace('/files/', '/previews/', $storageKey).'.pdf';
    }

    /** A local path as the file: URL LibreOffice wants for its profile (C:\Users\a b → file:///C:/Users/a%20b). */
    private static function fileUrl(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $parts = array_map(fn ($part) => preg_match('/^[A-Za-z]:$/', $part) ? $part : rawurlencode($part), $parts);

        return 'file://'.(str_starts_with($path, '/') ? '' : '/').implode('/', $parts);
    }

    /** LibreOffice settings for this run: no macros at all, and links in documents never updated. */
    private static function settings(): string
    {
        $item = fn (string $path, string $name, string $value) => "<item oor:path=\"{$path}\"><prop oor:name=\"{$name}\" oor:op=\"fuse\"><value>{$value}</value></prop></item>";

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .$item('/org.openoffice.Office.Common/Security/Scripting', 'DisableMacrosExecution', 'true')
            .$item('/org.openoffice.Office.Common/Security/Scripting', 'MacroSecurityLevel', '3')
            .$item('/org.openoffice.Office.Common/Security/Scripting', 'BlockUntrustedRefererLinks', 'true')
            // Writer: 0 always, 1 on request, 2 never. Calc: 0 always, 1 never, 2 on request.
            .$item('/org.openoffice.Office.Writer/Content/Update', 'Link', '2')
            .$item('/org.openoffice.Office.Calc/Content/Update', 'Link', '1')
            .'</oor:items>';
    }
}
