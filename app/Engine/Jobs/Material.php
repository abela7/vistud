<?php

namespace App\Engine\Jobs;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Findings;
use App\Study\ModuleBriefs;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Topics;

/**
 * What the Reader is given to work from, gathered through the services for the student's own course: a file's text, a
 * note's text, what a topic holds (its key points and the module's notes), the module's notes. Each is cut to a limit,
 * and the student's material is only ever fenced off as material in the prompt, never as instructions.
 */
final class Material
{
    /** The most of a file's text a job is given, in characters (the first pages, in full). */
    public const FILE_CHARS = 20_000;

    /** The most of the notes a job is given, in characters, and of one note. */
    public const NOTES_CHARS = 12_000;

    public const NOTE_CHARS = 4_000;

    public function __construct(private Files $files, private FileTexts $texts, private Notes $notes, private Topics $topics, private Findings $findings, private ModuleBriefs $briefs) {}

    /**
     * A file of the course, as text: [its name, what it says, its module].
     *
     * @return array{0: string, 1: string, 2: ?string}
     *
     * @throws NotFound not the student's file, or not in this course
     * @throws Unprocessable still being prepared, a picture, or nothing in it to read
     */
    public function file(Principal $by, string $workspaceId, string $fileId): array
    {
        $file = $this->files->find($by, $fileId);
        $file->workspaceId === $workspaceId || throw new NotFound;
        $text = $this->texts->of($by, $file->id);
        match (true) {
            $text->state === FileText::PREPARING => throw new Unprocessable('file_preparing', 'The file is still being prepared. Try again in a minute.'),
            $text->state === FileText::PICTURE => throw new Unprocessable('file_picture', 'That is a picture, and a picture has no words to work from.'),
            $text->state !== FileText::READY || ! $text->hasWords() => throw new Unprocessable('file_no_text', 'There are no words in that file to work from.'),
            default => null,
        };
        [$body] = ReadFile::body($text);

        return [$file->fileName(), mb_substr($body, 0, self::FILE_CHARS), $file->moduleId];
    }

    /**
     * A note of the course, as text: [its title, what it says, its module].
     *
     * @return array{0: string, 1: string, 2: ?string}
     *
     * @throws NotFound
     * @throws Unprocessable an empty note
     */
    public function note(Principal $by, string $workspaceId, string $noteId): array
    {
        $note = $this->notes->open($by, $noteId);
        ($note->workspaceId === $workspaceId && $note->trashedAt === null) || throw new NotFound;
        $text = trim(NoteDoc::markdown($note->doc ?? []));
        $text !== '' || throw new Unprocessable('note_empty', 'That note is empty: there is nothing to work from.');

        return [$note->displayTitle(), mb_substr($text, 0, self::NOTES_CHARS), $note->moduleId];
    }

    /**
     * A topic, as text: [its name, what is known about it, its module]. What is known: its key points, and the module's
     * notes, which are where it is written about.
     *
     * @return array{0: string, 1: string, 2: ?string}
     *
     * @throws NotFound
     * @throws Unprocessable nothing is written about it yet
     */
    public function topic(Principal $by, string $workspaceId, string $topicId): array
    {
        $topic = $this->topics->find($by, $topicId);
        $topic->workspaceId === $workspaceId || throw new NotFound;
        $parts = [];
        foreach ($this->findings->byTopic($by, $workspaceId)[$topic->id] ?? [] as $point) {
            $parts[] = "- {$point->text}";
        }
        $text = $parts === [] ? '' : "Key points:\n".implode("\n", $parts);
        [$notes] = $this->notesOf($by, $workspaceId, $topic->moduleId, self::NOTES_CHARS - mb_strlen($text));
        $text = trim($text."\n\n".$notes);
        $text !== '' || throw new Unprocessable('topic_empty', "Nothing is written about {$topic->name} yet: add a note, a file or a key point first.");

        return [$topic->name, $text, $topic->moduleId];
    }

    /**
     * The notes of a module (or of the course's top level, with no module), each under its title, cut to $limit
     * characters in all. Returns the text and the titles of the notes that went in.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function notesOf(Principal $by, string $workspaceId, ?string $moduleId, int $limit = self::NOTES_CHARS): array
    {
        $text = '';
        $titles = [];
        foreach ($this->notes->list($by, $workspaceId) as $note) {
            if ($note->moduleId !== $moduleId || $limit - mb_strlen($text) < 200) {
                continue;
            }
            $body = trim(NoteDoc::markdown($this->notes->open($by, $note->id)->doc ?? []));
            if ($body === '') {
                continue;
            }
            $room = min(self::NOTE_CHARS, $limit - mb_strlen($text) - mb_strlen($note->displayTitle()) - 8);
            $text .= "## {$note->displayTitle()}\n".mb_substr($body, 0, max(0, $room))."\n\n";
            $titles[] = $note->displayTitle();
        }

        return [trim($text), $titles];
    }

    /**
     * What the Reader has made of a module's files, one line each ("Lecture 3.pdf (18 pages): what it covers").
     *
     * @return list<string>
     */
    public function digestsOf(Principal $by, string $moduleId): array
    {
        return $this->briefs->for($by, $moduleId)->files;
    }
}
