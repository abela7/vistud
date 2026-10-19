<?php

namespace App\Engine\Context;

use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Engine\Tools\AssignmentPlanTool;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstanding;
use App\Study\AssignmentUnderstandings;
use App\Study\CourseProfiles;
use App\Study\CourseTypes;
use App\Study\Fence;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\LearnerProfiles;
use App\Study\ModuleBriefs;
use App\Study\ModuleDetails;
use App\Study\ModuleParts;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\PlanDetails;
use App\Study\Plans;
use App\Study\PlanStarters;
use App\Study\PluginDetails;
use App\Study\Plugins;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\TutorMemories;
use App\Study\TutorMemory;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;

/**
 * The tutor's standing context, in layers (docs/specs/vistud-2-blueprint.md §3.6.2). Stable layers come first and
 * are the same word for word from one call to the next, so the service can cache them; what changes comes last:
 *
 *  0 the tutor's rules (resources/prompts/tutor-2.md)      1 the tools, beside the system prompt
 *  2 the course      3 the student      4 the module      5 this session      6 the chat's folded part
 *
 * Each layer has a budget in tokens. What is over it is cut from the end, a line at a time, and the layer says so
 * ("(cut: 3 more lines)"); the student's own words (what they wrote about themselves, the course and the module) and
 * how to teach are never cut. The report on the result says what each layer weighs and what was cut. The layers
 * are written from Facts, which `gather()` collects for a session and `compose()` turns into text without a database.
 * What the model can fetch itself (findings, open questions, due dates, earlier sessions) is left to its tools.
 */
final class Stack
{
    /** Tokens, by layer. A layer left out has no budget of its own (the tools; the folded chat). */
    public const BUDGETS = [0 => 2_500, 2 => 600, 3 => 250, 4 => 700, 5 => 380];

    public const ASSIGNMENT_BUDGET = 4_000;

    /** The understanding's parts as the assignment layer says them, in order: heading, and whether it is always kept. */
    private const UNDERSTOOD = [
        'deliverables' => ['What to hand in', true],
        'requirements' => ['Must do', true],
        'constraints' => ['Limits', true],
        'criteria' => ['What it is marked on', true],
        'teacher_expects' => ['What the teacher wants to see', true],
        'dates' => ['Dates', true],
        'questions' => ['Questions for the teacher', true],
        'gaps' => ['Missing or unclear', true],
        'topics' => ['Topics it draws on', true],
        'modules' => ['Modules it draws on', false],
        'assumptions' => ['How the AI reads it', false],
        'risks' => ['Risks', false],
        'best_practices' => ['Good practice', false],
    ];

    /** The log entries the assignment layer always carries, newest first. */
    public const LOG_LINES = 15;

    /** What a session is told of the course's open work: how many deadlines, and how many linked assignments in full. */
    public const ASSIGNMENTS_DUE = 4;

    public const ASSIGNMENTS_LINKED = 2;

    /** The work with a deadline a session hears about: everything but a plain to-do. */
    private const GRADED_KINDS = ['assignment', 'project', 'quiz', 'exam', 'lab', 'problem_set'];

    public const PROMPT = 'resources/prompts/tutor-2.md';

    /** The helper's rules and its budget: short enough for the smallest model, which runs the most often. */
    public const HELPER_PROMPT = 'resources/prompts/helper.md';

    public const HELPER_BUDGET = 300;

    /** The most of one note that goes into the prompt of a model that can't read notes itself, in characters. */
    public const NOTE_LIMIT = 8_000;

    public function __construct(
        private Workspaces $workspaces,
        private Modules $modules,
        private Topics $topics,
        private Instructions $instructions,
        private Settings $settings,
        private Notes $notes,
        private Files $files,
        private Toolbox $toolbox,
        private CourseProfiles $courseProfiles,
        private LearnerProfiles $learnerProfiles,
        private ModuleBriefs $briefs,
        private Folders $folders,
        private TutorMemories $memories,
        private Sessions $sessions,
        private Plans $plans,
        private AssignmentSources $assignmentSources,
        private AssignmentUnderstandings $assignmentUnderstandings,
        private AssignmentLog $assignmentLog,
        private Activities $activities,
    ) {}

    /** The tutor's standing context for a session: its system prompt, its tools and the report on its layers. */
    public function build(Principal $by, SessionDetails $session, ?string $folded, bool $withTools): Built
    {
        return $this->compose($this->gather($by, $session, $folded, $withTools), $withTools, $withTools ? $this->toolbox->definitions() : []);
    }

    /** The tutor's standing context for an assignment: companion rules, course, student, and the assignment layer. */
    public function forAssignment(Principal $by, ActivityDetails $assignment, ?string $folded, bool $withTools): Built
    {
        $workspace = $this->workspaces->find($by, $assignment->workspaceId);
        $instructions = $this->instructions->forSession($by, $workspace->id, $assignment->moduleId);
        $choices = $this->settings->get($by);
        $course = $this->courseProfiles->get($by, $workspace->id);
        $today = CarbonImmutable::now();

        $facts = new Facts(
            courseName: $workspace->name,
            courseLine: $workspace->subtitle() !== '' ? $workspace->subtitle() : null,
            courseInstructions: $instructions['workspace'],
            courseAbout: $course->about,
            courseOutcomes: $course->outcomes,
            courseAssessment: array_map(fn (array $item) => self::assessment($item, $today), $course->assessment),
            courseTextbook: $course->textbook,
            courseKind: CourseTypes::lines($workspace->type, $course),
            aboutYou: $instructions['me'],
            preferences: LearnerProfiles::lines($this->learnerProfiles->get($by, $workspace->id)),
            memories: $choices->tutorRemembers ? array_map(fn (TutorMemory $m) => $m->text.($m->everywhere() ? ' (all courses)' : ''), $this->memories->list($by, $workspace->id)) : [],
            remembers: $choices->tutorRemembers,
            language: $choices->language,
            askTopics: $choices->askTopics,
            marksTopics: $choices->tutorMarksTopics,
            withTools: $withTools,
            folded: $folded,
        );

        $rulesText = self::rules($withTools)."\n\n".trim((string) file_get_contents(base_path('resources/prompts/assignment-companion.md')));
        $tools = $withTools ? $this->toolbox->only(SessionChat::ASSIGNMENT_TOOLS)->definitions() : [];

        $assignmentLines = $this->assignment($by, $assignment);

        $layers = [
            0 => ['Rules', [[$rulesText, true]], 3_500],
            2 => ['Course', $this->course($facts), self::BUDGETS[2]],
            3 => ['Student', $this->student($facts, $withTools), self::BUDGETS[3]],
            4 => ['Assignment', $assignmentLines, self::ASSIGNMENT_BUDGET],
        ];

        $report = [];
        $texts = [];
        foreach ($layers as $number => [$name, $lines, $budget]) {
            [$text, $cut] = self::fit($lines, $budget);
            $texts[$number] = $text;
            $report[] = ['layer' => $number, 'name' => $name, 'tokens' => Tokens::of($text), 'budget' => $budget, 'cut' => $cut];
        }

        $tooling = $tools === [] ? '' : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_splice($report, 1, 0, [['layer' => 1, 'name' => 'Tools', 'tokens' => Tokens::of($tooling), 'budget' => null, 'cut' => 0]]);

        $foldedText = $facts->folded !== null && trim($facts->folded) !== ''
            ? "## Earlier in this chat\n\nThe chat's first part was folded to keep it short. What happened in it:\n\n".trim($facts->folded)
            : '';
        $report[] = ['layer' => 6, 'name' => 'Chat so far', 'tokens' => Tokens::of($foldedText), 'budget' => null, 'cut' => 0];

        foreach ([2, 3, 4] as $layer) {
            $texts[$layer] = self::fenceLayer($texts[$layer]);
        }
        $foldedText = self::fenceLayer($foldedText);

        $stable = self::join([$texts[0], $texts[2], $texts[3]]);

        return new Built(self::join([$stable, $texts[4], $foldedText]), $stable, $tools, $report);
    }

    /**
     * The helper's standing context: its short rules, the read-only tools beside them, and at most the module or folder it is
     * asked about (title, instructions, topics) or the course's name, nothing else. Another student's module, folder or course
     * is not found.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function helper(Principal $by, ?string $moduleId, array $tools, ?string $workspaceId = null, ?string $folderId = null): Built
    {
        $facts = new Facts(courseName: '');
        if ($folderId !== null) {
            $folder = $this->folders->find($by, $folderId);
            $workspace = $this->workspaces->find($by, $folder->workspaceId);
            $module = $folder->moduleId !== null ? $this->modules->find($by, $folder->moduleId) : null;
            if ($module !== null) {
                [$folderName, $folderIds, $otherFolders] = $this->folderInfo($by, $folderId, $module);
                $brief = $folderIds !== null ? $this->briefs->forFolder($by, $folderId) : $this->briefs->for($by, $module->id);
                $facts = new Facts(
                    courseName: $workspace->name,
                    moduleTitle: $module->title,
                    moduleAbout: $module->about,
                    moduleParts: $this->parts($by, $module),
                    moduleDates: self::dates($module),
                    moduleInstructions: $this->instructions->forSession($by, $workspace->id, $module->id)['module'],
                    topics: self::topicsOf($this->topics->list($by, $workspace->id), $module, $folderIds),
                    moduleFiles: $brief->files,
                    moduleQuestions: $brief->questions,
                    moduleKeyPoints: $brief->keyPoints,
                    moduleLast: $brief->last,
                    folderName: $folderName,
                    otherFolders: $otherFolders,
                );
            } else {
                $path = $this->folders->path($by, $folder->id);
                $folderName = implode(' › ', array_map(fn ($f) => $f->name, $path));
                $brief = $this->briefs->forFolder($by, $folder->id);
                $facts = new Facts(
                    courseName: $workspace->name,
                    moduleFiles: $brief->files,
                    moduleQuestions: $brief->questions,
                    moduleKeyPoints: $brief->keyPoints,
                    moduleLast: $brief->last,
                    folderName: $folderName,
                );
            }
        } elseif ($moduleId !== null) {
            $module = $this->modules->find($by, $moduleId);
            $workspace = $this->workspaces->find($by, $module->workspaceId);
            $facts = new Facts(
                courseName: $workspace->name,
                moduleTitle: $module->title,
                moduleAbout: $module->about,
                moduleParts: $this->parts($by, $module),
                moduleDates: self::dates($module),
                moduleInstructions: $this->instructions->forSession($by, $workspace->id, $module->id)['module'],
                topics: self::topicsOf($this->topics->list($by, $workspace->id), $module),
                moduleFiles: ($brief = $this->briefs->for($by, $module->id))->files,
                moduleQuestions: $brief->questions,
                moduleKeyPoints: $brief->keyPoints,
                moduleLast: $brief->last,
            );
        } elseif ($workspaceId !== null) {
            // Asked about the course as a whole: it knows the course's name, and looks the rest up.
            $facts = new Facts(courseName: $this->workspaces->find($by, $workspaceId)->name);
        }

        // What the student's tutors remember about them for every course: a few short lines, so it explains their way.
        $facts = $facts->withMemories($this->memories->lines($by, null));

        return $this->composeHelper($facts, $tools);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    public function composeHelper(Facts $facts, array $tools): Built
    {
        $about = $facts->moduleTitle === null && $facts->courseName !== '' && $facts->folderName === null
            ? [["## The course: {$facts->courseName}", true]]
            : $this->module($facts, isHelper: true);
        $student = $facts->memories === [] ? [] : [['## The student', true], ['What their tutor remembers about them:', true], ...array_map(fn (string $line) => ["- {$line}", true], $facts->memories)];
        $layers = [0 => ['Rules', [[self::helperRules(), true]], self::HELPER_BUDGET], 3 => ['Student', $student, self::BUDGETS[3]], 4 => ['Module', $about, self::BUDGETS[4]]];
        $report = [];
        $texts = [];
        foreach ($layers as $number => [$name, $lines, $budget]) {
            [$text, $cut] = self::fit($lines, $budget);
            $texts[$number] = $text;
            $report[] = ['layer' => $number, 'name' => $name, 'tokens' => Tokens::of($text), 'budget' => $budget, 'cut' => $cut];
        }
        $tooling = $tools === [] ? '' : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_splice($report, 1, 0, [['layer' => 1, 'name' => 'Tools', 'tokens' => Tokens::of($tooling), 'budget' => null, 'cut' => 0]]);

        foreach ([3, 4] as $layer) {
            $texts[$layer] = self::fenceLayer($texts[$layer]);
        }

        return new Built(self::join([$texts[0], $texts[3], $texts[4]]), $texts[0], $tools, $report);
    }

    /**
     * The tutor's standing context for a session without its rules (layer 0): the course, the student, the module and
     * the session, each fenced, for an AI app that teaches outside ViStud (`study_brief`, `start_session`;
     * docs/specs/mcp-connections-plan.md §3.3). The chat app has its own system prompt; ViStud's rules travel in
     * resources/prompts/mcp-rules.md.
     */
    public function standing(Principal $by, SessionDetails $session): string
    {
        $facts = $this->gather($by, $session, null, true);
        $layers = [$this->course($facts), $this->student($facts, true), $this->module($facts), $this->session($facts)];
        $texts = [];
        foreach ([2, 3, 4, 5] as $i => $number) {
            [$text] = self::fit($layers[$i], self::BUDGETS[$number]);
            $texts[] = self::fenceLayer($text);
        }

        return self::join($texts);
    }

    /**
     * Writes the layers from the facts. Pure: the same facts give the same words.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function compose(Facts $facts, bool $withTools, array $tools = []): Built
    {
        $layers = [
            0 => ['Rules', [[self::rules($withTools), true]]],
            2 => ['Course', $this->course($facts)],
            3 => ['Student', $this->student($facts, $withTools)],
            4 => ['Module', $this->module($facts)],
            5 => ['Session', $this->session($facts)],
        ];

        $report = [];
        $texts = [];
        foreach ($layers as $number => [$name, $lines]) {
            [$text, $cut] = self::fit($lines, self::BUDGETS[$number]);
            $texts[$number] = $text;
            $report[] = ['layer' => $number, 'name' => $name, 'tokens' => Tokens::of($text), 'budget' => self::BUDGETS[$number], 'cut' => $cut];
        }
        $tooling = $tools === [] ? '' : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_splice($report, 1, 0, [['layer' => 1, 'name' => 'Tools', 'tokens' => Tokens::of($tooling), 'budget' => null, 'cut' => 0]]);

        $folded = $facts->folded !== null && trim($facts->folded) !== ''
            ? "## Earlier in this chat\n\nThe chat's first part was folded to keep it short. What happened in it:\n\n".trim($facts->folded)
            : '';
        $report[] = ['layer' => 6, 'name' => 'Chat so far', 'tokens' => Tokens::of($folded), 'budget' => null, 'cut' => 0];

        foreach ([2, 3, 4, 5] as $layer) {
            $texts[$layer] = self::fenceLayer($texts[$layer]);
        }
        $folded = self::fenceLayer($folded);

        $stable = self::join([$texts[0], $texts[2], $texts[3]]);

        return new Built(self::join([$stable, $texts[4], $texts[5], $folded]), $stable, $tools, $report);
    }

    /** The facts a session's layers are written from. */
    public function gather(Principal $by, SessionDetails $session, ?string $folded, bool $withTools): Facts
    {
        $workspace = $this->workspaces->find($by, $session->workspaceId);
        $topics = $this->topics->list($by, $workspace->id);
        $topic = null;
        foreach ($topics as $t) {
            if ($t->id === $session->topicId) {
                $topic = $t;
            }
        }
        $module = $this->moduleOf($by, $workspace->id, $session->moduleId ?? $topic?->moduleId);
        $instructions = $this->instructions->forSession($by, $workspace->id, $module?->id);
        $choices = $this->settings->get($by);
        [$material, $materialText] = $this->material($by, $session, $withTools);

        $tutoring = Tutoring::normalised($session->tutoring);
        // In a folder (docs/specs/vistud-2-blueprint.md, Phase 9): the folder's brief and topics, where it sits, and the module's other folders.
        [$folderName, $folderIds, $otherFolders] = $module === null ? [null, null, []] : $this->folderOf($by, $session, $module);
        $brief = $module === null ? null : ($folderIds !== null ? $this->briefs->forFolder($by, (string) $session->folderId) : $this->briefs->for($by, $module->id));
        $course = $this->courseProfiles->get($by, $workspace->id);
        $today = CarbonImmutable::now();

        [$topicChangedInChat, $topicTakenOff] = $this->topicHistory($by, $session);
        [$assignmentsDue, $assignmentsLinked] = $this->assignmentsFor($by, $workspace->id, $module, $topic, $topics);

        $openedNoteTitle = null;
        try {
            $threadNoteId = LearnerTables::query(Guard::learner($by), 'engine_threads')->where('session_id', $session->id)->value('note_id');
            if ($threadNoteId !== null) {
                $openedNote = $this->notes->find($by, (string) $threadNoteId);
                if ($openedNote->trashedAt === null) {
                    $openedNoteTitle = $openedNote->displayTitle();
                }
            }
        } catch (\Throwable) {
            // Note gone or table not queryable
        }

        return new Facts(
            courseName: $workspace->name,
            courseLine: $workspace->subtitle() !== '' ? $workspace->subtitle() : null,
            courseInstructions: $instructions['workspace'],
            courseAbout: $course->about,
            courseOutcomes: $course->outcomes,
            courseAssessment: array_map(fn (array $item) => self::assessment($item, $today), $course->assessment),
            courseTextbook: $course->textbook,
            courseKind: CourseTypes::lines($workspace->type, $course),
            aboutYou: $instructions['me'],
            preferences: LearnerProfiles::lines($this->learnerProfiles->get($by, $workspace->id)),
            memories: $choices->tutorRemembers ? array_map(fn (TutorMemory $m) => $m->text.($m->everywhere() ? ' (all courses)' : ''), $this->memories->list($by, $workspace->id)) : [],
            remembers: $choices->tutorRemembers,
            language: $choices->language,
            askTopics: $choices->askTopics,
            marksTopics: $choices->tutorMarksTopics,
            moduleTitle: $module?->title,
            moduleAbout: $module?->about,
            moduleParts: $module === null ? [] : $this->parts($by, $module),
            moduleDates: $module === null ? null : self::dates($module),
            moduleInstructions: $instructions['module'],
            topics: $module === null ? [] : self::topicsOf($topics, $module, $folderIds),
            moduleOwnTopics: $module === null || $folderIds === null ? [] : self::ownTopicsOf($topics, $module),
            moduleFiles: $brief?->files ?? [],
            moduleQuestions: $brief?->questions ?? [],
            moduleKeyPoints: $brief?->keyPoints ?? [],
            moduleLast: $brief?->last,
            assignmentsDue: $assignmentsDue,
            assignmentsLinked: $assignmentsLinked,
            folderName: $folderName,
            otherFolders: $otherFolders,
            mode: $session->mode,
            topicNow: $topic?->name,
            topicPractice: $topic === null ? null : self::sessionWord($topic).'; practice: '.$topic->evidence().$this->onTopicSoFar($by, $session),
            topicChangedInChat: $topicChangedInChat,
            topicTakenOff: $topicTakenOff,
            withTools: $withTools,
            clock: self::clock($session),
            teaching: array_values(array_filter([
                ...array_map(fn (string $key) => Tutoring::CHOICES[$key][$tutoring[$key]][1], array_keys(Tutoring::DEFAULTS)),
                ! empty($tutoring['custom_do']) ? "What to do: {$tutoring['custom_do']}" : null,
                ! empty($tutoring['custom_dont']) ? "What NOT to do: {$tutoring['custom_dont']}" : null,
                ...array_map(fn (PluginDetails $p) => "Active plugin [{$p->name}]: ".implode(' ', $p->promptInstructions), Plugins::active($tutoring['plugins'] ?? [])),
            ])),
            material: $material,
            checkpoint: $session->checkpoint,
            summary: $session->summary,
            studied: $session->studySeconds >= 60 ? SessionDetails::duration($session->studySeconds) : null,
            openedNoteTitle: $openedNoteTitle,
            materialText: $materialText,
            folded: $folded,
        );
    }

    /**
     * The course's open graded work, and what the assignments this session feeds ask of it (the owner's ask,
     * 2026-10-13: the study tutor knows the assignment, and says "on your assignment you are asked…"). A session feeds
     * an assignment when its understanding names the session's topic, a topic of the session's module, or the module,
     * or when it is set in the module.
     *
     * @param  list<TopicDetails>  $topics
     * @return array{0: list<string>, 1: list<array{0: string, 1: bool}>}
     */
    private function assignmentsFor(Principal $by, string $workspaceId, ?ModuleDetails $module, ?TopicDetails $topic, array $topics): array
    {
        $moduleTopics = $module === null ? [] : array_values(array_map(fn (TopicDetails $t) => $t->id, array_filter($topics, fn (TopicDetails $t) => $t->moduleId === $module->id)));
        $due = [];
        $linked = [];
        foreach ($this->activities->list($by, $workspaceId) as $work) {
            if ($work->status === 'done' || ! in_array($work->kind, self::GRADED_KINDS, true)) {
                continue;
            }
            if (count($due) < self::ASSIGNMENTS_DUE) {
                $left = $work->timeLeft();
                $due[] = "{$work->title} ({$work->kindLabel()}): ".($work->dueWords() ?? 'no deadline set').($left !== null ? ", {$left}" : '')."; stage: {$work->stage}";
            }
            if (count($linked) < self::ASSIGNMENTS_LINKED) {
                $lines = self::fed($work, $this->assignmentUnderstandings->latest($by, $work->id), $module, $topic, $moduleTopics);
                if ($lines !== []) {
                    $linked[] = $lines;
                }
            }
        }

        return [$due, array_merge(...$linked)];
    }

    /**
     * What one assignment asks of this session's study, when the session feeds it; none when it doesn't. Its header
     * and why are always kept; what it asks can be cut.
     *
     * @param  list<string>  $moduleTopics
     * @return list<array{0: string, 1: bool}>
     */
    private static function fed(ActivityDetails $work, ?AssignmentUnderstanding $understanding, ?ModuleDetails $module, ?TopicDetails $topic, array $moduleTopics): array
    {
        $drawsOn = $understanding?->items('topics') ?? [];
        $onTopic = $topic === null ? null : (array_values(array_filter($drawsOn, fn (array $item) => ($item['id'] ?? null) === $topic->id))[0] ?? null);
        $inModule = $module === null ? [] : array_column(array_filter($drawsOn, fn (array $item) => in_array($item['id'] ?? null, $moduleTopics, true)), 'topic');
        $why = match (true) {
            $onTopic !== null => "it draws on the session's topic, {$topic->name}".(($onTopic['why'] ?? '') !== '' ? ": {$onTopic['why']}" : ''),
            $inModule !== [] => 'it draws on this module\'s '.implode(', ', array_slice($inModule, 0, 4)),
            $module !== null && in_array($module->id, array_column($understanding?->items('modules') ?? [], 'id'), true) => 'it draws on this module',
            $module !== null && $work->moduleId === $module->id => 'it is set in this module',
            $understanding !== null && $work->moduleId === null && $understanding->items('modules') === [] && $understanding->items('topics') === [] => 'it is an open course assignment not tied to a single module yet',
            default => null,
        };
        if ($why === null) {
            return [];
        }
        $lines = [["{$work->title}, because {$why}.", true]];
        if ($understanding === null) {
            $lines[] = ['Not read yet: what the student was given isn\'t on its page. Suggest adding the brief there.', true];

            return $lines;
        }
        if ($understanding->text('summary') !== '') {
            $lines[] = ['What it is'.($understanding->confirmed() ? '' : ' (the student hasn\'t confirmed this reading)').": {$understanding->text('summary')}", true];
        }
        foreach (['deliverables' => 'Hand in', 'requirements' => 'Must', 'criteria' => 'Marked on'] as $part => $label) {
            foreach (array_slice($understanding->items($part), 0, 3) as $item) {
                $lines[] = ["- {$label}: ".self::understood($part, $item), false];
            }
        }

        return $lines;
    }

    /** One assessment as a line: "Midterm 30 % (12 Oct)"; the year only when it isn't this one. */
    private static function assessment(array $item, CarbonImmutable $today): string
    {
        $due = $item['due_on'] === null ? null : CarbonImmutable::parse($item['due_on']);
        $when = $due === null ? null : $due->format($due->year === $today->year ? 'j M' : 'j M Y');

        return $item['name'].($item['weight'] !== null ? " {$item['weight']} %" : '').($when !== null ? " ({$when})" : '');
    }

    /** The helper's rules, without the file's opening comment (for people). */
    public static function helperRules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::HELPER_PROMPT))));
    }

    /**
     * Which version of the tutor's rules a model was given, as "tutor-2@" and the start of the file's hash: it changes
     * by itself whenever the rules do, so what a model judged under older rules can be told apart.
     */
    public static function promptVersion(): string
    {
        return 'tutor-2@'.substr(sha1((string) file_get_contents(base_path(self::PROMPT))), 0, 12);
    }

    /** The tutor's rules, for a model that can call tools or one that can't (the file marks the parts for each). */
    public static function rules(bool $withTools): string
    {
        $text = (string) file_get_contents(base_path(self::PROMPT));
        $text = (string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', $text);
        [$keep, $drop] = $withTools ? ['tools', 'plain'] : ['plain', 'tools'];
        $text = (string) preg_replace("/<!-- {$drop} -->.*?<!-- \\/{$drop} -->\\s*/s", '', $text);
        $text = (string) preg_replace("/<!-- \\/?{$keep} -->\\s*/", '', $text);

        // A part taken out leaves its neighbours close: a heading always has a blank line before it.
        $text = (string) preg_replace('/([^\n])\n(#{1,3} )/', "$1\n\n$2", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    // ---------- The layers ----------

    /** @return list<array{0: string, 1: bool}> lines, each with whether it is never cut */
    private function course(Facts $f): array
    {
        $lines = [["## The course: {$f->courseName}", true]];
        if ($f->courseLine !== null) {
            $lines[] = [$f->courseLine, true];
        }
        foreach ($f->courseKind as $line) {
            $lines[] = [$line, true];
        }
        if ($f->courseAbout !== '') {
            $lines[] = ["About: {$f->courseAbout}", true];
        }
        if ($f->courseTextbook !== '') {
            $lines[] = ["Textbook: {$f->courseTextbook}", true];
        }
        if ($f->courseOutcomes !== []) {
            $lines[] = ['Outcomes:', true];
            foreach ($f->courseOutcomes as $outcome) {
                $lines[] = ["- {$outcome}", false];
            }
        }
        if ($f->courseAssessment !== []) {
            $lines[] = ['Assessment:', true];
            foreach ($f->courseAssessment as $item) {
                $lines[] = ["- {$item}", false];
            }
        }
        if ($f->courseInstructions !== '') {
            $lines[] = ["Instructions for this course, in the student's words: {$f->courseInstructions}", true];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> */
    private function student(Facts $f, bool $withTools): array
    {
        $lines = [['## The student', true], ['About you, in their words: '.($f->aboutYou !== '' ? $f->aboutYou : 'nothing written yet.'), true]];
        if ($f->preferences !== []) {
            $lines[] = ['How they like to learn in this course: '.implode(' · ', $f->preferences).'.', true];
        }
        // What the tutor kept from earlier chats: a few short lines, so never cut. Numbered, so the tool can replace one.
        if (! $f->remembers) {
            $lines[] = ['They have turned off your remembering things about them: keep nothing with remember.', true];
        } elseif ($f->memories !== []) {
            $lines[] = ['What you remember about them (kept from earlier chats, or written by them; they see, change and delete it):', true];
            foreach (array_values($f->memories) as $i => $memory) {
                $lines[] = [($i + 1).'. '.$memory, true];
            }
        } elseif ($withTools) {
            $lines[] = ['What you remember about them: nothing yet. Keep what lasts with remember.', true];
        }
        if ($withTools) {
            $lines[] = [$f->askTopics
                ? 'Topics: ask before you add or switch them; propose, and do it once they agree.'
                : 'Topics: keep them yourself, as you teach, and say so in one line.', true];

        }
        if ($f->language !== null) {
            $lines[] = ["\n## Language\n\nThe student chose to be taught in {$f->language}. Write your messages in {$f->language}, whatever language they write in, unless they ask for another in this chat. Keep the course's own terms, and anything you quote from their material, in the course's language, with the {$f->language} beside them when it helps. Write the marks' text (key points, cards, questions, answers) in the course's language, so they match the exams, unless the student asks otherwise.", true];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> */
    private function module(Facts $f, bool $isHelper = false): array
    {
        if ($f->moduleTitle === null && $f->folderName === null) {
            return self::graded($f);
        }
        $title = $f->moduleTitle !== null ? $f->moduleTitle.($f->moduleDates !== null ? " ({$f->moduleDates})" : '') : null;
        $here = $f->folderName !== null;
        if ($here) {
            // A folder's session (docs/specs/vistud-2-blueprint.md, Phase 9) or helper look-up: where they are, and what that means.
            $lines = [['## Where you are', true], [implode(' › ', array_filter([$f->courseName, $title, $f->folderName])), true]];
            if ($isHelper) {
                $lines[] = ["The student is looking at the folder {$f->folderName}. Answer their questions about this folder and its topics and files.", true];
            } else {
                $lines[] = ["This session is in the folder {$f->folderName}: teach from its files and keep to its topics. What you save (notes, cards, questions, key points, new topics) goes in it.", true];
            }
            if ($f->otherFolders !== []) {
                $otherText = $isHelper
                    ? 'The module\'s other folders: '.implode(' · ', array_slice($f->otherFolders, 0, 8)).'.'
                    : 'The module\'s other folders are for other sessions: '.implode(' · ', array_slice($f->otherFolders, 0, 8)).'.';
                $lines[] = [$otherText, true];
            }
        } else {
            $lines = [['## The module: '.$title, true]];
        }
        // What the module is (a week, a chapter, a lab block) and what it holds, so a lab week is taught as one.
        if ($f->moduleAbout !== null && $f->moduleAbout !== '') {
            $lines[] = ['About this module: '.$f->moduleAbout, true];
        }
        if ($f->moduleParts !== []) {
            $lines[] = ['Its parts: '.implode(' · ', $f->moduleParts).'.', true];
        }
        if ($f->moduleInstructions !== '') {
            $lines[] = ["Instructions for this module, in the student's words: {$f->moduleInstructions}", true];
        }
        $lines[] = [$f->topics === []
            ? ($here ? 'Topics in this folder: none yet.' : 'Topics: none yet.')
            : ($here ? 'Topics in this folder, in course order, where each stands (and who said so, when someone has):' : 'Topics, in course order, where each stands (and who said so, when someone has):'), true];
        // The session's topic is marked, and kept when the list is cut: the one after it is the next to study.
        foreach ($f->topics as $topic) {
            $now = $f->topicNow !== null && $topic['name'] === $f->topicNow;
            $lines[] = ["- {$topic['name']}: {$topic['status']}".($now ? ' ← the session\'s topic now' : ''), $now];
        }
        if ($here && $f->moduleOwnTopics !== []) {
            $lines[] = ['In the module but in no folder (they belong to every part of it):', true];
            foreach ($f->moduleOwnTopics as $topic) {
                $lines[] = ["- {$topic['name']}: {$topic['status']}", false];
            }
        }
        if ($f->moduleLast !== null) {
            $lines[] = [($here ? 'Last time in this folder: ' : 'Last time: ').$f->moduleLast, true];
        }
        array_push($lines, ...self::graded($f));
        // What can be fetched with a tool comes after what can't, and goes first when the layer is over its budget.
        foreach ([($here ? 'Files in this folder' : 'Files') => $f->moduleFiles, 'Open questions' => $f->moduleQuestions, 'Key points' => $f->moduleKeyPoints] as $name => $items) {
            if ($items !== []) {
                $lines[] = ["{$name}:", true];
                foreach ($items as $item) {
                    $lines[] = ["- {$item}", false];
                }
            }
        }

        return $lines;
    }

    /**
     * The course's open graded work, and what the assignments this study feeds ask for: so the tutor ties what it
     * teaches to what will be marked, and says so.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function graded(Facts $f): array
    {
        if ($f->assignmentsDue === [] && $f->assignmentsLinked === []) {
            return [];
        }
        $lines = [['## Assignments and exams', true]];
        if ($f->assignmentsDue !== []) {
            $lines[] = ['Open, soonest first:', true];
            foreach ($f->assignmentsDue as $line) {
                $lines[] = ["- {$line}", true];
            }
        }
        if ($f->assignmentsLinked !== []) {
            $lines[] = ['What this study feeds, from each assignment\'s reading ([S1], [F2]: its sources). When what you teach is asked for in one, say so ("On Coursework 1 you are asked to…"), tie it to what is marked, and offer to save useful study notes or examples directly to that assignment with save_to_assignment. Help them learn it; never write the graded work.', true];
            array_push($lines, ...$f->assignmentsLinked);
        }

        return $lines;
    }

    /** What each study mode means for this session, in one line (the paragraph per mode is in the tutor's rules). */
    private const MODES = [
        'module' => 'Mode: whole module. Go through the module\'s material in order, topic by topic.',
        'topic' => 'Mode: one topic. Teach the topic now only; offer the next, don\'t start it.',
        'quiz' => 'Mode: quiz. Five questions, one at a time, on the topic now or what they find hardest.',
        'test' => 'Mode: test. Ten to fifteen exam-level questions over the module, scored at the end.',
        'free' => 'Mode: free. Answer what they ask; no plan and no checkpoints.',
    ];

    /** The same for a session in a folder: the whole of it is the folder. */
    private const FOLDER_MODES = [
        'module' => 'Mode: whole folder. Go through the folder\'s material in order, topic by topic.',
        'test' => 'Mode: test. Ten to fifteen exam-level questions over the folder, scored at the end.',
    ];

    /** @return list<array{0: string, 1: bool}> */
    private function session(Facts $f): array
    {
        $lines = [['## This session', true]];
        $lines[] = [($f->folderName !== null ? (self::FOLDER_MODES[$f->mode] ?? null) : null) ?? self::MODES[$f->mode] ?? self::MODES['topic'], true];
        // The chat's earlier turns can be on another topic than the one now: the student changes it on the session page.
        $lines[] = [match (true) {
            $f->topicNow !== null && $f->topicChangedInChat => "Topic now: {$f->topicNow} ({$f->topicPractice}). It was chosen during this chat: earlier turns may be on another topic; teach this one from here.",
            $f->topicNow !== null => "Topic now: {$f->topicNow} ({$f->topicPractice}).",
            $f->topicTakenOff => 'Topic now: none: the student took it off on the session page. Answer what they ask, and set one (set_topic) only when they ask for it or agree to yours.',
            default => 'Topic now: none chosen. Propose one and ask whether to take it; once you are teaching one, set it (set_topic) so what is saved and studied counts for it.',
        }, true];
        if ($f->withTools) {
            $lines[] = [$f->marksTopics
                ? 'Statuses: what you set with set_topic_status is applied at once and shown as yours; the student can undo it.'
                : 'Statuses: what you set with set_topic_status is only proposed; the student accepts it or not.', true];
        }
        $lines[] = ["Clock: {$f->clock}.", true];
        if ($f->teaching !== []) {
            $lines[] = ['How to teach:', true];
            foreach ($f->teaching as $line) {
                $lines[] = ["- {$line}", true];
            }
        }
        foreach ($f->material as $line) {
            $lines[] = [$line, true];
        }
        if ($f->openedNoteTitle !== null) {
            $lines[] = ["Opened note beside chat: \"{$f->openedNoteTitle}\". The student has this note open right now. When taking notes, writing, editing lines, or updating content, edit or write directly into this note (with edit_note or write_note) unless they specify another. Changes appear live on their screen.", true];
        }
        if ($f->checkpoint !== null) {
            $lines[] = ["Where this session last stood (your last checkpoint): {$f->checkpoint} Pick up from here.", false];
        }
        if ($f->studied !== null) {
            $lines[] = ["Studied so far: {$f->studied}.", false];
        }
        if ($f->summary !== null) {
            $lines[] = ["Summary so far: {$f->summary}", false];
        }
        // A model without tools has no other way to read the chosen notes: they stay whole (each is capped by NOTE_LIMIT).
        foreach ($f->materialText as $block) {
            $lines[] = ["\n{$block}", true];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> */
    private function assignment(Principal $by, ActivityDetails $a): array
    {
        $lines = [];
        $lines[] = ["## The assignment: {$a->title}", true];
        // A paper can run for months and change models: this layer is read fresh every turn, the chat is not.
        $lines[] = ['This section is ViStud\'s record as of now. Where the chat above says something else about the stage, the plan or the log, the chat is older: go by this.', true];
        $lines[] = ["Kind: {$a->kindLabel()}", true];
        $lines[] = ["Stage: {$a->stage}", true];
        $lines[] = ["Status: {$a->status}", true];
        $due = $a->dueOn === null ? 'none given' : $a->dueAt()->format('l j F Y').($a->dueTime !== null ? " at {$a->dueTime}" : '')." ({$a->zone})".($a->timeLeft() !== null ? " — {$a->timeLeft()} left" : '');
        $lines[] = ["Deadline: {$due}", true];

        if ($a->moduleId !== null) {
            try {
                $module = $this->modules->find($by, $a->moduleId);
                $lines[] = ["Module: {$module->title}", true];
            } catch (NotFound) {
                // Gone
            }
        }

        // Latest understanding
        $understanding = $this->assignmentUnderstandings->latest($by, $a->id);
        if ($understanding === null) {
            $lines[] = ['Understanding: There is no stored understanding of this assignment yet. Ask the student to drop a file in this chat or paste what they were told. Any file counts. You may still help in general terms, marked as such, until they do.', true];
        } else {
            $confirmed = $understanding->confirmed() ? " (confirmed by the student at {$understanding->confirmedAt})" : ' (not confirmed yet)';
            $lines[] = ["### The AI's understanding (revision {$understanding->revision}){$confirmed}", true];
            if ($understanding->text('summary') !== '') {
                $lines[] = ["Summary: {$understanding->text('summary')}", true];
            }
            if ($understanding->text('goal') !== '') {
                $lines[] = ["Goal: {$understanding->text('goal')}", true];
            }
            if ($understanding->text('confidence') !== '') {
                $lines[] = ["Confidence: {$understanding->text('confidence')}", true];
            }

            // Every part, every item with all it says and the label of the source it came from. What the work is judged
            // on is always kept; the rest can be cut when the layer is over its budget (the tutor reads it on the page).
            // A topic it draws on says where the student stands on it, so the tutor sees what the work needs and they lack.
            $standing = [];
            foreach ($this->topics->list($by, $a->workspaceId) as $topic) {
                $standing[$topic->id] = $topic;
            }
            foreach (self::UNDERSTOOD as $part => [$heading, $kept]) {
                $items = $understanding->items($part);
                if ($items === []) {
                    continue;
                }
                $lines[] = ["{$heading}:", $kept];
                foreach ($items as $item) {
                    $topic = $part === 'topics' ? ($standing[$item['id'] ?? ''] ?? null) : null;
                    $lines[] = ['- '.self::understood($part, $item).($topic !== null ? ' — where the student stands: '.self::status($topic) : ''), $kept];
                }
            }
            foreach ($understanding->extraSections() as $section) {
                $lines[] = [$section['title'].':', false];
                foreach ($section['items'] as $item) {
                    $lines[] = ['- '.AssignmentUnderstanding::line('extra', $item), false];
                }
            }

            // Check if stale
            if ($this->assignmentSources->outOfDate($by, $a->id, $understanding)) {
                $lines[] = ["\nNOTE: This understanding is OUT OF DATE (sources have changed since it was built). Suggest 'Read again' on the assignment page.", true];
            }
        }

        // The sources, by the labels the understanding cites (a source added since has the next free one, and is said
        // to be new): read one with assignment_source, a file with read_file.
        $labelled = $this->assignmentSources->labelled($by, $a->id, $understanding);
        $lines[] = ["\n### Sources (read one in full with assignment_source; a file with read_file)", true];
        if ($labelled === []) {
            $lines[] = ['No sources added yet.', true];
        }
        foreach ($labelled as $label => $item) {
            $new = $understanding !== null && ! isset($understanding->sources[$label]) ? ' (new: not in the understanding yet)' : '';
            if ($item instanceof FileDetails) {
                $lines[] = ["- {$label} · file “{$item->fileName()}”{$new}", true];

                continue;
            }
            $title = $item->title !== null ? " · “{$item->title}”" : '';
            $date = $item->receivedOn ?? CarbonImmutable::parse($item->createdAt)->format('Y-m-d');
            $text = trim((string) preg_replace('/\s+/u', ' ', $item->text));
            $excerpt = mb_strlen($text) > 100 ? mb_substr($text, 0, 100).'…' : $text;
            $lines[] = ["- {$label} · {$item->kindLabel()}{$title} ({$date}){$new}: {$excerpt}", true];
        }

        // Plan summary
        $plan = $this->plans->get($by, $a->id);
        $lines[] = ["\n### Plan", true];
        if ($plan->empty()) {
            $lines[] = ['No plan yet.', true];
            $starters = [];
            foreach (PlanStarters::ALL as $key => $starter) {
                $partNames = implode(' → ', array_map(fn (array $part) => $part[0], $starter['parts']));
                $starters[] = "{$key} ({$starter['label']}: {$partNames})";
            }
            $lines[] = ['Pre-made plan starters available (via save_assignment_plan `starter`, or tailored with custom sections/steps/criteria): '.implode('; ', $starters).'.', true];
        } else {
            $progress = $plan->progress();
            $health = $plan->health($a);
            $healthText = $health !== null ? " · Health: {$health['state']}".($health['reasons'] !== [] ? ' ('.implode(', ', $health['reasons']).')' : '') : '';
            $lines[] = ["Progress: {$progress->done} of {$progress->total} done, {$progress->percent}%{$healthText}", true];

            $parts = $plan->parts();
            if ($parts !== []) {
                $lines[] = ['Sections and steps:', true];
                foreach ($parts as $part) {
                    $weight = $part->weight !== null ? " ({$part->weight}%)" : '';
                    $state = AssignmentPlanTool::state($plan, $part);
                    $lines[] = ["- {$part->title}{$weight}: {$state}", true];
                    // Every level of steps (Plans::MAX_DEPTH), so a later model sees which sub-step is open.
                    array_push($lines, ...self::stepLines($plan, $part->id, 1));
                }
            }

            $standalone = $plan->steps(null);
            if ($standalone !== []) {
                $lines[] = ['Tasks outside sections:', true];
                array_push($lines, ...self::stepLines($plan, null, 0));
            }

            $criteria = $plan->criteria();
            if ($criteria !== []) {
                $lines[] = ['Marking criteria in the plan:', true];
                foreach ($criteria as $criterion) {
                    $state = AssignmentPlanTool::words($criterion->state);
                    $marks = $criterion->weight !== null ? " ({$criterion->weight} marks)" : '';
                    $lines[] = ["- [{$state}] {$criterion->title}{$marks}", true];
                }
            }

            $milestones = $plan->milestones();
            if ($milestones !== []) {
                $lines[] = ['Milestones:', false];
                foreach ($milestones as $milestone) {
                    $dueM = $milestone->dueOn !== null ? " ({$milestone->dueOn})" : '';
                    $lines[] = ["- [{$milestone->state}] {$milestone->title}{$dueM}", false];
                }
            }
        }

        // Notes saved in the assignment's folder (either written in this chat or saved from study sessions via save_to_assignment)
        try {
            $folder = $this->activities->folder($by, $a->id);
            $folderNotes = array_values(array_filter(
                $this->notes->list($by, $a->workspaceId),
                fn (NoteDetails $n) => $n->trashedAt === null && $n->folderId === $folder->id
            ));
            if ($folderNotes !== []) {
                $lines[] = ["\n### Notes saved for this assignment (read with read_note; write or update with write_note / edit_note)", true];
                foreach ($folderNotes as $n) {
                    $lines[] = ["- “{$n->displayTitle()}”", true];
                }
            }
        } catch (NotFound) {
            // Folder not created yet
        }

        // What was decided and done, newest first: always kept (it is how a new model knows where things stand), at
        // most LOG_LINES of it; the page keeps the rest.
        $logEntries = $this->assignmentLog->list($by, $a->id, limit: self::LOG_LINES);
        $lines[] = ["\n### Log, newest first (decisions, progress, advice, feedback, risks): the newest ".self::LOG_LINES.'; assignment_plan with log_page 2, 3… gives older ones', true];
        if ($logEntries === []) {
            $lines[] = ['No entries yet.', true];
        }
        foreach ($logEntries as $entry) {
            $at = CarbonImmutable::parse($entry->createdAt)->format('Y-m-d H:i');
            $lines[] = ["- [{$at}] [{$entry->stage}] {$entry->kind}: {$entry->text} (by {$entry->by})", true];
        }

        return $lines;
    }

    /**
     * A plan's steps under `$parentId`, and their sub-steps, indented by level.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function stepLines(PlanDetails $plan, ?string $parentId, int $depth): array
    {
        $lines = [];
        foreach ($plan->steps($parentId) as $step) {
            $state = AssignmentPlanTool::state($plan, $step);
            $due = $step->dueOn !== null ? " (due {$step->dueOn})" : '';
            $lines[] = [str_repeat('  ', $depth)."- [{$state}] {$step->title}{$due}", true];
            if ($depth < 4) {
                array_push($lines, ...self::stepLines($plan, $step->id, $depth + 1));
            }
        }

        return $lines;
    }

    /** One item of the understanding as the tutor reads it: all it says, and where it came from. */
    private static function understood(string $part, array $item): string
    {
        return AssignmentUnderstanding::line($part, $item);
    }

    // ---------- Gathering ----------

    private function moduleOf(Principal $by, string $workspaceId, ?string $moduleId): ?ModuleDetails
    {
        foreach ($moduleId === null ? [] : $this->modules->list($by, $workspaceId) as $module) {
            if ($module->id === $moduleId) {
                return $module;
            }
        }

        return null;
    }

    /**
     * The session's chosen material: a line each, and, for a model that can't read notes itself, their text.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function material(Principal $by, SessionDetails $session, bool $withTools): array
    {
        if ($session->material === []) {
            return [[], []];
        }
        $lines = [];
        $blocks = [];
        foreach ($this->files->list($by, $session->workspaceId) as $file) {
            if ($session->uses("file:{$file->id}")) {
                $lines[] = "Material: {$file->typeLabel()} {$file->fileName()}".($withTools ? ', read it with read_file, a few pages at a time.' : '; the student shares it a part at a time.');
            }
        }
        foreach ($this->notes->list($by, $session->workspaceId) as $note) {
            if (! $session->uses("note:{$note->id}")) {
                continue;
            }
            $lines[] = "Material: the student's note \"{$note->displayTitle()}\"".($withTools ? ', read it with read_note.' : ', its text is below.');
            if ($withTools) {
                continue;
            }
            try {
                $text = NoteDoc::markdown($this->notes->open($by, $note->id)->doc ?? []);
            } catch (NotFound) {
                continue;
            }
            if (mb_strlen($text) > self::NOTE_LIMIT) {
                $text = rtrim(mb_substr($text, 0, self::NOTE_LIMIT))."\n\n(The rest of this note is left out.)";
            }
            $blocks[] = "## The student's note: {$note->displayTitle()}\n\n".NoteDoc::fenced($text !== '' ? $text : '(Empty.)');
        }

        return [$lines, $blocks];
    }

    /**
     * The module's topics, each with the student's word on it.
     *
     * @param  list<TopicDetails>  $topics
     * @return list<array{name: string, status: string}>
     */
    private static function topicsOf(array $topics, ModuleDetails $module, ?array $folderIds = null): array
    {
        return array_map(fn ($t) => ['name' => $t->name, 'status' => self::status($t)], array_values(array_filter($topics, fn ($t) => $folderIds !== null ? $t->in($folderIds) : $t->moduleId === $module->id)));
    }

    /**
     * The module's topics that are in none of its folders, for a folder's session: they are the module's, so its parts'.
     *
     * @param  list<TopicDetails>  $topics
     * @return list<array{name: string, status: string}>
     */
    private static function ownTopicsOf(array $topics, ModuleDetails $module): array
    {
        return array_map(fn ($t) => ['name' => $t->name, 'status' => self::status($t)], array_values(array_filter($topics, fn ($t) => $t->moduleId === $module->id && $t->folderId === null)));
    }

    /**
     * The session's folder, when it studies in one of the module's: its name with the folders above it ("Lecture 1 +
     * Lab 1 › Labs"), it and the folders inside it, and the names of the module's other folders. Nothing when it has
     * none, or the folder is gone or in another module.
     *
     * @return array{0: ?string, 1: ?list<string>, 2: list<string>}
     */
    private function folderOf(Principal $by, SessionDetails $session, ModuleDetails $module): array
    {
        if ($session->folderId === null) {
            return [null, null, []];
        }

        return $this->folderInfo($by, $session->folderId, $module);
    }

    /**
     * @return array{0: ?string, 1: ?list<string>, 2: list<string>}
     */
    /**
     * The module's parts: its top-level folders, each with its kind when it has one ("Lab session (lab)").
     *
     * @return list<string>
     */
    private function parts(Principal $by, ModuleDetails $module): array
    {
        $parts = [];
        foreach ($this->folders->tree($by, $module->workspaceId) as $folder) {
            if ($folder->moduleId === $module->id && $folder->parentId === null && count($parts) < 8) {
                $kind = ModuleParts::beside($folder->kind, $folder->name);
                $parts[] = $folder->name.($kind !== null ? ' ('.mb_strtolower($kind).')' : '');
            }
        }

        return $parts;
    }

    public function folderInfo(Principal $by, string $folderId, ModuleDetails $module): array
    {
        try {
            $path = $this->folders->path($by, $folderId);
        } catch (NotFound) {
            return [null, null, []];
        }
        if ($path[0]->moduleId !== $module->id) {
            return [null, null, []];
        }
        $others = [];
        foreach ($this->folders->tree($by, $module->workspaceId) as $folder) {
            if ($folder->moduleId === $module->id && $folder->parentId === null && $folder->id !== $path[0]->id) {
                $count = count(array_filter($this->topics->list($by, $module->workspaceId), fn ($t) => $t->in($this->folders->within($by, $folder->id))));
                $others[] = $folder->name.($count > 0 ? ' ('.$count.' '.($count === 1 ? 'topic' : 'topics').')' : '');
            }
        }

        return [implode(' › ', array_map(fn ($folder) => $folder->name, $path)), $this->folders->within($by, $folderId), $others];
    }

    /**
     * Where the student stands on a topic, as the tutor needs it to teach and to update it (the owner's ask, 2026-10-12):
     * the word shown, who said it and when (the student's word from this session stands; an older one, or the tutor's own,
     * it may update), and what the evidence says. "covered: the student said so 3 days ago; evidence: seen, not practised".
     */
    private static function status(TopicDetails $topic): string
    {
        $shown = $topic->shown();
        $word = self::word($shown);
        // Nobody has said anything and nothing has been studied: the default, not the student's word.
        if ($topic->statusSource() === 'none' && $topic->label === 'not_started') {
            return 'not started (nothing said or studied yet)';
        }
        $ago = $topic->statusAt !== null ? ' '.self::ago($topic->statusAt) : '';
        // Who said it, when the word shown is the one said (not overtaken by newer evidence).
        $said = match ($topic->statusSource()) {
            'stated' => "the student said so{$ago}",
            'stated_unverified' => "the student said so{$ago} (may have been an accepted AI proposal)",
            'accepted' => "the student accepted the AI's word{$ago}",
            'tutor' => "you marked it{$ago}",
            'chat' => "a chat app marked it{$ago}",
            default => null,
        };

        return $word.($said !== null ? ": {$said}" : '').($shown !== 'not_started' || $topic->label !== 'not_started' ? '; evidence: '.$topic->evidence() : '');
    }

    /**
     * The session topic's word and who said it, for the session line: "you marked it understood", "the student says
     * confusing". A word the practice has overtaken since is not put in anyone's mouth: "mastered, from the practice
     * since (the student said understood earlier)".
     */
    private static function sessionWord(TopicDetails $topic): string
    {
        $word = self::word($topic->shown());
        if ($topic->status === null || $topic->statusBy === null) {
            return "{$word}, nobody has said yet";
        }
        if ($topic->saidShown()) {
            return match ($topic->statusSource()) {
                'tutor' => "you marked it {$word}",
                'chat' => "a chat app marked it {$word}",
                'accepted' => "the student accepted the AI's word: {$word}",
                'stated_unverified' => "the student says {$word} (may have been an accepted AI proposal)",
                default => "the student says {$word}",
            };
        }

        $earlier = match ($topic->statusBy) {
            'tutor' => 'you marked it',
            'chat' => 'a chat app marked it',
            'accepted' => "the student accepted the AI's word:",
            'student' => 'the student said (may have been an accepted AI proposal)',
            default => 'the student said',
        };

        return "{$word}, from the practice since ({$earlier} ".self::word($topic->status).' earlier)';
    }

    /**
     * How long this session has been on its topic, up to the student's last activity: "; on it this session: 14 min,
     * recorded as studying it when the topic changes or the session ends". Nothing under a minute.
     */
    private function onTopicSoFar(Principal $by, SessionDetails $session): string
    {
        $since = LearnerTables::query(Guard::learner($by), 'study_sessions')->where('id', $session->id)->value('topic_since');
        if ($since === null || ! $session->isOpen()) {
            return '';
        }
        $until = CarbonImmutable::parse($session->lastActivityAt, 'UTC')->min(CarbonImmutable::now());
        $minutes = intdiv(max(0, $until->getTimestamp() - CarbonImmutable::parse((string) $since, 'UTC')->getTimestamp()), 60);

        return $minutes < 1 ? '' : "; on it this session: {$minutes} min, recorded as studying it when the topic changes or the session ends";
    }

    /**
     * From the session's topic log: whether the topic now was chosen after the chat's first turn (so its earlier turns
     * may be on another topic), and whether the student took the topic off. A topic's chat is no session: neither.
     *
     * @return array{0: bool, 1: bool}
     */
    private function topicHistory(Principal $by, SessionDetails $session): array
    {
        try {
            $log = $this->sessions->topicLog($by, $session->id);
        } catch (NotFound) {
            return [false, false];
        }
        $last = $log === [] ? null : $log[array_key_last($log)];
        if ($last === null || $last['topic'] === null) {
            return [false, $last !== null && $last['by'] === 'student'];
        }
        $scope = Guard::learner($by);
        $threadId = LearnerTables::query($scope, 'engine_threads')->where('session_id', $session->id)->value('id');
        $first = $threadId === null ? null : LearnerTables::query($scope, 'engine_messages')->where('thread_id', $threadId)->where('role', 'user')->min('created_at');

        return [$first !== null && CarbonImmutable::parse($last['at'])->greaterThan(CarbonImmutable::parse((string) $first, 'UTC')), false];
    }

    /** A status as the tutor's tools take it: not started, covered, understood, confusing, mastered. */
    public static function word(string $status): string
    {
        return ['not_started' => 'not started', 'confused' => 'confusing'][$status] ?? $status;
    }

    /** "just now", "3 days ago". */
    private static function ago(string $at): string
    {
        $then = CarbonImmutable::parse($at, 'UTC');

        return $then->diffInSeconds(CarbonImmutable::now(), true) < 60 ? 'just now' : $then->diffForHumans();
    }

    private static function dates(ModuleDetails $module): ?string
    {
        $day = fn (?string $date) => $date === null ? null : CarbonImmutable::parse($date)->format('j M');
        $text = trim(($day($module->startsOn) ?? '').' – '.($day($module->endsOn) ?? ''), ' –');

        return $text !== '' ? $text : null;
    }

    private static function clock(SessionDetails $session): string
    {
        if ($session->pomodoro === null) {
            return 'free: the student pauses and takes breaks when they like';
        }
        $p = $session->pomodoro;

        return "Pomodoro, {$p['focus']}-minute focus periods with {$p['short']}-minute breaks ({$p['long']} minutes after every {$p['every']}); plan each part to fit in a focus period, and when the student says they're on a break, stop until they're back";
    }

    // ---------- Fitting ----------

    /**
     * A layer's text within its budget. Lines that must stay always do; the others are kept in order while they
     * fit, and the first that doesn't, with all after it, is cut and counted.
     *
     * @param  list<array{0: string, 1: bool}>  $lines  each line with whether it is never cut
     * @return array{0: string, 1: int} the text, and how many lines were cut
     */
    private static function fit(array $lines, int $budget): array
    {
        $room = $budget - array_sum(array_map(fn (array $line) => $line[1] ? Tokens::of($line[0]) : 0, $lines));
        $kept = [];
        $cut = 0;
        foreach ($lines as [$text, $whole]) {
            if ($whole) {
                $kept[] = $text;

                continue;
            }
            $cost = Tokens::of($text);
            if ($cut === 0 && $cost <= $room) {
                $kept[] = $text;
                $room -= $cost;
            } else {
                $cut++;
            }
        }
        if ($cut > 0) {
            $kept[] = "(cut: {$cut} more ".($cut === 1 ? 'line' : 'lines').') Your tools have the rest.';
        }

        return [implode("\n", $kept), $cut];
    }

    /** @param list<string> $texts */
    private static function join(array $texts): string
    {
        return implode("\n\n", array_values(array_filter($texts, fn (string $text) => $text !== '')));
    }

    /** Wraps a non-empty layer in Fence::wrap(), without double-fencing pre-fenced plain-model note blocks. */
    private static function fenceLayer(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (str_contains($text, "\n\n## The student's note: ")) {
            [$head, $notes] = explode("\n\n## The student's note: ", $text, 2);

            return Fence::wrap($head)."\n\n## The student's note: ".$notes;
        }

        return Fence::wrap($text);
    }
}
