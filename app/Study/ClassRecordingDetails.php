<?php

declare(strict_types=1);

namespace App\Study;

/** One continuous part of a class recording, as the class page sees it. */
final readonly class ClassRecordingDetails
{
    public function __construct(
        public string $id,
        public string $classSessionId,
        public int $position,
        public string $mime,
        public string $extension,
        public ?string $path,
        public int $bytes,
        public int $chunks,
        public int $durationSeconds,
        public ?string $finishedAt,
        public ?string $deletedAt,
    ) {}

    public function isOpen(): bool
    {
        return $this->finishedAt === null && $this->deletedAt === null;
    }

    /** Part number and length, for the list on the class page. */
    public function line(): string
    {
        $length = $this->finishedAt === null ? 'not finished' : self::length($this->durationSeconds);

        return 'Part '.$this->position.' · '.$length;
    }

    /** A place in the recording, as 10:00 or 1:02:03. */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }

    public static function length(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        if ($minutes >= 60) {
            return intdiv($minutes, 60).' h '.($minutes % 60).' min';
        }
        if ($minutes > 0) {
            return $minutes.' min '.$rest.' s';
        }

        return $rest.' s';
    }
}
