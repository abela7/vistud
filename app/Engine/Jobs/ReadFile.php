<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\TopicSuggestions;

/**
 * The reader reads one file of a course (docs/specs/vistud-2-blueprint.md §3.5.3): its text in (the first 30,000
 * characters, and the first line of each later page), a digest out: a short summary, an outline, the topics it covers
 * and its language. The digest is kept for the file's content as it is, so the same bytes are read once, and the
 * topics go to the module that holds the file as suggestions for the student to add. A picture, or a file with no
 * words in it, is skipped. The file is read through the services when the job runs; the job carries only ids.
 */
final class ReadFile extends Job
{
    public const PROMPT = 'resources/prompts/reader-file.md';

    /** How much of the file's text the reader is given, in characters, and how much more of it as an outline. */
    public const TEXT_CHARS = 30_000;

    public const REST_CHARS = 3_000;

    public function __construct(string $workspaceId, string $fileId)
    {
        parent::__construct($workspaceId, 'file', $fileId);
    }

    public function kind(): string
    {
        return 'read_file';
    }

    public function handle(Principal $by, Run $run): void
    {
        $files = app(Files::class);
        $digests = app(FileDigests::class);
        $suggestions = app(TopicSuggestions::class);
        $file = $files->find($by, (string) $this->targetId);
        $file->workspaceId === $this->workspaceId || throw new NotFound;
        if ($file->trashedAt !== null) {
            $run->skip('trashed');
        }
        if (($done = $digests->find($by, $file->id)) !== null) {
            $run->skip($done->read() ? 'read' : (string) $done->reason);
        }
        // The same bytes in another file of the course: what was read there is the answer, and it costs nothing.
        if (($same = $digests->reuse($by, $file)) !== null) {
            $file->moduleId === null || $suggestions->suggest($by, $file->moduleId, $file->id, $same->topics);

            return;
        }

        $text = app(FileTexts::class)->of($by, $file->id);
        if ($text->state === FileText::PREPARING) {
            throw new Unprocessable('file_preparing', 'The file is still being prepared. Try again in a minute.');
        }
        if ($text->state === FileText::PICTURE) {
            $digests->skipped($by, $file->id, 'picture');
            $run->skip('picture');
        }
        if ($text->state !== FileText::READY || ! $text->hasWords()) {
            $digests->skipped($by, $file->id, 'no_text');
            $run->skip('no_text');
        }

        [$body, $chars] = self::body($text);
        $reply = $run->ask(self::rules(), "The file is \"{$file->fileName()}\", {$text->size()}. Its text is between the quotes.\n\"\"\"\n{$body}\n\"\"\"", 1_500);
        $reading = self::parse($reply->text);

        $digests->keep($by, $file->id, $reading, $text->count(), $chars, $reply->model, $run->id);
        $file->moduleId === null || $suggestions->suggest($by, $file->moduleId, $file->id, $reading['topics']);
    }

    /** The reader's rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * What the reader is given: each page's text under its heading up to TEXT_CHARS, then the first line of each later
     * page up to REST_CHARS more. Returns the text and how many characters of the file it holds in full.
     *
     * @return array{0: string, 1: int}
     */
    public static function body(FileText $text): array
    {
        $unit = ucfirst($text->unit);
        $blocks = [];
        $used = 0;
        $next = 0;
        foreach ($text->pages as $index => $page) {
            $page = trim($page);
            $block = "[{$unit} ".($index + 1)."]\n".($page !== '' ? $page : '(no text)');
            if ($blocks !== [] && $used + mb_strlen($block) > self::TEXT_CHARS) {
                break;
            }
            $blocks[] = mb_substr($block, 0, self::TEXT_CHARS);
            $used += mb_strlen($block);
            $next = $index + 1;
        }
        $rest = [];
        $length = 0;
        foreach (array_slice($text->pages, $next, null, true) as $index => $page) {
            $first = trim((string) strtok(trim($page)."\n", "\n"));
            $line = "{$unit} ".($index + 1).': '.($first !== '' ? mb_substr($first, 0, 100) : '(no text)');
            if ($length + mb_strlen($line) > self::REST_CHARS) {
                break;
            }
            $rest[] = $line;
            $length += mb_strlen($line) + 1;
        }

        return [implode("\n\n", $blocks).($rest === [] ? '' : "\n\nThe rest of the file, by first line:\n".implode("\n", $rest)), min($used, self::TEXT_CHARS)];
    }

    /**
     * What the answer says, cleaned and checked. An answer that isn't an object, or says nothing usable, is a failure
     * to try again.
     *
     * @return array{summary: string, outline: list<array{page: int, heading: string}>, topics: list<string>, language: ?string}
     *
     * @throws EngineFailed
     */
    public static function parse(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be read. Try again.');
        }
        $clean = fn (mixed $value, int $limit) => is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $limit) : '';

        $outline = [];
        foreach (is_array($data['outline'] ?? null) ? $data['outline'] : [] as $item) {
            $page = is_array($item) ? filter_var($item['page'] ?? null, FILTER_VALIDATE_INT) : false;
            $heading = is_array($item) ? $clean($item['heading'] ?? null, 100) : '';
            if ($page !== false && $page >= 1 && $heading !== '' && count($outline) < FileDigests::OUTLINE) {
                $outline[] = ['page' => $page, 'heading' => $heading];
            }
        }
        $topics = [];
        foreach (is_array($data['topics'] ?? null) ? $data['topics'] : [] as $name) {
            $name = $clean($name, 80);
            if ($name !== '' && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $topics), true) && count($topics) < FileDigests::TOPICS) {
                $topics[] = $name;
            }
        }
        $reading = [
            'summary' => $clean($data['summary'] ?? null, FileDigests::SUMMARY),
            'outline' => $outline,
            'topics' => $topics,
            'language' => ($language = $clean($data['language'] ?? null, 30)) === '' ? null : $language,
        ];
        if ($reading['summary'] === '' && $topics === [] && $outline === []) {
            throw new EngineFailed('engine_unreadable', 'The AI found nothing in it. Try again.');
        }

        return $reading;
    }
}
