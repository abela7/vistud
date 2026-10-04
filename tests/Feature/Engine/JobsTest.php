<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\Heartbeat;
use App\Engine\Jobs\Job;
use App\Engine\Jobs\Run;
use App\Engine\Jobs\Runner;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Usage;
use App\Identity\PrincipalFactory;
use App\Jobs\RunEngineJob;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader's and the helper's runs: recorded, counted against the month's limit, queued or run after the response. */
class JobsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private Fake $engine;

    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'helper_model' => 'fake/plain', 'consent' => true]);
        $this->runner = app(Runner::class);
        Heartbeat::forget();
    }

    /** The one row there is for $this->by. */
    private function row(?string $id = null): object
    {
        $query = LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs');
        $id !== null ? $query->where('id', $id) : null;

        return $query->orderByDesc('created_at')->firstOrFail();
    }

    private function reading(string $text = 'ok'): Job
    {
        return new ReadingJob($this->workspace, 'file', 'f1', $text);
    }

    public function test_a_run_is_recorded_with_its_role_its_target_and_what_it_cost(): void
    {
        $this->engine->will(Fake::says('A summary.', 12_000, 'fake/quick'), Fake::says('Another.', 3_000, 'fake/quick'));

        $result = $this->runner->run($this->by, Role::Reader, 'wrap_up', $this->workspace, 'session', 's1', function (Run $run) {
            $first = $run->ask('Write it.', 'The chat.', 500);
            $second = $run->ask('Again.', [['role' => 'user', 'content' => 'More.']], 300);

            return $first->text.' '.$second->text;
        });

        $this->assertSame('A summary. Another.', $result);
        $row = $this->row();
        $this->assertSame(['reader', 'wrap_up', 'session', 's1', 'done', 'fake/quick', 15_000, 200, 100, 1, null], [$row->role, $row->kind, $row->target_type, $row->target_id, $row->status, $row->model, (int) $row->cost_micros, (int) $row->tokens_in, (int) $row->tokens_out, (int) $row->attempts, $row->error_code]);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->finished_at);
        $this->assertSame($this->workspace, $row->workspace_id);

        // The engine was asked as the reader: its model, the student's training choice and no tools.
        $request = $this->engine->requests[0];
        $this->assertSame(['fake/quick', 'Write it.', true, []], [$request->model, $request->system, $request->noTraining, $request->tools]);
        $this->assertSame([['role' => 'user', 'content' => 'The chat.']], $request->messages);
    }

    public function test_a_failed_run_says_why_with_a_code_and_the_caller_still_hears_of_it(): void
    {
        $this->engine->will(fn () => throw new EngineFailed('engine_busy', 'The service is busy.'));

        try {
            $this->runner->run($this->by, Role::Reader, 'fold', $this->workspace, 'thread', 't1', fn (Run $run) => $run->ask('x', 'y'));
            $this->fail('The failure should reach the caller.');
        } catch (EngineFailed $e) {
            $this->assertSame('engine_busy', $e->errorCode);
        }
        $row = $this->row();
        $this->assertSame(['failed', 'engine_busy', 0], [$row->status, $row->error_code, (int) $row->cost_micros]);
        $this->assertNotNull($row->finished_at);
    }

    public function test_a_run_that_isnt_set_up_is_recorded_as_refused_and_asks_the_engine_nothing(): void
    {
        config(['vistud.engine.key' => '']);

        try {
            $this->runner->run($this->by, Role::Helper, 'quick', null, null, null, fn (Run $run) => $run->ask('x', 'y'));
            $this->fail('Should be refused.');
        } catch (Unprocessable $e) {
            $this->assertSame('engine_key', $e->errorCode);
        }
        $this->assertSame([], $this->engine->requests);
        $row = $this->row();
        $this->assertSame(['helper', 'quick', 'failed', 'engine_key', null], [$row->role, $row->kind, $row->status, $row->error_code, $row->workspace_id]);
    }

    public function test_the_month_limit_counts_every_role_and_stops_the_next_call(): void
    {
        // A cap of one cent, and a reader's run that has cost 0.8 cents already.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'helper_model' => 'fake/plain', 'consent' => true, 'month_cap' => '0.01']);
        $this->engine->will(Fake::says('First.', 8_000, 'fake/quick'), Fake::says('Second.', 8_000, 'fake/quick'));

        $this->runner->run($this->by, Role::Reader, 'read_file', $this->workspace, null, null, fn (Run $run) => $run->ask('x', 'y'));
        $this->assertSame(8_000, app(Usage::class)->month($this->by)['reader']);

        // 8,000 of 10,000: one more call fits (nothing is cut off in the middle of a call), and it takes the month over.
        $this->runner->run($this->by, Role::Helper, 'quick', $this->workspace, null, null, fn (Run $run) => $run->ask('x', 'y'));
        $this->assertSame(['reader' => 8_000, 'helper' => 8_000, 'total' => 16_000], array_intersect_key(app(Usage::class)->month($this->by), ['reader' => 1, 'helper' => 1, 'total' => 1]));

        // Over the limit: refused before the engine is called, and the row says so.
        try {
            $this->runner->run($this->by, Role::Reader, 'read_file', $this->workspace, null, null, fn (Run $run) => $run->ask('x', 'y'));
            $this->fail('Should be refused.');
        } catch (Unprocessable $e) {
            $this->assertSame('engine_cap', $e->errorCode);
            $this->assertStringContainsString("This month's AI use has reached its limit of $0.01.", $e->getMessage());
        }
        $this->assertCount(2, $this->engine->requests);
        $this->assertSame(['failed', 'engine_cap'], [$this->row()->status, $this->row()->error_code]);
    }

    public function test_the_month_is_counted_by_role_in_the_students_own_month(): void
    {
        $scope = LearnerScope::of($this->by);
        $row = fn (string $role, int $cost, string $at) => LearnerTables::insert($scope, 'engine_jobs', ['id' => Ids::new(), 'role' => $role, 'kind' => 'read_file', 'status' => 'done', 'cost_micros' => $cost, 'created_at' => $at]);
        $row('reader', 2_000, '2026-10-01 00:30:00');
        $row('reader', 500, '2026-10-03 12:00:00');
        $row('helper', 300, '2026-10-05 12:00:00');
        $row('reader', 99_000, '2026-09-30 23:00:00');

        $this->assertSame(['tutor' => 0, 'reader' => 2_500, 'helper' => 300, 'total' => 2_800], app(Usage::class)->month($this->by));

        // Another student sees none of it.
        $this->assertSame(0, app(Usage::class)->month($this->principal($this->student()))['total']);
    }

    public function test_a_started_job_runs_at_once_on_the_sync_queue_and_is_recorded(): void
    {
        $this->engine->will(Fake::says('Read.', 4_000, 'fake/quick'));
        $this->assertSame(Runner::SYNC, $this->runner->mode());

        $id = $this->runner->start($this->by, $this->reading());

        $status = $this->runner->status($this->by, $id);
        $this->assertSame(['read_file', 'done', null, 'fake/quick', 1, 4_000], [$status['kind'], $status['status'], $status['error_code'], $status['model'], $status['attempts'], $status['cost_micros']]);
    }

    public function test_a_started_job_goes_on_the_queue_while_a_worker_is_up_and_runs_when_the_worker_takes_it(): void
    {
        config(['queue.default' => 'database']);
        Heartbeat::beat();
        Bus::fake();
        $this->assertSame(Runner::QUEUE, $this->runner->mode());

        $id = $this->runner->start($this->by, $this->reading());

        Bus::assertDispatched(RunEngineJob::class, fn (RunEngineJob $job) => $job->rowId === $id && $job->userId === $this->ada->id);
        Bus::assertNotDispatchedAfterResponse(RunEngineJob::class);
        $this->assertSame('queued', $this->runner->status($this->by, $id)['status']);
        $this->assertSame([], $this->engine->requests);

        // The worker takes it: it runs as the student who started it.
        $this->engine->will(Fake::says('Read.', 4_000, 'fake/quick'));
        $job = Bus::dispatched(RunEngineJob::class)->first();
        $job->handle($this->runner, app(PrincipalFactory::class));
        $this->assertSame(['done', 4_000], [$this->runner->status($this->by, $id)['status'], $this->runner->status($this->by, $id)['cost_micros']]);
    }

    public function test_a_started_job_runs_after_the_response_when_no_worker_is_up(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $this->assertSame(Runner::AFTER_RESPONSE, $this->runner->mode());

        $id = $this->runner->start($this->by, $this->reading());

        Bus::assertDispatchedAfterResponse(RunEngineJob::class, fn (RunEngineJob $job) => $job->rowId === $id);
        $this->assertCount(0, Bus::dispatched(RunEngineJob::class));
        $this->assertSame('queued', $this->runner->status($this->by, $id)['status']);

        // A beat that is older than a minute doesn't count either.
        Heartbeat::beat();
        $this->assertSame(Runner::QUEUE, $this->runner->mode());
        $this->travel(61)->seconds();
        $this->assertSame(Runner::AFTER_RESPONSE, $this->runner->mode());
    }

    public function test_a_started_job_that_fails_never_throws_into_the_request_and_says_so_on_its_row(): void
    {
        $this->engine->will(fn () => throw new EngineFailed('engine_down', 'Down.'), fn () => throw new RuntimeException('A bug.'));
        Exceptions::fake();

        $failing = $this->runner->start($this->by, $this->reading());
        $this->assertSame(['failed', 'engine_down'], [$this->runner->status($this->by, $failing)['status'], $this->runner->status($this->by, $failing)['error_code']]);
        Exceptions::assertNothingReported();

        // Something unexpected is reported for the developers, and the row only says there was an error.
        $broken = $this->runner->start($this->by, $this->reading());
        $this->assertSame(['failed', 'error'], [$this->runner->status($this->by, $broken)['status'], $this->runner->status($this->by, $broken)['error_code']]);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_job_with_nothing_to_do_is_skipped_not_failed(): void
    {
        $id = $this->runner->start($this->by, new NothingToReadJob($this->workspace, 'file', 'f2'));

        $status = $this->runner->status($this->by, $id);
        $this->assertSame(['skipped', 'no_text', 0], [$status['status'], $status['error_code'], $status['cost_micros']]);
        $this->assertSame([], $this->engine->requests);
    }

    public function test_a_job_can_be_for_the_helper_and_a_run_is_only_its_students(): void
    {
        $this->engine->will(Fake::says('Done.', 1_000, 'fake/plain'));

        $id = $this->runner->start($this->by, new HelpingJob($this->workspace));

        $this->assertSame('helper', $this->row($id)->role);
        $this->assertSame('fake/plain', $this->runner->status($this->by, $id)['model']);
        $this->assertThrows(fn () => $this->runner->status($this->principal($this->student()), $id), NotFound::class);
    }

    public function test_the_carrier_names_the_row_the_account_and_the_job_and_drops_a_job_whose_student_is_gone_or_suspended(): void
    {
        $this->engine->will(Fake::says('Read.', 1_000, 'fake/quick'));
        $carrier = new RunEngineJob('row-x', $this->ada->id, new NothingToReadJob($this->workspace, 'file', 'f1'));
        $payload = serialize($carrier);
        $this->assertStringContainsString($this->ada->id, $payload);
        $this->assertStringContainsString($this->workspace, $payload);
        $this->assertSame(1, $carrier->tries);

        // A deleted account: nothing runs, nothing breaks.
        (new RunEngineJob('row-1', Ids::new(), $this->reading()))->handle($this->runner, app(PrincipalFactory::class));
        // A suspended one too.
        DB::table('users')->where('id', $this->ada->id)->update(['status' => 'suspended']);
        (new RunEngineJob('row-2', $this->ada->id, $this->reading()))->handle($this->runner, app(PrincipalFactory::class));
        $this->assertSame([], $this->engine->requests);
    }

    public function test_a_worker_says_it_is_there_while_it_loops_and_when_it_takes_a_job(): void
    {
        $this->assertFalse(Heartbeat::alive());

        Event::dispatch(new Looping('database', 'default'));
        $this->assertTrue(Heartbeat::alive());

        $this->travel(30)->seconds();
        $this->assertTrue(Heartbeat::alive());
        $this->travel(31)->seconds();
        $this->assertFalse(Heartbeat::alive());

        // The next loop says so again (a worker writes at most every 15 seconds).
        Event::dispatch(new Looping('database', 'default'));
        $this->assertTrue(Heartbeat::alive());

        Heartbeat::forget();
        $this->assertFalse(Heartbeat::alive());
        Event::dispatch(new JobProcessing('database', new FakeQueueJob));
        $this->assertTrue(Heartbeat::alive());
    }
}

/** A job that reads: asks the engine once with its text. (Jobs are serialized, even on the sync queue, so they are named.) */
final class ReadingJob extends Job
{
    public function __construct(string $workspaceId, ?string $targetType, ?string $targetId, public string $text = 'ok')
    {
        parent::__construct($workspaceId, $targetType, $targetId);
    }

    public function kind(): string
    {
        return 'read_file';
    }

    public function handle(Principal $by, Run $run): void
    {
        $run->ask('Read it.', $this->text);
    }
}

/** A job that finds nothing to read. */
final class NothingToReadJob extends Job
{
    public function kind(): string
    {
        return 'read_file';
    }

    public function handle(Principal $by, Run $run): void
    {
        $run->skip('no_text');
    }
}

/** A job done by the helper's model. */
final class HelpingJob extends Job
{
    public function kind(): string
    {
        return 'quick';
    }

    public function role(): Role
    {
        return Role::Helper;
    }

    public function handle(Principal $by, Run $run): void
    {
        $run->ask('Help.', 'x');
    }
}

/** What a worker's event carries: the queue's job, of which the listeners only read the payload. */
final class FakeQueueJob
{
    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [];
    }
}
