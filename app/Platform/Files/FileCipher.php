<?php

namespace App\Platform\Files;

use RuntimeException;

/** V1 raw AES-GCM framing. See docs/architecture/private-file-format.md. */
final class FileCipher
{
    public const MAGIC = 'VSTFILE1';

    public const CHUNK = 262144;

    public const HEADER = 116;

    public const OVERHEAD = 28;

    public const MAX_BYTES = 1099511627776;

    /** @param resource $input
     * @param  resource  $output
     * @return array{size:int, hash:string}
     */
    public static function write($input, $output, string $key, FileIdentity $identity): array
    {
        self::put($output, str_repeat("\0", self::HEADER));
        $generation = random_bytes(16);
        $hash = hash_init('sha256');
        $size = $index = 0;
        while (($bytes = self::upTo($input, self::CHUNK)) !== '') {
            $size += strlen($bytes);
            if ($size > self::MAX_BYTES) {
                throw new RuntimeException('Private file is too large.');
            }
            hash_update($hash, $bytes);
            self::put($output, self::seal($bytes, $key, $identity->aad($generation, $index++, strlen($bytes))));
        }
        $digest = hash_final($hash, true);
        if (fseek($output, 0) !== 0) {
            throw new RuntimeException('Private file could not be sealed.');
        }
        self::put($output, self::MAGIC.$generation.self::seal(pack('J', $size).$digest.str_repeat("\0", 24), $key, $identity->aad($generation, -1, 64)));
        if (! fflush($output) || (function_exists('fsync') && ! fsync($output))) {
            throw new RuntimeException('Private file could not be sealed.');
        }

        return ['size' => $size, 'hash' => $digest];
    }

    public static function seal(string $bytes, string $key, string $aad): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Invalid private file key.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($bytes, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Private file encryption failed.');
        }

        return $nonce.$cipher.$tag;
    }

    public static function unseal(string $frame, string $key, string $aad): string
    {
        if (strlen($key) !== 32 || strlen($frame) < self::OVERHEAD) {
            throw new RuntimeException('Private file authentication failed.');
        }
        $plain = openssl_decrypt(substr($frame, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($frame, 0, 12), substr($frame, -16), $aad);
        if ($plain === false) {
            throw new RuntimeException('Private file authentication failed.');
        }

        return $plain;
    }

    /** @param resource $stream */
    public static function exact($stream, int $length): string
    {
        $bytes = self::upTo($stream, $length);
        if (strlen($bytes) !== $length) {
            throw new RuntimeException('Private file is incomplete.');
        }

        return $bytes;
    }

    /** @param resource $stream */
    public static function put($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Private file could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $stream */
    private static function upTo($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length && ! feof($stream)) {
            $read = fread($stream, $length - strlen($bytes));
            if ($read === false || ($read === '' && ! feof($stream))) {
                throw new RuntimeException('Private file could not be read.');
            }
            $bytes .= $read;
        }

        return $bytes;
    }
}
