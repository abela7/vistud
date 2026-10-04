<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;

/** Key points (what the student must know) straight into a topic. */
final class SaveKeyPointsTool extends Saving
{
    public function name(): string
    {
        return 'save_key_points';
    }

    public function description(): string
    {
        return 'Saves key points (what the student must know, in a sentence each) straight into a topic in ViStud. Use it when the student says "save that" or agrees to keep something. Up to ten at a time.';
    }

    public function parameters(): array
    {
        return self::listOf('points', ['text' => 'The key point, in a sentence.'], 'The key points to save.');
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        return $this->save($by, $context, 'finding', self::items($input, 'points', ['text']), ['key point', 'key points']);
    }
}
