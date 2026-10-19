<?php

namespace Tests\Architecture;

use App\Mcp\ToolInfo;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The parity rule (ADR 0003 §8, docs/specs/mcp-connections-plan.md §3.9): ViStud has one service layer and three thin
 * adapters on it, Livewire screens, the JSON API (`/api/v1`) and MCP tools. This registry has one row per
 * student-facing capability and says which adapters carry it. A new MCP tool or API route that no row claims fails
 * here, so a capability is never added to one adapter by accident; a row with no API route or no MCP tool must say why
 * (`no_api`, `no_mcp`), which is the "web-only list with a reason" of the ADR. The Livewire class a row names must
 * exist, so a renamed screen is noticed.
 */
class AdapterParityTest extends TestCase
{
    private const W = 'App\\Livewire\\Workspaces\\';

    private const S = 'App\\Livewire\\Study\\';

    /**
     * @return list<array{capability: string, livewire: list<string>, api: list<string>, mcp: list<string>, no_api?: string, no_mcp?: string}>
     */
    private static function registry(): array
    {
        $sessionsNoApi = 'Study sessions run on the web page and from chat apps over MCP; the JSON API takes them when Flutter needs them (ADR 0003 §8).';

        return [
            ['capability' => 'Who the student is', 'livewire' => [], 'api' => ['api.v1.me', 'api.v1.me.update'], 'mcp' => ['me']],
            ['capability' => 'Notes: create, read, save with versions', 'livewire' => [self::W.'NoteActions'], 'api' => ['api.v1.notes.store', 'api.v1.notes.show', 'api.v1.notes.update'], 'mcp' => ['notes', 'read_note', 'search_notes', 'write_note']],
            ['capability' => 'Pictures in notes', 'livewire' => [], 'api' => ['api.v1.notes.images.store'], 'mcp' => [], 'no_mcp' => 'Pictures are added in the note editor; a chat app writes text.'],
            ['capability' => 'Files and links', 'livewire' => [self::W.'FileActions'], 'api' => ['api.v1.files.store'], 'mcp' => ['files', 'module_files', 'read_file']],
            ['capability' => 'The journal of evidence', 'livewire' => [], 'api' => ['api.v1.journal.entries.show'], 'mcp' => [], 'no_mcp' => 'Chat apps read the journal through memories, topics and study_time, never entry by entry.'],
            ['capability' => 'Offline sync of deletions', 'livewire' => [], 'api' => ['api.v1.sync.tombstones'], 'mcp' => [], 'no_mcp' => 'For the note editor and Flutter, which keep copies offline.'],
            ['capability' => 'Courses, their overview and calendar, search across them', 'livewire' => [self::W.'Index', self::W.'CalendarBoard'], 'api' => [], 'mcp' => ['courses', 'course_overview', 'calendar', 'search_everything'], 'no_api' => 'Read on the web and over MCP; the JSON API takes them when Flutter needs them (ADR 0003 §8).'],
            ['capability' => 'Study sessions: start, topic, checkpoint, resume, end, brief, earlier sessions', 'livewire' => [self::W.'StudySession', self::W.'ModuleSessions', self::W.'SessionCapture', self::W.'TutorChat'], 'api' => [], 'mcp' => ['start_session', 'set_session_topic', 'set_checkpoint', 'resume_session', 'end_session', 'current_session', 'study_brief', 'earlier_sessions'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Study time', 'livewire' => [self::W.'StudyTime'], 'api' => [], 'mcp' => ['study_time'], 'no_api' => 'Read on the web and over MCP; the JSON API takes it when Flutter needs it.'],
            ['capability' => 'Topics and where the student stands', 'livewire' => [self::W.'TopicPage', self::W.'TopicSheet'], 'api' => [], 'mcp' => ['topics', 'set_topic_status', 'add_topics'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Key points', 'livewire' => [self::W.'TopicPage'], 'api' => [], 'mcp' => ['findings', 'save_key_points'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Flashcards: make, review, answer', 'livewire' => [self::W.'FlashcardReview', self::W.'Deck', self::W.'CardMaker', self::W.'FlashcardEditor'], 'api' => [], 'mcp' => ['make_flashcards', 'flashcards_due', 'answer_flashcard'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Open questions', 'livewire' => [self::W.'QuestionBoard', self::W.'QuestionPage'], 'api' => [], 'mcp' => ['questions', 'add_questions', 'answer_question'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Quizzes and tests', 'livewire' => [self::W.'StudySession'], 'api' => [], 'mcp' => ['record_quiz'], 'no_api' => $sessionsNoApi],
            ['capability' => 'Assignments and exams: plan, progress, saved work', 'livewire' => [self::W.'AssignmentPage', self::W.'AssignmentCompanion', self::W.'AssignmentPlan', self::W.'AssignmentBoard'], 'api' => [], 'mcp' => ['assignments', 'assignment_plan', 'save_to_assignment', 'log_progress'], 'no_api' => 'Assignments run in the companion and over MCP; the JSON API takes them when Flutter needs them.'],
            ['capability' => 'What the tutor remembers', 'livewire' => [self::S.'Memories'], 'api' => [], 'mcp' => ['memories'], 'no_api' => 'Read on the web and over MCP; changes are made in Settings, or confirmed on a ViStud page (M6.5).'],
            ['capability' => 'Renames, what ViStud remembers about the student, replacing a whole note (changes the student confirms)', 'livewire' => [self::W.'TopicPage', self::W.'TopicSheet', self::W.'NoteActions', self::S.'Memories'], 'api' => [], 'mcp' => ['rename_topic', 'rename_module', 'rename_folder', 'rename_course', 'rename_note', 'forget_memory', 'remember', 'replace_note'], 'no_api' => 'A chat app only asks; the student confirms on a ViStud page. The screens and the API change names and notes directly.'],
            ['capability' => 'Connected AI apps: who is let in, and Undo', 'livewire' => [self::S.'Connections'], 'api' => [], 'mcp' => [], 'no_api' => 'Access is granted on ViStud\'s own pages only.', 'no_mcp' => 'An app can never widen or undo its own access.'],
        ];
    }

    public function test_every_mcp_tool_belongs_to_exactly_one_capability(): void
    {
        $claims = [];
        foreach (self::registry() as $row) {
            foreach ($row['mcp'] as $tool) {
                $claims[$tool][] = $row['capability'];
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys(ToolInfo::TOOLS), array_keys($claims))), 'These MCP tools are in no capability row: add them to the registry in AdapterParityTest.');
        $this->assertSame([], array_values(array_diff(array_keys($claims), array_keys(ToolInfo::TOOLS))), 'These registry rows name an MCP tool that does not exist.');
        $this->assertSame([], array_keys(array_filter($claims, fn (array $rows) => count($rows) > 1)), 'A tool belongs to one capability.');
    }

    public function test_every_api_route_belongs_to_exactly_one_capability(): void
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (str_starts_with($name, 'api.v1.')) {
                $routes[] = $name;
            }
        }
        $claims = [];
        foreach (self::registry() as $row) {
            foreach ($row['api'] as $name) {
                $claims[$name][] = $row['capability'];
            }
        }

        $this->assertSame([], array_values(array_diff($routes, array_keys($claims))), 'These API routes are in no capability row: add them to the registry in AdapterParityTest.');
        $this->assertSame([], array_values(array_diff(array_keys($claims), $routes)), 'These registry rows name an API route that does not exist.');
        $this->assertSame([], array_keys(array_filter($claims, fn (array $rows) => count($rows) > 1)), 'A route belongs to one capability.');
    }

    public function test_a_capability_without_an_api_route_or_an_mcp_tool_says_why_and_has_a_screen_or_an_adapter(): void
    {
        foreach (self::registry() as $row) {
            $this->assertTrue($row['api'] !== [] || ($row['no_api'] ?? '') !== '', "{$row['capability']}: no API route, and no reason in no_api.");
            $this->assertTrue($row['mcp'] !== [] || ($row['no_mcp'] ?? '') !== '', "{$row['capability']}: no MCP tool, and no reason in no_mcp.");
            $this->assertTrue($row['livewire'] !== [] || $row['api'] !== [] || $row['mcp'] !== [], "{$row['capability']}: carried by no adapter.");
        }
    }

    public function test_the_screens_a_row_names_exist(): void
    {
        foreach (self::registry() as $row) {
            foreach ($row['livewire'] as $class) {
                $this->assertTrue(class_exists($class), "{$row['capability']}: {$class} does not exist.");
            }
        }
    }
}
