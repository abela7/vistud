<?php

namespace App\Mcp\Apps;

use Laravel\Mcp\Server\Attributes\Description;

#[Description('Shows a change waiting for the student and opens the ViStud page. It never confirms the change.')]
final class ConfirmationCard extends ViStudApp
{
    protected string $title = 'Confirm in ViStud';

    protected function kind(): string
    {
        return 'confirm';
    }
}
