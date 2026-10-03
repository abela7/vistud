<?php

namespace App\Console\Commands;

use App\Study\FilePreviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Word, PowerPoint and Excel previews that could not be made are noted, so they aren't tried on every visit
 * (App\Study\FilePreviews). Once what stopped them is fixed (LibreOffice installed or updated, a setting changed),
 * this forgets those notes: each is made again the next time its file is opened.
 */
#[Signature('vistud:previews:retry')]
#[Description('Try again the Word, PowerPoint and Excel previews that could not be made')]
class RetryPreviews extends Command
{
    public function handle(): int
    {
        $failures = FilePreviews::failures();
        if ($failures === []) {
            $this->info('No preview has failed: nothing to try again.');

            return self::SUCCESS;
        }
        foreach (array_slice(array_unique(array_values($failures)), 0, 3) as $problem) {
            $this->line('  LibreOffice said: '.Str::limit($problem, 300));
        }
        $count = FilePreviews::retryFailed();
        $this->info($count.' '.Str::plural('preview', $count).' will be made again when '.($count === 1 ? 'its file is' : 'their files are').' next opened.');

        return self::SUCCESS;
    }
}
