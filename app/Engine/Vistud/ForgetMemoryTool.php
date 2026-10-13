<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Agreement;
use App\Engine\Tools\Context;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;
use App\Study\TutorMemories;

/** ViStud AI forgets a line about the student, by its number in the memories list, once they have agreed to it. */
final class ForgetMemoryTool implements Tool
{
    public function __construct(private TutorMemories $memories) {}

    public function name(): string
    {
        return 'forget_memory';
    }

    public function description(): string
    {
        return 'Forgets a line remembered about the student, by its number in the memories list, when they ask. It asks them first.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'number' => ['type' => 'integer', 'description' => 'The line\'s number in the memories list.'],
        ], 'required' => ['number'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $all = $this->memories->all($by);
        $line = $all[(int) ($input['number'] ?? 0) - 1] ?? null;
        if ($line === null) {
            return 'There is no line '.(int) ($input['number'] ?? 0).' in the memories list (1 to '.count($all).').';
        }
        if (($ask = Agreement::needed($by, $context, "forget_memory:{$line->id}", "Forget \"{$line->text}\"?")) !== null) {
            return $ask;
        }
        $this->memories->forget($by, $line->id);

        return "Forgotten: \"{$line->text}\".";
    }
}
