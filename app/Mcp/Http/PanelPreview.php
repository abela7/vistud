<?php

namespace App\Mcp\Http;

use App\Mcp\Apps\ConfirmationCard;
use App\Mcp\Apps\FlashcardCard;
use App\Mcp\Apps\QuizRound;
use App\Mcp\Apps\SessionEndCard;
use App\Mcp\Apps\TopicStatusCard;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel HTML, for the browser checks at 320 px (docs/specs/mcp-connections-plan.md M6.6). Local and the test
 * suite only: a panel holds no student text, and production never serves this address.
 */
final class PanelPreview
{
    /** @var array<string, class-string> */
    private const PANELS = [
        'quiz' => QuizRound::class,
        'topic' => TopicStatusCard::class,
        'session' => SessionEndCard::class,
        'confirm' => ConfirmationCard::class,
        'cards' => FlashcardCard::class,
    ];

    public function __invoke(string $panel): View|Response
    {
        if (! app()->environment('local', 'testing')) {
            abort(404);
        }
        $class = self::PANELS[$panel] ?? abort(404);
        $app = app($class);
        app()->instance('mcp.library_scripts', $app->libraryScripts());

        return view('mcp.apps.panel', ['kind' => match ($panel) {
            'quiz' => 'quiz', 'topic' => 'topic', 'session' => 'session', 'confirm' => 'confirm', default => 'cards',
        }, 'title' => $app->title()]);
    }
}
