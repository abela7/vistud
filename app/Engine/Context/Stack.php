<?php

namespace App\Engine\Context;

use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Instructions;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\Tutoring;
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
    public const BUDGETS = [0 => 2_500, 2 => 600, 3 => 250, 4 => 700, 5 => 300];

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
    ) {}

    /** The tutor's standing context for a session: its system prompt, its tools and the report on its layers. */
    public function build(Principal $by, SessionDetails $session, ?string $folded, bool $withTools): Built
    {
        return $this->compose($this->gather($by, $session, $folded, $withTools), $withTools, $withTools ? $this->toolbox->definitions() : []);
    }

    /**
     * The helper's standing context: its short rules, the read-only tools beside them, and at most the module it is
     * asked about (title, instructions, topics), nothing else. Another student's module is not found.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function helper(Principal $by, ?string $moduleId, array $tools): Built
    {
        $facts = new Facts(courseName: '');
        if ($moduleId !== null) {
            $module = $this->modules->find($by, $moduleId);
            $workspace = $this->workspaces->find($by, $module->workspaceId);
            $facts = new Facts(
                courseName: $workspace->name,
                moduleTitle: $module->title,
                moduleDates: self::dates($module),
                moduleInstructions: $this->instructions->forSession($by, $workspace->id, $module->id)['module'],
                topics: self::topicsOf($this->topics->list($by, $workspace->id), $module),
            );
        }

        return $this->composeHelper($facts, $tools);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    public function composeHelper(Facts $facts, array $tools): Built
    {
        $layers = [0 => ['Rules', [[self::helperRules(), true]], self::HELPER_BUDGET], 4 => ['Module', $this->module($facts), self::BUDGETS[4]]];
        $report = [];
        $texts = [];
        foreach ($layers as $number => [$name, $lines, $budget]) {
            [$text, $cut] = self::fit($lines, $budget);
            $texts[$number] = $text;
            $report[] = ['layer' => $number, 'name' => $name, 'tokens' => Tokens::of($text), 'budget' => $budget, 'cut' => $cut];
        }
        $tooling = $tools === [] ? '' : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        array_splice($report, 1, 0, [['layer' => 1, 'name' => 'Tools', 'tokens' => Tokens::of($tooling), 'budget' => null, 'cut' => 0]]);

        return new Built(self::join([$texts[0], $texts[4]]), $texts[0], $tools, $report);
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
        $status = fn ($t) => self::status($t);
        [$material, $materialText] = $this->material($by, $session, $withTools);

        $tutoring = Tutoring::normalised($session->tutoring);

        return new Facts(
            courseName: $workspace->name,
            courseLine: $workspace->subtitle() !== '' ? $workspace->subtitle() : null,
            courseInstructions: $instructions['workspace'],
            aboutYou: $instructions['me'],
            language: $choices->language,
            askTopics: $choices->askTopics,
            moduleTitle: $module?->title,
            moduleDates: $module === null ? null : self::dates($module),
            moduleInstructions: $instructions['module'],
            topics: $module === null ? [] : self::topicsOf($topics, $module),
            topicNow: $topic?->name,
            topicPractice: $topic === null ? null : "the student says {$status($topic)}; practice: {$topic->evidence()}",
            clock: self::clock($session),
            teaching: array_map(fn (string $key) => Tutoring::CHOICES[$key][$tutoring[$key]][1], array_keys(Tutoring::DEFAULTS)),
            material: $material,
            checkpoint: $session->checkpoint,
            summary: $session->summary,
            studied: $session->studySeconds >= 60 ? SessionDetails::duration($session->studySeconds) : null,
            materialText: $materialText,
            folded: $folded,
        );
    }

    /** The helper's rules, without the file's opening comment (for people). */
    public static function helperRules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::HELPER_PROMPT))));
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
        if ($f->courseInstructions !== '') {
            $lines[] = ["Instructions for this course, in the student's words: {$f->courseInstructions}", true];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> */
    private function student(Facts $f, bool $withTools): array
    {
        $lines = [['## The student', true], ['About you, in their words: '.($f->aboutYou !== '' ? $f->aboutYou : 'nothing written yet.'), true]];
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
    private function module(Facts $f): array
    {
        if ($f->moduleTitle === null) {
            return [];
        }
        $lines = [['## The module: '.$f->moduleTitle.($f->moduleDates !== null ? " ({$f->moduleDates})" : ''), true]];
        if ($f->moduleInstructions !== '') {
            $lines[] = ["Instructions for this module, in the student's words: {$f->moduleInstructions}", true];
        }
        $lines[] = [$f->topics === [] ? 'Topics: none yet.' : 'Topics, with what the student says of each:', true];
        foreach ($f->topics as $topic) {
            $lines[] = ["- {$topic['name']}: {$topic['status']}", false];
        }

        return $lines;
    }

    /** @return list<array{0: string, 1: bool}> */
    private function session(Facts $f): array
    {
        $lines = [['## This session', true]];
        $lines[] = [$f->topicNow !== null
            ? "Topic now: {$f->topicNow} ({$f->topicPractice})."
            : 'Topic now: none chosen. Propose one and ask whether to take it.', true];
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
        if ($f->checkpoint !== null) {
            $lines[] = ["Where this session last stood (your last checkpoint): {$f->checkpoint} Pick up from here.", false];
        }
        if ($f->studied !== null) {
            $lines[] = ["Studied so far: {$f->studied}.", false];
        }
        if ($f->summary !== null) {
            $lines[] = ["Summary so far: {$f->summary}", false];
        }
        foreach ($f->materialText as $block) {
            $lines[] = ["\n{$block}", false];
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
            $blocks[] = "## The student's note: {$note->displayTitle()}\n\n".($text !== '' ? $text : '(Empty.)');
        }

        return [$lines, $blocks];
    }

    /**
     * The module's topics, each with the student's word on it.
     *
     * @param  list<TopicDetails>  $topics
     * @return list<array{name: string, status: string}>
     */
    private static function topicsOf(array $topics, ModuleDetails $module): array
    {
        return array_map(fn ($t) => ['name' => $t->name, 'status' => self::status($t)], array_values(array_filter($topics, fn ($t) => $t->moduleId === $module->id)));
    }

    private static function status(TopicDetails $topic): string
    {
        return $topic->shown() === 'not_started' ? 'not started' : $topic->shown();
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
            $kept[] = "(cut: {$cut} more ".($cut === 1 ? 'line' : 'lines').')';
        }

        return [implode("\n", $kept), $cut];
    }

    /** @param list<string> $texts */
    private static function join(array $texts): string
    {
        return implode("\n\n", array_values(array_filter($texts, fn (string $text) => $text !== '')));
    }
}
