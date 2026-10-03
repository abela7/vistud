<?php

namespace App\Console\Commands;

use App\Platform\Uploads\UploadLimits;
use Illuminate\Foundation\Console\ServeCommand as LaravelServeCommand;
use Symfony\Component\Process\Process;

/**
 * `php artisan serve`, with PHP's upload limits raised to this app's (App\Platform\Uploads\UploadLimits).
 * PHP's own default is 2 MB, so lecture slides and papers were refused before ViStud even saw them (the
 * owner's review, 2026-09-30). On Windows the server also gets the user's temporary and profile folders
 * (found by Gemini): without TEMP and TMP PHP has nowhere to put an upload, and LibreOffice needs the profile
 * folders. It also starts the queue's worker beside the server (Gemini's report, 2026-10-03): Word, PowerPoint
 * and Excel previews are made there (App\Jobs\MakeFilePreview), because PHP's built-in server answers one request
 * at a time (on Windows it can't run more), and a request making a preview held up every other page. --no-queue
 * leaves the worker out. Everything else is Laravel's.
 */
class ServeCommand extends LaravelServeCommand
{
    /** The queue's worker, stopped with the server (Ctrl+C reaches both). */
    private ?Process $worker = null;

    public function __construct()
    {
        $this->signature .= ' {--no-queue : Do not start the queue worker beside the server}';
        parent::__construct();
    }

    public function handle()
    {
        static::$passthroughVariables = array_values(array_unique([...static::$passthroughVariables, 'TEMP', 'TMP', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA']));
        $this->startWorker();

        try {
            return parent::handle();
        } finally {
            $this->worker?->stop(5);
        }
    }

    /** Not with the sync queue (jobs run in the request then) or --no-queue, and once (serve calls itself for another port). */
    private function startWorker(): void
    {
        if ($this->worker !== null || $this->option('no-queue') || config('queue.default') === 'sync') {
            return;
        }
        // A worker left behind by a server that was closed without Ctrl+C finishes its job and stops.
        $this->callSilently('queue:restart');

        $this->worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--sleep=1'], base_path());
        $this->worker->setTimeout(null);
        $this->worker->disableOutput();
        $this->worker->start();
        $this->components->info('The queue worker is running too: Word, PowerPoint and Excel previews are made in the background.');
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
