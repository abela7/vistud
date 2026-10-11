<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Folders;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Sessions;

final class RenameNoteTool implements Tool
{
    public function __construct(private Notes $notes, private Sessions $sessions, private Folders $folders) {}

    public function name(): string
    {
        return 'rename_note';
    }

    public function description(): string
    {
        return 'Renames an existing note\'s title in the course. Use it when the student asks to rename a note.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'note' => ['type' => 'string', 'description' => 'The current title (or part of it) of the note to rename. Left out or "current note": this session\'s study note.'],
            'new_title' => ['type' => 'string', 'description' => 'The new title for the note.'],
        ], 'required' => ['new_title'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $newTitle = Lookup::text($input, 'new_title');
        if ($newTitle === null || trim($newTitle) === '') {
            return 'Send the new title for the note.';
        }
        $newTitle = trim($newTitle);
        $wanted = Lookup::text($input, 'note');
        $note = null;
        if ($wanted !== null) {
            $normalized = mb_strtolower(trim($wanted));
            if (in_array($normalized, ['the note', 'my note', 'current note', 'opened note', 'open note', 'this note', 'notes', 'study note', 'study notes'], true)) {
                $note = $this->studyNote($by, $context);
            } else {
                $note = Lookup::here($this->notes->list($by, $context->workspaceId), Lookup::folder($this->folders, $by, $context), $wanted, fn (NoteDetails $n) => $n->displayTitle(), 'note');
                if (is_string($note)) {
                    return $note;
                }
            }
        } else {
            $note = $this->studyNote($by, $context);
        }

        if ($note === null) {
            return 'Say which note to rename.';
        }

        $saved = $this->notes->rename($by, $note->id, $newTitle);
        $context->effects->wrote($note->id, $newTitle, $saved->version);

        return "Renamed note to \"{$newTitle}\".";
    }

    private function studyNote(Principal $by, Context $context): ?NoteDetails
    {
        if ($context->sessionId === null) {
            return null;
        }
        $session = $this->sessions->find($by, $context->sessionId);
        if ($session->noteId !== null) {
            try {
                return $this->notes->find($by, $session->noteId);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
