<?php

namespace App\Engine\Tools;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/**
 * The tutor takes notes with the student: what it writes (Markdown) is added at the end of a note, which an open
 * editor shows as it changes. Unless told another, it writes in the session's own study note ("Study notes ·
 * Joins · Mon 5 Oct", made in the session's module the first time and kept on the chat); it can also add to a
 * note the student names, or start a new one with a title.
 */
final class WriteNoteTool implements Tool
{
    /** The most one write may add, in characters. */
    public const MAX_TEXT = 12_000;

    public function __construct(private Notes $notes, private Sessions $sessions, private Topics $topics, private Modules $modules) {}

    public function name(): string
    {
        return 'write_note';
    }

    public function description(): string
    {
        return 'Writes in a note for the student, in ViStud: what you write (Markdown: headings, lists, tables, formulas, ```mermaid diagrams) is added at the end, and the student sees it appear if the note is open. Use it when the student asks you to take notes, or agrees to: short, clear notes in their words where you can. By default it writes in this session\'s study note; name a note to add to another, or give a title to start a new one.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string', 'description' => 'What to add, in Markdown.'],
            'note' => ['type' => 'string', 'description' => 'An existing note\'s title (or part of it) to add to. Left out: this session\'s study note.'],
            'title' => ['type' => 'string', 'description' => 'A title for a new note, instead of adding to one.'],
        ], 'required' => ['text'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($context->sessionId === null) {
            return 'Writing notes needs a study session: ask the student to start one.';
        }
        $text = trim(Lookup::text($input, 'text') ?? '');
        if ($text === '') {
            return 'Nothing to write: send the text.';
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return 'That is too long for one write: send it in parts of up to '.self::MAX_TEXT.' characters.';
        }
        $blocks = MarkdownDoc::blocks($text);
        if ($blocks === []) {
            return 'Nothing to write: the text had no words.';
        }

        $note = null;
        if (($wanted = Lookup::text($input, 'note')) !== null) {
            $note = Lookup::one($this->notes->list($by, $context->workspaceId), $wanted, fn (NoteDetails $n) => $n->displayTitle(), 'note');
            if (is_string($note)) {
                return $note.' Or give a title to start a new note.';
            }
        }
        $title = Lookup::text($input, 'title');
        if ($note === null && $title !== null) {
            // A title that is already a note's adds to it, rather than making a second one.
            $note = collect($this->notes->list($by, $context->workspaceId))->first(fn (NoteDetails $n) => mb_strtolower($n->displayTitle()) === mb_strtolower($title));
            if ($note === null) {
                return $this->wrote($context, $this->create($by, $context, mb_substr($title, 0, 200), $blocks), 1, new: true);
            }
        }
        $note ??= $this->studyNote($by, $context);
        if ($note === null) {
            return $this->wrote($context, $this->create($by, $context, $this->studyTitle($by, $context), $blocks, remember: true), 1, new: true);
        }
        try {
            $saved = $this->notes->append($by, $note->id, $blocks);
        } catch (Gone|NotFound) {
            return "The note \"{$note->displayTitle()}\" is in the trash or gone. Give a title to start a new one.";
        }

        return $this->wrote($context, $note, $saved->version);
    }

    private function wrote(Context $context, NoteDetails $note, int $version, bool $new = false): string
    {
        $context->effects->wrote($note->id, $note->displayTitle(), $version);

        return ($new ? 'Started the note' : 'Added to the note')." \"{$note->displayTitle()}\". The student can open it beside the chat from your message; tell them in a line what you wrote.";
    }

    /** This session's study note, if the tutor has started one and it is still there: kept on the chat, or found by its title. */
    private function studyNote(Principal $by, Context $context): ?NoteDetails
    {
        $id = LearnerTables::query(Guard::learner($by), 'engine_threads')->where('session_id', $context->sessionId)->value('note_id');
        if ($id !== null) {
            try {
                $note = $this->notes->find($by, (string) $id);
                if ($note->trashedAt === null) {
                    return $note;
                }
            } catch (NotFound) {
                // Deleted since: start another.
            }
        }
        $title = $this->studyTitle($by, $context);

        return collect($this->notes->list($by, $context->workspaceId))->first(fn (NoteDetails $n) => $n->displayTitle() === $title);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function create(Principal $by, Context $context, string $title, array $blocks, bool $remember = false): NoteDetails
    {
        $session = $this->sessions->find($by, $context->sessionId);
        $module = $this->moduleOf($by, $session->moduleId, $session->topicId);
        $note = $this->notes->createWritten($by, $module !== null ? 'module' : 'workspace', $module ?? $context->workspaceId, [
            'create_id' => Ids::new(), 'title' => $title, 'doc' => ['type' => 'doc', 'content' => $blocks],
        ]);
        if ($remember) {
            LearnerTables::query(Guard::learner($by), 'engine_threads')->where('session_id', $context->sessionId)->update(['note_id' => $note->id, 'updated_at' => now()]);
        }

        return $note;
    }

    /** "Study notes · Joins · Mon 5 Oct": the session's topic, else its module. */
    private function studyTitle(Principal $by, Context $context): string
    {
        $session = $this->sessions->find($by, $context->sessionId);
        $on = null;
        try {
            $on = $session->topicId !== null ? $this->topics->find($by, $session->topicId)->name : null;
            $module = $this->moduleOf($by, $session->moduleId, $session->topicId);
            $on ??= $module !== null ? $this->modules->find($by, $module)->title : null;
        } catch (NotFound) {
            // Removed since.
        }

        return mb_substr(implode(' · ', array_filter(['Study notes', $on, CarbonImmutable::parse($session->startedAt)->setTimezone($context->zone)->format('D j M')])), 0, 200);
    }

    private function moduleOf(Principal $by, ?string $moduleId, ?string $topicId): ?string
    {
        if ($moduleId !== null || $topicId === null) {
            return $moduleId;
        }
        try {
            return $this->topics->find($by, $topicId)->moduleId;
        } catch (NotFound) {
            return null;
        }
    }
}
