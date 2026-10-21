<?php

namespace App\Console\Commands;

use App\Identity\ErasureLedger;
use App\Identity\RestoreErasures;
use App\Jobs\EraseLearner;
use App\Platform\Access\Principal;
use App\Platform\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryErasures extends Command
{
    protected $signature = 'vistud:erasure:retry-pending';

    protected $description = 'Requeue due unfinished erasures, including dispatch failures.';

    public function handle(): int
    {
        app(RestoreErasures::class)->recoverPending(Principal::system());
        // Accounts marked deleted before S4 had no durable erasure request.
        // Discover them without shortening the grace period or changing content.
        foreach (DB::table('users')->where('status', 'deleted')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('erasure_requests')->whereColumn('erasure_requests.user_id', 'users.id');
        })->orderBy('id')->cursor() as $user) {
            DB::transaction(function () use ($user) {
                $current = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if ($current?->status !== 'deleted') {
                    return;
                }
                $intent = app(ErasureLedger::class)->intent((object) [
                    'id' => Ids::new(), 'user_id' => $user->id,
                    'learner_ref' => DB::table('learners')->where('user_id', $user->id)->value('id'),
                    'requested_at' => now()->toDateTimeString(), 'not_before' => now()->addSeconds(max(10800, (int) config('vistud.erasure.delay_seconds', 10800)))->toDateTimeString(),
                ]);
                $id = $intent['request_id'];
                unset($intent['kind'], $intent['request_id']);
                DB::table('erasure_requests')->insertOrIgnore(['id' => $id, 'stage' => 'pending'] + $intent);
            });
        }
        foreach (DB::table('erasure_requests')->whereNull('completed_at')->where('not_before', '<=', now())->orderBy('id')->cursor() as $request) {
            EraseLearner::dispatch($request->id)->onConnection('database');
        }

        return self::SUCCESS;
    }
}
