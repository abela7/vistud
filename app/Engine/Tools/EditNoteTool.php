<?php

namespace App\Engine\Tools;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\NoteDoc;
use App\Study\NoteEditor;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/**
 * Direct real-time editing of the student's notes.
 * Updates lines, sentences, code blocks, or sections in place, or inserts/prepends/appends.
 * The open note editor reflects changes immediately via live broadcast sync.
 */
final class EditNoteTool implements Tool
{
    public const MAX_TEXT = 12_000;

    public function __construct(
        private Notes $notes,
        private Sessions $sessions,
        private Topics $topics,
        private Modules $modules,
        private Folders $folders,
        private Activities $activities,
    ) {}

    public function name(): string
    {
        return 'edit_note';
    }

    public function description(): string
    {
        return 'Directly edits or updates a note in real time for the student. You can find and replace a specific line, code block, sentence, or section (in place), insert text before or after a line, or update the note. The student sees the changes appear live in their note editor. Use this whenever the student asks to change, fix, edit, update, or replace any line or content in their notes.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => [
                    'type' => 'string',
                    'description' => "The note's title (or part of it) to edit. Left out: the note currently opened beside the session chat.",
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'The exact line, code block, sentence, or text to find in the note and replace (or insert around).',
                ],
                'replace' => [
                    'type' => 'string',
                    'description' => 'The new text or Markdown to replace the search text with (or text to insert).',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['replace_text', 'insert_after', 'insert_before', 'append', 'prepend', 'replace_all'],
                    'description' => "Editing mode: 'replace_text' (default when search is given: replaces search with replace), 'insert_after' (inserts after search), 'insert_before' (inserts before search), 'append' (adds to end), 'prepend' (adds to beginning), or 'replace_all' (replaces entire note).",
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Alternate alias for replace/new text.',
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'When mode is replace_text, whether to replace all occurrences (true) or just the first match (false, default).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->sessionId === null && $context->activityId === null) {
            return 'Editing notes needs a study session: ask the student to start one.';
        }

        $search = Lookup::text($input, 'search')
            ?? Lookup::text($input, 'target')
            ?? Lookup::text($input, 'find')
            ?? Lookup::text($input, 'old_text');

        $replace = Lookup::text($input, 'replace')
            ?? Lookup::text($input, 'replacement')
            ?? Lookup::text($input, 'text')
            ?? Lookup::text($input, 'new_text')
            ?? '';

        if (mb_strlen($replace) > self::MAX_TEXT) {
            return 'That is too long for one edit: send changes in parts of up to '.self::MAX_TEXT.' characters.';
        }

        $mode = Lookup::text($input, 'mode') ?? ($search !== null && trim($search) !== '' ? 'replace_text' : 'append');
        $all = (bool) ($input['all'] ?? false);

        $folderIds = $context->activityId !== null
            ? [$this->activities->folder($by, $context->activityId)->id]
            : Lookup::folder($this->folders, $by, $context);

        $note = null;
        if (($wanted = Lookup::text($input, 'note')) !== null) {
            $normalized = mb_strtolower(trim($wanted));
            if (in_array($normalized, ['the note', 'my note', 'current note', 'opened note', 'open note', 'this note', 'notes', 'study note', 'study notes', 'assignment note', 'assignment notes'], true)) {
                $note = $this->studyNote($by, $context);
            } else {
                $note = Lookup::here($this->notes->list($by, $context->workspaceId), $folderIds, $wanted, fn (NoteDetails $n) => $n->displayTitle(), 'note');
                if (is_string($note)) {
                    return $note;
                }
            }
        }
        $note ??= $this->studyNote($by, $context);
        if ($note === null) {
            return 'No note is currently open or found to edit. Ask the student which note they would like to edit.';
        }

        try {
            $opened = $this->notes->open($by, $note->id);
            $doc = $opened->doc ?? NoteDoc::empty();
        } catch (Gone|NotFound) {
            return "The note \"{$note->displayTitle()}\" is in the trash or gone.";
        }

        $result = match ($mode) {
            'replace_text' => NoteEditor::replaceText($doc, $search ?? '', $replace, $all),
            'insert_after' => NoteEditor::insertAfter($doc, $search ?? '', $replace),
            'insert_before' => NoteEditor::insertBefore($doc, $search ?? '', $replace),
            'prepend' => NoteEditor::prepend($doc, $replace),
            'append' => NoteEditor::append($doc, $replace),
            'replace_all' => NoteEditor::replaceAll($doc, $replace),
            default => NoteEditor::replaceText($doc, $search ?? '', $replace, $all),
        };

        if ($result['count'] === 0) {
            if ($mode === 'replace_text' || $mode === 'insert_after' || $mode === 'insert_before') {
                return "Could not find the text \"{$search}\" in the note \"{$note->displayTitle()}\". Check the wording or quote a shorter unique phrase to replace.";
            }

            return "Nothing to change in \"{$note->displayTitle()}\".";
        }

        try {
            $saved = $this->notes->replaceDoc($by, $note->id, $result['doc'], 'tutor');
        } catch (Gone|NotFound) {
            return "The note \"{$note->displayTitle()}\" is in the trash or gone.";
        }

        $context->effects->wrote($note->id, $note->displayTitle(), $saved->version);

        $summary = match ($mode) {
            'replace_text' => 'Replaced "'.mb_substr($search ?? '', 0, 80).'" with "'.mb_substr($replace, 0, 80).'"',
            'insert_after' => 'Inserted content after "'.mb_substr($search ?? '', 0, 80).'"',
            'insert_before' => 'Inserted content before "'.mb_substr($search ?? '', 0, 80).'"',
            'prepend' => 'Added content to the beginning',
            'append' => 'Added content to the end',
            'replace_all' => 'Rewrote the note content',
            default => 'Updated content',
        };

        return "Updated the note \"{$note->displayTitle()}\": {$summary}. The change appeared live in the student's note editor. Tell them what you changed in a line.";
    }

    private function studyNote(Principal $by, Context $context): ?NoteDetails
    {
        $query = LearnerTables::query(Guard::learner($by), 'engine_threads');
        $id = $context->sessionId !== null
            ? $query->where('session_id', $context->sessionId)->value('note_id')
            : $query->where('activity_id', $context->activityId)->value('note_id');
        if ($id !== null) {
            try {
                $note = $this->notes->find($by, (string) $id);
                if ($note->trashedAt === null) {
                    return $note;
                }
            } catch (NotFound) {
                // Deleted since
            }
        }
        if ($context->activityId !== null && $context->sessionId === null) {
            $activity = $this->activities->find($by, $context->activityId);
            $title = mb_substr('Assignment notes · '.$activity->title, 0, 200);
            $folder = $this->activities->folder($by, $context->activityId);

            return collect($this->notes->list($by, $context->workspaceId))->first(fn (NoteDetails $n) => $n->trashedAt === null && ($n->displayTitle() === $title || $n->folderId === $folder->id));
        }

        $session = $this->sessions->find($by, $context->sessionId);
        $on = null;
        try {
            $on = $session->topicId !== null ? $this->topics->find($by, $session->topicId)->name : null;
            $folder = $session->folderId !== null ? $this->folders->find($by, $session->folderId)->id : null;
            $on ??= $folder !== null ? $this->folders->find($by, $folder)->name : null;
            $module = $session->moduleId ?? ($session->topicId !== null ? $this->topics->find($by, $session->topicId)->moduleId : null);
            $on ??= $module !== null ? $this->modules->find($by, $module)->title : null;
        } catch (NotFound) {
        }

        $title = mb_substr(implode(' · ', array_filter(['Study notes', $on, CarbonImmutable::parse($session->startedAt)->setTimezone($context->zone)->format('D j M')])), 0, 200);

        return collect($this->notes->list($by, $context->workspaceId))->first(fn (NoteDetails $n) => $n->displayTitle() === $title);
    }
}
