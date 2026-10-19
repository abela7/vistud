<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Prompt;

/**
 * What every ViStud prompt shares (docs/specs/mcp-connections-plan.md M6.4): a prompt is a ready-made first message
 * the student picks in the chat app ("/vistud:quiz_me"). It only tells the model which ViStud tools to use and in
 * what order; it reads and writes nothing itself, so it needs no data and cannot reach another student's. What the
 * student types into an argument is placed in the text as a quoted value, never as an instruction.
 */
abstract class StudyPrompt extends Prompt
{
    /** The steps, with `{name}` where a student's argument goes (an empty one leaves the step out). */
    abstract protected function steps(): array;

    public function handle(Request $request): Response|ResponseFactory
    {
        $lines = [];
        foreach ($this->steps() as $step) {
            $text = (string) $step;
            if (preg_match_all('/\{(\w+)\}/', $text, $found)) {
                foreach ($found[1] as $name) {
                    $value = self::clean($request->get($name));
                    if ($value === null) {
                        continue 2;
                    }
                    $text = str_replace('{'.$name.'}', '"'.$value.'"', $text);
                }
            }
            $lines[] = '- '.$text;
        }

        return Response::text(implode("\n", $lines));
    }

    /** One short line: no line breaks, no markup that could pass for a new instruction. */
    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\s"<>`]+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 120);
    }
}
