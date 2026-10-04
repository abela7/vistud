<?php

namespace App\Engine;

use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Input;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\SessionDetails;
use Throwable;

/**
 * What the student attaches to a message in the chat (docs/specs/study-memory.md §6): their notes and files, from
 * this course. A note goes with its text; a PDF, slides or a Word or text file with its first pages and how many
 * there are, the rest read with read_file; a picture is shown to the model, only with the message it came with
 * (later turns say it was shown), made no bigger than the model needs. What goes with a note or a file is kept
 * with the message, so the chat reads the same on every turn.
 */
final class Attachments
{
    /** The most attachments on one message. */
    public const MOST = 4;

    /** A file's first pages that go with it, and the most of them in characters. */
    public const FIRST_PAGES = 2;

    public const FIRST_CHARS = 6_000;

    public const NOTE_CHARS = 8_000;

    /** A picture's longest side, in pixels, and the most bytes sent when it can't be made smaller. */
    public const PICTURE_SIDE = 1568;

    public const PICTURE_BYTES = 5 * 1024 * 1024;

    public function __construct(private Notes $notes, private Files $files, private FileTexts $texts) {}

    /**
     * The chosen notes and files (`note:{id}`, `file:{id}`), checked to be the student's and in this course, as kept
     * with the message. A picture is refused when the model is known not to see pictures.
     *
     * @return list<array{ref: string, name: string, kind: string, text?: string}>
     */
    public function resolve(Principal $by, SessionDetails $session, mixed $refs, ?Model $model): array
    {
        $refs = is_array($refs) ? array_values(array_unique(array_filter($refs, 'is_string'))) : [];
        Input::refuse(count($refs) > self::MOST ? ['attach' => 'Attach up to '.self::MOST.' notes or files to one message.'] : []);
        $items = [];
        foreach ($refs as $ref) {
            if (preg_match('/^(note|file):([A-Za-z0-9-]{1,64})$/', $ref, $m) !== 1) {
                Input::refuse(['attach' => 'That isn\'t a note or a file.']);
            }
            try {
                $items[] = $m[1] === 'note' ? $this->note($by, $session, $m[2]) : $this->file($by, $session, $m[2], $model);
            } catch (AppError $e) {
                if ($e->errorCode === 'validation_failed') {
                    throw $e;
                }
                Input::refuse(['attach' => 'One of the attachments is no longer in this course. Take it off and try again.']);
            }
        }

        return $items;
    }

    /**
     * The student's message for the engine: their words and what they attached. Pictures go as pictures when $see
     * (the message being answered, to a model that sees them); otherwise as a line saying they were shown.
     *
     * @param  list<array<string, mixed>>  $items
     * @return string|list<array<string, mixed>>
     */
    public function content(Principal $by, string $text, array $items, bool $see): string|array
    {
        $words = [trim($text) !== '' ? $text : '(The student sent this without a message.)'];
        $pictures = [];
        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            if (($item['kind'] ?? '') !== 'picture') {
                $words[] = (string) ($item['text'] ?? "[Attached: \"{$name}\"]");

                continue;
            }
            $data = $see ? $this->picture($by, substr((string) $item['ref'], 5)) : null;
            if ($data !== null) {
                $words[] = "[Attached: the picture \"{$name}\", shown below.]";
                $pictures[] = ['type' => 'image_url', 'image_url' => ['url' => $data]];
            } else {
                $words[] = $see
                    ? "[Attached: the picture \"{$name}\", which couldn't be sent. Ask the student to describe it or attach a smaller one.]"
                    : "[Attached earlier: the picture \"{$name}\", shown with that message.]";
            }
        }
        $all = implode("\n\n", $words);

        return $pictures === [] ? $all : [['type' => 'text', 'text' => $all], ...$pictures];
    }

    /** @return array{ref: string, name: string, kind: string, text: string} */
    private function note(Principal $by, SessionDetails $session, string $id): array
    {
        $note = $this->notes->open($by, $id);
        self::sameCourse($note->workspaceId, $session);
        $name = $note->displayTitle();
        $text = trim(NoteDoc::markdown($note->doc ?? []));
        if (mb_strlen($text) > self::NOTE_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::NOTE_CHARS))."\n\n(The rest is left out: read it with read_note.)";
        }

        return ['ref' => "note:{$id}", 'name' => $name, 'kind' => 'note', 'text' => "[Attached: the student's note \"{$name}\"]\n".($text !== '' ? $text : '(It is empty.)')];
    }

    /** @return array{ref: string, name: string, kind: string, text?: string} */
    private function file(Principal $by, SessionDetails $session, string $id, ?Model $model): array
    {
        [$file] = $this->files->content($by, $id);
        self::sameCourse($file->workspaceId, $session);
        $name = $file->fileName();
        if ($file->kind === 'image') {
            Input::refuse($model !== null && ! $model->images ? ['attach' => 'Your tutor model can\'t see pictures. Choose one that can in your AI settings (it says "pictures"), or describe it in words.'] : []);

            return ['ref' => "file:{$id}", 'name' => $name, 'kind' => 'picture'];
        }
        $read = $this->texts->of($by, $id);
        $text = match (true) {
            $read->state === FileText::PREPARING => "[Attached: the file \"{$name}\". It is still being converted for reading: read it with read_file in a minute.]",
            $read->state !== FileText::READY => "[Attached: the file \"{$name}\". It can't be read here: ask the student to share the part they're on.]",
            ! $read->hasWords() => "[Attached: the file \"{$name}\", {$read->size()} with no text in them (scanned, or pictures). Ask the student to attach the pages they're on as pictures.]",
            default => $this->firstPages($read),
        };

        return ['ref' => "file:{$id}", 'name' => $name, 'kind' => 'file', 'text' => $text];
    }

    private function firstPages(FileText $read): string
    {
        $name = $read->file->fileName();
        $shown = min(self::FIRST_PAGES, $read->count());
        $blocks = [];
        for ($i = 1; $i <= $shown; $i++) {
            $blocks[] = '--- '.$read->label($i)." ---\n".(trim($read->pages[$i - 1]) !== '' ? trim($read->pages[$i - 1]) : "(no text on this {$read->unit})");
        }
        $body = implode("\n\n", $blocks);
        if (mb_strlen($body) > self::FIRST_CHARS) {
            $body = rtrim(mb_substr($body, 0, self::FIRST_CHARS)).' […]';
        }
        $rest = $read->count() > $shown ? " The first {$shown} are below; read the rest with read_file, a few {$read->unit}s at a time." : ' All of it is below.';

        return "[Attached: the file \"{$name}\" ({$read->file->typeLabel()}, {$read->size()}).{$rest}]\n{$body}";
    }

    /** A picture as a data URL the model can see, no bigger than it needs; null when it can't be read or sent. */
    private function picture(Principal $by, string $id): ?string
    {
        try {
            [$file, $key] = $this->files->content($by, $id);
            $bytes = (string) Files::disk()->get($key);
        } catch (Throwable) {
            return null;
        }
        if (function_exists('imagecreatefromstring') && ($image = @imagecreatefromstring($bytes)) !== false) {
            [$width, $height] = [imagesx($image), imagesy($image)];
            $scale = min(1, self::PICTURE_SIDE / max($width, $height, 1));
            $out = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            // Transparent parts become white, as the student sees them on a page.
            imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), $width, $height);
            ob_start();
            imagejpeg($out, null, 85);
            $jpeg = (string) ob_get_clean();

            return 'data:image/jpeg;base64,'.base64_encode($jpeg);
        }

        return strlen($bytes) <= self::PICTURE_BYTES ? "data:{$file->mime};base64,".base64_encode($bytes) : null;
    }

    private static function sameCourse(string $workspaceId, SessionDetails $session): void
    {
        Input::refuse($workspaceId !== $session->workspaceId ? ['attach' => 'Attach notes and files from this course.'] : []);
    }
}
