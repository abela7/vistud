<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * A component's passing notice ("The question is deleted."), shown as a
 * toast that closes itself (<x-toast>, resources/js/toasts.js). It lasts
 * one request: the next one starts without it, so drawing the component
 * again never shows it twice.
 */
trait Notices
{
    #[Locked]
    public ?string $notice = null;

    public function hydrateNotices(): void
    {
        $this->notice = null;
    }
}
