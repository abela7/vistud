<?php

namespace App\Engine;

/**
 * The language model behind the built-in chat (docs/specs/study-memory.md §6): ViStud is the body, this is the
 * engine. One request in, one reply out; the engine keeps nothing between calls. App\Engine\OpenRouter speaks
 * to any service with the OpenAI chat format; App\Engine\Fake stands in for it in tests.
 */
interface Engine
{
    /** @throws EngineFailed when the service can't answer (no key, refused, down, out of credit) */
    public function reply(Request $request): Reply;

    /**
     * The models the service offers, with their prices and what they can take.
     *
     * @return list<Model>
     *
     * @throws EngineFailed
     */
    public function models(): array;
}
