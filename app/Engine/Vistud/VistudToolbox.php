<?php

namespace App\Engine\Vistud;

use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\Container\Container;

/**
 * ViStud AI's tools: its own, across every course (courses, study_time, search_everything, memories, remember,
 * forget_memory), and the tutor's course tools that make sense with no session, each taking the course to work in
 * (InCourse). The tutor's chats never get these; a session's tools are App\Engine\Toolbox.
 */
final class VistudToolbox
{
    /** @var list<class-string<Tool>> */
    public const OWN = [CoursesTool::class, StudyTimeTool::class, SearchEverythingTool::class, MemoriesTool::class, RememberTool::class, ForgetMemoryTool::class];

    /** The tutor's tools ViStud AI may use in a course: reading it, and organising it (what can't be undone is agreed first). */
    public const IN_COURSE = [
        'course_overview', 'topics', 'questions', 'findings', 'assignments', 'assignment_plan', 'calendar',
        'notes', 'read_note', 'search_notes', 'files', 'read_file', 'module_files', 'earlier_sessions',
        'set_topic_status', 'add_topics', 'rename_topic', 'delete_topic', 'rename_module', 'rename_folder', 'rename_course', 'answer_question',
    ];

    /** @var list<Tool>|null */
    private ?array $tools = null;

    public function __construct(private Container $container, private Toolbox $toolbox, private Courses $courses) {}

    /** @return list<Tool> */
    public function tools(): array
    {
        return $this->tools ??= [
            ...array_map(fn (string $class) => $this->container->make($class), self::OWN),
            ...array_map(fn (Tool $tool) => new InCourse($tool, $this->courses), $this->toolbox->only(self::IN_COURSE)->tools()),
        ];
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
                $out = 'Not found: there is no such thing in that course.';
            } catch (AppError $e) {
                $fields = $e->details['fields'] ?? [];
                $out = 'That didn\'t work: '.(is_array($fields) && $fields !== [] ? implode(' ', array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $fields)) : $e->getMessage());
            }
            if (mb_strlen($out) > Toolbox::MAX_OUTPUT) {
                $out = rtrim(mb_substr($out, 0, Toolbox::MAX_OUTPUT))."\n\n(Cut here: the answer is longer than ".Toolbox::MAX_OUTPUT.' characters. Ask for less at a time, or one course at a time.)';
            }

            return $out;
        }

        return "There is no tool called \"{$name}\". The tools are: ".implode(', ', array_map(fn (Tool $t) => $t->name(), $this->tools())).'.';
    }
}
