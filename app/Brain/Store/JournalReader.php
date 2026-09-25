<?php

namespace App\Brain\Store;

use App\Brain\Journal\JournalEntry;
use App\Platform\Access\LearnerScope;

/** Learner-scoped reads of the journal. Another learner's IDs look exactly like missing ones. */
final class JournalReader
{
    public function __construct(private JournalStore $store) {}

    public function find(LearnerScope $scope, string $id): ?StoredEntry
    {
        $entry = $this->store->entry($scope, $id);
        if ($entry === null) {
            return null;
        }
        $blocked = $this->store->isBlocked($scope, 'event', $id);

        return new StoredEntry($entry, $blocked ? [] : $this->store->content($scope, $id), $blocked);
    }

    /** @return list<JournalEntry> in position order, optionally only up to a position (the belief view) */
    public function entries(LearnerScope $scope, ?int $upToPosition = null): array
    {
        return $this->store->entries($scope, $upToPosition);
    }

    /** @return array<string, string> field => text; empty when blocked */
    public function content(LearnerScope $scope, string $entryId): array
    {
        return $this->store->content($scope, $entryId);
    }
}
