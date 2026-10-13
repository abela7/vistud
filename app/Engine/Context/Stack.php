<?php

namespace App\Engine\Context;

use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\CourseProfiles;
use App\Study\CourseTypes;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\LearnerProfiles;
use App\Study\ModuleBriefs;
use App\Study\ModuleDetails;
use App\Study\ModuleParts;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
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
    ) {}

    /** The tutor's standing context for a session: its system prompt, its tools and the report on its layers. */
    public function build(Principal $by, SessionDetails $session, ?string $folded, bool $withTools): Built
    {
        return $this->compose($this->gather($by, $session, $folded, $withTools), $withTools, $withTools ? $this->toolbox->definitions() : []);
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

        return new Built(self::join([$texts[0], $texts[3], $texts[4]]), $texts[0], $tools, $report);
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
        // The session line says the word alone (it names who and the practice itself); the topic lists say it in full.
        $status = fn ($t) => self::word($t->shown());
        [$material, $materialText] = $this->material($by, $session, $withTools);

        $tutoring = Tutoring::normalised($session->tutoring);
        // In a folder (docs/specs/vistud-2-blueprint.md, Phase 9): the folder's brief and topics, where it sits, and the module's other folders.
        [$folderName, $folderIds, $otherFolders] = $module === null ? [null, null, []] : $this->folderOf($by, $session, $module);
        $brief = $module === null ? null : ($folderIds !== null ? $this->briefs->forFolder($by, (string) $session->folderId) : $this->briefs->for($by, $module->id));
        $course = $this->courseProfiles->get($by, $workspace->id);
        $today = CarbonImmutable::now();

        [$topicChangedInChat, $topicTakenOff] = $this->topicHistory($by, $session);

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
            folderName: $folderName,
            otherFolders: $otherFolders,
            mode: $session->mode,
            topicNow: $topic?->name,
            topicPractice: $topic === null ? null : ($topic->status === null ? "{$status($topic)}, nobody has said yet" : ($topic->byTutor() ? "you marked it {$status($topic)}" : "the student says {$status($topic)}")).'; practice: '.$topic->evidence().$this->onTopicSoFar($by, $session),
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
            return [];
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
        if ($shown === 'not_started' && $topic->status === null && $topic->label === 'not_started') {
            return 'not started (nothing said or studied yet)';
        }
        // Who said it, when the word shown is the one said (not overtaken by newer evidence).
        $said = $topic->status !== null && ($topic->status === $shown || ($shown === 'confused' && $topic->status === 'confused'))
            ? ($topic->statusBy === 'tutor' ? 'you marked it' : 'the student said so').($topic->statusAt !== null ? ' '.self::ago($topic->statusAt) : '')
            : null;

        return $word.($said !== null ? ": {$said}" : '').($shown !== 'not_started' || $topic->label !== 'not_started' ? '; evidence: '.$topic->evidence() : '');
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
}
