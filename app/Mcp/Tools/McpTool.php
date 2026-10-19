<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpPrincipal;
use App\Identity\PrincipalFactory;
use App\Mcp\Apps\Panels;
use App\Mcp\Calls;
use App\Mcp\ContextBuilder;
use App\Mcp\ToolInfo;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Study\CaptureFilter;
use App\Study\Sessions;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * What every ViStud MCP tool shares (docs/specs/mcp-connections-plan.md §3.1, §3.3, §3.4, §3.6):
 *
 * - it acts as the student behind the token, and is neither listed nor run above the connection's level;
 * - refusals reach the app as MCP errors (`isError`), and another student's things answer like things that never were;
 * - a call carrying an open `session_id` keeps that session running; a write to a session paused "away" resumes it;
 * - every write is checked first (App\Study\CaptureFilter), may carry a `capture_key` (the same key again answers
 *   `duplicate`; the same key with other content is `rejected`), counts against the daily cap, and answers with an
 *   acknowledgement: `recorded`, `duplicate`, `rejected` or `unchanged`;
 * - one `mcp_calls` row per call, with ids and counts and never text.
 */
abstract class McpTool extends Tool
{
    public const NOT_FOUND = 'Not found: there is no such thing in your ViStud.';

    /** How many things the student's AI apps may write in 24 hours (cards, key points, questions, answers, marks, notes). */
    public const DAILY_ROWS = 500;

    /** The tool's row in App\Mcp\ToolInfo. */
    abstract protected function info(): string;

    /**
     * The work itself.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, structured?: ?array<string, mixed>, type?: ?string, id?: ?string, effects?: array<string, mixed>, changed?: bool, status?: string}
     */
    abstract protected function answer(Principal $by, array $arguments, ?object $connection): array;

    public function handle(Request $request): Response|ResponseFactory
    {
        $container = Container::getInstance();
        $by = $container->make(PrincipalFactory::class)->forToken(request());
        $scope = Guard::learner($by);
        $connection = request()->attributes->get(McpPrincipal::CONNECTION);
        $info = ToolInfo::of($this->info());
        $arguments = $request->all();
        $writes = ! $info['read_only'];

        if (! is_object($connection) || ! ToolInfo::allows((string) $connection->level, $info['level'])) {
            if (is_object($connection)) {
                Calls::record($scope, $connection, $this->name(), Calls::NOT_ALLOWED, null, null, 0);
            }

            return Response::error('This app is connected to ViStud as read only. The student can choose more in ViStud, Settings › AI.');
        }

        $key = $writes && is_string($arguments['capture_key'] ?? null) ? mb_substr(trim($arguments['capture_key']), 0, 100) : null;
        unset($arguments['capture_key']);
        $hash = hash('sha256', (string) json_encode($arguments));

        if ($writes) {
            if (($reason = CaptureFilter::reason($arguments)) !== null) {
                Calls::record($scope, $connection, $this->name(), Calls::ERROR, null, null, 0, ['reason' => $reason]);

                return self::acknowledge(['status' => 'rejected', 'reason' => $reason, 'note' => 'Nothing was saved: it looks like a password, key or token, and ViStud never keeps those.'], true);
            }
            if ($key !== null && ($earlier = Calls::byCaptureKey($scope, $connection, $this->name(), $key)) !== null) {
                $kept = json_decode((string) $earlier->effects, true) ?: [];
                if (($kept['hash'] ?? null) !== $hash) {
                    return self::acknowledge(['status' => 'rejected', 'reason' => 'capture_key_conflict', 'note' => 'That capture_key was used before for something else. Use a new key.'], true);
                }

                return self::acknowledge(['status' => 'duplicate', 'note' => 'Already saved with this capture_key; nothing was saved twice.'] + (array) ($kept['ack'] ?? []), false);
            }
            if (Calls::rowsToday($scope) >= self::DAILY_ROWS) {
                Calls::limitReached($by, $connection, $this->name());

                return self::acknowledge(['status' => 'rejected', 'reason' => 'rate_limited', 'note' => 'The daily limit of '.self::DAILY_ROWS.' saved items from AI apps is reached. It frees up over the next 24 hours.'], true);
            }
        }

        try {
            $resumed = $this->heartbeat($by, $arguments, $writes);
            $done = $this->answer($by, $arguments, $connection);
        } catch (NotFound) {
            Calls::record($scope, $connection, $this->name(), Calls::NOT_FOUND, null, null, 0);

            return Response::error(self::NOT_FOUND);
        } catch (AppError $e) {
            Calls::record($scope, $connection, $this->name(), Calls::ERROR, null, null, 0, ['code' => $e->errorCode]);
            $fields = $e->details['fields'] ?? [];

            return $writes
                ? self::acknowledge(['status' => 'rejected', 'reason' => $e->errorCode, 'note' => self::message($e, $fields)], true)
                : Response::error('That didn\'t work: '.self::message($e, $fields));
        }

        $text = $done['text'];
        $effects = $done['effects'] ?? [];
        $structured = $done['structured'] ?? null;
        if ($writes) {
            $ack = array_filter([
                'status' => $done['status'] ?? (($effects['rows'] ?? 0) > 0 || ($done['changed'] ?? false) ? 'recorded' : 'unchanged'),
                'session_id' => ContextBuilder::id($arguments, 'session_id'),
                'resumed' => $resumed ?: null,
                'saved' => $effects['saved'] ?? null,
                'ids' => $effects['saved_ids'] ?? null,
            ], fn ($v) => $v !== null && $v !== []);
            $structured = $ack + ($structured ?? []);
            if ($resumed) {
                $text = 'The session was paused while the chat was quiet; it is running again. '.$text;
            }
            $effects += ['capture_key' => $key, 'hash' => $hash, 'ack' => $ack];
        }
        Calls::record($scope, $connection, $this->name(), Calls::OK, $done['type'] ?? null, $done['id'] ?? null, mb_strlen($text), $effects === [] ? null : array_filter($effects, fn ($v) => $v !== null));
        if (Panels::meta($this->info()) !== null) {
            $structured = ['ui_version' => Panels::version()] + ($structured ?? []);
        }

        return $structured === null ? Response::text($text) : (new ResponseFactory([Response::text($text)]))->withStructuredContent($structured);
    }

    /** Listed only when the connection's level allows it: a read-only app never sees a write. */
    public function shouldRegister(): bool
    {
        $connection = request()->attributes->get(McpPrincipal::CONNECTION);

        return ! is_object($connection) || ToolInfo::allows((string) $connection->level, ToolInfo::of($this->info())['level']);
    }

    /** @return array<string, bool> the four hints, from App\Mcp\ToolInfo: never left to the schema's defaults */
    public function annotations(): array
    {
        $info = ToolInfo::of($this->info());

        return [
            'readOnlyHint' => $info['read_only'],
            'destructiveHint' => $info['destructive'],
            'idempotentHint' => $info['idempotent'],
            'openWorldHint' => false,
        ];
    }

    public function title(): string
    {
        return ToolInfo::of($this->info())['title'];
    }

    public function name(): string
    {
        return $this->info();
    }

    /**
     * The ids a tool takes, as JSON schema properties: course, module, session, assignment, and a capture key on writes.
     *
     * @return array<string, array<string, string>>
     */
    protected function idProperties(): array
    {
        $name = $this->info();
        $info = ToolInfo::of($name);
        $session = ToolInfo::SESSION[$name] ?? null;
        $properties = [];
        if ($info['course']) {
            $properties['course_id'] = ['type' => 'string', 'description' => $session !== null ? 'The course\'s id, from courses. Leave out when you pass session_id: the session gives it.' : 'The course\'s id, from the courses tool.'];
            $properties['module_id'] = ['type' => 'string', 'description' => 'A module\'s id in that course, from course_overview; leave out for the whole course.'];
        }
        if ($session !== null) {
            $properties['session_id'] = ['type' => 'string', 'description' => $session === 'required' ? 'The open study session\'s id, from start_session or current_session. Required.' : 'The open study session\'s id, when there is one.'];
        }
        if (in_array($name, ToolInfo::ASSIGNMENT, true)) {
            $properties['assignment_id'] = ['type' => 'string', 'description' => 'The assignment\'s id, from the assignments tool.'];
        }
        if (! $info['read_only']) {
            $properties['capture_key'] = ['type' => 'string', 'description' => 'Optional. Any unique string for this save; sending the same key again does not save twice.'];
        }

        return $properties;
    }

    /**
     * A native tool's input schema: its ids (idProperties) plus its own fields.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function inputSchema(array $properties, array $required = []): array
    {
        $required = array_values(array_unique([...$this->requiredIds(), ...$required]));

        return array_filter([
            'type' => 'object',
            'properties' => (object) ($this->idProperties() + $properties),
            'required' => $required === [] ? null : $required,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = parent::toArray();
        $result['inputSchema'] = $this->inputSchema($this->fields(), $this->requiredFields());
        if (($ui = Panels::meta($this->info())) !== null) {
            $result['_meta'] = array_replace($result['_meta'] ?? [], ['ui' => $ui]);
        }

        return $result;
    }

    /**
     * The tool's own fields, beside the ids. EngineTool takes the in-app tool's schema instead.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function fields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function requiredFields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function requiredIds(): array
    {
        $name = $this->info();
        $session = ToolInfo::SESSION[$name] ?? null;

        return match (true) {
            $session === 'required' => ['session_id'],
            in_array($name, ['save_to_assignment', 'log_progress'], true) => ['assignment_id'],
            ToolInfo::of($name)['course'] && $session === null => ['course_id'],
            default => [],
        };
    }

    /**
     * Keeps the named session running: any call carrying its `session_id` counts as activity, and a write to a
     * session paused "away" (30 quiet minutes) resumes it. Reads never resume. True when it resumed.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function heartbeat(Principal $by, array $arguments, bool $writes): bool
    {
        $sessionId = ContextBuilder::id($arguments, 'session_id');
        if ($sessionId === null || ! isset(ToolInfo::SESSION[$this->info()])) {
            return false;
        }
        $sessions = Container::getInstance()->make(Sessions::class);
        $session = $sessions->find($by, $sessionId);
        $resumed = false;
        if ($writes && $session->state === 'paused' && $session->pausedBy === 'away') {
            $sessions->resume($by, $sessionId);
            $resumed = true;
        }
        $sessions->touch($by, $sessionId);

        return $resumed;
    }

    /** @param  array<string, mixed>  $ack */
    private static function acknowledge(array $ack, bool $error): ResponseFactory
    {
        $text = match ($ack['status']) {
            'duplicate' => 'Already saved: '.($ack['note'] ?? ''),
            'rejected' => 'Not saved: '.($ack['note'] ?? ''),
            default => (string) ($ack['note'] ?? ''),
        };
        $response = $error ? Response::error($text) : Response::text($text);

        return (new ResponseFactory([$response]))->withStructuredContent($ack);
    }

    private static function message(AppError $e, mixed $fields): string
    {
        return is_array($fields) && $fields !== [] ? implode(' ', array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $fields)) : $e->getMessage();
    }
}
