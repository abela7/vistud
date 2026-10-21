<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

/** The second-learner gate stays closed until its checks exist (docs/specs/mcp-connections-plan.md M6.9). */
class SecondLearnerGateTest extends TestCase
{
    public function test_the_gate_is_closed_and_says_what_is_missing(): void
    {
        $this->artisan('vistud:gate:second-learner')
            ->expectsOutputToContain('per-learner keys loaded: no')
            ->expectsOutputToContain('notes, journal, files encrypted: no')
            ->expectsOutputToContain('g2 suite: present')
            ->expectsOutputToContain('g3 erasure drill: present')
            ->expectsOutputToContain('privacy policy: present')
            ->expectsOutputToContain('student uses own AI key only: yes')
            ->expectsOutputToContain('result: closed')
            ->assertFailed();
    }
}
