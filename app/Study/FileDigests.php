<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Carbon\CarbonImmutable;

/**
 * What the reader has written about each file (docs/specs/vistud-2-blueprint.md §3.5.3, §3.7): a summary, an outline,
 * the topics it covers. One digest for a file's content as it is now (its hash): the same bytes are read once, and a
 * changed file is read again. A file is *read*, *reading* (a run of the reader is queued or going), *unread*, or
 * *skipped* (a picture, or no words in it). The student's own files only: another student's is 404.
 */
final class FileDigests
{
    public const SUMMARY = 600;

    public const TOPICS = 8;

    public const OUTLINE = 40;

    /** A run that has waited longer than this on a queue nobody is working is not "reading" any more, in minutes. */
    private const STALE_MINUTES = 15;

    public function __construct(private Files $files) {}

    /** The digest of the file as it is now, or none. */
    public function find(Principal $by, string $fileId): ?FileDigestDetails
    {
        $scope = Guard::learner($by);
        $file = $this->files->find($by, $fileId);

        return $this->current($scope, [$file])[$file->id] ?? null;
    }

    /**
     * What each of these files is: read, reading, unread or skipped.
     *
     * @param  list<FileDetails>  $files
     * @return array<string, array{state: string, digest: ?FileDigestDetails}>
     */
    public function states(Principal $by, array $files): array
    {
        $scope = Guard::learner($by);
        $files = array_values(array_filter($files, fn (FileDetails $file) => $file->trashedAt === null));
        $digests = $this->current($scope, $files);
        $running = $files === [] ? [] : LearnerTables::query($scope, 'engine_jobs')
            ->where('kind', 'read_file')->where('target_type', 'file')->whereIn('target_id', array_map(fn (FileDetails $f) => $f->id, $files))
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>=', CarbonImmutable::now()->subMinutes(self::STALE_MINUTES))
            ->pluck('target_id')->flip()->all();

        $states = [];
        foreach ($files as $file) {
            $digest = $digests[$file->id] ?? null;
            $states[$file->id] = ['digest' => $digest, 'state' => match (true) {
                $digest?->read() === true => 'read',
                isset($running[$file->id]) => 'reading',
                $digest !== null => 'skipped',
                default => 'unread',
            }];
        }

        return $states;
    }

    /**
     * Keeps what the reader found in a file, for the file's content as it is now (replacing an earlier digest of the
     * same content). `$reading` is what App\Engine\Jobs\ReadFile::parse returned; `$jobId` is the run, which says what it cost.
     *
     * @param  array{summary: string, outline: list<array{page: int, heading: string}>, topics: list<string>, language: ?string}  $reading
     */
    public function keep(Principal $by, string $fileId, array $reading, int $pages, int $chars, ?string $model, ?string $jobId = null): FileDigestDetails
    {
        $scope = Guard::learner($by);
        $file = $this->files->find($by, $fileId);
        $cost = $jobId === null ? 0 : (int) LearnerTables::query($scope, 'engine_jobs')->where('id', $jobId)->value('cost_micros');

        return $this->write($scope, $file, [
            'status' => 'done', 'reason' => null,
            'summary' => mb_substr($reading['summary'], 0, self::SUMMARY),
            'outline' => json_encode(array_slice($reading['outline'], 0, self::OUTLINE)),
            'topics' => json_encode(array_slice($reading['topics'], 0, self::TOPICS)),
            'language' => $reading['language'] === null ? null : mb_substr($reading['language'], 0, 30),
            'pages' => $pages, 'chars' => $chars, 'model' => $model === null ? null : mb_substr($model, 0, 120), 'cost_micros' => $cost,
        ]);
    }

    /**
     * The same bytes, read before in another file of the student's: that digest, kept for this file too and costing
     * nothing. None when the content was never read.
     */
    public function reuse(Principal $by, FileDetails $file): ?FileDigestDetails
    {
        $scope = Guard::learner($by);
        if ($file->sha256 === null) {
            return null;
        }
        $same = LearnerTables::query($scope, 'file_digests')->where('content_hash', $file->sha256)->where('status', 'done')->where('file_id', '!=', $file->id)->orderByDesc('created_at')->first();
        if ($same === null) {
            return null;
        }
        $copy = self::details($same);

        return $this->write($scope, $file, [
            'status' => 'done', 'reason' => null, 'summary' => $copy->summary, 'outline' => json_encode($copy->outline), 'topics' => json_encode($copy->topics),
            'language' => $copy->language, 'pages' => $copy->pages, 'chars' => $copy->chars, 'model' => $copy->model, 'cost_micros' => 0,
        ]);
    }

    /** Notes that there was nothing to read in the file (a picture, or no words in it), so it isn't tried again. */
    public function skipped(Principal $by, string $fileId, string $reason): FileDigestDetails
    {
        $file = $this->files->find($by, $fileId);

        return $this->write(Guard::learner($by), $file, ['status' => 'skipped', 'reason' => mb_substr($reason, 0, 30), 'summary' => null, 'outline' => null, 'topics' => null, 'language' => null]);
    }

    /** A module's files with their digests, for the tutor (a map of file id to digest). */
    public function forModule(Principal $by, string $moduleId): array
    {
        $scope = Guard::learner($by);
        LearnerTables::query($scope, 'modules')->where('id', $moduleId)->exists() || throw new NotFound;
        $files = [];
        foreach (LearnerTables::query($scope, 'files')->where('module_id', $moduleId)->whereNull('trashed_at')->orderBy('position')->get() as $row) {
            $files[] = $this->files->find($by, $row->id);
        }

        return $this->current($scope, $files);
    }

    // ---------- Inside ----------

    /**
     * @param  list<FileDetails>  $files
     * @return array<string, FileDigestDetails> by file id
     */
    private function current(LearnerScope $scope, array $files): array
    {
        if ($files === []) {
            return [];
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file->id] = $file->sha256;
        }
        $digests = [];
        foreach (LearnerTables::query($scope, 'file_digests')->whereIn('file_id', array_keys($hashes))->orderByDesc('created_at')->get() as $row) {
            if (! isset($digests[$row->file_id]) && $row->content_hash === ($hashes[$row->file_id] ?? null)) {
                $digests[$row->file_id] = self::details($row);
            }
        }

        return $digests;
    }

    /** @param array<string, mixed> $values */
    private function write(LearnerScope $scope, FileDetails $file, array $values): FileDigestDetails
    {
        $hash = (string) $file->sha256;
        $query = LearnerTables::query($scope, 'file_digests')->where('file_id', $file->id)->where('content_hash', $hash);
        if ($query->exists()) {
            $query->update($values + ['created_at' => now()]);
        } else {
            LearnerTables::insert($scope, 'file_digests', $values + ['id' => Ids::new(), 'file_id' => $file->id, 'content_hash' => $hash, 'created_at' => now()]);
        }

        return self::details(LearnerTables::query($scope, 'file_digests')->where('file_id', $file->id)->where('content_hash', $hash)->firstOrFail());
    }

    private static function details(object $row): FileDigestDetails
    {
        $list = fn (mixed $json) => is_string($json) && is_array($decoded = json_decode($json, true)) ? array_values($decoded) : [];

        return new FileDigestDetails(
            id: $row->id, fileId: $row->file_id, status: $row->status, reason: $row->reason,
            summary: (string) $row->summary,
            outline: array_map(fn (array $item) => ['page' => (int) ($item['page'] ?? 0), 'heading' => (string) ($item['heading'] ?? '')], $list($row->outline)),
            topics: array_map('strval', $list($row->topics)),
            language: $row->language, pages: (int) $row->pages, chars: (int) $row->chars,
            model: $row->model, costMicros: (int) $row->cost_micros, createdAt: (string) $row->created_at,
        );
    }
}
