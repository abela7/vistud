<?php

namespace App\Platform\Files;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Private, leased processing directory. Production root must be memory-backed. */
final class TempWorkspace
{
    public readonly string $path;

    private mixed $lease;

    public function __construct()
    {
        $root = (string) config('vistud.files.temp_root');
        if ($root === '') {
            throw new RuntimeException('Private temporary storage is not configured.');
        }
        File::ensureDirectoryExists($root, 0700);
        $this->path = $root.'/work-'.bin2hex(random_bytes(16));
        if (! mkdir($this->path, 0700)) {
            throw new RuntimeException('Private temporary storage could not be created.');
        }
        $this->lease = fopen($this->path.'/.lease', 'x+b');
        if ($this->lease === false || ! flock($this->lease, LOCK_EX)) {
            $this->close();
            throw new RuntimeException('Private temporary storage could not be leased.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->lease)) {
            fclose($this->lease);
        }
        $this->lease = null;
        if (is_dir($this->path)) {
            if (! File::deleteDirectory($this->path)) {
                Log::warning('Private processing cleanup pending.', ['code' => 'temporary_cleanup_pending']);
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Only our old, unlocked work directories; never follows links or clears uploads. */
    public static function prune(int $olderThanSeconds = 3600, bool $strict = false): int
    {
        $root = (string) config('vistud.files.temp_root');
        if (! is_dir($root)) {
            return 0;
        }
        $removed = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || $entry->isLink() || ! $entry->isDir() || ! preg_match('/^work-[a-f0-9]{32}$/D', $entry->getFilename())) {
                continue;
            }
            $path = $entry->getPathname();
            $leasePath = $path.'/.lease';
            if (! is_file($leasePath)) {
                // Interrupted cleanup can have removed the lease first. A fresh
                // workspace always creates it before putting any private input in it.
                if ($entry->getMTime() < time() - $olderThanSeconds) {
                    $deleted = File::deleteDirectory($path);
                    if (! $deleted && $strict) {
                        throw new RuntimeException('Temporary cleanup pending.');
                    }
                    $removed += $deleted ? 1 : 0;
                }

                continue;
            }
            if (is_link($leasePath) || filemtime($leasePath) > time() - $olderThanSeconds) {
                continue;
            }
            $lease = @fopen($leasePath, 'r+b');
            if ($lease === false) {
                if ($strict) {
                    throw new RuntimeException('Temporary cleanup pending.');
                }

                continue;
            }
            if (! flock($lease, LOCK_EX | LOCK_NB)) {
                fclose($lease);
                if ($strict) {
                    throw new RuntimeException('Expired processing lease is still active.');
                }

                continue;
            }
            fclose($lease); // Windows must release every handle before deletion.
            $deleted = File::deleteDirectory($path);
            if (! $deleted && $strict) {
                throw new RuntimeException('Temporary cleanup pending.');
            }
            $removed += $deleted ? 1 : 0;
        }

        return $removed;
    }
}
