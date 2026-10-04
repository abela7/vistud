<?php

namespace App\Engine\Jobs;

use App\Engine\Engine;
use App\Engine\Models;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Usage;
use App\Jobs\RunEngineJob;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use App\Study\Sessions;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the reader's and the helper's work and keeps a row for every run (docs/specs/vistud-2-blueprint.md §3.6.3).
 *
 * - `start()` is for work that may wait (reading a file as it is uploaded): the row is queued, and the job goes on the
 *   queue while a worker is running, otherwise to the end of this request (`dispatchAfterResponse`), so a computer
 *   without a worker still gets it done; with the sync queue (tests) it runs at once. It never throws into the
 *   request: a failure is on the row, for the screen to say.
 * - `run()` is for work the caller needs the answer of now (a session's summary at its end, a quick edit): it runs
 *   in this request, returns what the work returns and throws what the work throws, after the row has said so.
 *
 * Either way the run checks first that a key, a model for the role and the student's consent are there, and the
 * work calls the engine through Run::ask(), which counts it and stops at the month's limit.
 */
final class Runner
{
    public const SYNC = 'sync';

    public const QUEUE = 'queue';

    public const AFTER_RESPONSE = 'after_response';

    public function __construct(private Settings $settings, private Engine $engine, private Usage $usage, private Sessions $sessions, private Models $models) {}

    /** How a started job is carried out now: at once, on the queue, or at the end of this request. */
    public function mode(): string
    {
        return match (true) {
            config('queue.default') === 'sync' => self::SYNC,
            Heartbeat::alive() => self::QUEUE,
            default => self::AFTER_RESPONSE,
        };
    }

    /** Queues a job (or runs it after the response); returns the id of its row. */
    public function start(Principal $by, Job $job): string
    {
        $scope = Guard::learner($by);
        $id = $this->record($scope, $job->role(), $job->kind(), $job->workspaceId, $job->targetType, $job->targetId, 'queued');
        $carrier = [$id, (string) $by->userId, $job];
        match ($this->mode()) {
            self::SYNC => RunEngineJob::dispatchSync(...$carrier),
            self::QUEUE => RunEngineJob::dispatch(...$carrier),
            self::AFTER_RESPONSE => RunEngineJob::dispatchAfterResponse(...$carrier),
        };

        return $id;
    }

    /** Runs a started job (the queue's worker, or the end of the request). Whatever goes wrong is on the row. */
    public function execute(string $id, Principal $by, Job $job): void
    {
        $this->perform($by, $id, $job->role(), fn (Run $run) => $job->handle($by, $run), swallow: true);
    }

    /**
     * Runs $work now as one recorded run, and returns its result.
     *
     * @template T
     *
     * @param  Closure(Run): T  $work
     * @return T
     *
     * @throws AppError when the run isn't set up, is over the limit, or the work fails with one
     */
    public function run(Principal $by, Role $role, string $kind, ?string $workspaceId, ?string $targetType, ?string $targetId, Closure $work): mixed
    {
        $id = $this->record(Guard::learner($by), $role, $kind, $workspaceId, $targetType, $targetId, 'running');

        return $this->perform($by, $id, $role, $work, swallow: false);
    }

    /**
     * What a run came to, for a screen that waits for it.
     *
     * @return array{id: string, kind: string, status: string, error_code: ?string, model: ?string, attempts: int, cost_micros: int}
     *
     * @throws NotFound another student's run, or none
     */
    public function status(Principal $by, string $id): array
    {
        $row = LearnerTables::query(Guard::learner($by), 'engine_jobs')->where('id', $id)->first() ?? throw new NotFound;

        return [
            'id' => (string) $row->id, 'kind' => (string) $row->kind, 'status' => (string) $row->status,
            'error_code' => $row->error_code === null ? null : (string) $row->error_code,
            'model' => $row->model === null ? null : (string) $row->model,
            'attempts' => (int) $row->attempts, 'cost_micros' => (int) $row->cost_micros,
        ];
    }

    // ---------- Inside ----------

    private function perform(Principal $by, string $id, Role $role, Closure $work, bool $swallow): mixed
    {
        $scope = Guard::learner($by);
        $this->update($scope, $id, ['status' => 'running', 'started_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
        try {
            [$choices, $key, $model] = $this->settings->ready($by, $role);
            $result = $work(new Run($id, $role, $by, $choices, $key, $model, $this->sessions->timezone($by), $this->engine, $this->usage, $this->models));
            $this->update($scope, $id, ['status' => 'done', 'error_code' => null, 'finished_at' => now()]);

            return $result;
        } catch (Skipped $e) {
            $this->update($scope, $id, ['status' => 'skipped', 'error_code' => mb_substr($e->reason, 0, 60), 'finished_at' => now()]);

            return null;
        } catch (AppError $e) {
            $this->update($scope, $id, ['status' => 'failed', 'error_code' => mb_substr($e->errorCode, 0, 60), 'finished_at' => now()]);
            if (! $swallow) {
                throw $e;
            }
        } catch (Throwable $e) {
            $this->update($scope, $id, ['status' => 'failed', 'error_code' => 'error', 'finished_at' => now()]);
            report($e);
            if (! $swallow) {
                throw $e;
            }
        }

        return null;
    }

    private function record(LearnerScope $scope, Role $role, string $kind, ?string $workspaceId, ?string $targetType, ?string $targetId, string $status): string
    {
        $id = Ids::new();
        LearnerTables::insert($scope, 'engine_jobs', [
            'id' => $id, 'workspace_id' => $workspaceId, 'role' => $role->value, 'kind' => $kind,
            'target_type' => $targetType, 'target_id' => $targetId, 'status' => $status, 'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $values */
    private function update(LearnerScope $scope, string $id, array $values): void
    {
        LearnerTables::query($scope, 'engine_jobs')->where('id', $id)->update($values);
    }
}
