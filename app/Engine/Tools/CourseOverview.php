<?php

namespace App\Engine\Tools;

use App\Engine\Context\Stack;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
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
        private Folders $folders,
    ) {}

    public function name(): string
    {
        return 'course_overview';
    }

    public function description(): string
    {
        return 'The course at a glance: every module with its dates, its topics by where they stand (not started, covered, understood, confusing, mastered) in all and in each folder, the topics in no folder, and how many notes and files it holds; plus open questions, what is due in the next 14 days, and flashcards due. Start here when the student asks where they stand.';
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
        $tree = $this->folders->tree($by, $w);
        $folderNames = [];
        foreach ($tree as $f) {
            $folderNames[$f->id] = $f->name;
        }
        // Each folder's topics count under the folder at the top of its branch, as the student's Progress page shows them.
        $parentOf = [];
        foreach ($tree as $f) {
            $parentOf[$f->id] = $f->parentId;
        }
        $topOf = function (?string $id) use ($parentOf): ?string {
            while ($id !== null && ($parentOf[$id] ?? null) !== null) {
                $id = $parentOf[$id];
            }

            return $id;
        };
        // "10 topics: 0 understood, 10 not started", the words the tutor's other tools use.
        $count = function (array $list): array {
            $by = [];
            foreach ($list as $topic) {
                $word = Stack::word($topic->shown());
                $by[$word] = ($by[$word] ?? 0) + 1;
            }

            return ['total' => count($list), ...$by];
        };
        $modules = [];
        foreach ($this->modules->list($by, $w) as $i => $module) {
            $mine = array_values(array_filter($topics, fn ($topic) => $topic->moduleId === $module->id));
            $folders = [];
            foreach ($tree as $folder) {
                if ($folder->moduleId === $module->id && $folder->parentId === null) {
                    $in = array_filter($mine, fn ($topic) => $topic->folderId !== null && $topOf($topic->folderId) === $folder->id);
                    $folders[] = ['folder' => $folder->name, 'topics' => $count($in)];
                }
            }
            $own = array_filter($mine, fn ($topic) => $topic->folderId === null);
            $modules[] = array_filter([
                'number' => $i + 1,
                'title' => $module->title,
                'runs' => $module->startsOn || $module->endsOn ? trim(($module->startsOn ?? '').' to '.($module->endsOn ?? ''), ' to') : null,
                'current' => $context->moduleId === $module->id ? true : null,
                'topics' => $mine !== [] ? $count($mine) : null,
                'folders' => $folders ?: null,
                'topics_in_no_folder' => $folders !== [] && $own !== [] ? array_values(array_map(fn ($topic) => $topic->name, $own)) : null,
                'notes' => count(array_filter($notes, fn ($n) => $n->moduleId === $module->id)) ?: null,
                'files' => count(array_filter($files, fn ($f) => $f->moduleId === $module->id)) ?: null,
            ], fn ($v) => $v !== null);
        }
        $today = CarbonImmutable::now($context->zone)->startOfDay();
        $open = array_filter($this->questions->list($by, $w), fn ($q) => $q->status !== 'answered');
        $due = array_filter($this->activities->list($by, $w), fn ($a) => $a->status !== 'done' && $a->dueOn !== null && $a->dueOn <= $today->addDays(14)->toDateString());

        return Lookup::json(array_filter([
            'today' => $today->toDateString(),
            'current_folder' => $context->folderId !== null ? ($folderNames[$context->folderId] ?? null) : null,
            'modules' => $modules,
            'topics_in_no_module' => count(array_filter($topics, fn ($t) => $t->moduleId === null)) ?: null,
            'open_questions' => count($open),
            'stuck_questions' => count(array_filter($open, fn ($q) => $q->status === 'stuck')),
            'due_in_14_days' => count($due),
            'flashcards_due' => $this->flashcards->counts($by, $w)['due'],
        ], fn ($v) => $v !== null));
    }
}
