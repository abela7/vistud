<?php

namespace App\Study;

/**
 * Where the pictures put into notes are kept: on the files disk, under the student's own folder, as the type
 * their bytes showed when they were added (App\Http\Controllers\Api\V1\NoteImageController).
 */
final class NoteImages
{
    /** @var array<int, array{0: string, 1: string}> the image type => [MIME type, extension] */
    public const TYPES = [
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    public static function key(string $learnerId, string $id, string $extension): string
    {
        return "learners/{$learnerId}/note-images/{$id}.{$extension}";
    }

    /** @return array{0: string, 1: string, 2: string}|null the picture's [key, MIME type, extension], or null when there is none */
    public static function find(string $learnerId, string $id): ?array
    {
        foreach (self::TYPES as [$mime, $extension]) {
            $key = self::key($learnerId, $id, $extension);
            if (Files::disk()->exists($key)) {
                return [$key, $mime, $extension];
            }
        }

        return null;
    }
}
