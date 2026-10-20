<?php

namespace Tests\Unit\Platform;

use App\Platform\Files\FileCipher;
use App\Platform\Files\FileIdentity;
use App\Platform\Files\PrivateFileReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileCipherTest extends TestCase
{
    private array $streams = [];

    private function stream(string $bytes = '')
    {
        $stream = tmpfile();
        $this->streams[] = $stream;
        FileCipher::put($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        parent::tearDown();
    }

    private function identity(): FileIdentity
    {
        return new FileIdentity('synthetic-learner', 'synthetic-object', 'upload');
    }

    private function encode(string $bytes, string $key): string
    {
        $out = $this->stream();
        FileCipher::write($this->stream($bytes), $out, $key, $this->identity());
        rewind($out);

        return stream_get_contents($out);
    }

    public function test_binary_chunks_and_boundaries_round_trip_without_an_envelope_or_readable_fingerprint(): void
    {
        $key = random_bytes(32);
        $nonces = [];
        foreach ([0, 1, FileCipher::CHUNK - 1, FileCipher::CHUNK, FileCipher::CHUNK + 1, FileCipher::CHUNK * 2 + 17] as $size) {
            $plain = substr(str_repeat("private-canary\0\xff", (int) ceil($size / 16) + 1), 0, $size);
            $raw = $this->encode($plain, $key);
            $nonces[] = substr($raw, 24, 12);
            for ($i = 0; $i < (int) ceil($size / FileCipher::CHUNK); $i++) {
                $nonces[] = substr($raw, FileCipher::HEADER + $i * (FileCipher::CHUNK + 28), 12);
            }
            $this->assertSame(FileCipher::HEADER + $size + (int) ceil($size / FileCipher::CHUNK) * 28, strlen($raw));
            $this->assertStringNotContainsString(hash('sha256', $plain, true), $raw);
            $this->assertStringNotContainsString('private-canary', $raw);
            $reader = new PrivateFileReader($this->stream($raw), $key, $this->identity());
            $reader->verify();
            $this->assertSame($size, $reader->size);
            $this->assertSame($plain, implode('', iterator_to_array($reader->chunks())));
            if ($size > FileCipher::CHUNK) {
                $this->assertSame(substr($plain, FileCipher::CHUNK - 7, 15), implode('', iterator_to_array($reader->chunks(FileCipher::CHUNK - 7, min(15, $size - FileCipher::CHUNK + 7)))));
            }
            $this->assertNotSame($raw, $this->encode($plain, $key));
        }
        $this->assertCount(count($nonces), array_unique($nonces));
    }

    public function test_every_identity_field_and_the_key_are_authenticated(): void
    {
        $key = random_bytes(32);
        $raw = $this->encode('Synthetic bytes', $key);
        foreach ([new FileIdentity('other', 'synthetic-object', 'upload'), new FileIdentity('synthetic-learner', 'other', 'upload'), new FileIdentity('synthetic-learner', 'synthetic-object', 'preview:pdf')] as $wrong) {
            try {
                new PrivateFileReader($this->stream($raw), $key, $wrong);
                $this->fail('Wrong identity accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(RuntimeException::class);
        new PrivateFileReader($this->stream($raw), random_bytes(32), $this->identity());
    }

    public function test_tampered_reordered_duplicated_truncated_and_mixed_generation_frames_are_refused(): void
    {
        $key = random_bytes(32);
        $raw = $this->encode(str_repeat('a', FileCipher::CHUNK).str_repeat('b', FileCipher::CHUNK).'tail', $key);
        $slot = FileCipher::CHUNK + 28;
        $a = substr($raw, FileCipher::HEADER, $slot);
        $b = substr($raw, FileCipher::HEADER + $slot, $slot);
        $second = $this->encode(str_repeat('a', FileCipher::CHUNK).str_repeat('b', FileCipher::CHUNK).'tail', $key);
        $flipped = $raw;
        $flipped[FileCipher::HEADER + 15] = chr(ord($flipped[FileCipher::HEADER + 15]) ^ 1);
        $header = $raw;
        $header[30] = chr(ord($header[30]) ^ 1);
        foreach ([
            $flipped, $header, substr($raw, 0, -1), substr($raw, 0, -32), $raw.'x', substr($raw, 0, FileCipher::HEADER),
            substr_replace($raw, $b.$a, FileCipher::HEADER, 2 * $slot),
            substr_replace($raw, $a, FileCipher::HEADER + $slot, $slot),
            substr_replace($raw, substr($second, FileCipher::HEADER, $slot), FileCipher::HEADER, $slot),
        ] as $bad) {
            try {
                $reader = new PrivateFileReader($this->stream($bad), $key, $this->identity());
                $reader->verify();
                $this->fail('Altered file accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_range_authenticates_only_the_frames_it_reads_and_full_verification_checks_the_rest(): void
    {
        $key = random_bytes(32);
        $raw = $this->encode(str_repeat('a', FileCipher::CHUNK).str_repeat('b', FileCipher::CHUNK), $key);
        $offset = FileCipher::HEADER + FileCipher::CHUNK + 28 + 15;
        $raw[$offset] = chr(ord($raw[$offset]) ^ 1);
        $reader = new PrivateFileReader($this->stream($raw), $key, $this->identity());
        $reader->verify(0, 10);
        $this->assertSame('aaaaaaaaaa', implode('', iterator_to_array($reader->chunks(0, 10))));
        $this->expectException(RuntimeException::class);
        $reader->verify();
    }

    public function test_storage_and_full_verification_memory_do_not_grow_with_file_size(): void
    {
        $in = $this->stream();
        $out = $this->stream();
        $block = str_repeat('x', 65536);
        for ($i = 0; $i < 512; $i++) {
            FileCipher::put($in, $block);
        }
        rewind($in);
        $key = random_bytes(32);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        FileCipher::write($in, $out, $key, $this->identity());
        rewind($out);
        $reader = new PrivateFileReader($out, $key, $this->identity());
        $reader->verify();
        $this->assertSame(32 * 1024 * 1024, $reader->size);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }
}
