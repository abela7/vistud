<?php

namespace App\Mcp;

/**
 * Every tool ViStud's MCP server offers, with its four hints, spelled out (docs/specs/mcp-connections-plan.md §3.1).
 * The MCP schema of 2026-07-28 defaults `destructiveHint` and `openWorldHint` to true, so no hint is ever left out.
 * `Mcp/ToolInfoTest` checks that what the server lists matches this table.
 *
 * - `source`: `engine` (an App\Engine\Toolbox tool, run in a course), `vistud` (a ViStud AI tool, across courses) or
 *   `native` (built here on the services).
 * - `course`: whether it works in one course, named by `course_id`.
 * - `level`: the lowest connection level that may use it (`read`, `write`, `full`).
 */
final class ToolInfo
{
    /** @var array<string, array{title: string, read_only: bool, destructive: bool, idempotent: bool, source: string, course: bool, level: string}> */
    public const TOOLS = [
        // Reads (M6.2).
        'me' => ['title' => 'Who am I in ViStud', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'read'],
        'courses' => ['title' => 'Your courses', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'vistud', 'course' => false, 'level' => 'read'],
        'study_time' => ['title' => 'Your study time', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'vistud', 'course' => false, 'level' => 'read'],
        'search_everything' => ['title' => 'Search all your courses', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'vistud', 'course' => false, 'level' => 'read'],
        'memories' => ['title' => 'What the tutor remembers', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'vistud', 'course' => false, 'level' => 'read'],
        'current_session' => ['title' => 'Your open study session', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'read'],
        'course_overview' => ['title' => 'A course at a glance', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'topics' => ['title' => 'Topics and where they stand', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'questions' => ['title' => 'Open questions', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'findings' => ['title' => 'Key points', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'assignments' => ['title' => 'Assignments and exams', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'assignment_plan' => ['title' => 'An assignment\'s plan', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'calendar' => ['title' => 'The course calendar', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'notes' => ['title' => 'Notes', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'read_note' => ['title' => 'Read a note', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'search_notes' => ['title' => 'Search notes', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'files' => ['title' => 'Files and links', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'module_files' => ['title' => 'A module\'s files', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'read_file' => ['title' => 'Read a file', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'earlier_sessions' => ['title' => 'Earlier study sessions', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'flashcards_due' => ['title' => 'Flashcards due', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => true, 'level' => 'read'],
        'show_quiz' => ['title' => 'Show a quiz', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => true, 'level' => 'read'],
        'catch_up' => ['title' => 'Catch up on the student', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'read'],
        'assignment_source' => ['title' => 'Read a source of an assignment', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'read'],
        'study_brief' => ['title' => 'Where the student stands, to teach', 'read_only' => true, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'read'],
        // Sessions and writes (M6.3), at level read + write.
        'start_session' => ['title' => 'Start a study session', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'set_session_topic' => ['title' => 'Change the session\'s topic', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'set_checkpoint' => ['title' => 'Save where the session stands', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'resume_session' => ['title' => 'Resume a paused session', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'end_session' => ['title' => 'End the study session', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'set_topic_status' => ['title' => 'Mark where the student stands on a topic', 'read_only' => false, 'destructive' => true, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'add_topics' => ['title' => 'Add topics', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'save_key_points' => ['title' => 'Save key points', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'make_flashcards' => ['title' => 'Save flashcards', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'add_questions' => ['title' => 'Save open questions', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'answer_question' => ['title' => 'Answer an open question', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'record_quiz' => ['title' => 'Record a quiz or test', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'answer_flashcard' => ['title' => 'Record a flashcard answer', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'write_note' => ['title' => 'Write in a note', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'save_to_assignment' => ['title' => 'Save to an assignment', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        // The assignment companion (2026-10-24): sources, the agreed understanding, the plan and its stage.
        'add_assignment_source' => ['title' => 'Add a source to an assignment', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'confirm_assignment_understanding' => ['title' => 'Confirm what the assignment asks', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'save_assignment_plan' => ['title' => 'Save the plan of an assignment', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'update_assignment_plan_item' => ['title' => 'Update a step of the plan', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        'set_stage' => ['title' => 'Move the assignment to its next stage', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        // Making and changing what the tutor works with (2026-10-24); nothing is deleted.
        'create_course' => ['title' => 'Make a course', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'create_note' => ['title' => 'Make a note', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'create_assignment' => ['title' => 'Add an assignment, exam or class', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'move_topic' => ['title' => 'Move a topic', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'edit_flashcard' => ['title' => 'Change a flashcard', 'read_only' => false, 'destructive' => true, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'set_tutoring' => ['title' => 'Change how the tutor teaches', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'set_instructions' => ['title' => 'Write standing instructions for tutors', 'read_only' => false, 'destructive' => true, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'full'],
        // A course's shape (2026-10-24): modules and folders, made once whatever the asking.
        'create_module' => ['title' => 'Make a module', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => false, 'level' => 'write'],
        'create_folder' => ['title' => 'Make a folder', 'read_only' => false, 'destructive' => false, 'idempotent' => true, 'source' => 'native', 'course' => true, 'level' => 'write'],
        'log_progress' => ['title' => 'Log assignment progress', 'read_only' => false, 'destructive' => false, 'idempotent' => false, 'source' => 'engine', 'course' => true, 'level' => 'write'],
        // Changes the student confirms on a ViStud page (M6.5), at level full: the tool only asks, it never changes anything.
        'rename_topic' => ['title' => 'Ask to rename a topic', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'rename_module' => ['title' => 'Ask to rename a module', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'rename_folder' => ['title' => 'Ask to rename a folder', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'rename_course' => ['title' => 'Ask to rename a course', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'rename_note' => ['title' => 'Ask to rename a note', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'forget_memory' => ['title' => 'Ask to forget a memory', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'remember' => ['title' => 'Remember something about the student', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
        'replace_note' => ['title' => 'Ask to replace a notes whole text', 'read_only' => false, 'destructive' => true, 'idempotent' => false, 'source' => 'native', 'course' => false, 'level' => 'full'],
    ];

    /**
     * Which tools work inside a study session, named by `session_id`: `required` refuses without one (and names the
     * fix), `optional` uses it when given. A session gives the course, module and folder.
     *
     * @var array<string, 'required'|'optional'>
     */
    public const SESSION = [
        'study_brief' => 'required', 'show_quiz' => 'required',
        'set_session_topic' => 'required', 'set_checkpoint' => 'required', 'resume_session' => 'required', 'end_session' => 'required',
        'set_topic_status' => 'optional', 'add_topics' => 'optional', 'answer_question' => 'optional', 'answer_flashcard' => 'optional',
        'save_key_points' => 'required', 'make_flashcards' => 'required', 'add_questions' => 'required', 'record_quiz' => 'required',
        'write_note' => 'optional', 'set_tutoring' => 'required',
    ];

    /** @var list<string> tools that work on one assignment, named by `assignment_id` */
    public const ASSIGNMENT = ['write_note', ...self::ASSIGNMENT_REQUIRED];

    /** @var list<string> tools that need an `assignment_id`: they work on that one assignment and nothing else */
    public const ASSIGNMENT_REQUIRED = ['save_to_assignment', 'log_progress', 'assignment_source', 'add_assignment_source', 'confirm_assignment_understanding', 'save_assignment_plan', 'update_assignment_plan_item', 'set_stage'];

    /** @return array{title: string, read_only: bool, destructive: bool, idempotent: bool, source: string, course: bool, level: string} */
    public static function of(string $name): array
    {
        return self::TOOLS[$name] ?? throw new \InvalidArgumentException("No MCP tool called {$name}.");
    }

    /** @return list<string> */
    public static function names(string $source): array
    {
        return array_keys(array_filter(self::TOOLS, fn (array $row) => $row['source'] === $source));
    }

    /** Whether a connection at $level may use a tool that needs $needs. */
    public static function allows(string $level, string $needs): bool
    {
        $order = ['read' => 0, 'write' => 1, 'full' => 2];

        return ($order[$level] ?? -1) >= ($order[$needs] ?? 99);
    }
}
