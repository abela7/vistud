<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Fence;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Folders;

/**
 * One of the student's files, a few pages at a time: a PDF's pages, a PowerPoint's slides (with the speaker's
 * notes), a Word or text file in parts, each headed "Page 4 of 18" so the tutor can say where it is.
 */
final class ReadFileTool implements Tool
{
    /** The most pages one look-up returns, and the most characters. */
    public const PAGES = 5;

    public const CHARS = 11_000;

    public function __construct(private Files $files, private FileTexts $texts, private Folders $folders) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Reads one of the student\'s files, a few pages at a time: a PDF\'s pages, a PowerPoint\'s slides (title, text, tables, chart data, picture descriptions, hidden or not) with the speaker\'s notes, a Word document\'s pages (headings, lists and tables kept), an Excel workbook\'s sheets (row numbers, column letters, formulas beside their results), a text file in parts; each headed like "Page 4 of 18", with what it is (a slide\'s title, a sheet and its rows). Every answer starts with the file\'s details: its name, kind and size, how long it is, who made it and when. Use it for the session\'s material and any file the student names or attaches, instead of guessing what it says, and go through it a part at a time. Pictures can\'t be read as text: the student attaches them in the chat.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'file' => ['type' => 'string', 'description' => 'The file\'s name (or part of it).'],
            'pages' => ['type' => 'string', 'description' => 'Which pages (or slides, or parts): one, like "7", or a range, like "4-6"; at most '.self::PAGES.' at a time. "all" gives the file\'s details and an outline of the whole file: what each page is. The first three when left out.'],
        ], 'required' => ['file'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        // In a folder's session, a name the folder's files fit is one of them.
        $file = Lookup::here($this->files->list($by, $context->workspaceId), Lookup::folder($this->folders, $by, $context), Lookup::text($input, 'file') ?? '', fn ($f) => $f->fileName(), 'file');
        if (is_string($file)) {
            return $file;
        }
        $text = $this->texts->of($by, $file->id);
        $name = Fence::escape($file->fileName());

        return match ($text->state) {
            FileText::PICTURE => "\"{$name}\" is a picture: it can't be read as text. Ask the student to attach it in the chat, so you can see it.",
            FileText::PREPARING => "\"{$name}\" is still being converted for reading. Try again in a minute, or ask the student to share the part they're on.",
            FileText::NONE => "\"{$name}\" can't be read here. Ask the student to share the part they're on, or to attach it as pictures.",
            default => $this->pages($text, Lookup::text($input, 'pages')),
        };
    }

    private function pages(FileText $text, ?string $wanted): string
    {
        $name = Fence::escape($text->file->fileName());
        $count = $text->count();
        if ($count === 0 || ! $text->hasWords()) {
            return "\"{$name}\" has {$text->size()} but no text in them: it is probably scanned, or made of pictures. Ask the student to attach the pages they're on as pictures.\n".$text->profile();
        }
        if ($wanted !== null && in_array(mb_strtolower(trim($wanted)), ['all', 'outline', 'everything'], true)) {
            return $this->outline($text);
        }
        [$from, $to] = self::range($wanted, $count);
        if ($from === null) {
            return "\"{$name}\" has {$text->size()}: ask for {$text->unit}s 1 to {$count}.\n".$text->profile();
        }
        $out = [];
        $length = 0;
        for ($number = $from; $number <= $to; $number++) {
            $page = trim($text->pages[$number - 1]);
            $caption = $text->caption($number);
            $block = '--- '.$text->label($number).($caption !== '' && ! str_starts_with($page, $caption) ? ': '.$caption : '')." ---\n".($page !== '' ? $page : '(no text on this '.$text->unit.')');
            if ($out !== [] && $length + mb_strlen($block) > self::CHARS) {
                $out[] = "(Stopped before {$text->unit} {$number}, to keep this short: ask for {$text->unit} {$number} on.)";
                $to = $number - 1;
                break;
            }
            $out[] = $block;
            $length += mb_strlen($block);
        }
        $span = $from === $to ? "{$text->unit} {$from}" : "{$text->unit}s {$from}-{$to}";

        return "\"{$name}\": {$span} of {$count}.\n".$text->profile()."\n\n".Fence::wrap(implode("\n\n", $out));
    }

    /** The whole file at a glance: what each page is (a slide's title, a sheet and its rows, or its first line). */
    private function outline(FileText $text): string
    {
        $lines = [];
        foreach ($text->pages as $i => $page) {
            $caption = trim((string) strtok($text->caption($i + 1)."\n", "\n"));
            $first = $caption !== '' ? $caption : trim((string) strtok(trim($page)."\n", "\n"));
            $lines[] = ucfirst($text->unit).' '.($i + 1).': '.($first !== '' ? mb_substr($first, 0, 100) : '(no text)');
        }
        $name = Fence::escape($text->file->fileName());

        return "\"{$name}\": an outline of its {$text->size()}, what each one is. Read any of them with pages.\n".$text->profile()."\n\n".Fence::wrap(implode("\n", $lines));
    }

    /**
     * The pages asked for, within the file and at most PAGES of them; the first three when none were named.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function range(?string $wanted, int $count): array
    {
        if ($wanted === null) {
            return [1, min($count, 3)];
        }
        if (preg_match('/^\s*(\d+)\s*(?:[-–—to]+\s*(\d+))?\s*$/u', $wanted, $m) !== 1) {
            return [1, min($count, 3)];
        }
        $from = max(1, (int) $m[1]);
        $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $from;
        if ($from > $count) {
            return [null, null];
        }
        $to = min($count, max($from, $to), $from + self::PAGES - 1);

        return [$from, $to];
    }
}
