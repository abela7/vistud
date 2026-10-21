<?php

namespace Tests\Feature\Identity;

use App\Identity\ErasureLedger;
use App\Platform\Ids;
use RuntimeException;
use Tests\TestCase;

/** Builder format/recovery checks, not the independent G3 acceptance drill. */
class ErasureLedgerTest extends TestCase
{
    private function request(): object
    {
        return (object) ['id' => Ids::new(), 'user_id' => Ids::new(), 'learner_ref' => Ids::new(), 'requested_at' => now()->startOfSecond()->toDateTimeString(), 'not_before' => now()->startOfSecond()->addHours(3)->toDateTimeString()];
    }

    public function test_authenticated_content_free_export_is_immutable_and_repeated_writes_are_idempotent(): void
    {
        $ledger = app(ErasureLedger::class);
        $request = $this->request();
        $ledger->intent($request);
        $ledger->completed($request, now()->toDateTimeString());
        $first = $ledger->read();
        $ledger->intent($request);
        $ledger->completed($request, now()->addDay()->toDateTimeString());
        $this->assertSame($first, $ledger->read());
        $path = config('vistud.erasure.ledger_path').'/export.jsonl';
        $ledger->export($path);
        $this->assertSame($first, $ledger->read($path, $first['hash']));
        $this->assertThrows(fn () => $ledger->export($path), RuntimeException::class);
        $this->assertThrows(fn () => $ledger->initialize(), RuntimeException::class);
        $this->assertSame(2, $first['seq']);
        foreach ($first['records'] as $record) {
            $this->assertSame(['kind', 'request_id', 'user_id', 'learner_ref', 'requested_at', 'not_before'], array_keys($record['intent']));
            $this->assertSame(['kind', 'request_id', 'user_id', 'learner_ref', 'erased_at'], array_keys($record['complete']));
        }
    }

    public function test_altered_reordered_removed_truncated_and_wrong_site_exports_are_refused(): void
    {
        $ledger = app(ErasureLedger::class);
        $request = $this->request();
        $ledger->completed($request, now()->toDateTimeString());
        $state = $ledger->read();
        $root = config('vistud.erasure.ledger_path');
        $source = file_get_contents($root.'/journal.jsonl');
        $lines = explode("\n", trim($source));
        foreach ([str_replace($request->user_id, Ids::new(), $source), str_replace('{"v":', '{ "v":', $source), $lines[0]."\n".$lines[2]."\n".$lines[1]."\n", $lines[0]."\n".$lines[1]."\n", substr($source, 0, -5)] as $bytes) {
            file_put_contents($root.'/broken.jsonl', $bytes);
            $this->assertThrows(fn () => $ledger->read($root.'/broken.jsonl', $state['hash']), \Throwable::class);
        }
        config(['vistud.erasure.ledger_id' => Ids::new()]);
        $this->assertThrows(fn () => $ledger->read(), RuntimeException::class);
    }

    public function test_interrupted_append_recovers_only_beyond_an_authenticated_checkpoint(): void
    {
        $request = $this->request();
        $interrupted = new class extends ErasureLedger
        {
            protected function publishHead(string $root, array $state): void
            {
                throw new RuntimeException('Synthetic publication interruption.');
            }
        };
        $this->assertThrows(fn () => $interrupted->intent($request), RuntimeException::class);
        $ledger = app(ErasureLedger::class);
        $this->assertThrows(fn () => $ledger->read(), RuntimeException::class);
        $ledger->recover();
        $state = $ledger->read();
        $this->assertSame(1, $state['seq']);
        $request->id = Ids::new(); // A retried HTTP transaction must reuse the durable UUID.
        $this->assertSame(array_key_first($state['records']), $ledger->intent($request)['request_id']);
        $root = config('vistud.erasure.ledger_path');
        file_put_contents($root.'/journal.jsonl', '{"unacknowledged', FILE_APPEND);
        $ledger->recover();
        $this->assertSame($state, $ledger->read());
        $bytes = file_get_contents($root.'/journal.jsonl');
        file_put_contents($root.'/journal.jsonl', substr($bytes, 0, -8));
        $this->assertThrows(fn () => $ledger->recover(), \Throwable::class);
        $this->assertSame(substr($bytes, 0, -8), file_get_contents($root.'/journal.jsonl'));
    }

    public function test_wrong_or_missing_independent_key_and_unconfigured_path_fail_closed(): void
    {
        $ledger = app(ErasureLedger::class);
        $ledger->intent($this->request());
        $old = $_ENV['VISTUD_ERASURE_LEDGER_KEY'];
        try {
            $_ENV['VISTUD_ERASURE_LEDGER_KEY'] = 'base64:'.base64_encode(random_bytes(32));
            $this->assertThrows(fn () => $ledger->read(), RuntimeException::class);
            $_ENV['VISTUD_ERASURE_LEDGER_KEY'] = '';
            $this->assertThrows(fn () => $ledger->read(), RuntimeException::class);
        } finally {
            $_ENV['VISTUD_ERASURE_LEDGER_KEY'] = $old;
        }
        config(['vistud.erasure.ledger_path' => '']);
        $this->assertThrows(fn () => $ledger->read(), RuntimeException::class);
    }

    public function test_export_and_recovery_work_without_mysql_or_the_backup_master(): void
    {
        $ledger = app(ErasureLedger::class);
        $ledger->intent($this->request());
        $state = $ledger->read();
        config(['database.default' => 'deliberately-unavailable']);
        $master = $_ENV['VISTUD_MASTER_KEY'];
        try {
            $_ENV['VISTUD_MASTER_KEY'] = '';
            $root = config('vistud.erasure.ledger_path');
            $this->artisan('vistud:erasure:ledger', ['action' => 'status'])->assertSuccessful();
            $this->artisan('vistud:erasure:ledger', ['action' => 'recover'])->assertSuccessful();
            $this->artisan('vistud:erasure:ledger', ['action' => 'export', '--output' => $root.'/never.jsonl', '--dry-run' => true])->assertSuccessful();
            $this->assertFileDoesNotExist($root.'/never.jsonl');
            $this->artisan('vistud:erasure:ledger', ['action' => 'export', '--output' => $root.'/offline.jsonl'])->assertSuccessful();
            $this->assertSame($state, $ledger->read($root.'/offline.jsonl', $state['hash']));
        } finally {
            $_ENV['VISTUD_MASTER_KEY'] = $master;
        }
    }
}
