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
        /**
         * The model's reasoning as the service hands it back (`reasoning_details`), to send back unchanged with
         * this message: some models refuse a look-up's result without it.
         *
         * @var list<array<string, mixed>>
         */
        public array $reasoning = [],
        /** Of `tokensIn`, how many the service read from its prompt cache (billed at about a tenth). */
        public int $tokensCached = 0,
    ) {}

    /**
     * This reply, once it is known to be whole. An answer that stopped at the length limit is cut off mid-way (a JSON
     * object without its end, a plan without its last part): reading what is left would keep half an answer as if it
     * were all of it, so it is a failure with its own reason instead.
     *
     * @throws EngineFailed when the answer was cut off
     */
    public function whole(): self
    {
        if ($this->finish === 'length') {
            throw new EngineFailed('engine_cut_off', 'The AI ran out of room before it finished its answer. Try again, or choose a model that can write more.');
        }

        return $this;
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
