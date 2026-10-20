<?php

namespace App\Study;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;

/**
 * What anyone who is handed a class's written-up notes is told first: which class it was, which files the teacher taught
 * from (the student ticked them), and what the note is. It heads the transcript and the lecture notes as plain text, so
 * the AI tutor in a study session or an assignment, or a person who is sent the note, knows what the teacher was
 * teaching from. It is rewritten when the student changes the files (`refresh`). Built by code, never by the AI.
 */
final class ClassHeader
{
    public function __construct(private Files $files, private Workspaces $workspaces, private Modules $modules, private Notes $notes) {}

    /** The names of the files the class used that are still there. @return list<string> */
    public function fileNames(Principal $by, ClassSessionDetails $class): array
    {
        $names = [];
        foreach ($class->checkFiles as $id) {
            try {
                $file = $this->files->find($by, $id);
            } catch (NotFound) {
                continue;
            }
            if ($file->trashedAt === null) {
                $names[] = $file->fileName();
            }
        }

        return $names;
    }

    /**
     * The header as Markdown, ending with a divider. `$kind`: `lecture` (the written-up notes) or `transcript` (word for word).
     */
    public function markdown(Principal $by, ClassSessionDetails $class, string $kind): string
    {
        $where = $this->workspaces->find($by, $class->workspaceId)->name;
        if ($class->moduleId !== null) {
            $where = $this->modules->find($by, $class->moduleId)->title.', '.$where;
        }
        $names = $this->fileNames($by, $class);
        $lines = [
            "**Class:** {$class->title} ({$where})".($class->teacher !== null && $class->teacher !== '' ? ", teacher {$class->teacher}" : ''),
            '**The teacher taught from:** '.($names === [] ? 'not said yet' : implode('; ', array_map(fn (string $n) => Fence::escape($n), $names))),
            $kind === 'transcript'
                ? '**This note:** the class word for word, as the AI wrote it down from the recording. Where it could not make out a word it says [unclear].'
                : '**This note:** what the teacher said in the class, written up from the recording. It is the teacher\'s teaching, not the student\'s own words. The word for word transcript is a separate note beside it.',
        ];

        return implode("\n\n", $lines)."\n\n---\n\n";
    }

    /** Rewrites the header of the class's transcript and lecture notes after the student changed the files. */
    public function refresh(Principal $by, ClassSessionDetails $class): void
    {
        foreach ([[$class->transcriptNoteId, 'transcript'], [$class->lectureNotesId, 'lecture']] as [$noteId, $kind]) {
            if ($noteId === null) {
                continue;
            }
            try {
                $note = $this->notes->open($by, $noteId);
            } catch (NotFound) {
                continue;
            }
            if ($note->trashedAt !== null) {
                continue;
            }
            $content = array_values(array_filter(($note->doc ?? [])['content'] ?? [], 'is_array'));
            $rule = null;
            foreach (array_slice($content, 0, 8, true) as $i => $block) {
                if (($block['type'] ?? null) === 'horizontalRule') {
                    $rule = $i;
                    break;
                }
            }
            $first = NoteDoc::text(['type' => 'doc', 'content' => array_slice($content, 0, 1)]);
            if ($rule === null || ! str_starts_with($first, 'Class:')) {
                continue;
            }
            $header = MarkdownDoc::blocks($this->markdown($by, $class, $kind));
            $this->notes->replace($by, $noteId, [...$header, ...array_slice($content, $rule + 1)]);
        }
    }
}
