<?php

namespace App\Study;

use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Engine\Role;
use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;

/**
 * Re-reads an assignment when a file in its folder changes.
 * A new file, a deletion, or a move starts the same reading the tutor
 * already keeps, so a later chat does not use an old picture of the work.
 */
final class AssignmentReading
{
    public function __construct(
        private Activities $activities,
        private Runner $runner,
        private Settings $settings,
    ) {}

    /**
     * Starts a fresh reading when this folder belongs to an assignment.
     * Does nothing when the AI is not set up, or a reading is already waiting:
     * that one reads the folder as it is when it runs.
     */
    public function afterFile(Principal $by, ?string $folderId): void
    {
        if ($folderId === null || $folderId === '') {
            return;
        }

        $activity = $this->activities->forFolder($by, $folderId);
        if ($activity === null) {
            return;
        }

        try {
            $this->settings->ready($by, Role::Tutor);
        } catch (AppError) {
            return;
        }

        if ($this->runner->pending($by, 'understand_assignment', 'activity', $activity->id) !== null) {
            return;
        }

        $this->runner->start($by, new UnderstandAssignment($activity->workspaceId, $activity->id));
    }
}
