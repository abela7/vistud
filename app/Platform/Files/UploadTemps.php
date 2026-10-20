<?php

namespace App\Platform\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Consume framework-owned uploads, never arbitrary server paths. */
final class UploadTemps
{
    public static function using(UploadedFile $file, callable $use): mixed
    {
        try {
            return $use($file->getRealPath());
        } finally {
            self::discard([$file]);
        }
    }

    public static function discard(array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if (method_exists($file, 'delete')) {
                $file->delete();
            } elseif (($path = $file->getRealPath()) !== false && is_file($path) && ! @unlink($path)) {
                Log::warning('Private upload cleanup pending.', ['code' => 'temporary_cleanup_pending']);
            }
        }
    }

    public static function bytes(UploadedFile $file, int $maximum): string
    {
        return self::using($file, function ($path) use ($maximum) {
            if (! is_string($path) || ! is_file($path) || filesize($path) > $maximum) {
                throw new RuntimeException('Attachment exceeds its processing limit.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Attachment could not be read.');
            }

            return $bytes;
        });
    }
}
