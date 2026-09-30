<?php

namespace App\Console\Commands;

use App\Platform\Uploads\UploadLimits;
use App\Study\FilePreviews;
use App\Study\Files;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
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

        $this->table(['', 'Check', 'How to fix'], $rows);
        $this->line($failed ? 'Something ViStud needs is missing: fix the MISSING rows, then run this again.' : 'ViStud has what it needs.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
