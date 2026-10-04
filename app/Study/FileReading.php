<?php

namespace App\Study;

use App\Engine\Jobs\ReadFile;
use App\Engine\Jobs\Runner;
use App\Engine\Role;
use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;

/**
 * When the reader reads a file (docs/specs/vistud-2-blueprint.md §3.5.3, §3.6.6): at once when a file is uploaded into a
 * module, if the student has *Read my files automatically* on and their AI is set up; otherwise when they press
 * *Read now*. A picture is never sent. Nothing here reads the file: it starts the reader's job (App\Engine\Jobs\ReadFile),
 * which runs on a worker, or at the end of the request where there is none.
 */
final class FileReading
{
    public function __construct(private Runner $runner, private Settings $settings, private Files $files) {}

    /**
     * After an upload. Returns the id of the run started, or null when none was (the file isn't in a module, the
     * student turned it off, it is a picture, or their AI isn't set up: then the file waits for *Read now*).
     */
    public function afterUpload(Principal $by, FileDetails $file): ?string
    {
        if ($file->moduleId === null || $file->kind === 'image' || ! $this->settings->get($by)->autoReadFiles) {
            return null;
        }
        try {
            $this->settings->ready($by, Role::Reader);
        } catch (AppError) {
            return null;
        }

        return $this->runner->start($by, new ReadFile($file->workspaceId, $file->id));
    }

    /**
     * *Read now*: starts the reader on the file, whatever the automatic setting says.
     *
     * @return string the id of the run
     *
     * @throws AppError when the AI isn't set up (the message says what to do), or the file isn't the student's
     */
    public function readNow(Principal $by, string $fileId): string
    {
        $file = $this->files->find($by, $fileId);
        $this->settings->ready($by, Role::Reader);

        return $this->runner->start($by, new ReadFile($file->workspaceId, $file->id));
    }
}
