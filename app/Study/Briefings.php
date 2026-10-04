<?php

namespace App\Study;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Builds a study session's briefing (docs/specs/study-memory.md §4.3): the
 * tutoring prompt with the session's teaching choices, then the student's
 * instructions, the session, where they stand (topics, what confuses them,
 * open questions, what they recorded), what's due, earlier sessions, and
 * the material chosen for it. The same briefing goes to any AI: pasted by
 * the student, through the MCP server, or from the built-in chat.
 *
 * It stays within BUDGET characters: the prompt, the instructions and the
 * session are always whole; the rest is filled in order of relevance, and
 * what doesn't fit is counted, not silently dropped. The student's name
 * and email never go into it.
 */
final class Briefings
{
    /** About 15,000 tokens: room for the material in the conversation. */
    public const BUDGET = 60_000;

    /** The most of one note that goes in, in characters. */
    public const NOTE_LIMIT = 8_000;

    public function __construct(
        private Sessions $sessions,
        private Workspaces $workspaces,
        private Modules $modules,
        private Topics $topics,
        private Questions $questions,
        private Findings $findings,
        private Instructions $instructions,
        private Activities $activities,
        private Notes $notes,
        private Files $files,
        private Links $links,
    ) {}

    public function forSession(Principal $by, string $sessionId): Briefing
    {
        $session = $this->sessions->find($by, $sessionId);
        $workspace = $this->workspaces->find($by, $session->workspaceId);
        $zone = $this->sessions->timezone($by);
        $date = fn (string $at, string $format = 'D j M Y, H:i') => CarbonImmutable::parse($at)->setTimezone($zone)->format($format);

        $topics = $this->topics->list($by, $workspace->id);
        $topicsById = [];
        foreach ($topics as $topic) {
            $topicsById[$topic->id] = $topic;
        }
        $topic = $session->topicId === null ? null : ($topicsById[$session->topicId] ?? null);
        $modules = $this->modules->list($by, $workspace->id);
        $moduleTitles = [];
        foreach ($modules as $module) {
            $moduleTitles[$module->id] = $module->title;
        }
        $moduleId = $session->moduleId ?? $topic?->moduleId;
        $moduleId = $moduleId !== null && isset($moduleTitles[$moduleId]) ? $moduleId : null;
        $instructions = $this->instructions->forSession($by, $workspace->id, $moduleId);
        $findings = $this->findings->byTopic($by, $workspace->id);
        $status = fn (TopicDetails $t) => $t->shown() === 'not_started' ? 'not started' : $t->shown();

        $sections = [];
        $add = function (int $order, int $priority, ?string $heading, array $lines, ?string $intro = null, bool $whole = false, bool $cut = false) use (&$sections) {
            $sections[] = compact('order', 'priority', 'heading', 'lines', 'intro', 'whole', 'cut');
        };

        // Always whole: the prompt, the instructions, the session.
        $add(0, 0, null, [Tutoring::prompt($session->tutoring), "---\n\n# Briefing"], whole: true);
        $add(10, 0, 'About the student', [$instructions['me'] !== '' ? $instructions['me'] : 'Nothing written yet.'], whole: true);
        $course = implode(' · ', array_filter([$workspace->code ? "Course code {$workspace->code}" : null, $workspace->term ? "Term: {$workspace->term}" : null]));
        $add(20, 0, "The course: {$workspace->name}", array_values(array_filter([
            $course !== '' ? $course : null,
            $instructions['workspace'] !== '' ? $instructions['workspace'] : 'No instructions for this course yet.',
        ])), whole: true);
        if ($moduleId !== null) {
            $add(30, 0, "The module: {$moduleTitles[$moduleId]}", [$instructions['module'] !== '' ? $instructions['module'] : 'No instructions for this module.'], whole: true);
        }
        $add(40, 0, 'This session', $this->sessionLines($session, $topic, $status, $date), whole: true);

        // The session's topic first.
        if ($topic !== null) {
            $add(50, 1, "What the student has recorded about {$topic->name}", array_map(fn (FindingDetails $f) => '- '.$this->finding($f), $findings[$topic->id] ?? []));
        }
        $confused = array_values(array_filter($topics, fn (TopicDetails $t) => $t->shown() === 'confused'));
        $add(60, 2, 'Still confusing', array_map(fn (TopicDetails $t) => '- '.$t->name.($t->moduleId !== null && isset($moduleTitles[$t->moduleId]) ? " ({$moduleTitles[$t->moduleId]})" : ''), $confused));
        // Stuck first, then the ones about this session's topic or module.
        $open = array_values(array_filter($this->questions->list($by, $workspace->id), fn (QuestionDetails $q) => $q->status !== 'answered'));
        $here = fn (QuestionDetails $q) => ($session->topicId !== null && $q->topicId === $session->topicId) || ($moduleId !== null && $q->moduleId === $moduleId);
        usort($open, fn ($a, $b) => [$b->status === 'stuck', $here($b)] <=> [$a->status === 'stuck', $here($a)]);
        $add(70, 2, 'Open questions', array_map(fn (QuestionDetails $q) => '- '.$q->text.$this->bracket([
            $q->status === 'stuck' ? 'stuck: tried and still unclear' : null,
            $q->topicId !== null ? ($topicsById[$q->topicId]->name ?? null) : null,
            $q->topicId === null && $q->moduleId !== null ? ($moduleTitles[$q->moduleId] ?? null) : null,
            $q->askTeacher ? 'for the teacher' : null,
        ]), $open));

        $today = CarbonImmutable::now($zone)->startOfDay();
        // The student's date, at midnight like the due dates, so days count right in any time zone.
        $studentsToday = Carbon::parse($today->toDateString(), 'UTC');
        $due = array_values(array_filter($this->activities->list($by, $workspace->id), fn (ActivityDetails $a) => $a->status !== 'done' && $a->dueOn !== null && $a->dueOn <= $today->addDays(14)->toDateString()));
        $add(80, 3, 'Due soon', array_map(fn (ActivityDetails $a) => '- '.$a->title.$this->bracket([
            strtolower($a->kindLabel()),
            $a->moduleId !== null ? ($moduleTitles[$a->moduleId] ?? null) : null,
        ]).': '.lcfirst((string) $a->dueWords($studentsToday)).($a->status === 'doing' ? ', in progress' : ''), array_slice($due, 0, 10)));

        $earlier = array_values(array_filter($this->sessions->list($by, $workspace->id, 10), fn (SessionDetails $s) => $s->id !== $session->id && ! $s->isOpen()));
        $add(90, 3, 'Earlier sessions', array_map(fn (SessionDetails $s) => '- '.$date($s->startedAt, 'D j M').': '.implode(', ', array_filter([
            $s->topicId !== null ? ($topicsById[$s->topicId]->name ?? 'a removed topic') : 'no particular topic',
            SessionDetails::duration($s->studySeconds),
            $s->pomodoros > 0 ? $s->pomodoros.' '.($s->pomodoros === 1 ? 'pomodoro' : 'pomodoros') : null,
        ])).'.'.($s->summary !== null ? ' The tutor\'s summary: '.$s->summary : ($s->checkpoint !== null ? ' It stopped at: '.$s->checkpoint : '')), array_slice($earlier, 0, 5)));

        [$materialLines, $noteBlocks] = $this->material($by, $session, $moduleId, $moduleTitles);
        $add(100, 3, $session->material === [] ? 'Material in ViStud' : 'Material for this session', $materialLines);

        $add(110, 4, 'All topics in this course', $this->topicLines($topics, $modules, $status), intro: 'The status is the student\'s own word; the evidence is what their practice shows.');
        $others = [];
        foreach ($topics as $t) {
            if ($t->id !== $session->topicId) {
                foreach ($findings[$t->id] ?? [] as $finding) {
                    $others[] = "- {$t->name}: ".$this->finding($finding);
                }
            }
        }
        $add(120, 5, 'Other things the student has recorded', $others);
        $add(130, 6, null, $noteBlocks, cut: true);

        [$markdown, $trimmed] = $this->fit($sections);

        return new Briefing($markdown, implode(' · ', array_filter([$topic?->name, $workspace->name])), $trimmed);
    }

    /** @return list<string> */
    private function sessionLines(SessionDetails $session, ?TopicDetails $topic, callable $status, callable $date): array
    {
        $lines = ['- Started: '.$date($session->startedAt).' (the student\'s time).'];
        $lines[] = $topic !== null
            ? "- Topic: {$topic->name}. The student says: {$status($topic)}. Evidence: {$topic->evidence()}."
            : '- Topic: none chosen. Propose one (from what is still confusing, open or due soon below, or the module\'s material) and ask whether to take it.';
        if ($session->pomodoro !== null) {
            $p = $session->pomodoro;
            $lines[] = "- Clock: Pomodoro, {$p['focus']}-minute focus periods with {$p['short']}-minute breaks ({$p['long']} minutes after every {$p['every']}). Plan each part to fit in a focus period, and when the student says they're on a break, stop until they're back.";
        } else {
            $lines[] = '- Clock: free. The student pauses and takes breaks when they like.';
        }
        $lines[] = '- Teaching: '.Tutoring::summary($session->tutoring).'.';
        if ($session->checkpoint !== null) {
            $lines[] = '- Where this session last stood (your last checkpoint): '.$session->checkpoint.' Pick up from here.';
        }
        if ($session->summary !== null) {
            $lines[] = '- Summary so far: '.$session->summary;
        }
        if ($session->studySeconds >= 60) {
            $lines[] = ($session->isOpen() ? '- Studied so far in this session: ' : '- Studied in this session: ').SessionDetails::duration($session->studySeconds).'.';
        }

        return $lines;
    }

    /**
     * The chosen material (or, with nothing chosen, what the module holds),
     * and the chosen notes' text.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function material(Principal $by, SessionDetails $session, ?string $moduleId, array $moduleTitles): array
    {
        $notes = $this->notes->list($by, $session->workspaceId);
        $files = $this->files->list($by, $session->workspaceId);
        $lines = [];
        $blocks = [];

        if ($session->material === []) {
            if ($moduleId === null) {
                return [['Nothing chosen for this session. The student can share material with you directly.'], []];
            }
            $lines[] = "Nothing chosen for this session. In {$moduleTitles[$moduleId]}, the student has these, and can share any of them with you:";
            foreach ($notes as $note) {
                if ($note->moduleId === $moduleId) {
                    $lines[] = "- Note: {$note->displayTitle()}";
                }
            }
            foreach ($files as $file) {
                if ($file->moduleId === $moduleId) {
                    $lines[] = "- {$file->typeLabel()}: {$file->fileName()}";
                }
            }
            foreach ($this->links->list($by, $session->workspaceId) as $link) {
                if ($link->moduleId === $moduleId) {
                    $lines[] = "- Link: {$link->title}, {$link->url}";
                }
            }

            return [count($lines) === 1 ? ["{$moduleTitles[$moduleId]} has no notes, files or links yet. The student can share material with you directly."] : $lines, []];
        }

        foreach ($files as $file) {
            if ($session->uses("file:{$file->id}")) {
                $lines[] = "- {$file->typeLabel()}: {$file->fileName()}. The student will share it with you, a part at a time.";
            }
        }
        foreach ($notes as $note) {
            if ($session->uses("note:{$note->id}")) {
                $lines[] = "- The student's note \"{$note->displayTitle()}\": its text follows.";
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
        }

        return [$lines, $blocks];
    }

    /** @return list<string> */
    private function topicLines(array $topics, array $modules, callable $status): array
    {
        $lines = [];
        $groups = [...array_map(fn (ModuleDetails $m) => [$m->id, $m->title], $modules), [null, 'Not in a module']];
        foreach ($groups as [$moduleId, $title]) {
            $inGroup = array_values(array_filter($topics, fn (TopicDetails $t) => $t->moduleId === $moduleId));
            if ($inGroup === []) {
                continue;
            }
            $lines[] = "### {$title}";
            foreach ($inGroup as $t) {
                $lines[] = "- {$t->name}: {$status($t)} (evidence: {$t->evidence()})";
            }
        }

        return $lines;
    }

    private function finding(FindingDetails $finding): string
    {
        return $finding->text.$this->bracket([
            $finding->sourceName !== null ? 'from '.$finding->sourceName.($finding->locator ? ", {$finding->locator}" : '') : null,
            $finding->author === 'ai' ? 'from a study session' : null,
        ]);
    }

    /** " (a; b)", or nothing when all parts are empty. */
    private function bracket(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn ($part) => $part !== null && $part !== ''));

        return $parts === [] ? '' : ' ('.implode('; ', $parts).')';
    }

    /**
     * The sections within the budget, in their order: whole ones first,
     * then the rest by priority, as many lines as fit, with what's left
     * out counted.
     *
     * @return array{0: string, 1: bool}
     */
    private function fit(array $sections): array
    {
        $size = fn (?string $text) => $text === null ? 0 : mb_strlen($text) + 2;
        $used = 0;
        $kept = [];
        $trimmed = false;

        foreach ($sections as $i => $section) {
            if ($section['whole']) {
                $kept[$i] = $section['lines'];
                $used += $size($section['heading']) + $size($section['intro']) + array_sum(array_map($size, $section['lines']));
            }
        }
        $rest = array_filter($sections, fn ($s) => ! $s['whole'] && $s['lines'] !== []);
        uasort($rest, fn ($a, $b) => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);

        foreach ($rest as $i => $section) {
            $base = $size($section['heading'] === null ? null : '## '.$section['heading']) + $size($section['intro']);
            $lines = [];
            $spent = $base;
            foreach ($section['lines'] as $line) {
                $room = self::BUDGET - $used - $spent - 80;
                if ($size($line) > $room) {
                    // A long block (a note) is cut to the room left, when there's enough for it to be useful.
                    if ($section['cut'] && $room > 500) {
                        $line = rtrim(mb_substr($line, 0, $room - 60))."\n\n(The rest is left out to keep this briefing short.)";
                        $lines[] = $line;
                        $spent += $size($line);
                    }
                    break;
                }
                $lines[] = $line;
                $spent += $size($line);
            }
            $left = count($section['lines']) - count($lines);
            if ($left > 0) {
                $trimmed = true;
                if ($lines !== [] && ! $section['cut']) {
                    $lines[] = "- … and {$left} more, left out to keep this briefing short.";
                }
            }
            if ($lines !== []) {
                $kept[$i] = $lines;
                $used += $spent;
            }
        }

        ksort($kept);
        $orderOf = array_map(fn ($s) => $s['order'], $sections);
        uksort($kept, fn ($a, $b) => $orderOf[$a] <=> $orderOf[$b]);

        $parts = [];
        foreach ($kept as $i => $lines) {
            $section = $sections[$i];
            $block = $section['heading'] === null ? '' : '## '.$section['heading']."\n\n";
            $block .= $section['intro'] === null ? '' : $section['intro']."\n\n";
            // List items and subheadings of a list stay together; paragraphs get a blank line between them.
            $text = '';
            foreach ($lines as $n => $line) {
                $listy = fn (string $l) => str_starts_with($l, '- ') || str_starts_with($l, '### ');
                $text .= $n === 0 ? $line : (($listy($line) && $listy($lines[$n - 1]) && ! $section['cut']) ? "\n" : "\n\n").$line;
            }
            $parts[] = $block.$text;
        }

        return [trim(implode("\n\n", $parts))."\n", $trimmed];
    }
}
