<?php

namespace App\Engine;

/** One look-up the engine asks for: which tool, with what, and the id its result answers to. */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $extra  what the service sent with the call to have back unchanged (Gemini's
     *                                       `extra_content.google.thought_signature`: without it, it refuses the result)
     */
    public function __construct(public string $id, public string $name, public array $arguments, public array $extra = []) {}

    /** The call as the conversation sends it back, in the OpenAI shape; its arguments always an object, even with none. */
    public function wire(): array
    {
        return [
            'id' => $this->id, 'type' => 'function',
            'function' => ['name' => $this->name, 'arguments' => (string) json_encode($this->arguments === [] ? new \stdClass : $this->arguments)],
            ...($this->extra !== [] ? ['extra_content' => $this->extra] : []),
        ];
    }
}
