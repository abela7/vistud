<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/** The course at a glance: its modules with their dates, topics by status and what each holds, and the counts that matter now. */
final class CourseOverview implements Tool
{
    public function __construct(
        private Modules $modules,
        private Topics $topics,
        private Questions $questions,
        private Activities $activities,
        private Notes $notes,
        private Files $files,
        private Flashcards $flashcards,
    ) {}

    public function name(): string
    {
        return 'course_overview';
    }

    public function description(): string
    {
        return 'The course at a glance: every module with its dates, how many of its topics are understood, confused or not started, and how many notes and files it holds; plus open questions, what is due in the next 14 days, and flashcards due. Start here when the student asks where they stand.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $w = $context->workspaceId;
        $topics = $this->topics->list($by, $w);
        $notes = $this->notes->list($by, $w);
        $files = $this->files->list($by, $w);
        $modules = [];
        foreach ($this->modules->list($by, $w) as $i => $module) {
            $statuses = [];
            foreach ($topics as $topic) {
                if ($topic->moduleId === $module->id) {
                    $statuses[$topic->shown()] = ($statuses[$topic->shown()] ?? 0) + 1;
                }
            }
            $modules[] = array_filter([
                'number' => $i + 1,
                'title' => $module->title,
                'runs' => $module->startsOn || $module->endsOn ? trim(($module->startsOn ?? '').' to '.($module->endsOn ?? ''), ' to') : null,
                'current' => $context->moduleId === $module->id ? true : null,
                'topics' => $statuses ?: null,
                'notes' => count(array_filter($notes, fn ($n) => $n->moduleId === $module->id)) ?: null,
                'files' => count(array_filter($files, fn ($f) => $f->moduleId === $module->id)) ?: null,
            ], fn ($v) => $v !== null);
        }
        $today = CarbonImmutable::now($context->zone)->startOfDay();
        $open = array_filter($this->questions->list($by, $w), fn ($q) => $q->status !== 'answered');
        $due = array_filter($this->activities->list($by, $w), fn ($a) => $a->status !== 'done' && $a->dueOn !== null && $a->dueOn <= $today->addDays(14)->toDateString());

        return Lookup::json([
            'today' => $today->toDateString(),
            'modules' => $modules,
            'topics_in_no_module' => count(array_filter($topics, fn ($t) => $t->moduleId === null)) ?: null,
            'open_questions' => count($open),
            'stuck_questions' => count(array_filter($open, fn ($q) => $q->status === 'stuck')),
            'due_in_14_days' => count($due),
            'flashcards_due' => $this->flashcards->counts($by, $w)['due'],
        ]);
    }
}
