<?php

namespace App\Mcp\Apps;

use App\Mcp\Confirmations;
use Laravel\Mcp\Server\AppResource;

/**
 * Which tool opens which panel (docs/specs/mcp-connections-plan.md M6.6). The tool's text answer stays complete;
 * the panel is only another way to read the same result. A confirmation panel never confirms.
 */
final class Panels
{
    /** @var array<string, class-string<AppResource>> */
    public const LINKED = [
        'show_quiz' => QuizRound::class,
        'set_topic_status' => TopicStatusCard::class,
        'end_session' => SessionEndCard::class,
        'flashcards_due' => FlashcardCard::class,
        'answer_flashcard' => FlashcardCard::class,
    ];

    /** A clock and a counter, so a later card can tell it is newer than one already on screen. */
    public static function version(): string
    {
        static $n = 0;

        return sprintf('%d.%04d', (int) round(microtime(true) * 1000), $n++ % 10000);
    }

    /** @return array{resourceUri: string, visibility: list<string>}|null */
    public static function meta(string $tool): ?array
    {
        $class = self::LINKED[$tool] ?? (isset(Confirmations::TOOLS[$tool]) ? ConfirmationCard::class : null);
        if ($class === null) {
            return null;
        }
        $app = app($class);

        return ['resourceUri' => $app->uri(), 'visibility' => ['model', 'app']];
    }
}
