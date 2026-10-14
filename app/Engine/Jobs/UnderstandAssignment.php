<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;
use App\Engine\Role;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentSource;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\CourseProfiles;
use App\Study\FileDetails;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;

/**
 * The tutor's model understands an assignment (the owner's ask, 2026-10-13): everything the student gave about it (its
 * written sources and the files in its folder, each with a label it must cite), the course and its modules and topics
 * (to link the assignment to them), and its earlier understanding (to update rather than start again) in; a new
 * revision of its understanding out (App\Study\AssignmentUnderstandings). The tutor's model, not the reader's, unless
 * the student chose another for this assignment (ActivityDetails::$aiModel): this is what every later step of the
 * assignment stands on. Everything is read when the job runs, from where the student left it.
 */
final class UnderstandAssignment extends Job
{
    public const PROMPT = 'resources/prompts/assignment-understanding.md';

    /** The most of the sources and files one reading carries, in characters (about 40,000 tokens). */
    public const BUDGET = 160_000;

    /** The most of one file it carries. */
    public const FILE_LIMIT = 50_000;

    /**
     * The longest answer, in tokens: a full understanding of a big assignment. It was 6,000, and a real one (about
     * 15,000 tokens of sources) filled it and was cut off, unreadable. A model that thinks first spends some of this too.
     */
    public const ANSWER_TOKENS = 16_000;

    public function __construct(string $workspaceId, string $activityId)
    {
        parent::__construct($workspaceId, 'activity', $activityId);
    }

    public function kind(): string
    {
        return 'understand_assignment';
    }

    public function role(): Role
    {
        return Role::Tutor;
    }

    /** The model the student chose to read this assignment; none (their tutor's model) when they haven't, or it is gone. */
    public function model(Principal $by): ?string
    {
        try {
            return app(Activities::class)->find($by, (string) $this->targetId)->aiModel;
        } catch (NotFound) {
            return null;
        }
    }

    public function handle(Principal $by, Run $run): void
    {
        $activityId = (string) $this->targetId;
        $sources = app(AssignmentSources::class);
        $earlier = app(AssignmentUnderstandings::class)->latest($by, $activityId);
        // The labels the earlier understanding cites stay theirs, so updating it never mixes two sources up.
        $labelled = $sources->labelled($by, $activityId, $earlier);
        if ($labelled === []) {
            $run->skip('no_sources');
        }
        // Taken before reading: a source added while the model works shows the result as out of date.
        $fingerprint = $sources->fingerprint($by, $activityId);
        [$material, $labels] = $this->material($by, $labelled);
        [$courseText, $modules, $topics] = $this->course($by);
        $assignment = app(Activities::class)->find($by, $activityId);

        $message = implode("\n\n", array_filter([
            'Today is '.CarbonImmutable::now($assignment->zone)->format('l j F Y').'.',
            "## The assignment\n".$this->assignment($by, $assignment),
            $courseText,
            "## Its sources\n".$material,
            $earlier === null ? null : "## Your earlier understanding (revision {$earlier->revision}): update it\n".json_encode($earlier->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]));
        $reply = $run->ask(self::rules(), $message, self::ANSWER_TOKENS)->whole();
        $data = JsonAnswer::object($reply->text);
        $body = AssignmentUnderstandings::clean($data, array_keys($labels), $modules, $topics);
        if (! AssignmentUnderstandings::saysSomething($body)) {
            throw new EngineFailed('engine_unreadable', 'The AI found nothing to say about it. Check its sources, or try again.');
        }

        app(AssignmentUnderstandings::class)->keep($by, $activityId, $body, is_string($data['changes'] ?? null) ? $data['changes'] : null, $labels, $fingerprint, $reply->model);
    }

    /** The rules, without the file's opening comment (for people). */
    public static function rules(): string
    {
        return trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
    }

    /**
     * The sources as the model reads them, each with its label, within the budget; and what each label is.
     *
     * @param  array<string, AssignmentSource|FileDetails>  $labelled  as AssignmentSources::labelled() gives them
     * @return array{0: string, 1: array<string, array{type: string, id: string, title: string, unread?: bool}>}
     */
    private function material(Principal $by, array $labelled): array
    {
        $left = self::BUDGET;
        $blocks = [];
        $labels = [];
        $take = function (string $text, int $limit) use (&$left): string {
            $room = max(0, min($limit, $left));
            $cut = mb_strlen($text) > $room;
            $text = $cut ? rtrim(mb_substr($text, 0, $room))."\n(The rest is left out: there was no more room.)" : $text;
            $left -= mb_strlen($text);

            return $text;
        };

        $written = array_filter($labelled, fn ($item) => $item instanceof AssignmentSource);
        $files = array_filter($labelled, fn ($item) => $item instanceof FileDetails);

        foreach ($written as $label => $source) {
            $labels[$label] = ['type' => 'source', 'id' => $source->id, 'title' => $source->name()];
            $head = "### {$label} · {$source->kindLabel()}".($source->title !== null ? " · “{$source->title}”" : '').($source->receivedOn !== null ? " · received {$source->receivedOn}" : '');
            $blocks[] = $head."\n<source>\n".$take($source->text, AssignmentSources::MAX_TEXT)."\n</source>";
        }

        $texts = app(FileTexts::class);
        foreach ($files as $label => $file) {
            $labels[$label] = ['type' => 'file', 'id' => $file->id, 'title' => $file->fileName()];
            try {
                $read = $texts->of($by, $file->id);
            } catch (NotFound) {
                continue;
            }
            if ($read->state === FileText::PREPARING) {
                // Read nothing of it: once it is converted, the understanding is out of date (AssignmentSources::outOfDate).
                $labels[$label]['unread'] = true;
            }
            $body = match (true) {
                $read->state === FileText::PREPARING => '(Still being converted: not read yet. Say so in gaps.)',
                $read->state === FileText::PICTURE => '(A picture: it can\'t be read here. Say so in gaps if it may matter.)',
                ! $read->hasWords() => '(No words could be read from it. Say so in gaps if it may matter.)',
                default => "<source>\n".$take(trim(implode("\n\n", array_map('trim', $read->pages))), self::FILE_LIMIT)."\n</source>",
            };
            $blocks[] = "### {$label} · ".$read->profile()."\n".$body;
        }

        return [implode("\n\n", $blocks), $labels];
    }

    /** The assignment's own details: what it is, its deadline, its module, how far it is. */
    private function assignment(Principal $by, ActivityDetails $assignment): string
    {
        $module = null;
        if ($assignment->moduleId !== null) {
            try {
                $module = app(Modules::class)->find($by, $assignment->moduleId)->title;
            } catch (NotFound) {
                // Deleted since.
            }
        }
        $due = $assignment->dueOn === null ? 'none given' : $assignment->dueAt()->format('l j F Y').($assignment->dueTime !== null ? " at {$assignment->dueTime}" : '')." ({$assignment->zone})";

        return implode("\n", array_filter([
            "Title: {$assignment->title}",
            'Kind: '.strtolower($assignment->kindLabel()),
            "Deadline in ViStud: {$due}",
            $module !== null ? "Module: {$module}" : null,
            'Where the student is: '.(['todo' => 'not started', 'doing' => 'working on it', 'done' => 'done'][$assignment->status] ?? $assignment->status),
        ]));
    }

    /**
     * The course as the model links to it: what it is and how it is assessed, its modules in order with their topics.
     *
     * @return array{0: string, 1: list<array{id: string, title: string}>, 2: list<array{id: string, name: string}>}
     */
    private function course(Principal $by): array
    {
        $workspace = app(Workspaces::class)->find($by, $this->workspaceId);
        $profile = app(CourseProfiles::class)->get($by, $this->workspaceId);
        $topicList = app(Topics::class)->list($by, $this->workspaceId);
        $moduleList = app(Modules::class)->list($by, $this->workspaceId);

        $lines = ["## The course\n{$workspace->name}".($workspace->subtitle() !== '' ? " ({$workspace->subtitle()})" : '')];
        if ($profile->about !== '') {
            $lines[] = "About it: {$profile->about}";
        }
        if ($profile->outcomes !== []) {
            $lines[] = "What it should teach:\n- ".implode("\n- ", $profile->outcomes);
        }
        if ($profile->assessment !== []) {
            $lines[] = "How it is assessed:\n- ".implode("\n- ", array_map(fn (array $item) => $item['name'].(($item['weight'] ?? null) !== null ? " ({$item['weight']} %)" : '').(($item['due_on'] ?? null) !== null ? ", due {$item['due_on']}" : ''), $profile->assessment));
        }
        $byModule = [];
        foreach ($topicList as $topic) {
            $byModule[$topic->moduleId ?? ''][] = $topic->name;
        }
        $modules = [];
        $outline = [];
        foreach (array_slice($moduleList, 0, 40) as $module) {
            $modules[] = ['id' => $module->id, 'title' => $module->title];
            $names = array_slice($byModule[$module->id] ?? [], 0, 25);
            $outline[] = "- {$module->title}".($names !== [] ? ': '.implode('; ', $names) : '');
        }
        if (($byModule[''] ?? []) !== []) {
            $outline[] = '- (in no module): '.implode('; ', array_slice($byModule[''], 0, 25));
        }
        if ($outline !== []) {
            $lines[] = "Its modules, in order, with their topics (link only to these, by these names):\n".implode("\n", $outline);
        }
        $topics = array_map(fn ($topic) => ['id' => $topic->id, 'name' => $topic->name], $topicList);

        return [implode("\n\n", $lines), $modules, $topics];
    }
}
