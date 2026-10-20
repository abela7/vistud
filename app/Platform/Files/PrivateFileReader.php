<?php

namespace App\Platform\Files;

use Generator;
use RuntimeException;

/** One open immutable file; no plaintext staging for HTTP ranges. */
final class PrivateFileReader
{
    public readonly int $size;

    public readonly string $etag;

    private string $generation;

    private string $digest;

    /** @param resource $stream */
    public function __construct(private $stream, private string $key, private FileIdentity $identity)
    {
        try {
            $header = FileCipher::exact($stream, FileCipher::HEADER);
            if (substr($header, 0, 8) !== FileCipher::MAGIC) {
                throw new RuntimeException('Private file needs the verified offline conversion.');
            }
            $this->generation = substr($header, 8, 16);
            $plain = FileCipher::unseal(substr($header, 24), $key, $identity->aad($this->generation, -1, 64));
            $size = unpack('Jsize', substr($plain, 0, 8))['size'];
            if (! is_int($size) || $size < 0 || $size > FileCipher::MAX_BYTES || substr($plain, 40) !== str_repeat("\0", 24)) {
                throw new RuntimeException('Private file header is invalid.');
            }
            $this->size = $size;
            $this->digest = substr($plain, 8, 32);
            $this->etag = '"'.bin2hex($this->generation).'"';
            $chunks = intdiv($size + FileCipher::CHUNK - 1, FileCipher::CHUNK);
            if ((fstat($stream)['size'] ?? -1) !== FileCipher::HEADER + $size + $chunks * FileCipher::OVERHEAD) {
                throw new RuntimeException('Private file length is invalid.');
            }
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /** Authenticate before yielding any byte of an individual chunk. */
    public function chunks(int $start = 0, ?int $length = null): Generator
    {
        $length ??= $this->size - $start;
        if ($start < 0 || $length < 0 || $start > $this->size || $length > $this->size - $start) {
            throw new RuntimeException('Invalid private file range.');
        }
        $until = $start + $length;
        for ($index = intdiv($start, FileCipher::CHUNK); $length > 0 && $index * FileCipher::CHUNK < $until; $index++) {
            $bytes = min(FileCipher::CHUNK, $this->size - $index * FileCipher::CHUNK);
            if (fseek($this->stream, FileCipher::HEADER + $index * (FileCipher::CHUNK + FileCipher::OVERHEAD)) !== 0) {
                throw new RuntimeException('Private file could not be read.');
            }
            $plain = FileCipher::unseal(FileCipher::exact($this->stream, $bytes + FileCipher::OVERHEAD), $this->key, $this->identity->aad($this->generation, $index, $bytes));
            $offset = max(0, $start - $index * FileCipher::CHUNK);
            yield substr($plain, $offset, min($bytes - $offset, $until - ($index * FileCipher::CHUNK + $offset)));
        }
    }

    public function verify(int $start = 0, ?int $length = null): void
    {
        $hash = hash_init('sha256');
        foreach ($this->chunks($start, $length) as $chunk) {
            hash_update($hash, $chunk);
        }
        if ($start === 0 && ($length === null || $length === $this->size) && ! hash_equals($this->digest, hash_final($hash, true))) {
            throw new RuntimeException('Private file digest failed.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->key = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __debugInfo(): array
    {
        return ['size' => $this->size ?? null];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private file readers cannot be serialized.');
    }
}
