<?php

namespace App\Engine;

/** One look-up the engine asks for: which tool, with what, and the id its result answers to. */
final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(public string $id, public string $name, public array $arguments) {}
}
