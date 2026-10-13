<?php

namespace App\Engine;

use App\Engine\Tools\AddAssignmentSourceTool;
use App\Engine\Tools\AddQuestionsTool;
use App\Engine\Tools\AddTopicsTool;
use App\Engine\Tools\AnswerQuestionTool;
use App\Engine\Tools\AssignmentPlanTool;
use App\Engine\Tools\AssignmentSourceTool;
use App\Engine\Tools\AssignmentsTool;
use App\Engine\Tools\CalculateTool;
use App\Engine\Tools\CalendarTool;
use App\Engine\Tools\Context;
use App\Engine\Tools\CourseOverview;
use App\Engine\Tools\CreateSnippetTool;
use App\Engine\Tools\DeleteTopicTool;
use App\Engine\Tools\EarlierSessionsTool;
use App\Engine\Tools\EditNoteTool;
use App\Engine\Tools\FilesTool;
use App\Engine\Tools\FindingsTool;
use App\Engine\Tools\LogProgressTool;
use App\Engine\Tools\MakeFlashcardsTool;
use App\Engine\Tools\ModuleFilesTool;
use App\Engine\Tools\NotesTool;
use App\Engine\Tools\PlotGraphTool;
use App\Engine\Tools\QuestionsTool;
use App\Engine\Tools\ReadFileTool;
use App\Engine\Tools\ReadNoteTool;
use App\Engine\Tools\RecordQuizTool;
use App\Engine\Tools\RememberTool;
use App\Engine\Tools\RenameCourseTool;
use App\Engine\Tools\RenameFolderTool;
use App\Engine\Tools\RenameModuleTool;
use App\Engine\Tools\RenameNoteTool;
use App\Engine\Tools\RenameTopicTool;
use App\Engine\Tools\SaveKeyPointsTool;
use App\Engine\Tools\SearchNotesTool;
use App\Engine\Tools\SetStageTool;
use App\Engine\Tools\SetTopicStatusTool;
use App\Engine\Tools\SetTopicTool;
use App\Engine\Tools\Tool;
use App\Engine\Tools\TopicsTool;
use App\Engine\Tools\WriteNoteTool;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\Container\Container;

/**
 * What the engine may look up in ViStud during a chat (docs/specs/study-memory.md §6): the tools, as the
 * OpenAI chat format describes them, and running one. Every tool runs as the student, through the services the
 * pages use, so another student's things are "not found" exactly as they are on a page. A tool's answer is
 * capped, so one look-up can't flood the chat. The same tools will serve the MCP server.
 */
final class Toolbox
{
    public const MAX_OUTPUT = 12_000;

    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        CourseOverview::class,
        TopicsTool::class,
        QuestionsTool::class,
        FindingsTool::class,
        AssignmentsTool::class,
        AssignmentPlanTool::class,
        AssignmentSourceTool::class,
        AddAssignmentSourceTool::class,
        LogProgressTool::class,
        SetStageTool::class,
        CalendarTool::class,
        NotesTool::class,
        ReadNoteTool::class,
        ReadFileTool::class,
        SearchNotesTool::class,
        FilesTool::class,
        ModuleFilesTool::class,
        EarlierSessionsTool::class,
        SetTopicTool::class,
        SetTopicStatusTool::class,
        AddTopicsTool::class,
        MakeFlashcardsTool::class,
        SaveKeyPointsTool::class,
        AddQuestionsTool::class,
        AnswerQuestionTool::class,
        WriteNoteTool::class,
        EditNoteTool::class,
        RecordQuizTool::class,
        RememberTool::class,
        RenameTopicTool::class,
        DeleteTopicTool::class,
        RenameFolderTool::class,
        RenameCourseTool::class,
        RenameModuleTool::class,
        RenameNoteTool::class,
        PlotGraphTool::class,
        CalculateTool::class,
        CreateSnippetTool::class,
    ];

    /** @var list<string>|null the names of the only tools this toolbox offers and runs; null for all of them */
    private ?array $only = null;

    public function __construct(private Container $container) {}

    /**
     * The same toolbox with only the named tools: they alone are described to the engine, and a call to any other is
     * answered as if it didn't exist (the helper's look-ups, which never change anything).
     *
     * @param  list<string>  $names
     */
    public function only(array $names): self
    {
        $toolbox = clone $this;
        $toolbox->only = $names;

        return $toolbox;
    }

    /** @return list<Tool> */
    public function tools(): array
    {
        $tools = array_map(fn (string $class) => $this->container->make($class), self::TOOLS);

        return $this->only === null ? $tools : array_values(array_filter($tools, fn (Tool $tool) => in_array($tool->name(), $this->only, true)));
    }

    /** What each look-up reads, as the chat tells the student ("Looking up your notes…", "Looked up: your notes"). */
    public const WORDS = [
        'course_overview' => 'the course', 'topics' => 'your topics', 'questions' => 'your questions', 'findings' => 'your key points',
        'assignments' => 'your assignments', 'assignment_plan' => 'an assignment plan', 'assignment_source' => 'an assignment source', 'calendar' => 'your calendar', 'notes' => 'your notes',
        'read_note' => 'a note', 'search_notes' => 'your notes', 'files' => 'your files', 'read_file' => 'a file', 'earlier_sessions' => 'earlier sessions',
        'plot_graph' => 'a function graph', 'calculate' => 'a calculation', 'create_snippet' => 'a copyable snippet',
        // ViStud AI's (App\Engine\Vistud\VistudToolbox).
        'courses' => 'your courses', 'study_time' => 'your study time', 'search_everything' => 'all your courses', 'memories' => 'what is remembered',
    ];

    /** What each tool that changes the course does, as the chat says it while it works ("Saving flashcards…"). */
    public const DOING = [
        'set_topic' => 'Setting the topic', 'set_topic_status' => 'Marking a topic', 'record_quiz' => 'Recording the quiz', 'remember' => 'Remembering', 'forget_memory' => 'Forgetting', 'add_topics' => 'Adding topics', 'make_flashcards' => 'Saving flashcards', 'save_key_points' => 'Saving key points', 'add_questions' => 'Adding questions', 'answer_question' => 'Answering question', 'write_note' => 'Writing in your note',
        'rename_topic' => 'Renaming topic', 'delete_topic' => 'Removing topic', 'rename_folder' => 'Renaming folder', 'rename_course' => 'Renaming course', 'rename_module' => 'Renaming module', 'rename_note' => 'Renaming note',
        'plot_graph' => 'Plotting function graph', 'calculate' => 'Calculating', 'create_snippet' => 'Creating copyable snippet',
        'add_assignment_source' => 'Adding assignment source', 'log_progress' => 'Logging progress', 'set_stage' => 'Updating assignment stage',
    ];

    public static function words(string $tool): string
    {
        return self::WORDS[$tool] ?? 'your course';
    }

    /** "Looking up your notes…" or "Saving flashcards…": what the tutor is doing while a tool runs. */
    public static function doing(string $tool): string
    {
        return (self::DOING[$tool] ?? 'Looking up '.self::words($tool)).'…';
    }

    /** Whether a tool changes the course, rather than reading it. */
    public static function writes(string $tool): bool
    {
        return isset(self::DOING[$tool]);
    }

    /** @return list<array<string, mixed>> the tools in the OpenAI chat format */
    public function definitions(): array
    {
        return array_map(fn (Tool $tool) => [
            'type' => 'function',
            'function' => ['name' => $tool->name(), 'description' => $tool->description(), 'parameters' => $tool->parameters()],
        ], $this->tools());
    }

    /** @param array<string, mixed> $input */
    public function run(Principal $by, Context $context, string $name, array $input = []): string
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }
            try {
                $out = $tool->run($by, $context, $input);
            } catch (NotFound) {
                $out = 'Not found: there is no such thing in this course.';
            } catch (AppError $e) {
                $fields = $e->details['fields'] ?? [];
                $out = 'That didn\'t work: '.(is_array($fields) && $fields !== [] ? implode(' ', array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $fields)) : $e->getMessage());
            }
            if (mb_strlen($out) > self::MAX_OUTPUT) {
                $out = rtrim(mb_substr($out, 0, self::MAX_OUTPUT))."\n\n(Cut here: the answer is longer than ".self::MAX_OUTPUT.' characters. Ask for less at a time.)';
            }

            return $out;
        }

        return "There is no tool called \"{$name}\". The tools are: ".implode(', ', array_map(fn (Tool $t) => $t->name(), $this->tools())).'.';
    }
}
