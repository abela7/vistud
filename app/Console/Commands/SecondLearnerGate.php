<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Whether a second person may connect a chat app (docs/specs/mcp-connections-plan.md M6.9).
 * It reports. A closed gate exits with a failure so it cannot be mistaken for a pass.
 */
#[Signature('vistud:gate:second-learner')]
#[Description('Report whether a second learner may connect a chat app. Closed until encryption, the isolation suites and the privacy policy are in place.')]
class SecondLearnerGate extends Command
{
    public function handle(): int
    {
        // S1 supplies key support. Only S7/S8 add executable gate checks;
        // source edits or directory presence must never open admission.
        $policy = is_file(base_path('docs/legal/privacy.md'));
        $g2 = is_dir(base_path('tests/Acceptance/G2'));
        $g3 = is_dir(base_path('tests/Acceptance/G3'));

        $this->line('per-learner keys loaded: no (runtime check pending S7/S8)');
        $this->line('notes, journal, files encrypted: no');
        $this->line('g2 suite: '.($g2 ? 'present' : 'not present (a validator writes it)'));
        $this->line('g3 erasure drill: '.($g3 ? 'present' : 'not present (a validator writes it)'));
        $this->line('privacy policy: '.($policy ? 'present' : 'missing'));
        $this->line('result: closed');

        return self::FAILURE;
    }
}
