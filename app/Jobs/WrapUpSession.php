<?php

namespace App\Jobs;

use App\Engine\SessionChat;
use App\Identity\PrincipalFactory;
use App\Livewire\Study\WrapUpWatch;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Writes an ended session's summary and checkpoint from its chat (App\Engine\SessionChat::wrapUp) on the queue's
 * worker, so ending a session never waits for the reader (the owner's ask, 2026-10-24). While it is queued or running
 * the session's end screen says so, and the student's next pages watch for it (App\Livewire\Study\WrapUpWatch). An
 * engine that fails is tried again twice; then the session's page tries again when it is opened.
 */
final class WrapUpSession implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** A model that thinks for a long time gets this long. */
    public int $timeout = 300;

    /** If a worker dies holding it, the session can be queued again after this long. */
    public int $uniqueFor = 900;

    public function __construct(public string $userId, public string $sessionId) {}

    /** Queues the wrap-up of an ended session, and has the student's pages watch for it. */
    public static function start(Principal $by, string $sessionId): void
    {
        Cache::put(self::key($sessionId), true, now()->addMinutes(15));
        if (app()->bound('session.store') && request()->hasSession()) {
            $watched = (array) session(WrapUpWatch::KEY, []);
            session([WrapUpWatch::KEY => array_values(array_unique([...$watched, $sessionId]))]);
        }
        self::dispatch((string) $by->userId, $sessionId);
    }

    /** Whether the session's wrap-up is queued or running. */
    public static function queued(string $sessionId): bool
    {
        return Cache::has(self::key($sessionId));
    }

    public function uniqueId(): string
    {
        return $this->sessionId;
    }

    public function handle(SessionChat $chat, PrincipalFactory $principals): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            Cache::forget(self::key($this->sessionId));

            return;
        }
        try {
            $by = $principals->forUser($user, 'job');
            $chat->wrapUp($by, $this->sessionId);
            $failed = $chat->wrapPending($by, $this->sessionId);
        } catch (AppError) {
            // Suspended or deleted since, or the session is gone: nothing more to write.
            $failed = false;
        }
        // On the sync queue (tests, a local copy) a released job never comes back: nothing to wait for.
        if ($failed && $this->attempts() < $this->tries && $this->job?->getConnectionName() !== 'sync') {
            $this->release(30 * $this->attempts());

            return;
        }
        Cache::forget(self::key($this->sessionId));
    }

    private static function key(string $sessionId): string
    {
        return 'vistud.wrap-up.'.$sessionId;
    }
}
