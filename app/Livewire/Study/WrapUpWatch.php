<?php

namespace App\Livewire\Study;

use App\Engine\SessionChat;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Errors\AppError;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * On every student page: watches the sessions whose summaries are being written in the background
 * (App\Jobs\WrapUpSession), and says so once each is saved (the owner's ask, 2026-10-24). It checks only while one
 * is being written; the list lives in the student's browser session.
 */
final class WrapUpWatch extends Component
{
    use Notices;

    public const KEY = 'vistud.wrap-watch';

    public function mount(): void
    {
        $this->check();
    }

    public function check(): void
    {
        $watched = (array) session(self::KEY, []);
        if ($watched === []) {
            return;
        }
        $by = app(PrincipalFactory::class)->fromRequest(request());
        $chat = app(SessionChat::class);
        $still = [];
        foreach ($watched as $sessionId) {
            try {
                $state = $chat->wrapState($by, (string) $sessionId);
            } catch (AppError) {
                continue;
            }
            match ($state) {
                'writing' => $still[] = $sessionId,
                'done' => $this->notify('Your session summary is saved.'),
                'empty' => null,
                default => $this->notify('Your session summary couldn\'t be written just now. It will be tried again when you open that session.', 'info'),
            };
        }
        session([self::KEY => $still]);
    }

    public function render(): View
    {
        return view('livewire.study.wrap-up-watch', ['watching' => (array) session(self::KEY, []) !== []]);
    }
}
