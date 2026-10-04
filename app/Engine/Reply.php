<?php

namespace App\Engine;

/**
 * What the engine answered: its words, the tools it asks ViStud to run (then it waits for their results), the
 * model that actually answered, and what it cost. The cost is in millionths of a dollar, or null when the
 * service didn't say.
 */
final readonly class Reply
{
    /** @param list<ToolCall> $toolCalls */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
        public string $model = '',
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public ?int $costMicros = null,
        /** stop · tool_calls · length · other */
        public string $finish = 'stop',
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
