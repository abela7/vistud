<?php

namespace App\Console\Commands;

use App\Platform\Uploads\UploadLimits;
use Illuminate\Foundation\Console\ServeCommand as LaravelServeCommand;

/**
 * `php artisan serve`, with PHP's upload limits raised to this app's (App\Platform\Uploads\UploadLimits).
 * PHP's own default is 2 MB, so lecture slides and papers were refused before ViStud even saw them (the
 * owner's review, 2026-09-30). On Windows the server also gets the user's temporary and profile folders
 * (found by Gemini): without TEMP and TMP PHP has nowhere to put an upload, and LibreOffice needs the profile
 * folders. Everything else is Laravel's.
 */
class ServeCommand extends LaravelServeCommand
{
    public function handle()
    {
        static::$passthroughVariables = array_values(array_unique([...static::$passthroughVariables, 'TEMP', 'TMP', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA']));

        return parent::handle();
    }

    protected function serverCommand()
    {
        $command = parent::serverCommand();
        $settings = [];
        foreach (UploadLimits::phpSettings() as $key => $value) {
            array_push($settings, '-d', "{$key}={$value}");
        }

        // `php -d key=value -S host:port server.php`: the settings go before the server's own arguments.
        return [$command[0], ...$settings, ...array_slice($command, 1)];
    }
}
