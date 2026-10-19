<?php

namespace App\Mcp\Apps;

use Laravel\Mcp\Server\Attributes\Description;

#[Description('Shows that a study session has ended, and for how long. It changes nothing.')]
final class SessionEndCard extends ViStudApp
{
    protected string $title = 'Session ended';

    protected function kind(): string
    {
        return 'session';
    }
}
