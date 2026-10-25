<?php

namespace App\Mcp\Tools;

use App\Brain\Journal\Vocabulary;
use App\Engine\Tools\WriteNoteTool;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Activities;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\Workspaces;
use Illuminate\Container\Container;

/**
 * What a chat app may make or change for the student so it can be their whole tutor (the owner's ask, 2026-10-24):
 * a course, a note from anything it read (a video, a page, a talk), an assignment or a dated item for the calendar,
 * a topic moved to its module, a card's wording, how the tutor teaches in this session, and the student's standing
 * rules for tutors. Each goes through the same service as the app's own pages, so the same limits apply. Nothing is
 * deleted here. One class, one tool per name.
 */
final class StudyWriteTool extends McpTool
{
    public const NAMES = ['create_course', 'create_note', 'create_assignment', 'move_topic', 'edit_flashcard', 'set_tutoring', 'set_instructions'];

    /** The level names a student uses, to Tutoring's `quiz` keys. */
    private const LEVELS = ['beginner' => 'easy', 'intermediate' => 'normal', 'master' => 'exam'];

    public function __construct(private string $what) {}

    protected function info(): string
    {
        return $this->what;
    }

    public function description(): string
    {
        return match ($this->what) {
            'create_course' => 'Makes a new course (a subject the student studies), with its code and term when known. Gives its course_id; then create_module for its modules.',
            'create_note' => 'Makes a new note in a course, in a module (module_id) or a folder (folder_id), from Markdown: headings, lists, tables, formulas, ```mermaid diagrams. Use it to keep what you learned from a video, a web page, a recording or a document the student shared; give its link or name as source. No study session needed.',
            'create_assignment' => 'Adds an assignment, exam, quiz, project, lab or lecture to a course, with its due date (and time) when known, so it shows on the calendar and the tutor plans around it. Gives its assignment_id for the assignment tools.',
            'move_topic' => 'Moves a topic into a module (module_id) or a folder (folder_id) of its course. Nothing else about it changes.',
            'edit_flashcard' => 'Changes a flashcard\'s front and/or back (flashcard_id, from make_flashcards or flashcards_due), when the student asks or it was wrong.',
            'set_tutoring' => 'Changes how the tutor teaches in this study session, and in the next ones (they start from the last): level (beginner, intermediate, master), method, check questions, pace, and the student\'s own Do and Don\'t rules. Only what the student asked for.',
            'set_instructions' => 'Writes the student\'s standing instructions for every tutor (for: me), for one course (course_id) or one module (module_id): how they want to be taught there. Replaces what was there; empty text removes it. Only in the student\'s words or with their agreement.',
        };
    }

    protected function fields(): array
    {
        $text = fn (string $about) => ['type' => 'string', 'description' => $about];

        return match ($this->what) {
            'create_course' => ['name' => $text('Name.'), 'code' => $text('Optional, e.g. 5302COMP.'), 'term' => $text('Optional.'), 'starts_on' => $text('Optional, YYYY-MM-DD.'), 'ends_on' => $text('Optional, YYYY-MM-DD.')],
            'create_note' => ['folder_id' => $text('Optional folder id (files).'), 'title' => $text('Title.'), 'text' => $text('Markdown, at most '.WriteNoteTool::MAX_TEXT.' characters.'), 'source' => $text('Optional link or name it came from.')],
            'create_assignment' => ['title' => $text('e.g. CourseWork 01.'), 'kind' => ['type' => 'string', 'enum' => Vocabulary::ACTIVITY_KINDS], 'due_on' => $text('Optional, YYYY-MM-DD.'), 'due_time' => $text('Optional, HH:MM.')],
            'move_topic' => ['topic_id' => $text('Topic id (topics).'), 'folder_id' => $text('Optional folder id (files).')],
            'edit_flashcard' => ['flashcard_id' => $text('Card id.'), 'front' => $text('Optional new front.'), 'back' => $text('Optional new back.')],
            'set_tutoring' => [
                'level' => ['type' => 'string', 'enum' => array_keys(self::LEVELS)],
                'method' => ['type' => 'string', 'enum' => array_keys(Tutoring::CHOICES['method'])],
                'check_ins' => ['type' => 'string', 'enum' => array_keys(Tutoring::CHOICES['check_ins'])],
                'pace' => ['type' => 'string', 'enum' => array_keys(Tutoring::CHOICES['pace'])],
                'do' => $text('Optional; replaces their do rules.'),
                'dont' => $text('Optional; replaces their dont rules.'),
            ],
            'set_instructions' => ['for' => ['type' => 'string', 'enum' => ['me', 'course', 'module']], 'course_id' => $text('For course.'), 'module_id' => $text('For module.'), 'text' => $text('At most '.Instructions::MAX_TEXT.' characters.')],
        };
    }

    protected function requiredFields(): array
    {
        return match ($this->what) {
            'create_course' => ['name'],
            'create_note' => ['title', 'text'],
            'create_assignment' => ['title'],
            'move_topic' => ['topic_id'],
            'edit_flashcard' => ['flashcard_id'],
            'set_tutoring' => [],
            'set_instructions' => ['for', 'text'],
        };
    }

    protected function answer(Principal $by, array $arguments, ?object $connection): array
    {
        return match ($this->what) {
            'create_course' => $this->course($by, $arguments),
            'create_note' => $this->note($by, $arguments),
            'create_assignment' => $this->assignment($by, $arguments),
            'move_topic' => $this->move($by, $arguments),
            'edit_flashcard' => $this->card($by, $arguments),
            'set_tutoring' => $this->tutoring($by, $arguments),
            'set_instructions' => $this->instructions($by, $arguments),
        };
    }

    /** @param  array<string, mixed>  $a */
    private function course(Principal $by, array $a): array
    {
        $course = self::make(Workspaces::class)->create($by, array_filter(['name' => self::text($a, 'name'), 'code' => self::text($a, 'code'), 'term' => self::text($a, 'term'), 'starts_on' => self::text($a, 'starts_on'), 'ends_on' => self::text($a, 'ends_on')], fn ($v) => $v !== null));

        return ['text' => "Made the course \"{$course->name}\" (course_id {$course->id}). Add its modules with create_module.", 'structured' => ['course_id' => $course->id], 'type' => 'course', 'id' => $course->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function note(Principal $by, array $a): array
    {
        [$courseId, $moduleId] = $this->place($by, $a);
        $folderId = self::text($a, 'folder_id');
        if ($folderId !== null && self::make(Folders::class)->find($by, $folderId)->workspaceId !== $courseId) {
            throw new NotFound;
        }
        $body = (string) ($a['text'] ?? '');
        if (mb_strlen($body) > WriteNoteTool::MAX_TEXT) {
            throw new Unprocessable('too_long', 'That is too long for one note: send at most '.WriteNoteTool::MAX_TEXT.' characters, and add the rest with write_note in a session, or as a second note.');
        }
        $source = self::text($a, 'source');
        $markdown = ($source !== null ? "*Source: {$source}*\n\n" : '').$body;
        [$type, $id] = match (true) {
            $folderId !== null => ['folder', $folderId],
            $moduleId !== null => ['module', $moduleId],
            default => ['workspace', $courseId],
        };
        $note = self::make(Notes::class)->createWritten($by, $type, $id, ['create_id' => Ids::new(), 'title' => self::text($a, 'title') ?? '', 'doc' => ['type' => 'doc', 'content' => MarkdownDoc::blocks($markdown)]]);

        return ['text' => "Made the note \"{$note->displayTitle()}\" (note_id {$note->id}). The student finds it in ViStud.", 'structured' => ['note_id' => $note->id], 'type' => 'note', 'id' => $note->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function assignment(Principal $by, array $a): array
    {
        [$courseId, $moduleId] = $this->place($by, $a);
        $made = self::make(Activities::class)->create($by, $courseId, array_filter([
            'title' => self::text($a, 'title'), 'kind' => self::text($a, 'kind') ?? 'assignment', 'due_on' => self::text($a, 'due_on'),
            'due_time' => self::text($a, 'due_time'), 'module_id' => $moduleId,
        ], fn ($v) => $v !== null));

        return ['text' => "Added \"{$made->title}\" ({$made->kind}".($made->dueOn !== null ? ", due {$made->dueOn}".($made->dueTime !== null ? " {$made->dueTime}" : '') : '').", assignment_id {$made->id}).", 'structured' => ['assignment_id' => $made->id], 'type' => 'assignment', 'id' => $made->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function move(Principal $by, array $a): array
    {
        [$courseId, $moduleId] = $this->place($by, $a);
        $topic = self::make(Topics::class)->find($by, self::text($a, 'topic_id') ?? '');
        if ($topic->workspaceId !== $courseId) {
            throw new NotFound;
        }
        $folderId = self::text($a, 'folder_id');
        if ($folderId !== null) {
            $folder = self::make(Folders::class)->find($by, $folderId);
            if ($folder->workspaceId !== $courseId) {
                throw new NotFound;
            }
            $moduleId = $folder->moduleId;
        }
        if ($moduleId === null && $folderId === null) {
            throw new Unprocessable('place_required', 'missing: module_id (from course_overview) or folder_id: where the topic goes.');
        }
        self::make(Topics::class)->move($by, $topic->id, $moduleId, $folderId);

        return ['text' => "Moved the topic \"{$topic->name}\".", 'type' => 'topic', 'id' => $topic->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function card(Principal $by, array $a): array
    {
        $cards = self::make(Flashcards::class);
        $card = $cards->find($by, self::text($a, 'flashcard_id') ?? '');
        $cards->update($by, $card->id, $card->topicId, self::text($a, 'front') ?? $card->front, self::text($a, 'back') ?? $card->back);

        return ['text' => 'Changed the card.', 'type' => 'flashcard', 'id' => $card->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function tutoring(Principal $by, array $a): array
    {
        $sessions = self::make(Sessions::class);
        $session = $sessions->find($by, self::text($a, 'session_id') ?? '');
        $choices = Tutoring::normalised($session->tutoring);
        $level = self::text($a, 'level');
        if ($level !== null) {
            $choices['quiz'] = self::LEVELS[$level] ?? throw new Unprocessable('validation_failed', 'level is beginner, intermediate or master.');
        }
        foreach (['method', 'check_ins', 'pace'] as $key) {
            if (($value = self::text($a, $key)) !== null) {
                $choices[$key] = $value;
            }
        }
        foreach (['do' => 'custom_do', 'dont' => 'custom_dont'] as $from => $key) {
            if (array_key_exists($from, $a) && is_string($a[$from])) {
                $choices[$key] = $a[$from];
            }
        }
        $sessions->setTutoring($by, $session->id, $choices);

        return ['text' => 'The tutor now teaches: '.Tutoring::summary($choices).'. Teach that way from here; the next session starts from it.', 'type' => 'session', 'id' => $session->id, 'changed' => true];
    }

    /** @param  array<string, mixed>  $a */
    private function instructions(Principal $by, array $a): array
    {
        $for = self::text($a, 'for');
        $scope = match ($for) {
            'me' => 'me',
            'course' => 'workspace:'.(self::make(Workspaces::class)->find($by, self::text($a, 'course_id') ?? throw new Unprocessable('course_required', 'missing: course_id.'))->id),
            'module' => 'module:'.(self::make(Modules::class)->find($by, self::text($a, 'module_id') ?? throw new Unprocessable('module_required', 'missing: module_id.'))->id),
            default => throw new Unprocessable('validation_failed', 'for is me, course or module.'),
        };
        self::make(Instructions::class)->set($by, $scope, $a['text'] ?? '');

        return ['text' => 'Saved the instructions'.($for === 'me' ? ' for every tutor' : " for this {$for}").'. Every tutor reads them from now on.', 'type' => 'instructions', 'id' => null, 'changed' => true];
    }

    /**
     * The course (course_id) and module (module_id) a tool works in, each checked to be the student's and together.
     *
     * @param  array<string, mixed>  $a
     * @return array{0: string, 1: ?string}
     */
    private function place(Principal $by, array $a): array
    {
        $course = self::make(Workspaces::class)->find($by, self::text($a, 'course_id') ?? '');
        $moduleId = self::text($a, 'module_id');
        if ($moduleId !== null && self::make(Modules::class)->find($by, $moduleId)->workspaceId !== $course->id) {
            throw new NotFound;
        }

        return [$course->id, $moduleId];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function make(string $class): object
    {
        return Container::getInstance()->make($class);
    }

    /** @param  array<string, mixed>  $input */
    private static function text(array $input, string $key): ?string
    {
        return is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
    }
}
