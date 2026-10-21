<?php

namespace App\Identity;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Independent, content-free, authenticated journal. Never restored with MySQL. */
class ErasureLedger
{
    private const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    public function initialize(): void
    {
        $this->locked(function ($lock, $root) {
            if (file_exists($root.'/journal.jsonl') || file_exists($root.'/head.json')) {
                throw new RuntimeException('Erasure ledger already exists.');
            }
            $record = $this->frame(0, self::ZERO, ['kind' => 'header']);
            $this->create($root.'/journal.jsonl', $this->json($record)."\n");
            $this->publishHead($root, ['seq' => 0, 'hash' => $record['hash']]);
        });
    }

    public function intent(object $request): array
    {
        return $this->append([
            'kind' => 'intent', 'request_id' => $request->id, 'user_id' => $request->user_id,
            'learner_ref' => $request->learner_ref,
            'requested_at' => $this->date($request->requested_at),
            'not_before' => $this->date($request->not_before),
        ]);
    }

    public function completed(object $request, string $erasedAt): void
    {
        $intent = $this->intent($request);
        if ($intent['request_id'] !== $request->id) {
            throw new RuntimeException('Restore replay is required.');
        }
        $this->append([
            'kind' => 'complete', 'request_id' => $request->id, 'user_id' => $request->user_id,
            'learner_ref' => $request->learner_ref, 'erased_at' => $this->date($erasedAt),
        ]);
    }

    /** Control metadata only, at most two small records per account. */
    public function read(?string $export = null, ?string $head = null): array
    {
        return $this->locked(function ($lock, $root) use ($export, $head) {
            $saved = $this->savedHead($root);
            $state = $this->scan($export ?? $root.'/journal.jsonl');
            $expected = $export === null ? $saved['hash'] : $head;
            if (! is_string($expected) || ! hash_equals($expected, $state['hash']) || ($export === null && $state['seq'] !== $saved['seq'])) {
                throw new RuntimeException('Erasure ledger checkpoint mismatch.');
            }

            return $state;
        });
    }

    /** Finish an interrupted publication, never discard an acknowledged record. */
    public function recover(): void
    {
        $this->locked(function ($lock, $root) {
            $state = $this->scan($root.'/journal.jsonl', $this->savedHead($root));
            $file = fopen($root.'/journal.jsonl', 'r+b');
            if ($file === false) {
                throw new RuntimeException('Erasure ledger unavailable.');
            }
            try {
                if (! ftruncate($file, $state['bytes']) || ! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('Erasure ledger recovery failed.');
                }
            } finally {
                fclose($file);
            }
            $this->publishHead($root, $state);
        });
    }

    /** Exclusive new export; its head must be obtained independently at restore. */
    public function export(string $destination): array
    {
        return $this->locked(function ($lock, $root) use ($destination) {
            $saved = $this->savedHead($root);
            $state = $this->scan($root.'/journal.jsonl');
            if ($state['hash'] !== $saved['hash'] || $state['seq'] !== $saved['seq']) {
                throw new RuntimeException('Erasure ledger checkpoint mismatch.');
            }
            $parent = realpath(dirname($destination));
            $checkout = realpath(base_path());
            if ($parent === false || $checkout === false || str_starts_with(strtolower(str_replace('\\', '/', $parent)).'/', strtolower(str_replace('\\', '/', $checkout)).'/')) {
                throw new RuntimeException('Export must be outside the checkout.');
            }
            $out = @fopen($destination, 'x+b');
            if ($out === false) {
                throw new RuntimeException('Erasure export must be a new writable file.');
            }
            $in = fopen($root.'/journal.jsonl', 'rb');
            try {
                if ($in === false || stream_copy_to_stream($in, $out) === false || ! fflush($out) || ! fsync($out)) {
                    throw new RuntimeException('Erasure export could not be persisted.');
                }
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
                fclose($out);
            }
            $this->syncDirectory(dirname($destination));

            return $state;
        });
    }

    private function append(array $data): array
    {
        $this->validate($data);

        return $this->locked(function ($lock, $root) use ($data) {
            $saved = $this->savedHead($root);
            // Recover only an unacknowledged tail. The authenticated saved head
            // must still occur in the complete verified prefix, or nothing changes.
            $state = $this->scan($root.'/journal.jsonl', $saved);
            if ($data['kind'] === 'intent') {
                foreach ($state['records'] as $existing) {
                    if ($existing['intent']['user_id'] === $data['user_id']) {
                        if ($existing['intent']['learner_ref'] !== $data['learner_ref']) {
                            throw new RuntimeException('Erasure ledger identity conflict.');
                        }
                        $data = $existing['intent'];
                        break;
                    }
                }
            }
            if ($data['kind'] === 'complete' && ! isset($state['records'][$data['request_id']]['intent'])) {
                throw new RuntimeException('Erasure ledger intent missing.');
            }
            $handle = fopen($root.'/journal.jsonl', 'r+b');
            if ($handle === false) {
                throw new RuntimeException('Erasure ledger unavailable.');
            }
            try {
                if (! ftruncate($handle, $state['bytes'])) {
                    throw new RuntimeException('Erasure ledger unavailable.');
                }
                if (isset($state['records'][$data['request_id']][$data['kind']])) {
                    $old = $state['records'][$data['request_id']][$data['kind']];
                    foreach (['user_id', 'learner_ref'] as $field) {
                        if ($old[$field] !== $data[$field]) {
                            throw new RuntimeException('Erasure ledger identity conflict.');
                        }
                    }
                    $data = $old;
                } else {
                    $frame = $this->frame($state['seq'] + 1, $state['hash'], $data);
                    fseek($handle, 0, SEEK_END);
                    $line = $this->json($frame)."\n";
                    $offset = 0;
                    while ($offset < strlen($line)) {
                        $written = fwrite($handle, substr($line, $offset));
                        if ($written === false || $written === 0) {
                            throw new RuntimeException('Erasure ledger write failed.');
                        }
                        $offset += $written;
                    }
                    $state['seq'] = $frame['seq'];
                    $state['hash'] = $frame['hash'];
                }
                if (! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Erasure ledger flush failed.');
                }
            } finally {
                fclose($handle);
            }
            $this->publishHead($root, $state);

            return $data;
        });
    }

    private function scan(string $path, ?array $recover = null): array
    {
        if (is_link($path)) {
            throw new RuntimeException('Erasure ledger path is unsafe.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Erasure ledger unavailable.');
        }
        $state = ['seq' => -1, 'hash' => self::ZERO, 'bytes' => 0, 'records' => [], 'heads' => []];
        $anchored = false;
        $users = [];
        try {
            while (($line = fgets($handle, 2049)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    if ($recover !== null && $anchored && feof($handle) && strlen($line) < 2048) {
                        break;
                    }
                    throw new RuntimeException('Erasure ledger is truncated.');
                }
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record)) {
                    throw new RuntimeException('Erasure record invalid.');
                }
                $hash = $record['hash'] ?? '';
                unset($record['hash']);
                if ($this->json($record + ['hash' => $hash])."\n" !== $line) {
                    throw new RuntimeException('Erasure record encoding invalid.');
                }
                if (array_keys($record) !== ['v', 'ledger', 'seq', 'prev', 'data'] || $record['v'] !== 1 || $record['ledger'] !== config('vistud.erasure.ledger_id') || $record['seq'] !== $state['seq'] + 1 || $record['prev'] !== $state['hash'] || ! is_string($hash) || ! hash_equals($this->mac($record), $hash)) {
                    throw new RuntimeException('Erasure ledger authentication failed.');
                }
                $data = $record['data'];
                if ($record['seq'] === 0) {
                    if ($data !== ['kind' => 'header']) {
                        throw new RuntimeException('Erasure ledger header invalid.');
                    }
                } else {
                    $this->validate($data);
                    $id = $data['request_id'];
                    if (isset($state['records'][$id][$data['kind']])) {
                        throw new RuntimeException('Erasure ledger duplicate.');
                    }
                    if ($data['kind'] === 'intent') {
                        if (isset($users[$data['user_id']])) {
                            throw new RuntimeException('Erasure ledger identity conflict.');
                        }
                        $users[$data['user_id']] = $id;
                    } else {
                        $intent = $state['records'][$id]['intent'] ?? null;
                        if ($intent === null || $intent['user_id'] !== $data['user_id'] || $intent['learner_ref'] !== $data['learner_ref']) {
                            throw new RuntimeException('Erasure ledger intent missing.');
                        }
                    }
                    $state['records'][$id][$data['kind']] = $data;
                }
                $state['seq'] = $record['seq'];
                $state['hash'] = $hash;
                $state['heads'][$record['seq']] = $hash;
                $state['bytes'] = ftell($handle);
                if ($recover !== null && $state['seq'] === $recover['seq'] && hash_equals($recover['hash'], $hash)) {
                    $anchored = true;
                }
            }
        } finally {
            fclose($handle);
        }
        if ($state['seq'] < 0 || ($recover !== null && ! $anchored)) {
            throw new RuntimeException('Erasure ledger checkpoint missing.');
        }

        return $state;
    }

    private function validate(array $data): void
    {
        $kind = $data['kind'] ?? null;
        $keys = $kind === 'intent' ? ['kind', 'request_id', 'user_id', 'learner_ref', 'requested_at', 'not_before'] : ['kind', 'request_id', 'user_id', 'learner_ref', 'erased_at'];
        if (! in_array($kind, ['intent', 'complete'], true) || array_keys($data) !== $keys) {
            throw new RuntimeException('Erasure record invalid.');
        }
        foreach (['request_id', 'user_id', 'learner_ref'] as $field) {
            if ($field === 'learner_ref' && $data[$field] === null) {
                continue;
            }
            if (! is_string($data[$field]) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $data[$field])) {
                throw new RuntimeException('Erasure identity invalid.');
            }
        }
        foreach ($kind === 'intent' ? ['requested_at', 'not_before'] : ['erased_at'] as $field) {
            if (! is_string($data[$field]) || $this->date($data[$field]) !== $data[$field]) {
                throw new RuntimeException('Erasure timestamp invalid.');
            }
        }
        if ($kind === 'intent' && strtotime($data['not_before']) - strtotime($data['requested_at']) < 10800) {
            throw new RuntimeException('Erasure grace invalid.');
        }
    }

    private function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new RuntimeException('Erasure timestamp invalid.');
        }

        return $date;
    }

    private function frame(int $seq, string $prev, array $data): array
    {
        $record = ['v' => 1, 'ledger' => config('vistud.erasure.ledger_id'), 'seq' => $seq, 'prev' => $prev, 'data' => $data];

        return $record + ['hash' => $this->mac($record)];
    }

    private function savedHead(string $root): array
    {
        $raw = @file_get_contents($root.'/head.json');
        $head = $raw === false ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($head) || array_keys($head) !== ['seq', 'hash', 'mac'] || ! is_int($head['seq']) || $head['seq'] < 0 || ! is_string($head['hash']) || ! is_string($head['mac']) || ! hash_equals($this->mac(['seq' => $head['seq'], 'hash' => $head['hash']]), $head['mac'])) {
            throw new RuntimeException('Erasure ledger checkpoint invalid.');
        }

        return $head;
    }

    protected function publishHead(string $root, array $state): void
    {
        $head = ['seq' => $state['seq'], 'hash' => $state['hash']];
        $path = $root.'/head-'.bin2hex(random_bytes(16)).'.tmp';
        try {
            $this->create($path, $this->json($head + ['mac' => $this->mac($head)]));
            if (! rename($path, $root.'/head.json')) {
                throw new RuntimeException('Erasure checkpoint publication failed.');
            }
            $this->syncDirectory($root);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function create(string $path, string $bytes): void
    {
        $file = @fopen($path, 'x+b');
        if ($file === false) {
            throw new RuntimeException('Erasure ledger creation failed.');
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Erasure ledger flush failed.');
            }
        } finally {
            fclose($file);
        }
    }

    private function syncDirectory(string $root): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        } // Windows fsyncs files; deployment must verify filesystem durability.
        $directory = @fopen($root, 'r');
        if ($directory === false) {
            throw new RuntimeException('Erasure ledger directory unavailable.');
        }
        try {
            if (! fsync($directory)) {
                throw new RuntimeException('Erasure ledger directory flush failed.');
            }
        } finally {
            fclose($directory);
        }
    }

    private function locked(callable $action): mixed
    {
        $path = config('vistud.erasure.ledger_path');
        $id = config('vistud.erasure.ledger_id');
        if (! is_string($path) || $path === '' || ! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/D', $id) || ! is_dir($path) || is_link($path)) {
            throw new RuntimeException('Independent erasure ledger is not configured.');
        }
        $resolved = realpath($path);
        $checkout = realpath(base_path());
        if ($resolved === false || $checkout === false) {
            throw new RuntimeException('Independent erasure ledger unavailable.');
        }
        $root = str_replace('\\', '/', $resolved);
        $base = str_replace('\\', '/', $checkout);
        if (str_starts_with(strtolower($root).'/', strtolower($base).'/')) {
            throw new RuntimeException('Erasure ledger must be outside the checkout.');
        }
        foreach (['lock', 'journal.jsonl', 'head.json'] as $name) {
            if (is_link($root.'/'.$name)) {
                throw new RuntimeException('Erasure ledger path is unsafe.');
            }
        }
        $this->mac([]); // Fail before writing if the independent authentication key is absent.
        $lock = @fopen($root.'/lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Erasure ledger lock unavailable.');
        }
        try {
            return $action($lock, $root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function mac(array $record): string
    {
        $encoded = $_ENV['VISTUD_ERASURE_LEDGER_KEY'] ?? $_SERVER['VISTUD_ERASURE_LEDGER_KEY'] ?? getenv('VISTUD_ERASURE_LEDGER_KEY');
        $key = is_string($encoded) && str_starts_with($encoded, 'base64:') ? base64_decode(substr($encoded, 7), true) : false;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Erasure ledger authentication key unavailable.');
        }

        return hash_hmac('sha256', $this->json($record), $key);
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
