<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Engine\Role;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Study\CourseProfiles;
use App\Study\CourseTypes;
use App\Study\FileDetails;
use App\Study\FileDigestDetails;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Folders;
use App\Study\ModuleParts;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;

/**
 * Chooses the main topics of one part of a module from all of its files at once (the owner's ask, 2026-10-12). Each
 * file has been read into a digest (App\Engine\Jobs\ReadFile); this reads them together, says which files are study
 * material and which aren't (a timetable, a marking scheme), and picks the few main topics a student would track, in the
 * order to study them, each with why it matters, the smaller points it covers and where it is taught. They wait for the
 * student to accept (App\Study\TopicSuggestions::choose); what the AI made of each file is kept to show beside them.
 *
 * A part is a folder at the top of a module (a lecture, a lab: everything inside it is read with it), or the module
 * itself for the files outside its folders. Started when a file of the part has been read, and by "Find the main
 * topics"; while another file of the part is still being read, it waits for that one, which starts it again.
 */
final class ChooseTopics extends Job
{
    public const PROMPT = 'resources/prompts/reader-topics.md';

    /** The most main topics for one part, whatever the model says. */
    public const MAX_TOPICS = 8;

    public const MAX_COVERS = 5;

    /** The most the student can say about the last choice. */
    public const MAX_NOTE = 600;

    /** What of each file's digest goes in: its summary, its headings and the topics the reader saw. */
    private const OUTLINE = 20;

    /** How long what the AI made of the files is kept to show. */
    private const REVIEW_DAYS = 120;

    public function kind(): string
    {
        return 'choose_topics';
    }

    /** Choosing well matters more than the cost here (a few short digests): the tutor's model does it. */
    public function role(): Role
    {
        return Role::Tutor;
    }

    public function handle(Principal $by, Run $run): void
    {
        [$moduleId, $folderId, $files] = self::part($by, $this->workspaceId, (string) $this->targetType, (string) $this->targetId);
        // The files the student said the AI should leave out are not read for topics (a new file is in until they say so).
        $out = self::leftOut($by, $moduleId);
        $files = array_values(array_filter($files, fn (FileDetails $file) => ! in_array($file->id, $out, true)));
        if ($files === []) {
            $run->skip('no_files');
        }
        if (self::stillReading($by, $files)) {
            // The last file to be read starts this again, with every file read.
            $run->skip('still_reading');
        }
        $digests = app(FileDigests::class);
        $read = [];
        foreach ($files as $file) {
            $digest = $digests->find($by, $file->id);
            if ($digest !== null && $digest->read()) {
                $read[] = [$file, $digest];
            }
        }
        if ($read === []) {
            $run->skip('nothing_read');
        }

        $labels = [];
        foreach ($read as $i => [$file]) {
            $labels['F'.($i + 1)] = $file->id;
        }
        $suggestions = app(TopicSuggestions::class);
        $waiting = $suggestions->list($by, $moduleId);
        $here = array_values(array_filter($waiting, fn ($s) => $s->folderId === $folderId));
        $note = Cache::get(self::noteKey($by, $moduleId, $folderId));
        try {
            $reply = $run->ask(self::rules(), $this->message($by, $moduleId, $folderId, $read, $here, array_values(array_filter($waiting, fn ($s) => $s->folderId !== $folderId)), is_string($note) ? $note : null), 3_000);
            $choice = self::parse($reply->text, $labels, array_combine(array_keys($labels), array_map(fn (array $r) => $r[0]->fileName(), $read)));
        } catch (AppError $e) {
            // Nothing chosen: what each file's reader saw waits instead, as before, so the student still has topics to
            // pick; asked again, what was waiting stays as it was.
            if ($here === []) {
                self::fallBack($by, $moduleId, $read);
            }

            throw $e;
        }
        $suggestions->choose($by, $moduleId, $folderId, $choice['topics']);
        Cache::forget(self::noteKey($by, $moduleId, $folderId));
        Cache::put(self::reviewKey($by, $moduleId, $folderId), [
            'at' => now()->toIso8601String(),
            'files' => array_map(fn (array $f) => $f + ['name' => self::nameOf($read, $f['file'])], $choice['files']),
        ], now()->addDays(self::REVIEW_DAYS));
    }

    /** The rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * The part a folder or module is: its module, the folder (null for the module's own files) and its files, those in
     * folders inside it too.
     *
     * @return array{0: string, 1: ?string, 2: list<FileDetails>}
     */
    public static function part(Principal $by, string $workspaceId, string $type, string $id): array
    {
        $all = array_values(array_filter(app(Files::class)->list($by, $workspaceId), fn (FileDetails $file) => $file->trashedAt === null && $file->kind !== 'image'));
        if ($type === 'folder') {
            $folder = app(Folders::class)->find($by, $id);
            $folder->moduleId !== null || throw new NotFound;
            $inside = app(Folders::class)->within($by, $folder->id);

            return [$folder->moduleId, $folder->id, array_values(array_filter($all, fn (FileDetails $file) => in_array($file->folderId, $inside, true)))];
        }
        app(Modules::class)->find($by, $id);

        return [$id, null, array_values(array_filter($all, fn (FileDetails $file) => $file->moduleId === $id && $file->folderId === null))];
    }

    /** Where a file's topics are chosen: the folder at the top of its module that holds it, or its module. */
    public static function placeOf(Principal $by, FileDetails $file): ?array
    {
        if ($file->moduleId === null) {
            return null;
        }
        if ($file->folderId === null) {
            return ['module', $file->moduleId];
        }
        $path = app(Folders::class)->path($by, $file->folderId);

        return ['folder', $path[0]->id];
    }

    /**
     * What the AI made of a part's files, for the screen: when, and each file with whether it is study material and why.
     *
     * @return ?array{at: string, files: list<array{file: string, use: string, why: string, name: string}>}
     */
    public static function review(Principal $by, string $moduleId, ?string $folderId): ?array
    {
        $review = Cache::get(self::reviewKey($by, $moduleId, $folderId));

        return is_array($review) ? $review : null;
    }

    /**
     * What the AI is doing with a part now, for the screen: `reading` its files, `choosing` its topics, or null.
     */
    public static function progress(Principal $by, string $workspaceId, string $moduleId, ?string $folderId): ?string
    {
        if (self::choosing($by, $moduleId, $folderId)) {
            return 'choosing';
        }
        $out = self::leftOut($by, $moduleId);
        $files = array_values(array_filter(self::part($by, $workspaceId, $folderId === null ? 'module' : 'folder', $folderId ?? $moduleId)[2], fn (FileDetails $file) => ! in_array($file->id, $out, true)));

        return $files !== [] && self::stillReading($by, $files) ? 'reading' : null;
    }

    /**
     * Asks again, with what the student says of the last choice ("too broad: split graphs into BFS and DFS"), or with
     * nothing to say (choose again from the files). The words wait here for the run, not in the queue's payload.
     */
    public static function again(Principal $by, string $moduleId, ?string $folderId, ?string $note): void
    {
        $note = $note === null ? '' : mb_substr(trim($note), 0, self::MAX_NOTE);
        $note === '' ? Cache::forget(self::noteKey($by, $moduleId, $folderId)) : Cache::put(self::noteKey($by, $moduleId, $folderId), $note, now()->addHour());
    }

    /**
     * The files of a module the student told the AI to leave out when choosing topics: kept until they tick them again.
     *
     * @return list<string> file ids
     */
    public static function leftOut(Principal $by, string $moduleId): array
    {
        $out = Cache::get(self::outKey($by, $moduleId));

        return is_array($out) ? array_values(array_filter($out, 'is_string')) : [];
    }

    /**
     * What the student ticked among the files shown ($shown): those ticked are read for topics, the others left out; the
     * module's other files keep what was said of them.
     *
     * @param  list<string>  $shown
     * @param  list<string>  $ticked
     */
    public static function useFiles(Principal $by, string $moduleId, array $shown, array $ticked): void
    {
        $out = array_values(array_unique([...array_diff(self::leftOut($by, $moduleId), $shown), ...array_diff($shown, $ticked)]));
        $out === [] ? Cache::forget(self::outKey($by, $moduleId)) : Cache::forever(self::outKey($by, $moduleId), $out);
    }

    /** Whether the AI is choosing a part's topics now (queued or running). */
    public static function choosing(Principal $by, string $moduleId, ?string $folderId): bool
    {
        return LearnerTables::query(Guard::learner($by), 'engine_jobs')->where('kind', 'choose_topics')->whereIn('status', ['queued', 'running'])
            ->where('target_type', $folderId === null ? 'module' : 'folder')->where('target_id', $folderId ?? $moduleId)
            ->where('created_at', '>', now()->subMinutes(10))->exists();
    }

    /**
     * What the answer says, cleaned to what the app knows: each file once, by the labels given, with a use the app knows;
     * at most MAX_TOPICS topics, each named, with at most MAX_COVERS smaller points, its files by id. Not an object, or
     * no topics and no files, is a failure.
     *
     * @param  array<string, string>  $labels  F1 => the file's id
     * @param  array<string, string>  $names  F1 => the file's name: a label left in a note reads as the file it stands for
     * @return array{files: list<array{file: string, use: string, why: string}>, topics: list<array{name: string, why: string, note: string, covers: list<string>, sources: list<array{file: string, pages: string}>}>}
     */
    public static function parse(string $answer, array $labels, array $names = []): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new EngineFailed('engine_empty', 'The AI did not choose any topics. Try again.');
        }
        $clean = fn (mixed $value, int $limit) => is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $limit) : '';

        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $item) {
            $id = is_array($item) ? ($labels[strtoupper(trim((string) ($item['file'] ?? '')))] ?? null) : null;
            if ($id === null || isset($files[$id])) {
                continue;
            }
            $use = is_string($item['use'] ?? null) ? strtolower(trim($item['use'])) : '';
            $files[$id] = ['file' => $id, 'use' => in_array($use, ['core', 'support', 'skip'], true) ? $use : 'core', 'why' => $clean($item['why'] ?? null, 160)];
        }

        $topics = [];
        $seen = [];
        foreach (is_array($data['topics'] ?? null) ? $data['topics'] : [] as $item) {
            $name = is_array($item) ? $clean($item['name'] ?? null, Topics::MAX_NAME) : '';
            $key = mb_strtolower($name);
            if ($name === '' || isset($seen[$key]) || count($topics) >= self::MAX_TOPICS) {
                continue;
            }
            $seen[$key] = true;
            $covers = [];
            foreach (is_array($item['covers'] ?? null) ? $item['covers'] : [] as $point) {
                $point = $clean($point, 60);
                if ($point !== '' && mb_strtolower($point) !== $key && count($covers) < self::MAX_COVERS) {
                    $covers[] = $point;
                }
            }
            $pages = $clean($item['pages'] ?? null, 20);
            $sources = [];
            foreach (is_array($item['files'] ?? null) ? $item['files'] : [] as $label) {
                $id = is_string($label) ? ($labels[strtoupper(trim($label))] ?? null) : null;
                // A topic taught only in a file said not to be study material is left out with it.
                if ($id !== null && ($files[$id]['use'] ?? 'core') !== 'skip' && ! in_array($id, array_column($sources, 'file'), true)) {
                    $sources[] = ['file' => $id, 'pages' => $pages];
                }
            }
            if ($sources === [] && ($item['files'] ?? []) !== []) {
                continue;
            }
            // The note is the student's to read: a file's label (F2) is said as the file's name.
            $note = (string) preg_replace_callback('/\bF(\d+)\b/', fn (array $m) => $names['F'.$m[1]] ?? $m[0], $clean($item['note'] ?? null, 500));
            $topics[] = ['name' => $name, 'why' => $clean($item['why'] ?? null, 300), 'note' => mb_substr($note, 0, 500), 'covers' => $covers, 'sources' => $sources];
        }
        if ($topics === [] && $files === []) {
            throw new EngineFailed('engine_empty', 'The AI did not choose any topics. Try again.');
        }

        return ['files' => array_values($files), 'topics' => $topics];
    }

    /** Whether a file of the part has no reading yet and is being read now. */
    private static function stillReading(Principal $by, array $files): bool
    {
        $digests = app(FileDigests::class);
        $unread = array_values(array_filter(array_map(fn (FileDetails $file) => $digests->find($by, $file->id) === null ? $file->id : null, $files)));

        return $unread !== [] && LearnerTables::query(Guard::learner($by), 'engine_jobs')->where('kind', 'read_file')->whereIn('status', ['queued', 'running'])
            ->whereIn('target_id', $unread)->where('created_at', '>', now()->subMinutes(10))->exists();
    }

    /**
     * The course, the module and the part, the topics there already and those said no to, then each file's digest.
     *
     * @param  list<array{0: FileDetails, 1: FileDigestDetails}>  $read
     */
    private function message(Principal $by, string $moduleId, ?string $folderId, array $read, array $here = [], array $elsewhere = [], ?string $note = null): string
    {
        $workspace = app(Workspaces::class)->find($by, $this->workspaceId);
        $profile = app(CourseProfiles::class)->get($by, $this->workspaceId);
        $module = app(Modules::class)->find($by, $moduleId);
        $folder = $folderId !== null ? app(Folders::class)->find($by, $folderId) : null;
        $have = array_map(fn ($t) => $t->name, array_filter(app(Topics::class)->list($by, $this->workspaceId), fn ($t) => $t->moduleId === $moduleId));
        $refused = app(TopicSuggestions::class)->refused($by, $moduleId);

        $lines = ['Course: '.$workspace->name.(($kind = CourseTypes::label($workspace->type)) !== null ? " ({$kind}".($profile->level !== null ? ', '.CourseTypes::LEVELS[$profile->level][0] : '').')' : '')];
        if ($profile->about !== '') {
            $lines[] = 'About it: '.mb_substr($profile->about, 0, 500);
        }
        if ($profile->outcomes !== []) {
            $lines[] = 'What it should teach: '.implode('; ', array_slice($profile->outcomes, 0, 8));
        }
        $lines[] = 'Module: '.$module->title.($module->about !== null ? " ({$module->about})" : '');
        $lines[] = 'This part: '.($folder !== null ? $folder->name.(ModuleParts::label($folder->kind) !== null ? ' ('.mb_strtolower((string) ModuleParts::label($folder->kind)).')' : '') : 'the module\'s own files');
        $lines[] = 'Topics the student already has in this module: '.($have === [] ? 'none' : implode('; ', array_slice($have, 0, 60)));
        $lines[] = 'Topics they said no to: '.($refused === [] ? 'none' : implode('; ', array_slice($refused, 0, 40)));
        $lines[] = 'Suggested in other parts of this module: '.($elsewhere === [] ? 'none' : implode('; ', array_slice(array_map(fn ($s) => $s->name, $elsewhere), 0, 40)));
        if ($here !== []) {
            $lines[] = 'Your last choice for this part, still waiting for the student: '.implode('; ', array_map(fn ($s) => $s->name, $here));
        }
        if ($note !== null && $note !== '') {
            $lines[] = 'What the student says about your last choice (their words, between the quotes): """'.str_replace('"""', '"', $note).'"""';
        }
        $lines[] = '';
        $lines[] = 'The files, as read (between the quotes):';
        $lines[] = '"""';
        foreach ($read as $i => [$file, $digest]) {
            $lines[] = 'F'.($i + 1).": {$file->fileName()}".($digest->pages > 0 ? " ({$digest->pages} pages)" : '');
            $lines[] = 'Summary: '.$digest->summary;
            if ($digest->outline !== []) {
                $lines[] = 'Headings: '.implode('; ', array_map(fn (array $h) => "p{$h['page']} {$h['heading']}", array_slice($digest->outline, 0, self::OUTLINE)));
            }
            if ($digest->topics !== []) {
                $lines[] = 'Topics seen: '.implode('; ', $digest->topics);
            }
            $lines[] = '';
        }
        $lines[] = '"""';

        return implode("\n", $lines);
    }

    /** What each file's reader saw, waiting as it used to, when nothing could be chosen. */
    private static function fallBack(Principal $by, string $moduleId, array $read): void
    {
        $suggestions = app(TopicSuggestions::class);
        foreach ($read as [$file, $digest]) {
            $suggestions->suggest($by, $moduleId, $file->id, $digest->topics);
        }
    }

    private static function nameOf(array $read, string $fileId): string
    {
        foreach ($read as [$file]) {
            if ($file->id === $fileId) {
                return $file->fileName();
            }
        }

        return '';
    }

    private static function outKey(Principal $by, string $moduleId): string
    {
        return 'topic-files-out:'.Guard::learner($by)->learnerId.':'.$moduleId;
    }

    private static function noteKey(Principal $by, string $moduleId, ?string $folderId): string
    {
        return 'topic-again:'.Guard::learner($by)->learnerId.':'.($folderId ?? 'module:'.$moduleId);
    }

    private static function reviewKey(Principal $by, string $moduleId, ?string $folderId): string
    {
        return 'topic-review:'.Guard::learner($by)->learnerId.':'.($folderId ?? 'module:'.$moduleId);
    }
}
