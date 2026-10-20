<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\AudioPieces;
use App\Study\ClassHeader;
use App\Study\ClassNote;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Fence;
use App\Study\Files;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\File;

/**
 * A class written down from its recording, in two steps. First an audio model writes each 10-minute piece word for
 * word, marking what it can't make out instead of guessing. Then the same model turns that transcript into lecture
 * notes, dropping the chatter and flagging what is unclear. Both are kept as notes in the class's module: the student
 * reads the notes and can always check the transcript or the recording at the same time. Transcribing again writes
 * new versions of the same two notes. ffmpeg cuts the recording (App\Study\AudioPieces).
 */
final class TranscribeClass extends Job
{
    /** How long each piece is: short enough for one call, and a mistake stays inside its piece. */
    public const PIECE_MINUTES = 10;

    public const HEAR = <<<'TXT'
        You write down a piece of a class recording, word for word, for a student's study record.
        Write only what is said, in the language it is said in. Never add, guess, correct or summarise.
        Where a word or a sentence can't be made out, write [unclear]. Where nothing useful is said for a while, write [pause]; where people talk over each other or chat off the subject, write [chatter].
        Start a new paragraph when the speaker or the subject changes. When someone other than the lecturer speaks, start their paragraph with "Student:".
        No headings, no notes about the audio, nothing but the transcript.
        TXT;

    public const NOTES = <<<'TXT'
        You turn the transcript of one class into lecture notes for the student who was there.
        Keep: what the lecturer teaches, definitions, worked examples, what they say matters for the exam or the coursework, homework and dates.
        Leave out: off-topic talk, chatter, repetition, admin that is not about the course.
        The transcript is in pieces, each headed with its time in the recording. Keep those times as headings, so the student can find the part in the recording.
        Where the transcript says [unclear] or a passage is confusing, write "Check this (at mm:ss): " and what is unclear, instead of guessing what was meant.
        The student may also have written notes of their own during the class, each with the minutes it was written in. Where there are some, add under the part of the lecture at those minutes a line "Your note (mm:ss): ..." and, under it, what was being taught right then, so each of their notes is answered from what was said. Never change their words.
        Never add facts that are not in the transcript. Plain Markdown: headings, short paragraphs and lists.
        TXT;

    public function __construct(string $workspaceId, string $classId)
    {
        parent::__construct($workspaceId, 'class_session', $classId);
    }

    public function kind(): string
    {
        return 'transcribe_class';
    }

    /** An audio model (the student's tutor and reader models may not hear). */
    public function model(Principal $by): string
    {
        return (string) config('vistud.engine.audio_model', 'google/gemini-2.5-flash');
    }

    public function handle(Principal $by, Run $run): void
    {
        // An hour of class is six calls and the notes: longer than a web request may run.
        set_time_limit(0);
        $classes = app(ClassSessions::class);
        $class = $classes->find($by, (string) $this->targetId);
        $parts = array_values(array_filter(app(ClassRecordings::class)->forClass($by, $class->id), fn ($p) => $p->finishedAt !== null && $p->deletedAt === null && $p->path !== null));
        if ($parts === []) {
            $run->skip('no_recording');
        }
        $headers = app(ClassHeader::class);
        $about = self::about($by, $class->workspaceId, $class->moduleId, $class->title, $headers->fileNames($by, $class));
        $work = storage_path('app/transcribe/'.$class->id.'-'.bin2hex(random_bytes(4)));

        try {
            [$transcript, $offset] = ['', 0];
            foreach ($parts as $part) {
                $pieces = app(AudioPieces::class)->split(Files::disk()->path((string) $part->path), self::PIECE_MINUTES * 60, $work.'/take-'.$part->position);
                foreach ($pieces as $i => $piece) {
                    $from = $offset + $i * self::PIECE_MINUTES * 60;
                    $reply = $run->ask(self::HEAR, [['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Write down this piece of the class. '.$about],
                        ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode((string) file_get_contents($piece)), 'format' => 'mp3']],
                    ]]], 6000)->whole();
                    $transcript .= '## '.self::clock($from).'–'.self::clock($from + self::PIECE_MINUTES * 60)."\n\n".trim($reply->text)."\n\n";
                }
                $offset += $part->durationSeconds > 0 ? $part->durationSeconds : count($pieces) * self::PIECE_MINUTES * 60;
            }
        } catch (\RuntimeException $e) {
            if ($e instanceof EngineFailed) {
                throw $e;
            }
            throw new EngineFailed('ffmpeg_missing', 'The recording could not be cut into pieces: ffmpeg is missing on this server, or the recording could not be read.');
        } finally {
            File::deleteDirectory($work);
        }

        // What the student wrote while the class ran, each against the minutes it was written in: the notes answer them.
        $own = '';
        foreach (app(ClassNote::class)->read($by, $class->id) as $mark) {
            $own .= "- {$mark['from']}–{$mark['until']}: ".trim($mark['body'] !== '' ? $mark['body'] : $mark['about'])."\n";
        }
        $input = Fence::wrap($transcript).($own !== '' ? "\n\nThe student's own notes, with the minutes of the recording they were written in:\n".Fence::wrap($own) : '');
        $notes = trim($run->ask(self::NOTES."\n\n".$about, $input, 8000)->whole()->text);
        if (trim($transcript) === '' || $notes === '') {
            throw new EngineFailed('engine_empty', 'The AI wrote nothing. Try again.');
        }

        // Each note starts by saying which class it is and which files the teacher taught from: whoever reads it knows.
        $transcriptId = $this->keep($by, $class->transcriptNoteId, $class->moduleId, $class->workspaceId, 'Transcript · '.$class->title, $headers->markdown($by, $class, 'transcript').$transcript);
        $notesId = $this->keep($by, $class->lectureNotesId, $class->moduleId, $class->workspaceId, 'Lecture notes · '.$class->title, $headers->markdown($by, $class, 'lecture').$notes);
        $classes->keepTranscript($by, $class->id, $transcriptId, $notesId);
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

    /** What the class is, so the model hears the subject's words right. */
    public static function about(Principal $by, string $workspaceId, ?string $moduleId, string $title, array $files = []): string
    {
        return 'Course: '.app(Workspaces::class)->find($by, $workspaceId)->name
            .($moduleId !== null ? '. Module: '.app(Modules::class)->find($by, $moduleId)->title : '')
            .'. Class: '.$title.'.'
            .($files !== [] ? ' The teacher taught from these files, so their words are the words to expect: '.implode('; ', $files).'.' : '');
    }

    public static function clock(int $seconds): string
    {
        return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
