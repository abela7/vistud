<?php

namespace App\Engine\Vistud;

use App\Engine\Settings;
use App\Engine\Tools\Context;
use App\Engine\Tools\Tool;
use App\Platform\Access\Principal;

/**
 * ViStud AI keeps a student's answer to its offer of a key of their own (the owner's ask, 2026-10-23): yes (it gets
 * the link that opens the step-by-step key guide), no (never asked again) or later (asked again in a few days).
 */
final class OwnKeyAnswerTool implements Tool
{
    public function __construct(private Settings $settings) {}

    public function name(): string
    {
        return 'own_key_answer';
    }

    public function description(): string
    {
        return 'Keeps the student\'s answer when you asked whether they want their own AI key: yes, no or later. With yes, it gives the link to the step-by-step guide.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'answer' => ['type' => 'string', 'enum' => Settings::OFFER_ANSWERS, 'description' => 'What they said: yes, no, or later (not now).'],
        ], 'required' => ['answer'], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        if ($this->settings->key($by) !== null) {
            return 'They already have a key of their own: nothing to keep.';
        }
        $answer = is_string($input['answer'] ?? null) ? $input['answer'] : '';
        $this->settings->answerOwnKeyOffer($by, $answer);

        return match ($answer) {
            'yes' => 'Kept. Give them this link, which opens the step-by-step guide: '.route('settings', ['part' => 'ai', 'guide' => 'key'])
                .' Tell them to add a little credit at OpenRouter and set a spending limit on the key, then follow the guide one step at a time.',
            'no' => 'Kept: they don\'t want one. Don\'t ask again; they can still find the guide in AI settings.',
            default => 'Kept: ask again in a few days, not before.',
        };
    }
}
