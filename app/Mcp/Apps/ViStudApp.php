<?php

namespace App\Mcp\Apps;

use App\Http\Middleware\McpPrincipal;
use App\Mcp\Provider;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * A panel every chat app can render (docs/specs/mcp-connections-plan.md M6.6). System fonts, the host's light and
 * dark colours, and a connect rule for this ViStud only. Claude gets a domain hashed from the server address;
 * ChatGPT gets none, and uses its own. The HTML holds no student text: that arrives in the tool result and is
 * written with textContent, never into an attribute.
 */
abstract class ViStudApp extends AppResource
{
    /** quiz, topic, session, confirm or cards: which card the one view draws. */
    abstract protected function kind(): string;

    public function handle(Request $request): Response
    {
        return Response::view('mcp.apps.panel', ['kind' => $this->kind(), 'title' => $this->title()]);
    }

    public function appMeta(): AppMeta
    {
        $url = rtrim((string) config('app.url'), '/');

        return AppMeta::make()->csp(Csp::make()->connectDomains($url === '' ? [] : [$url]));
    }

    public function resolvedAppMeta(): array
    {
        $meta = parent::resolvedAppMeta();
        $connection = request()->attributes->get(McpPrincipal::CONNECTION);
        $provider = is_object($connection) ? (string) ($connection->provider ?? '') : Provider::ANTHROPIC;
        if ($provider === Provider::OPENAI) {
            unset($meta['domain']);

            return $meta;
        }
        $meta['domain'] = substr(hash('sha256', rtrim((string) url('/mcp'), '/')), 0, 32).self::CLAUDE_DOMAIN_SUFFIX;

        return $meta;
    }
}
