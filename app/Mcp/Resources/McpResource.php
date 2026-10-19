<?php

namespace App\Mcp\Resources;

use App\Http\Middleware\McpPrincipal;
use App\Identity\PrincipalFactory;
use App\Mcp\Calls;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Study\Fence;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;

/**
 * What every ViStud resource shares (docs/specs/mcp-connections-plan.md M6.4): a resource is something the chat app can
 * attach by address (a course brief, a note, a page of a file). Like a tool it acts as the student behind the token, an
 * id that is not theirs answers like one that never was, the text is fenced as the student's material, and the read is
 * recorded in `mcp_calls` (an id and a count, never the text) so the Connections screen discloses it.
 */
abstract class McpResource extends Resource implements HasUriTemplate
{
    /** The longest text one resource gives back, in characters. */
    public const MAX_CHARS = 60_000;

    protected string $mimeType = 'text/plain';

    /** What this resource is, for the call record: course, note or file. */
    abstract protected function kind(): string;

    /**
     * @param  array<string, string>  $variables  the address's placeholders
     * @return array{text: string, id: string}
     */
    abstract protected function read(Principal $by, array $variables, ?object $connection): array;

    public function handle(Request $request): Response
    {
        $by = Container::getInstance()->make(PrincipalFactory::class)->forToken(request());
        $scope = Guard::learner($by);
        $connection = request()->attributes->get(McpPrincipal::CONNECTION);
        if (! is_object($connection)) {
            return Response::error('Not allowed.');
        }
        $variables = $this->uriTemplate()->match((string) $request->uri()) ?? [];

        try {
            foreach ($variables as $value) {
                preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $value) || throw new NotFound;
            }
            $done = $this->read($by, $variables, $connection);
        } catch (NotFound) {
            Calls::record($scope, $connection, 'resource_'.$this->kind(), Calls::NOT_FOUND, null, null, 0);

            return Response::error('Not found: there is no such thing in your ViStud.');
        } catch (AppError $e) {
            Calls::record($scope, $connection, 'resource_'.$this->kind(), Calls::ERROR, null, null, 0, ['code' => $e->errorCode]);

            return Response::error('That could not be read: '.$e->getMessage());
        }

        $text = mb_strlen($done['text']) > self::MAX_CHARS ? mb_substr($done['text'], 0, self::MAX_CHARS)."\n\n(Cut here: longer than ".self::MAX_CHARS.' characters.)' : $done['text'];
        Calls::record($scope, $connection, 'resource_'.$this->kind(), Calls::OK, $this->kind(), $done['id'], mb_strlen($text));

        return Response::text(Fence::wrap($text));
    }
}
