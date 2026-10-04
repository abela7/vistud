<?php

namespace App\Engine;

use Closure;

/**
 * An engine for tests: answers from a script, in order, and remembers every request it was given. A step is a
 * Reply, or a closure given the Request that returns one (or throws EngineFailed).
 */
final class Fake implements Engine
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Reply|Closure> */
    private array $script = [];

    /** @var list<Model> */
    private array $models;

    /** @param list<Model> $models */
    public function __construct(array $models = [])
    {
        $this->models = $models ?: [
            new Model('fake/tutor', 'Fake tutor', 1.0, 5.0, 200_000, true, true, true),
            new Model('fake/quick', 'Fake quick', 0.1, 0.4, 32_000, true, false, false),
            new Model('fake/plain', 'Fake plain', 0.05, 0.2, 8_000, false, false, false),
        ];
    }

    /** Queues the next answers. */
    public function will(Reply|Closure ...$steps): self
    {
        array_push($this->script, ...$steps);

        return $this;
    }

    /** A plain text answer, with a small cost, for scripts. */
    public static function says(string $text, ?int $costMicros = 1_000, string $model = 'fake/tutor'): Reply
    {
        return new Reply($text, model: $model, tokensIn: 100, tokensOut: 50, costMicros: $costMicros);
    }

    /** An answer that asks for a tool. */
    public static function calls(string $tool, array $arguments = [], string $id = 'call_1'): Reply
    {
        return new Reply('', [new ToolCall($id, $tool, $arguments)], 'fake/tutor', 100, 20, 1_000, 'tool_calls');
    }

    public function reply(Request $request): Reply
    {
        $this->requests[] = $request;
        $step = array_shift($this->script) ?? self::says('(The fake engine has nothing more to say.)');

        return $step instanceof Closure ? $step($request) : $step;
    }

    /** The scripted reply, its words handed over a few at a time as a service would stream them. */
    public function stream(Request $request, Closure $onText): Reply
    {
        $reply = $this->reply($request);
        foreach (preg_split('/(?<=\s)/u', $reply->text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $words) {
            $onText($words);
        }

        return $reply;
    }

    public function models(?string $key = null): array
    {
        return $this->models;
    }

    public function last(): ?Request
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }
}
