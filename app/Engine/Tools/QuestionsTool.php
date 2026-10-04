<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Topics;
use Carbon\CarbonImmutable;

/** The questions the student wrote down: the open ones (stuck first), or all of them with their answers. */
final class QuestionsTool implements Tool
{
    public function __construct(private Questions $questions, private Topics $topics, private Modules $modules) {}

    public function name(): string
    {
        return 'questions';
    }

    public function description(): string
    {
        return 'The questions the student wrote down while studying: by default the open ones (stuck first, then pending), or all of them with their answers; narrowed to one topic or one module when asked. Use it to pick up what they still don\'t get, before explaining a note or a topic.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'which' => ['type' => 'string', 'enum' => ['open', 'all'], 'description' => 'open (the default) or all'],
            'topic' => ['type' => 'string', 'description' => 'A topic\'s name (or part of it) to limit to.'],
            'module' => ['type' => 'string', 'description' => 'A module\'s title (or part of it) to limit to.'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $all = ($input['which'] ?? 'open') === 'all';
        $topicList = $this->topics->list($by, $context->workspaceId);
        $moduleList = $this->modules->list($by, $context->workspaceId);
        $topics = collect($topicList)->pluck('name', 'id');
        $modules = collect($moduleList)->pluck('title', 'id');
        $onlyTopic = null;
        if (($wanted = Lookup::text($input, 'topic')) !== null) {
            $topic = Lookup::one($topicList, $wanted, fn ($t) => $t->name, 'topic');
            if (is_string($topic)) {
                return $topic;
            }
            $onlyTopic = $topic->id;
        }
        $onlyModule = null;
        if (($wanted = Lookup::text($input, 'module')) !== null) {
            $module = Lookup::one($moduleList, $wanted, fn ($m) => $m->title, 'module');
            if (is_string($module)) {
                return $module;
            }
            $onlyModule = $module->id;
        }
        $list = array_values(array_filter($this->questions->list($by, $context->workspaceId), fn ($q) => ($all || $q->status !== 'answered')
            && ($onlyTopic === null || $q->topicId === $onlyTopic)
            && ($onlyModule === null || $q->moduleId === $onlyModule || ($q->topicId !== null && ($topicList[array_search($q->topicId, array_column($topicList, 'id'), true)]->moduleId ?? null) === $onlyModule))));
        usort($list, fn ($a, $b) => [$b->status === 'stuck', $a->status === 'answered', $b->askedAt] <=> [$a->status === 'stuck', $b->status === 'answered', $a->askedAt]);
        $rows = [];
        foreach (array_slice($list, 0, 40) as $q) {
            $rows[] = array_filter([
                'question' => $q->text,
                'status' => $q->status,
                'topic' => $q->topicId !== null ? $topics[$q->topicId] ?? null : null,
                'module' => $q->moduleId !== null ? $modules[$q->moduleId] ?? null : null,
                'answer' => $q->answer,
                'for_the_teacher' => $q->askTeacher ?: null,
                'asked' => CarbonImmutable::parse($q->askedAt)->setTimezone($context->zone)->toDateString(),
            ]);
        }

        $where = $onlyTopic !== null || $onlyModule !== null ? ' there' : '';

        return $rows === [] ? ($all ? "No questions written down{$where} yet." : "No open questions{$where}.") : Lookup::json($rows);
    }
}
