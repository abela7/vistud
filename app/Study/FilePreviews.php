<?php

namespace App\Study;

use App\Platform\Access\Principal;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Word, PowerPoint and Excel files shown in the browser (the owner's review, 2026-09-30). LibreOffice turns the
 * file into a PDF the first time it is opened (a few seconds), and the PDF is kept beside it on the files disk,
 * so the browser's own PDF viewer shows it after that. LibreOffice runs with a fresh profile for each file, with
 * macros off and links never updated, so a document can't run anything or pull in anything from outside; the
 * file was checked by FileTypes before it was kept. Without LibreOffice on this computer there is no preview,
 * and the file page says how to get one.
 */
final class FilePreviews
{
    /** The kinds that are turned into a PDF to show. */
    public const KINDS = ['document', 'slides', 'spreadsheet'];

    private const TIMEOUT_SECONDS = 120;

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
     * The file as a PDF to show: its key on the files disk, made the first time. Null when it can't be made here
     * (no LibreOffice, or LibreOffice couldn't read it). Another student's file is 404, a trashed one 410.
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
        if ($disk->exists("{$pdfKey}.failed") || ($converter = self::converter()) === null) {
            return null;
        }

        // One conversion of a file at a time: a page asking twice, or two tabs, wait for it and take its PDF.
        try {
            return Cache::lock("vistud:preview:{$pdfKey}", self::TIMEOUT_SECONDS * 2)->block(self::TIMEOUT_SECONDS * 2, function () use ($disk, $pdfKey, $key, $file, $converter, $id) {
                if ($disk->exists($pdfKey)) {
                    return $pdfKey;
                }

                return $disk->exists("{$pdfKey}.failed") ? null : $this->make($disk, $key, $pdfKey, $file, $converter, $id);
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /** Makes the PDF of the file at $key with LibreOffice and keeps it at $pdfKey; null when LibreOffice can't read the file. */
    private function make($disk, string $key, string $pdfKey, FileDetails $file, string $converter, string $id): ?string
    {
        $work = storage_path('app/private/previews-work/'.Str::random(20));
        File::ensureDirectoryExists("{$work}/in");
        try {
            $source = "{$work}/in/source.{$file->extension}";
            $in = $disk->readStream($key);
            $out = fopen($source, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $pdf = self::convert($converter, $work, $source, 'pdf', null, $problem);
            if ($pdf !== null && str_starts_with((string) file_get_contents($pdf, length: 5), '%PDF-')) {
                $stream = fopen($pdf, 'rb');
                $disk->writeStream($pdfKey, $stream);
                fclose($stream);

                return $pdfKey;
            }
            $problem ??= 'no PDF';
        } catch (Throwable $e) {
            $problem = $e->getMessage();
        } finally {
            File::deleteDirectory($work);
        }
        // Too busy is not the file's fault: it is tried again next time.
        if ($problem === self::BUSY) {
            return null;
        }

        // Not tried again on every visit: a file LibreOffice can't read won't read next time either.
        Log::warning('A file preview could not be made.', ['file' => $id, 'problem' => Str::limit($problem, 500)]);
        $disk->put("{$pdfKey}.failed", Str::limit($problem, 500));

        return null;
    }

    /**
     * LibreOffice turns $source, in the folder $work (which it fills, and the caller deletes), into $format: 'pdf',
     * or a format with its filter ('docx:MS Word 2007 XML'). A fresh profile each time, with macros off and links
     * never updated. The file it made, or null, with $problem saying why not.
     */
    public static function convert(string $converter, string $work, string $source, string $format, ?string $inputFilter, ?string &$problem): ?string
    {
        $slot = self::slot();
        if ($slot === null) {
            $problem = self::BUSY;

            return null;
        }
        try {
            return self::run($converter, $work, $source, $format, $inputFilter, $problem);
        } finally {
            $slot->release();
        }
    }

    /**
     * One of the few conversions allowed at once (vistud.files.office_at_once): each LibreOffice takes about
     * 200 MB for a few seconds, so many students opening slides together never take all the memory. Waits for
     * one to come free; null when none does in time.
     */
    private static function slot(): ?Lock
    {
        $slots = max(1, (int) config('vistud.files.office_at_once', 2));
        $until = microtime(true) + self::TIMEOUT_SECONDS;
        do {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $lock = Cache::lock("vistud:office-slot:{$slot}", self::TIMEOUT_SECONDS + 30);
                if ($lock->get()) {
                    return $lock;
                }
            }
            usleep(250_000);
        } while (microtime(true) < $until);

        return null;
    }

    private static function run(string $converter, string $work, string $source, string $format, ?string $inputFilter, ?string &$problem): ?string
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
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        $made = "{$work}/out/".pathinfo($source, PATHINFO_FILENAME).'.'.explode(':', $format)[0];
        if (is_file($made) && filesize($made) > 0) {
            return $made;
        }
        $problem = trim($process->getErrorOutput().' '.$process->getOutput()) ?: "no {$format}";

        return null;
    }

    /** Forgets the preview of a file whose bytes are at $storageKey (the file is deleted). */
    public static function forget(string $storageKey): void
    {
        Files::disk()->delete([self::key($storageKey), self::key($storageKey).'.failed']);
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
