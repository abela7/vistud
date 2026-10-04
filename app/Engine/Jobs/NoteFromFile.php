<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\MarkdownDoc;
use App\Study\NoteDetails;
use App\Study\Notes;

/**
 * The reader writes study notes from one file (docs/specs/vistud-2-blueprint.md §3.6.5): the file's text in, Markdown
 * out, kept as a new note beside the file (in its folder or module), marked as written by the AI in its history. The
 * student tapped it, and is told what was made and where, with a way to open it and to take it away again.
 */
final class NoteFromFile extends Answering
{
    public const PROMPT = 'resources/prompts/reader-note.md';

    /** The most a note's title is kept at; the longest a name can be is the file's. */
    public const TITLE = 'Notes · ';

    public function __construct(string $workspaceId, string $fileId)
    {
        parent::__construct($workspaceId, 'file', $fileId);
    }

    public function kind(): string
    {
        return 'note_from_file';
    }

    public function answer(Principal $by, Run $run): NoteDetails
    {
        [$name, $text] = app(Material::class)->file($by, $this->workspaceId, (string) $this->targetId);
        $file = app(Files::class)->find($by, (string) $this->targetId);

        $reply = $run->ask(self::rules(), "The file is \"{$name}\". Its text is between the quotes.\n\"\"\"\n{$text}\n\"\"\"", 3_000);
        $blocks = MarkdownDoc::blocks(trim($reply->text));
        if ($blocks === []) {
            throw new EngineFailed('engine_empty', 'The AI wrote nothing. Try again.');
        }

        $notes = app(Notes::class);
        [$placeType, $placeId] = match (true) {
            $file->folderId !== null => ['folder', $file->folderId],
            $file->moduleId !== null => ['module', $file->moduleId],
            default => ['workspace', $file->workspaceId],
        };
        $note = $notes->create($by, $placeType, $placeId, mb_substr(self::TITLE.$file->name, 0, 200));
        $notes->append($by, $note->id, $blocks, 'tutor');

        return $notes->find($by, $note->id);
    }

    /** The reader's rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }
}
