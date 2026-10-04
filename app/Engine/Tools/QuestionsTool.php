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
        return 'The questions the student wrote down while studying: by default the open ones (stuck first, then pending), or all of them with their answers. Use it to pick up what they still don\'t get.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['which' => ['type' => 'string', 'enum' => ['open', 'all'], 'description' => 'open (the default) or all']], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $all = ($input['which'] ?? 'open') === 'all';
        $topics = collect($this->topics->list($by, $context->workspaceId))->pluck('name', 'id');
        $modules = collect($this->modules->list($by, $context->workspaceId))->pluck('title', 'id');
        $list = array_values(array_filter($this->questions->list($by, $context->workspaceId), fn ($q) => $all || $q->status !== 'answered'));
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

        return $rows === [] ? ($all ? 'No questions written down yet.' : 'No open questions.') : Lookup::json($rows);
    }
}
