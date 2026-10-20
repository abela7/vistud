<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Study\ClassNote;
use App\Study\ClassSessions;
use App\Study\Fence;
use App\Study\MarkdownDoc;
use App\Study\NoteDoc;
use App\Study\Notes;

/**
 * A class checked against its files (docs: the lecture's slides and handouts). The reader is given the lecture notes the
 * recording made, the student's own notes with the minutes they wrote them in, and the text of the files the student
 * ticked for this class, and writes one note in the class's module: what the teacher said that the files don't have,
 * what the files have that the class didn't reach, where the two differ, and the student's own notes checked against
 * both. It never says who is right. Checking again writes a new version of the same note.
 */
final class ClassCheck extends Job
{
    /** How many files one check reads, and how much of the lecture notes it is given. */
    public const MAX_FILES = 6;

    public const LECTURE_CHARS = 40_000;

    public const RULES = <<<'TXT'
        You check what a teacher said in one class against the files the student has for it (slides, handouts).
        You are given the lecture notes made from the recording (their headings are minutes of the recording), the student's own notes with the minute each was written in, and the text of each file, whose pages are labelled [Slide 3], [Page 2] and so on.
        Write exactly these four parts in plain Markdown, in this order, each with its heading:
        ## Said in class, not in your files
        ## In your files, not covered in class
        ## Different from your files
        ## Your notes, checked
        For every point name the class minute (mm:ss) and the file and page or slide. Where the class and a file differ, quote both briefly, side by side, and never decide who is right; say "ask your teacher" when it matters for an exam or coursework.
        Where the lecture notes say [unclear] or "Check this", do not treat it as a difference.
        In "Your notes, checked", take each of the student's notes in turn and say, from the lecture notes and the files, what answers it, or that nothing does.
        Use only the text you are given. Never add facts. A part with nothing in it says "Nothing found."
        Keep it short and plain: the student reads it on a phone.
        TXT;

    public function __construct(string $workspaceId, string $classId)
    {
        parent::__construct($workspaceId, 'class_session', $classId);
    }

    public function kind(): string
    {
        return 'class_check';
    }

    public function handle(Principal $by, Run $run): void
    {
        $classes = app(ClassSessions::class);
        $class = $classes->find($by, (string) $this->targetId);
        if ($class->lectureNotesId === null) {
            $run->skip('no_lecture_notes');
        }
        try {
            $lecture = trim(NoteDoc::markdown(app(Notes::class)->open($by, $class->lectureNotesId)->doc ?? []));
        } catch (NotFound) {
            $run->skip('no_lecture_notes');
        }

        $material = app(Material::class);
        [$files, $left] = ['', []];
        foreach (array_slice($class->checkFiles, 0, self::MAX_FILES) as $fileId) {
            try {
                [$name, $text] = $material->file($by, $this->workspaceId, $fileId);
                $files .= "### File: {$name}\n{$text}\n\n";
            } catch (AppError) {
                $left[] = $fileId;
            }
        }
        if ($files === '') {
            $run->skip('no_files');
        }

        $own = '';
        foreach (app(ClassNote::class)->read($by, $class->id) as $mark) {
            $own .= "- {$mark['from']}–{$mark['until']}: ".trim($mark['body'] !== '' ? $mark['body'] : $mark['about'])."\n";
        }
        $input = "The lecture notes:\n".Fence::wrap(mb_substr($lecture, 0, self::LECTURE_CHARS))
            .($own !== '' ? "\n\nThe student's own notes:\n".Fence::wrap($own) : '')
            ."\n\nThe files:\n".Fence::wrap($files);
        $about = TranscribeClass::about($by, $class->workspaceId, $class->moduleId, $class->title);
        $check = trim($run->ask(self::RULES."\n\n".$about, $input, 6000)->whole()->text);
        if ($check === '') {
            throw new EngineFailed('engine_empty', 'The AI wrote nothing. Try again.');
        }
        if ($left !== []) {
            $check .= "\n\nNot checked (no readable text, or no longer there): ".count($left).' file'.(count($left) === 1 ? '' : 's').'.';
        }

        $noteId = $this->keep($by, $class->checkNoteId, $class->moduleId, $class->workspaceId, 'Class check · '.$class->title, $check);
        $classes->keepCheck($by, $class->id, $class->checkFiles, $noteId);
    }

    /** The note, as a new version of the one kept before when it is still there, else a new note in the class's module. */
    private function keep(Principal $by, ?string $noteId, ?string $moduleId, string $workspaceId, string $title, string $markdown): string
    {
        $notes = app(Notes::class);
        $blocks = MarkdownDoc::blocks($markdown);
        if ($noteId !== null) {
            try {
                if ($notes->find($by, $noteId)->trashedAt === null) {
                    $notes->replace($by, $noteId, $blocks);

                    return $noteId;
                }
            } catch (NotFound) {
                // Deleted since: a new one.
            }
        }
        $note = $moduleId !== null ? $notes->create($by, 'module', $moduleId, mb_substr($title, 0, 200)) : $notes->create($by, 'workspace', $workspaceId, mb_substr($title, 0, 200));
        $notes->append($by, $note->id, $blocks);

        return $note->id;
    }
}
