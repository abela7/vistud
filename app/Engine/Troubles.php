<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The last time the AI service failed a student (the owner's ask, 2026-10-12): which role, which model and what the
 * service said, kept for a week so the reason outlives the page it happened on. The chat's "hasn't answered" says it,
 * and the AI settings page shows it beside the models to try. It never holds the key or what was sent.
 */
final class Troubles
{
    private const DAYS = 7;

    public function note(Principal $by, string $role, string $model, EngineFailed $e): void
    {
        LearnerCache::put(Guard::learner($by), self::cacheKey($by), [
            'role' => $role, 'model' => $model, 'code' => $e->errorCode, 'message' => mb_substr($e->getMessage(), 0, 600),
            'at' => CarbonImmutable::now()->toIso8601String(),
        ], now()->addDays(self::DAYS));
    }

    /** @return ?array{role: string, model: string, code: string, message: string, at: string} */
    public function last(Principal $by): ?array
    {
        $trouble = LearnerCache::get(Guard::learner($by), self::cacheKey($by));

        return is_array($trouble) ? $trouble : null;
    }

    /** Forgets it once the same role answers again (or the student has read it and moved on). */
    public function clear(Principal $by, ?string $role = null): void
    {
        if ($role === null || ($this->last($by)['role'] ?? null) === $role) {
            Cache::forget(self::cacheKey($by));
        }
    }

    private static function cacheKey(Principal $by): string
    {
        return 'engine-trouble:'.Guard::learner($by)->learnerId;
    }
}
