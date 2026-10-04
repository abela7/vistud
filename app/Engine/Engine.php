<?php

namespace App\Engine;

use Closure;

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
     * The same reply, with its words handed to $onText chunk by chunk as the service writes them (a reply that
     * only asks for tools may send none).
     *
     * @param  Closure(string): void  $onText
     *
     * @throws EngineFailed
     */
    public function stream(Request $request, Closure $onText): Reply;

    /**
     * The models the service offers, with their prices and what they can take; asked with the given key, or
     * the one set up for everyone.
     *
     * @return list<Model>
     *
     * @throws EngineFailed
     */
    public function models(?string $key = null): array;
}
