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
            'id' => ['type' => 'string', 'description' => 'The memory\'s id from the memories tool.'],
            'number' => ['type' => 'integer', 'description' => 'The line\'s number in the memories list.'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $all = $this->memories->all($by);
        $id = isset($input['id']) && is_string($input['id']) ? trim($input['id']) : '';
        if ($id === '' && isset($input['number']) && is_string($input['number']) && ! ctype_digit(trim($input['number']))) {
            $id = trim($input['number']);
        }
        if ($id !== '') {
            $line = collect($all)->firstWhere('id', $id);
            if ($line === null) {
                return "There is no memory with id \"{$id}\".";
            }
        } else {
            if ($by->channel === 'mcp') {
                return 'Give the memory\'s id from the memories tool, not its list number.';
            }
            $line = $all[(int) ($input['number'] ?? 0) - 1] ?? null;
            if ($line === null) {
                return 'There is no line '.(int) ($input['number'] ?? 0).' in the memories list (1 to '.count($all).').';
            }
        }
        if (($ask = Agreement::needed($by, $context, "forget_memory:{$line->id}", "Forget \"{$line->text}\"?")) !== null) {
            return $ask;
        }
        $this->memories->forget($by, $line->id);

        return "Forgotten: \"{$line->text}\".";
    }
}
