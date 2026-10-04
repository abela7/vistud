<?php

namespace App\Engine;

/**
 * One call to the engine: the model (and the ones to try if it fails), the system prompt, the conversation so
 * far and the tools it may call, in the OpenAI chat shape (`{role, content}` messages; `{type: function, …}` tools).
 */
final readonly class Request
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  list<string>  $fallbacks
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public array $tools = [],
        public array $fallbacks = [],
        public int $maxTokens = 4000,
        public bool $noTraining = true,
        /** The student's own key for the service; null means the one set up for everyone. Never logged. */
        public ?string $key = null,
    ) {}
}
