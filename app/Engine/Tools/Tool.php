<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;

/**
 * One thing the engine may look up in ViStud (docs/specs/study-memory.md §6). It runs as the student, through
 * the same services the pages use, so it can never see another student's things. It answers in words or compact
 * JSON the model reads; never the student's name or email.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> a JSON schema for the input, OpenAI's `parameters` */
    public function parameters(): array;

    /** @param array<string, mixed> $input */
    public function run(Principal $by, Context $context, array $input): string;
}
