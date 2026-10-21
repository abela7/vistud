<?php

namespace App\Platform\Files;

use App\Platform\Access\LearnerScope;
use RuntimeException;

/** Offline only: legacy bytes are accepted here, never by runtime readers. */
final class FileConversion
{
    public static function inspect(LearnerScope $scope, string $identity, string $physical): array
    {
        $key = $scope->key('files');
        $in = self::open($physical, 'rb');
        $encrypted = fread($in, 8) === FileCipher::MAGIC;
        rewind($in);
        $hash = hash_init('sha256');
        $size = 0;
        if ($encrypted) {
            $reader = new PrivateFileReader($in, $key, FileIdentity::at($scope, $identity));
            try {
                $reader->verify();
                foreach ($reader->chunks() as $chunk) {
                    hash_update($hash, $chunk);
                    $size += strlen($chunk);
                }
            } finally {
                $reader->close();
            }
        } else {
            try {
                while (! feof($in)) {
                    $chunk = fread($in, FileCipher::CHUNK);
                    if ($chunk === false || ($chunk === '' && ! feof($in))) {
                        throw new RuntimeException('Source read failed.');
                    }
                    hash_update($hash, $chunk);
                    $size += strlen($chunk);
                }
            } finally {
                fclose($in);
            }
        }
        $raw = hash_final($hash, true);

        return ['encrypted' => $encrypted, 'size' => $size, 'sha' => bin2hex($raw), 'fingerprint' => hash_hmac('sha256', $raw, $key)];
    }

    /** Source survives until a completely verified replacement is atomically published. */
    public static function convert(LearnerScope $scope, string $identity, string $source, string $target, bool $decrypt): array
    {
        $before = self::inspect($scope, $identity, $source);
        if ($before['encrypted'] !== $decrypt && $source === $target) {
            return $before;
        }
        if ($source !== $target && is_file($target)) {
            $existing = self::inspect($scope, $identity, $target);
            if ($existing['sha'] !== $before['sha'] || $existing['size'] !== $before['size']) {
                throw new RuntimeException('Conflicting copies.');
            }
            if ($existing['encrypted'] !== $decrypt) {
                return $existing;
            }
        }
        $parent = dirname($target);
        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new RuntimeException('Target directory unavailable.');
        }
        $stage = $target.'.converting-'.bin2hex(random_bytes(16));
        $out = self::open($stage, 'x+b');
        @chmod($stage, 0600);
        $in = null;
        $reader = null;
        try {
            $in = self::open($source, 'rb');
            if ($decrypt && $before['encrypted']) {
                $reader = new PrivateFileReader($in, $scope->key('files'), FileIdentity::at($scope, $identity));
                foreach ($reader->chunks() as $bytes) {
                    FileCipher::put($out, $bytes);
                }
            } elseif (! $decrypt && ! $before['encrypted']) {
                FileCipher::write($in, $out, $scope->key('files'), FileIdentity::at($scope, $identity));
            } else {
                if (stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException('Copy failed.');
                }
            }
            if (! fflush($out) || ! fsync($out)) {
                throw new RuntimeException('Flush failed.');
            }
            fclose($out);
            $out = null;
            $reader?->close();
            if (is_resource($in)) {
                fclose($in);
            }
            $after = self::inspect($scope, $identity, $stage);
            if ($after['encrypted'] === $decrypt || $after['sha'] !== $before['sha'] || $after['size'] !== $before['size']) {
                throw new RuntimeException('Replacement verification failed.');
            }
            if (! @rename($stage, $target)) {
                throw new RuntimeException('Atomic publication failed.');
            }

            return $after;
        } finally {
            $reader?->close();
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($stage)) {
                @unlink($stage);
            }
        }
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $stream = @fopen($path, $mode);
        if ($stream === false) {
            throw new RuntimeException('Conversion file unavailable.');
        }

        return $stream;
    }
}
