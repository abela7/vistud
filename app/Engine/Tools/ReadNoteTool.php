<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Folders;
use App\Study\NoteDoc;
use App\Study\Notes;

/** One note's text, as Markdown, up to a size; the rest is said to be left out. */
final class ReadNoteTool implements Tool
{
    public const LIMIT = 8_000;

    public function __construct(private Notes $notes, private Folders $folders) {}

    public function name(): string
    {
        return 'read_note';
    }

    public function description(): string
    {
        return 'The text of one of the student\'s notes, by its title. Read a note before answering questions about what it says; quote it rather than guessing.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'description' => 'The note\'s title (or part of it).']], 'required' => ['note'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $note = Lookup::here($this->notes->list($by, $context->workspaceId), Lookup::folder($this->folders, $by, $context), Lookup::text($input, 'note') ?? '', fn ($n) => $n->displayTitle(), 'note');
        if (is_string($note)) {
            return $note;
        }
        $text = trim(NoteDoc::markdown($this->notes->open($by, $note->id)->doc ?? []));
        if ($text === '') {
            return "The note \"{$note->displayTitle()}\" is empty.";
        }
        if (mb_strlen($text) > self::LIMIT) {
            $text = rtrim(mb_substr($text, 0, self::LIMIT))."\n\n(The rest of this note is left out; it is longer than ".self::LIMIT.' characters.)';
        }

        return "# {$note->displayTitle()}\n\n{$text}";
    }
}
