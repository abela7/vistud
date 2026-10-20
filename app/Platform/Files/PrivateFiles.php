<?php

namespace App\Platform\Files;

use App\Platform\Access\LearnerScope;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/** All S3a durable byte IO. Recording and extracted-text kinds arrive in S3b. */
final class PrivateFiles
{
    /** S3b's offline inventory must include all these trees, including trashed uploads. */
    public const S3A_BACKFILL = [
        'files' => ['disk' => 'vistud.files.disk', 'kinds' => ['upload']],
        'note-images' => ['disk' => 'vistud.files.disk', 'kinds' => ['note-image:png', 'note-image:jpg', 'note-image:webp', 'note-image:gif']],
        'previews' => ['disk' => 'vistud.files.derived_disk', 'kinds' => ['preview:pdf', 'preview:docx', 'preview:pptx', 'preview:xlsx']],
    ];

    public static function disk(string $path): FilesystemAdapter
    {
        $disk = Storage::disk(config(str_contains($path, '/previews/') ? 'vistud.files.derived_disk' : 'vistud.files.disk'));
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('Private files require a local seekable disk.');
        }

        return $disk;
    }

    /** @param resource $input
     * @return array{size:int, fingerprint:string}
     */
    public static function write(LearnerScope $scope, string $path, $input): array
    {
        $identity = FileIdentity::at($scope, $path);
        $disk = self::disk($path);
        $disk->makeDirectory(dirname($path));
        $target = $disk->path($path);
        $stage = $target.'.writing-'.bin2hex(random_bytes(16));
        $out = @fopen($stage, 'x+b');
        if ($out === false) {
            throw new RuntimeException('Private file could not be written.');
        }
        @chmod($stage, 0600);
        try {
            $key = $scope->key('files');
            $info = FileCipher::write($input, $out, $key, $identity);
            fclose($out);
            $out = null;
            $read = new PrivateFileReader(self::open($stage), $key, $identity);
            try {
                $read->verify();
            } finally {
                $read->close();
            }
            if (! @rename($stage, $target)) {
                throw new RuntimeException('Private file could not be published.');
            }

            return ['size' => $info['size'], 'fingerprint' => hash_hmac('sha256', $info['hash'], $key)];
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($stage)) {
                @unlink($stage);
            }
        }
    }

    public static function read(LearnerScope $scope, string $path): PrivateFileReader
    {
        $identity = FileIdentity::at($scope, $path);
        $key = $scope->key('files');

        return new PrivateFileReader(self::open(self::disk($path)->path($path)), $key, $identity);
    }

    /** For the existing explicitly capped image/parser consumers, not file transport. */
    public static function bytes(LearnerScope $scope, string $path, int $maximum): string
    {
        $reader = self::read($scope, $path);
        try {
            if ($reader->size > $maximum) {
                throw new RuntimeException('Private file exceeds its processing limit.');
            }
            $reader->verify();
            $bytes = '';
            foreach ($reader->chunks() as $chunk) {
                $bytes .= $chunk;
            }

            return $bytes;
        } finally {
            $reader->close();
        }
    }

    public static function temporary(LearnerScope $scope, string $path): TempWorkspace
    {
        $temp = new TempWorkspace;
        try {
            $reader = self::read($scope, $path);
            try {
                $reader->verify();
                $out = self::open($temp->path.'/input', 'x+b');
                try {
                    foreach ($reader->chunks() as $chunk) {
                        FileCipher::put($out, $chunk);
                    }
                } finally {
                    fclose($out);
                }
            } finally {
                $reader->close();
            }

            return $temp;
        } catch (\Throwable $e) {
            $temp->close();
            throw $e;
        }
    }

    /** @return resource */
    private static function open(string $path, string $mode = 'rb')
    {
        $stream = @fopen($path, $mode);
        if ($stream === false) {
            throw new RuntimeException('Private file could not be opened.');
        }

        return $stream;
    }
}
