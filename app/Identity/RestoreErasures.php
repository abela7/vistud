<?php

namespace App\Identity;

use App\Jobs\RevokeConnections;
use App\Platform\Access\LearnerKeys;
use App\Platform\Access\Principal;
use App\Platform\Errors\Forbidden;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Replay only authenticated intent; never trust restored completion flags. */
final class RestoreErasures
{
    public function __construct(private ErasureLedger $ledger, private Erasure $erasure) {}

    public function replay(Principal $by, string $file, string $head, bool $dryRun, bool $offline): array
    {
        if (! $by->isSystem()) {
            throw new Forbidden('system_required');
        }
        if (! $dryRun && (! $offline || ! app()->isDownForMaintenance())) {
            throw new RuntimeException('Restore replay requires maintenance and stopped workers.');
        }
        $latest = $this->ledger->read();
        $snapshot = $this->ledger->read($file, $head);
        // An older prefix is usable only when all subsequent records are
        // completions of these same intents (our own interrupted replay).
        if (($latest['heads'][$snapshot['seq']] ?? null) !== $snapshot['hash'] || $this->intents($latest) !== $this->intents($snapshot)) {
            throw new RuntimeException('Fetch the latest independent erasure export.');
        }
        $intents = $this->intents($latest);
        $this->preflight($intents);
        $result = ['requests' => count($intents), 'erased' => 0, 'deferred' => 0, 'head' => $latest['hash']];
        if ($dryRun) {
            return $result;
        }
        // Close every restored account before any individual erasure begins.
        foreach ($intents as $intent) {
            $this->block($intent);
        }
        foreach ($intents as $intent) {
            if ($this->erasure->erase($by, $intent['request_id'])) {
                $result['erased']++;
            } else {
                $result['deferred']++;
            }
        }
        $now = $this->ledger->read();
        if ($this->intents($now) !== $intents) {
            throw new RuntimeException('New erasure intent arrived; keep maintenance enabled and replay the latest export.');
        }
        $result['head'] = $now['hash'];

        return $result;
    }

    /** Recover an acknowledged independent intent after a lost DB commit. */
    public function recoverPending(Principal $by): void
    {
        if (! $by->isSystem()) {
            throw new Forbidden('system_required');
        }
        $this->ledger->recover();
        $intents = $this->intents($this->ledger->read());
        $this->preflight($intents);
        $db = DB::connection();
        foreach ($intents as $intent) {
            if (! $db->table('erasure_requests')->where('id', $intent['request_id'])->exists()) {
                $this->block($intent);
            }
        }
    }

    /** Export S4a history too, without importing data from a restored backup. */
    public function synchronize(Principal $by): array
    {
        if (! $by->isSystem()) {
            throw new Forbidden('system_required');
        }
        $this->ledger->recover();
        foreach (DB::table('erasure_requests')->orderBy('id')->cursor() as $request) {
            $intent = $this->ledger->intent($request);
            if ($intent['request_id'] !== $request->id) {
                throw new RuntimeException('Restore replay is required.');
            }
            if ($request->completed_at !== null) {
                $this->ledger->completed($request, $request->completed_at);
            }
        }

        return $this->ledger->read();
    }

    private function intents(array $state): array
    {
        $intents = array_map(fn ($record) => $record['intent'], $state['records']);
        ksort($intents);

        return $intents;
    }

    private function preflight(array $intents): void
    {
        $db = DB::connection();
        foreach ($intents as $intent) {
            $learner = $db->table('learners')->where('user_id', $intent['user_id'])->value('id');
            $owner = $intent['learner_ref'] === null ? null : $db->table('learners')->where('id', $intent['learner_ref'])->value('user_id');
            $request = $db->table('erasure_requests')->where('id', $intent['request_id'])->orWhere('user_id', $intent['user_id'])->first();
            if (($learner !== null && $learner !== $intent['learner_ref']) || ($owner !== null && $owner !== $intent['user_id']) || ($request !== null && ($request->id !== $intent['request_id'] || $request->user_id !== $intent['user_id'] || $request->learner_ref !== $intent['learner_ref']))) {
                throw new RuntimeException('Restored erasure identity conflicts; no replay performed.');
            }
        }
    }

    private function block(array $intent): void
    {
        $db = DB::connection(config('vistud.database.owner_connection'));
        $db->transaction(function () use ($db, $intent) {
            $fields = $intent;
            unset($fields['kind'], $fields['request_id']);
            $db->table('erasure_requests')->updateOrInsert(['id' => $intent['request_id']], $fields + ['stage' => 'pending', 'completed_at' => null, 'error_code' => null]);
            $db->table('users')->where('id', $intent['user_id'])->update(['status' => 'deleted', 'status_changed_at' => now(), 'remember_token' => null]);
            $db->table('sessions')->where('user_id', $intent['user_id'])->delete();
            // Run directly: a queue/after-response mode cannot delay revocation.
            (new RevokeConnections($intent['user_id'], 'account'))->handle();
        });
        app(LearnerKeys::class)->forget();
    }
}
