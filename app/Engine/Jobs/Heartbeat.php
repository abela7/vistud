<?php

namespace App\Engine\Jobs;

use Illuminate\Support\Facades\Cache;

/**
 * Whether a queue worker is running (docs/specs/vistud-2-blueprint.md §3.6.3). A worker says so every few
 * seconds while it loops and whenever it takes a job (listeners in the AppServiceProvider); a job is queued only
 * while the last beat is recent, otherwise it runs at the end of the request, so a computer without a worker
 * still works, only slower.
 */
final class Heartbeat
{
    public const KEY = 'engine.worker_seen_at';

    /** A beat counts for this many seconds. */
    public const ALIVE_FOR = 60;

    /** A worker writes at most this often, however fast it loops. */
    private const EVERY = 15;

    private static int $last = 0;

    public static function beat(): void
    {
        $now = now()->getTimestamp();
        if ($now - self::$last < self::EVERY) {
            return;
        }
        self::$last = $now;
        Cache::put(self::KEY, $now, self::ALIVE_FOR * 3);
    }

    public static function alive(): bool
    {
        $seen = Cache::get(self::KEY);

        return is_int($seen) && now()->getTimestamp() - $seen <= self::ALIVE_FOR;
    }

    /** Forgets every beat (tests, and a worker that is known to have stopped). */
    public static function forget(): void
    {
        self::$last = 0;
        Cache::forget(self::KEY);
    }
}
