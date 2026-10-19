<?php

namespace App\Study;

use Symfony\Component\Process\Process;

/**
 * A class recording cut into pieces an audio model can take (for transcription): ffmpeg turns the browser's webm or
 * mp4 into mono mp3 at speech quality and cuts it every `$seconds`. One piece is small enough for one call, and a
 * mistake stays inside its piece. The ffmpeg program is `config('vistud.files.ffmpeg')`.
 */
class AudioPieces
{
    /**
     * @return list<string> the pieces' paths, in order, inside `$directory`
     *
     * @throws \RuntimeException when ffmpeg is missing or cannot read the recording
     */
    public function split(string $file, int $seconds, string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $process = new Process([
            (string) config('vistud.files.ffmpeg', 'ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $file, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '32k',
            '-f', 'segment', '-segment_time', (string) $seconds, '-reset_timestamps', '1',
            $directory.DIRECTORY_SEPARATOR.'piece-%03d.mp3',
        ]);
        $process->setTimeout(1800);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new \RuntimeException('ffmpeg could not be started: '.$e->getMessage());
        }
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg could not read the recording: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
        $pieces = glob($directory.DIRECTORY_SEPARATOR.'piece-*.mp3') ?: [];
        sort($pieces);

        return $pieces;
    }
}
