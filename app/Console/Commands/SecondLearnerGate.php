<?php

namespace App\Console\Commands;

use App\Identity\AdmissionGate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Whether a second person may join. It only reads. A closed gate exits
 * with a failure so it cannot be mistaken for a pass.
 */
#[Signature('vistud:gate:second-learner')]
#[Description('Report whether a second learner may join. Open only when every check passes.')]
class SecondLearnerGate extends Command
{
    public function handle(AdmissionGate $gate): int
    {
        $lines = $gate->lines();
        foreach ($lines as $line) {
            $this->line($line);
        }

        return str_ends_with($lines[array_key_last($lines)] ?? '', 'open') ? self::SUCCESS : self::FAILURE;
    }
}
