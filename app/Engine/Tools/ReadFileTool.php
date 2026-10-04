<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;

/**
 * One of the student's files, a few pages at a time: a PDF's pages, a PowerPoint's slides (with the speaker's
 * notes), a Word or text file in parts, each headed "Page 4 of 18" so the tutor can say where it is.
 */
final class ReadFileTool implements Tool
{
    /** The most pages one look-up returns, and the most characters. */
    public const PAGES = 5;

    public const CHARS = 11_000;

    public function __construct(private Files $files, private FileTexts $texts) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Reads one of the student\'s files, a few pages at a time: a PDF\'s pages, a PowerPoint\'s slides with the speaker\'s notes, a Word or text file in parts; each headed like "Page 4 of 18". Use it for the session\'s material and any file the student names or attaches, instead of guessing what it says, and go through it a part at a time. Pictures can\'t be read as text: the student attaches them in the chat.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'file' => ['type' => 'string', 'description' => 'The file\'s name (or part of it).'],
            'pages' => ['type' => 'string', 'description' => 'Which pages (or slides, or parts): one, like "7", or a range, like "4-6"; at most '.self::PAGES.' at a time. The first three when left out.'],
        ], 'required' => ['file'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $file = Lookup::one($this->files->list($by, $context->workspaceId), Lookup::text($input, 'file') ?? '', fn ($f) => $f->fileName(), 'file');
        if (is_string($file)) {
            return $file;
        }
        $text = $this->texts->of($by, $file->id);
        $name = $file->fileName();

        return match ($text->state) {
            FileText::PICTURE => "\"{$name}\" is a picture: it can't be read as text. Ask the student to attach it in the chat, so you can see it.",
            FileText::PREPARING => "\"{$name}\" is still being converted for reading. Try again in a minute, or ask the student to share the part they're on.",
            FileText::NONE => "\"{$name}\" can't be read here. Ask the student to share the part they're on, or to attach it as pictures.",
            default => $this->pages($text, Lookup::text($input, 'pages')),
        };
    }

    private function pages(FileText $text, ?string $wanted): string
    {
        $name = $text->file->fileName();
        $count = $text->count();
        if ($count === 0 || ! $text->hasWords()) {
            return "\"{$name}\" has {$text->size()} but no text in them: it is probably scanned, or made of pictures. Ask the student to attach the pages they're on as pictures.";
        }
        [$from, $to] = self::range($wanted, $count);
        if ($from === null) {
            return "\"{$name}\" has {$text->size()}: ask for {$text->unit}s 1 to {$count}.";
        }
        $out = [];
        $length = 0;
        for ($number = $from; $number <= $to; $number++) {
            $page = trim($text->pages[$number - 1]);
            $block = '--- '.$text->label($number)." ---\n".($page !== '' ? $page : '(no text on this '.$text->unit.')');
            if ($out !== [] && $length + mb_strlen($block) > self::CHARS) {
                $out[] = "(Stopped before {$text->unit} {$number}, to keep this short: ask for {$text->unit} {$number} on.)";
                $to = $number - 1;
                break;
            }
            $out[] = $block;
            $length += mb_strlen($block);
        }
        $span = $from === $to ? "{$text->unit} {$from}" : "{$text->unit}s {$from}-{$to}";

        return "\"{$name}\": {$span} of {$count}.\n\n".implode("\n\n", $out);
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
