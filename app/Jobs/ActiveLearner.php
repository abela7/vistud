<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/** Fresh checks, never a cached principal or queued account snapshot. */
final class ActiveLearner
{
    /** @phpstan-impure Account state can change during a provider call. */
    public static function check(string $learnerId): bool
    {
        return DB::table('learners')->join('users', 'users.id', '=', 'learners.user_id')
            ->where('learners.id', $learnerId)->where('users.status', 'active')->exists();
    }
}
