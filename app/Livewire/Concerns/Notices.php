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

    #[Locked]
    public ?string $noticeTone = 'success';

    #[Locked]
    public ?string $noticeActionLabel = null;

    #[Locked]
    public ?string $noticeActionEvent = null;

    #[Locked]
    public ?array $noticeActionPayload = null;

    public function hydrateNotices(): void
    {
        $this->notice = null;
        $this->noticeTone = 'success';
        $this->noticeActionLabel = null;
        $this->noticeActionEvent = null;
        $this->noticeActionPayload = null;
    }

    public function notify(string $message, string $tone = 'success', ?string $actionLabel = null, ?string $actionEvent = null, ?array $actionPayload = null): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
        $this->noticeActionLabel = $actionLabel;
        $this->noticeActionEvent = $actionEvent;
        $this->noticeActionPayload = $actionPayload;
    }
}
