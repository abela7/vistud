<?php

namespace App\Jobs;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;

/** Jobs carry an explicit learner ID and recheck their file before doing any disk work. */
final class StoredFileScope
{
    public static function check(string $learnerId, string $fileId, string $storageKey): LearnerScope
    {
        if (! ActiveLearner::check($learnerId)) {
            throw new NotFound;
        }
        $scope = LearnerScope::forJob($learnerId);
        if (! LearnerTables::query($scope, 'files')->where('id', $fileId)->where('storage_key', $storageKey)->whereNull('trashed_at')->exists()) {
            throw new NotFound;
        }

        return $scope;
    }

    /** Only for an already-authorized canonical storage key, never a request parameter. */
    public static function learnerId(string $storageKey): string
    {
        if (preg_match('#^learners/([a-f0-9-]{36})/files/[a-f0-9-]{36}$#D', $storageKey, $match) !== 1) {
            throw new NotFound;
        }

        return $match[1];
    }
}
