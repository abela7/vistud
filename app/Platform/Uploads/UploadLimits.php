<?php

namespace App\Platform\Uploads;

/**
 * How big an upload can be (docs/architecture/conventions.md "Uploaded files"). Two limits apply: this app's
 * (vistud.files.max_bytes) and PHP's (upload_max_filesize, and post_max_size for the whole request), and the
 * lower wins. PHP's default is 2 MB, smaller than most lecture slides, so `php artisan serve` starts PHP with
 * limits that fit this app's (App\Console\Commands\ServeCommand); any other server's php.ini must allow it
 * (php artisan vistud:doctor checks).
 */
final class UploadLimits
{
    /** Room in a request beyond the file itself: the form's other fields and the multipart framing. */
    private const REQUEST_ROOM = 1024 * 1024;

    /** This app's own limit for one file, in bytes. */
    public static function wanted(): int
    {
        return (int) config('vistud.files.max_bytes');
    }

    /** The largest file that gets through, in bytes: this app's limit, or PHP's if lower. */
    public static function effective(): int
    {
        return min(array_filter([self::wanted(), self::ini('upload_max_filesize'), max(0, self::ini('post_max_size') - self::REQUEST_ROOM)]));
    }

    /**
     * The PHP settings that let a file of this app's largest size through, for a server started here.
     *
     * @return array<string, string>
     */
    public static function phpSettings(): array
    {
        $megabytes = (int) ceil(self::wanted() / (1024 * 1024));

        return [
            'upload_max_filesize' => "{$megabytes}M",
            'post_max_size' => ($megabytes + (int) ceil(self::REQUEST_ROOM / (1024 * 1024)) + 1).'M',
        ];
    }

    /** A php.ini size ("2M", "1G", "512K", "8388608") in bytes; 0 means no limit. */
    public static function ini(string $key): int
    {
        return self::bytes((string) ini_get($key));
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
