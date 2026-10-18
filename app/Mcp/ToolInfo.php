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
    ];

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
