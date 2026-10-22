<?php

namespace App\Engine;

use Closure;

/**
 * Every call made with the shared key for students runs one of the free or very cheap models the admin chose, the
 * default when it names another (the owner's ask, 2026-10-22). The screens already offer only those; this holds at
 * the service's door, so a chat that names another model, or one picked before, still can't run on the shared key.
 */
final class SharedKeyPin implements Engine
{
    public function __construct(private Engine $inner, private Setup $setup) {}

    public function reply(Request $request): Reply
    {
        return $this->inner->reply($this->pin($request));
    }

    public function stream(Request $request, Closure $onText): Reply
    {
        return $this->inner->stream($this->pin($request), $onText);
    }

    public function models(?string $key = null): array
    {
        return $this->inner->models($key);
    }

    private function pin(Request $request): Request
    {
        $shared = $this->setup->shared();
        if ($shared === null || $request->key === null || $request->key !== $shared['key']) {
            return $request;
        }

        $model = in_array($request->model, $shared['models'], true) ? $request->model : $shared['model'];

        return new Request($model, $request->system, $request->messages, $request->tools, array_values(array_intersect($request->fallbacks, $shared['models'])), $request->maxTokens, $request->noTraining, $request->key);
    }
}
