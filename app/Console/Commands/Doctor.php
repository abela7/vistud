<?php

namespace App\Console\Commands;

use App\Platform\Uploads\UploadLimits;
use App\Study\FilePreviews;
use App\Study\Files;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * What this computer lacks for ViStud to work fully, and how to fix it: PHP's extensions and upload limits,
 * where files are kept, the built assets, the database, and LibreOffice for Word, PowerPoint and Excel previews.
 * Exits 1 when something ViStud needs is missing.
 */
#[Signature('vistud:doctor')]
#[Description('Check this computer has what ViStud needs (extensions, upload limits, LibreOffice), and say how to fix what it lacks')]
class Doctor extends Command
{
    public function handle(): int
    {
        $ini = php_ini_loaded_file() ?: 'no php.ini is loaded; copy php.ini-development to php.ini beside php.exe';
        $rows = [];
        $failed = false;
        $check = function (string $what, bool $ok, string $fix, bool $needed = true) use (&$rows, &$failed) {
            $rows[] = [$ok ? 'OK' : ($needed ? 'MISSING' : 'optional'), $what, $ok ? '' : $fix];
            $failed = $failed || (! $ok && $needed);
        };

        $check('PHP '.PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>='), 'Install PHP 8.3 or newer.');
        foreach (['pdo_mysql' => 'the database', 'mbstring' => 'text', 'openssl' => 'logins', 'zip' => 'checking Word, PowerPoint and Excel uploads', 'fileinfo' => 'Laravel\'s file handling'] as $extension => $for) {
            $check("PHP extension {$extension} ({$for})", extension_loaded($extension), "In {$ini}, remove the ; before extension={$extension}, then restart the server.");
        }

        $wanted = UploadLimits::wanted();
        $check(
            'Uploads up to '.Number::fileSize($wanted).' (here: '.Number::fileSize(Files::maxBytes()).')',
            Files::maxBytes() >= $wanted,
            '`php artisan serve` raises this by itself. For another server (Apache in XAMPP), set in '.$ini.': upload_max_filesize = '.UploadLimits::phpSettings()['upload_max_filesize'].' and post_max_size = '.UploadLimits::phpSettings()['post_max_size'].'.',
            needed: false,
        );

        foreach ([storage_path('app'), storage_path('framework'), storage_path('logs')] as $dir) {
            $check('Writable: '.$dir, is_dir($dir) && is_writable($dir), 'Let the user running PHP write to this folder.');
        }
        $check('Assets built (public/build)', is_file(public_path('build/manifest.json')), 'Run npm install, then npm run build.');

        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (Throwable) {
            $database = false;
        }
        $check('Database connection', $database, 'Start MySQL, and check DB_HOST, DB_PORT and the passwords in .env.');

        $office = FilePreviews::converter();
        $check(
            'LibreOffice, to show Word, PowerPoint and Excel files'.($office ? " ({$office})" : ''),
            $office !== null,
            'Install LibreOffice (free, libreoffice.org; on Windows: winget install TheDocumentFoundation.LibreOffice). If it is somewhere unusual, set VISTUD_OFFICE_BINARY in .env to its soffice.',
            needed: false,
        );

        if ($office !== null) {
            // A real conversion of a tiny file: shows that LibreOffice runs from PHP here, and how long it takes.
            $work = storage_path('app/private/previews-work/doctor-'.Str::random(8));
            File::ensureDirectoryExists("{$work}/in");
            file_put_contents("{$work}/in/test.txt", "ViStud checks that LibreOffice makes a PDF.\n");
            $started = microtime(true);
            try {
                $made = FilePreviews::convert($office, $work, "{$work}/in/test.txt", 'pdf', null, $problem) !== null;
            } catch (Throwable $e) {
                [$made, $problem] = [false, $e->getMessage()];
            }
            $seconds = number_format(microtime(true) - $started, 1);
            File::deleteDirectory($work);
            $check(
                "LibreOffice makes a PDF (a small test file took {$seconds} s)",
                $made,
                'LibreOffice said: '.Str::limit((string) $problem, 200),
                needed: false,
            );
        }

        $failures = $office === null ? [] : FilePreviews::failures();
        $check(
            'Word, PowerPoint and Excel previews that could not be made: '.count($failures),
            $failures === [],
            'LibreOffice said: '.Str::limit((string) reset($failures), 200).' Fix that, then run php artisan vistud:previews:retry.',
            needed: false,
        );

        $check(
            'Previews made in the background (queue: '.config('queue.default').')',
            config('queue.default') !== 'sync',
            'Set QUEUE_CONNECTION=database in .env. `php artisan serve` starts the worker beside it; on a server, keep `php artisan queue:work` running.',
            needed: false,
        );

        $this->table(['', 'Check', 'How to fix'], $rows);
        $this->line($failed ? 'Something ViStud needs is missing: fix the MISSING rows, then run this again.' : 'ViStud has what it needs.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
