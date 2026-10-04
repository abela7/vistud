<?php

namespace App\Engine;

use App\Engine\Context\Stack;
use App\Engine\Jobs\Run;
use App\Engine\Jobs\Runner;
use App\Engine\Tools\Context;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Input;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\WriteBack;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The built-in chat of a study session (docs/specs/study-memory.md §6): the student's message goes to the
 * engine with the tutor's standing context (App\Engine\Context\Stack) and the tools it may look things up with;
 * the engine answers, or asks for look-ups first (each run here, its result sent back, a few rounds at most);
 * every turn is kept with what it cost. A session's chat may not cost more than the student's limit, nor a
 * month's chats theirs. When a chat grows long, its oldest turns are folded into a short summary the engine
 * reads instead, so a long session stays sharp and cheap. Saving what the tutor marked goes through the
 * write-back, as a pasted chat does: nothing is remembered until the student ticks it. When the session ends, the
 * chat is wrapped up once: the reader writes the session's summary and checkpoint from it, so the next
 * session starts from what happened without the student explaining again. Folding and wrapping up are recorded as the
 * reader's runs (App\Engine\Jobs\Runner), so the month's limit counts them.
 */
final class SessionChat
{
    public const MAX_TEXT = 8_000;

    public const SUMMARY_CHARS = 1_500;

    /** The wrap-up's summary and checkpoint, in characters; and the most of a chat it reads (the end of it). */
    public const WRAP_SUMMARY_CHARS = 1_200;

    public const WRAP_CHECKPOINT_CHARS = 400;

    public const WRAP_INPUT_CHARS = 40_000;

    public function __construct(
        private Engine $engine,
        private Sessions $sessions,
        private Settings $settings,
        private Models $models,
        private Toolbox $toolbox,
        private Stack $stack,
        private WriteBack $writeBack,
        private Attachments $attachments,
        private Runner $runner,
        private Usage $usage,
    ) {}

    /**
     * Sends the student's words and returns the engine's final answer for the turn. Given $onEvent, the answer
     * is streamed: it hears `text` with each chunk of words as the engine writes them, and `looking` with a
     * tool's name before each look-up runs. A refusal (Unprocessable) keeps nothing; once the words are kept,
     * a failing engine (EngineFailed) leaves them unanswered, for retry().
     *
     * With $attach (`note:{id}`, `file:{id}`), the student's notes and files go with the words (App\Engine\Attachments).
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     */
    public function send(Principal $by, string $sessionId, mixed $text, ?Closure $onEvent = null, mixed $attach = []): Reply
    {
        $scope = Guard::learner($by);
        [$session, $choices, $key] = $this->ready($by, $sessionId);
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        $items = $this->attachments->resolve($by, $session, $attach, $this->models->find($choices->tutorModel, $key));
        Input::refuse(match (true) {
            $text === '' && $items === [] => ['text' => 'Write something first.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep a message to '.self::MAX_TEXT.' characters.'],
            default => [],
        });

        $thread = $this->thread($scope, $session, $choices->tutorModel);
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text, 'attachments' => $items === [] ? null : json_encode($items)]);

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent);
    }

    /**
     * Answers the student's last words again, when the engine failed on them (busy, cut off, unreachable): the
     * chat goes on from where it stopped, look-ups already made included, without the words being sent twice.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     */
    public function retry(Principal $by, string $sessionId, ?Closure $onEvent = null): Reply
    {
        $scope = Guard::learner($by);
        [$session, $choices, $key] = $this->ready($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread === null || ! $this->unanswered($scope, $thread->id)) {
            throw new Unprocessable('nothing_to_retry', 'There is nothing to try again: the tutor has answered.');
        }
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent);
    }

    /** Whether the chat's last words are the student's (or a look-up's) with no answer after them. */
    public function waiting(Principal $by, string $sessionId): bool
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();

        return $thread !== null && $this->unanswered($scope, $thread->id);
    }

    /**
     * The engine's turn: the standing context, the chat so far and the tools; look-ups run and sent back, a few rounds
     * at most; every message kept with its cost.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     */
    private function answer(Principal $by, SessionDetails $session, object $thread, Choices $choices, ?string $key, int $position, ?Closure $onEvent): Reply
    {
        $scope = Guard::learner($by);
        $model = $this->models->find($choices->tutorModel, $key);
        $withTools = $model === null || $model->tools;
        $built = $this->stack->build($by, $session, $thread->summary, $withTools);
        [$system, $tools] = [$built->system, $built->tools];
        $messages = $this->messagesOf($by, $thread, $model === null || $model->images, $choices->tutorModel);
        $context = new Context($session->workspaceId, $session->moduleId, $session->id, $this->sessions->timezone($by));
        $fallbacks = $choices->fallbackModel !== '' ? [$choices->fallbackModel] : [];
        $rounds = max(0, (int) config('vistud.engine.tool_rounds'));
        $reply = null;

        for ($round = 0; $round <= $rounds; $round++) {
            // Room for a model that thinks before it answers: its thinking counts against the limit.
            $request = new Request($choices->tutorModel, $system, $messages, $round < $rounds ? $tools : [], $fallbacks, maxTokens: 8000, noTraining: $choices->noTraining, key: $key);
            $reply = $onEvent === null ? $this->engine->reply($request) : $this->engine->stream($request, fn (string $words) => $onEvent('text', $words));
            // A call's arguments are always an object, even with none: a service refuses a list there.
            $calls = array_map(fn (ToolCall $c) => ['id' => $c->id, 'type' => 'function', 'function' => ['name' => $c->name, 'arguments' => (string) json_encode($c->arguments === [] ? new \stdClass : $c->arguments)]], $reply->toolCalls);
            $this->keep($scope, $thread->id, ++$position, [
                'role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $calls === [] ? null : json_encode($calls), 'model' => $reply->model,
                'reasoning' => $reply->reasoning === [] ? null : json_encode($reply->reasoning),
                'tokens_in' => $reply->tokensIn, 'tokens_out' => $reply->tokensOut, 'cost_micros' => $reply->costMicros ?? 0,
            ]);
            // Counted round by round, so a turn the engine fails on later still counts what it cost.
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['spent_micros' => DB::raw('spent_micros + '.(int) ($reply->costMicros ?? 0)), 'updated_at' => now()]);
            $messages[] = array_filter(['role' => 'assistant', 'content' => $reply->text !== '' ? $reply->text : null, 'tool_calls' => $calls ?: null, 'reasoning_details' => $reply->reasoning !== [] && $reply->model === $choices->tutorModel ? $reply->reasoning : null], fn ($v) => $v !== null);
            if (! $reply->wantsTools()) {
                break;
            }
            foreach ($reply->toolCalls as $call) {
                if ($onEvent !== null) {
                    $onEvent('looking', $call->name);
                }
                $out = $this->toolbox->run($by, $context, $call->name, $call->arguments);
                // What the tool did in the course, kept with its result and told to the page as it happens.
                $effects = $context->effects->take();
                $this->keep($scope, $thread->id, ++$position, ['role' => 'tool', 'content' => $out, 'tool_call_id' => $call->id, 'tool_name' => $call->name, 'effects' => $effects === [] ? null : json_encode($effects)]);
                if ($onEvent !== null && $effects !== []) {
                    $onEvent('saved', (string) json_encode($effects));
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $out];
            }
        }

        LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update([
            'turns' => DB::raw('turns + 1'), 'model' => $choices->tutorModel, 'updated_at' => now(),
        ]);
        $this->foldIfLong($by, $session->workspaceId, $thread->id);

        return $reply;
    }

    /**
     * The chat as the student reads it: their turns and the tutor's, with the look-ups each turn made and what
     * it cost; the engine's tool results stay out.
     *
     * @return list<array{role: string, text: string, tools: list<string>, saved: array<string, int>, notes: list<array{id: string, title: string}>, cost_micros: int, folded: bool, at: string}>
     */
    public function transcript(Principal $by, string $sessionId): array
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread === null) {
            return [];
        }
        $turns = [];
        // The look-ups asked for before an answer, and what they cost, belong to that answer.
        $pendingTools = [];
        $pendingCost = 0;
        // What the tools did in the course (saved, notes written in) belongs to the answer after them.
        $pendingSaved = [];
        $pendingNotes = [];
        $pendingTopic = null;
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ($row->role === 'tool') {
                $effects = isset($row->effects) && is_string($row->effects) ? (json_decode($row->effects, true) ?: []) : [];
                foreach (is_array($effects['saved'] ?? null) ? $effects['saved'] : [] as $kind => $count) {
                    $pendingSaved[$kind] = ($pendingSaved[$kind] ?? 0) + (int) $count;
                }
                foreach (is_array($effects['notes'] ?? null) ? $effects['notes'] : [] as $note) {
                    $pendingNotes[(string) $note['id']] = ['id' => (string) $note['id'], 'title' => (string) $note['title']];
                }
                $pendingTopic = is_string($effects['topic'] ?? null) ? $effects['topic'] : $pendingTopic;

                continue;
            }
            $tools = array_map(fn ($c) => (string) ($c['function']['name'] ?? ''), is_string($row->tool_calls) ? (json_decode($row->tool_calls, true) ?: []) : []);
            if ($row->role === 'assistant' && trim((string) $row->content) === '' && $tools !== []) {
                $pendingTools = [...$pendingTools, ...$tools];
                $pendingCost += (int) $row->cost_micros;

                continue;
            }
            $turns[] = [
                'role' => $row->role,
                'text' => (string) $row->content,
                'attachments' => $row->role === 'user' ? array_map(fn (array $a) => ['ref' => (string) $a['ref'], 'name' => (string) $a['name'], 'kind' => (string) $a['kind']], self::attached($row)) : [],
                'tools' => $row->role === 'assistant' ? [...$pendingTools, ...$tools] : [],
                'saved' => $row->role === 'assistant' ? $pendingSaved : [],
                'notes' => $row->role === 'assistant' ? array_values($pendingNotes) : [],
                'topic' => $row->role === 'assistant' ? $pendingTopic : null,
                'cost_micros' => (int) $row->cost_micros + ($row->role === 'assistant' ? $pendingCost : 0),
                'folded' => (int) $row->position <= (int) $thread->folded_through,
                'at' => (string) $row->created_at,
            ];
            if ($row->role === 'assistant') {
                [$pendingTools, $pendingCost, $pendingSaved, $pendingNotes, $pendingTopic] = [[], 0, [], [], null];
            }
        }
        if ($pendingTools !== []) {
            // A turn that ended in look-ups without an answer (the engine failed after them).
            $turns[] = ['role' => 'assistant', 'text' => '', 'attachments' => [], 'tools' => $pendingTools, 'saved' => $pendingSaved, 'notes' => array_values($pendingNotes), 'topic' => $pendingTopic, 'cost_micros' => $pendingCost, 'folded' => false, 'at' => ''];
        }

        return $turns;
    }

    /**
     * What the tutor marked for saving in this chat so far, ready for the write-back's review (as a pasted chat).
     *
     * @return list<array<string, mixed>>
     */
    public function proposals(Principal $by, string $sessionId): array
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread === null) {
            return [];
        }
        $texts = [];
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ($row->role === 'assistant' && trim((string) $row->content) !== '') {
                $texts[] = (string) $row->content;
            }
        }

        return $texts === [] ? [] : $this->writeBack->review($by, $sessionId, implode("\n\n", $texts));
    }

    /**
     * Writes what the chat came to into the session's record (its summary and checkpoint, §4.3), once, when the
     * session ends: the next session and the earlier-sessions look-up start from them, so the student
     * never explains again what happened. The reader writes them from the chat (the folded part's summary
     * and the rest); a summary or checkpoint the student already ticked from the tutor's marks stays as it is.
     * Nothing happens without a chat with an answer in it, or when the engine isn't set up; a failed try is
     * left for the next.
     *
     * @return array{summary: ?string, checkpoint: ?string}|null what was written; null when nothing was
     */
    public function wrapUp(Principal $by, string $sessionId): ?array
    {
        $scope = Guard::learner($by);
        $session = $this->sessions->find($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread === null || $thread->wrapped_at !== null) {
            return null;
        }
        $rows = $this->rows($scope, $thread->id);
        $answered = array_filter($rows, fn ($row) => $row->role === 'assistant' && trim((string) $row->content) !== '');
        $asked = array_filter($rows, fn ($row) => $row->role === 'user');
        if ($answered === [] || $asked === [] || ($session->summary !== null && $session->checkpoint !== null)) {
            // Nothing to write from, or the student already kept the tutor's own summary and checkpoint.
            $this->markWrapped($scope, $thread->id, 0);

            return null;
        }
        try {
            $this->settings->ready($by, Role::Reader);
        } catch (Unprocessable) {
            // Not set up, or no consent: nothing is tried, and nothing is recorded.
            return null;
        }

        $unfolded = array_values(array_filter($rows, fn ($row) => (int) $row->position > (int) $thread->folded_through));
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What happened first (already summarised):\n".trim((string) $thread->summary)."\n\n---\n\n" : '';
        $text = $earlier.$this->asText($unfolded);
        if (mb_strlen($text) > self::WRAP_INPUT_CHARS) {
            $text = "[The chat's start is cut; this is its end.]\n".mb_substr($text, -self::WRAP_INPUT_CHARS);
        }
        try {
            $reply = $this->runner->run($by, Role::Reader, 'wrap_up', $session->workspaceId, 'session', $sessionId, fn (Run $run) => $run->ask(
                'You write the record of one study session\'s chat between a student and their tutor, for the student\'s own study memory in ViStud. Answer with one JSON object and nothing else: {"summary": "...", "checkpoint": "..."}. summary: what was studied and explained, what the student got right or wrong, and what still confuses them, in plain prose of at most '.self::WRAP_SUMMARY_CHARS.' characters, no headings or lists. checkpoint: where the session stopped (like slide 7 of 18) and what comes next, in one or two sentences of at most '.self::WRAP_CHECKPOINT_CHARS.' characters. Write in the language the student wrote in. Only what is in the chat: never invent what wasn\'t said.',
                $text,
                900,
            ));
        } catch (EngineFailed|Unprocessable) {
            return null;
        }

        [$summary, $checkpoint] = $this->parseWrap($reply->text);
        $written = ['summary' => null, 'checkpoint' => null];
        if ($session->summary === null && $summary !== '') {
            $this->sessions->setSummary($by, $sessionId, $summary);
            $written['summary'] = $summary;
        }
        if ($session->checkpoint === null && $checkpoint !== '') {
            $this->sessions->setCheckpoint($by, $sessionId, $checkpoint);
            $written['checkpoint'] = $checkpoint;
        }
        if ($written === ['summary' => null, 'checkpoint' => null]) {
            // The model gave nothing usable; the next try may do better.
            return null;
        }
        $this->markWrapped($scope, $thread->id, (int) ($reply->costMicros ?? 0));

        return $written;
    }

    /**
     * What this session's chat and this month's chats have cost, against the student's limits (millionths of a dollar).
     *
     * @return array{session: int, month: int, session_cap: int, month_cap: int}
     */
    public function spent(Principal $by, string $sessionId): array
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $choices = $this->settings->get($by);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();

        return [
            'session' => (int) ($thread->spent_micros ?? 0),
            'month' => $this->usage->monthTotal($scope, $this->sessions->timezone($by)),
            'session_cap' => $choices->sessionCapMicros,
            'month_cap' => $choices->monthCapMicros,
        ];
    }

    // ---------- Inside ----------

    /**
     * The session, the student's choices and key, once the chat may run: the session open, a key, a model and
     * the student's consent.
     *
     * @return array{0: SessionDetails, 1: Choices, 2: ?string}
     */
    private function ready(Principal $by, string $sessionId): array
    {
        $session = $this->sessions->find($by, $sessionId);
        if (! $session->isOpen()) {
            throw new Unprocessable('session_ended', 'This session has ended. Start a new one to keep chatting.');
        }
        [$choices, $key] = $this->settings->ready($by, Role::Tutor);

        return [$session, $choices, $key];
    }

    private function unanswered(LearnerScope $scope, string $threadId): bool
    {
        $last = LearnerTables::query($scope, 'engine_messages')->where('thread_id', $threadId)->orderByDesc('position')->first();

        return $last !== null && in_array($last->role, ['user', 'tool'], true);
    }

    private function thread(LearnerScope $scope, SessionDetails $session, string $model): object
    {
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $session->id)->first();
        if ($thread !== null) {
            return $thread;
        }
        $id = Ids::new();
        LearnerTables::insert($scope, 'engine_threads', ['id' => $id, 'workspace_id' => $session->workspaceId, 'session_id' => $session->id, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);

        return LearnerTables::query($scope, 'engine_threads')->where('id', $id)->first();
    }

    private function refuseOverCap(LearnerScope $scope, object $thread, Choices $choices, string $zone): void
    {
        if ($choices->sessionCapMicros > 0 && (int) $thread->spent_micros >= $choices->sessionCapMicros) {
            throw new Unprocessable('engine_cap', 'This session\'s chat has reached its limit of '.Choices::dollars($choices->sessionCapMicros).'. Raise it in the AI engine settings to keep going.');
        }
        $this->usage->refuseOverMonthCap($scope, $choices, $zone, chat: true);
    }

    /** @param array<string, mixed> $values */
    private function keep(LearnerScope $scope, string $threadId, int $position, array $values): void
    {
        LearnerTables::insert($scope, 'engine_messages', $values + ['id' => Ids::new(), 'thread_id' => $threadId, 'position' => $position, 'created_at' => now()]);
    }

    /** @return list<object> every message of the thread, in order */
    private function rows(LearnerScope $scope, string $threadId): array
    {
        return LearnerTables::query($scope, 'engine_messages')->where('thread_id', $threadId)->orderBy('position')->get()->all();
    }

    /**
     * The unfolded messages, in the OpenAI chat shape. The student's attachments go with their words; pictures are
     * shown only with the last message they wrote (when the model sees pictures), and named on earlier ones. A
     * model's reasoning goes back with its own messages, to the same model only; an answer with nothing in it is
     * left out (a service refuses an empty message).
     *
     * @return list<array<string, mixed>>
     */
    private function messagesOf(Principal $by, object $thread, bool $see, string $model): array
    {
        $rows = $this->rows(Guard::learner($by), $thread->id);
        $lastAsked = 0;
        foreach ($rows as $row) {
            $lastAsked = $row->role === 'user' ? (int) $row->position : $lastAsked;
        }
        $messages = [];
        foreach ($rows as $row) {
            if ((int) $row->position <= (int) $thread->folded_through) {
                continue;
            }
            $attached = self::attached($row);
            if ($row->role === 'assistant' && trim((string) $row->content) === '' && ! is_string($row->tool_calls)) {
                continue;
            }
            $reasoning = isset($row->reasoning) && is_string($row->reasoning) && (string) $row->model === $model ? json_decode($row->reasoning, true) : null;
            $messages[] = match ($row->role) {
                'tool' => ['role' => 'tool', 'tool_call_id' => (string) $row->tool_call_id, 'content' => (string) $row->content],
                'assistant' => array_filter(['role' => 'assistant', 'content' => (string) $row->content !== '' ? (string) $row->content : null, 'tool_calls' => is_string($row->tool_calls) ? self::objectArguments(json_decode($row->tool_calls, true) ?: []) : null, 'reasoning_details' => is_array($reasoning) && $reasoning !== [] ? $reasoning : null], fn ($v) => $v !== null),
                default => ['role' => 'user', 'content' => $attached === [] ? (string) $row->content : $this->attachments->content($by, (string) $row->content, $attached, $see && (int) $row->position === $lastAsked)],
            };
        }

        return $messages;
    }

    /**
     * Kept tool calls with their arguments as an object, even with none (older rows kept an empty list, which a
     * service refuses).
     *
     * @param  list<array<string, mixed>>  $calls
     * @return list<array<string, mixed>>
     */
    private static function objectArguments(array $calls): array
    {
        foreach ($calls as &$call) {
            if (is_array($call) && isset($call['function']) && is_array($call['function']) && in_array($call['function']['arguments'] ?? null, ['[]', '', null], true)) {
                $call['function']['arguments'] = '{}';
            }
        }

        return $calls;
    }

    /** @return list<array<string, mixed>> what the student attached to a message */
    private static function attached(object $row): array
    {
        $items = isset($row->attachments) && is_string($row->attachments) ? json_decode($row->attachments, true) : null;

        return is_array($items) ? array_values(array_filter($items, fn ($item) => is_array($item) && isset($item['ref'], $item['name'], $item['kind']))) : [];
    }

    /**
     * Past a size, the oldest turns (all but the last few messages, cut at a student's message so a look-up
     * never loses its result) are summarised by the reader and kept only as that summary.
     */
    private function foldIfLong(Principal $by, string $workspaceId, string $threadId): void
    {
        $scope = Guard::learner($by);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('id', $threadId)->first();
        $rows = array_values(array_filter($this->rows($scope, $threadId), fn ($row) => (int) $row->position > (int) $thread->folded_through));
        $size = array_sum(array_map(fn ($row) => mb_strlen((string) $row->content), $rows));
        if ($size <= (int) config('vistud.engine.fold_at')) {
            return;
        }
        $cut = count($rows) - max(1, (int) config('vistud.engine.keep_recent'));
        while ($cut > 0 && $rows[$cut]->role !== 'user') {
            $cut--;
        }
        if ($cut <= 0) {
            return;
        }
        $folded = array_slice($rows, 0, $cut);
        $transcript = $this->asText($folded);
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What was already folded:\n".trim((string) $thread->summary)."\n\n---\n\n" : '';

        try {
            $reply = $this->runner->run($by, Role::Reader, 'fold', $workspaceId, 'thread', $threadId, fn (Run $run) => $run->ask(
                'You summarise one study session\'s chat between a student and their tutor, so the tutor can go on with it later. Keep what was explained and how far the student got, what they got right or wrong, what still confuses them, and where the chat stands. Plain text, at most '.self::SUMMARY_CHARS.' characters, no headings.',
                $earlier.$transcript,
                800,
            ));
        } catch (EngineFailed|Unprocessable) {
            return;
        }
        $summary = mb_substr(trim($reply->text), 0, self::SUMMARY_CHARS * 2);
        if ($summary === '') {
            return;
        }
        LearnerTables::query($scope, 'engine_threads')->where('id', $threadId)->update([
            'summary' => $summary, 'folded_through' => (int) $folded[array_key_last($folded)]->position,
            'spent_micros' => DB::raw('spent_micros + '.(int) ($reply->costMicros ?? 0)), 'updated_at' => now(),
        ]);
    }

    private function markWrapped(LearnerScope $scope, string $threadId, int $costMicros): void
    {
        LearnerTables::query($scope, 'engine_threads')->where('id', $threadId)->update([
            'wrapped_at' => now(), 'spent_micros' => DB::raw('spent_micros + '.$costMicros), 'updated_at' => now(),
        ]);
    }

    /**
     * The wrap-up's answer as a summary and a checkpoint, within the record's limits; an answer that isn't the
     * JSON asked for is taken whole as the summary.
     *
     * @return array{0: string, 1: string}
     */
    private function parseWrap(string $text): array
    {
        $clean = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)));
        $json = json_decode($clean, true);
        if (! is_array($json)) {
            return [mb_substr($clean, 0, Sessions::SUMMARY_LIMIT), ''];
        }
        $summary = is_string($json['summary'] ?? null) ? trim($json['summary']) : '';
        $checkpoint = is_string($json['checkpoint'] ?? null) ? trim($json['checkpoint']) : '';

        return [mb_substr($summary, 0, Sessions::SUMMARY_LIMIT), mb_substr($checkpoint, 0, Sessions::CHECKPOINT_LIMIT)];
    }

    /**
     * Messages as a plain transcript for the reader: the student's and the tutor's words, a look-up as a line.
     *
     * @param  list<object>  $rows
     */
    private function asText(array $rows): string
    {
        return implode("\n", array_map(fn ($row) => match ($row->role) {
            'tool' => '[Looked up '.$row->tool_name.': '.mb_substr((string) $row->content, 0, 300).']',
            'assistant' => 'Tutor: '.((string) $row->content !== '' ? $row->content : '(asked for a look-up)'),
            default => 'Student: '.$row->content.(($names = array_column(self::attached($row), 'name')) !== [] ? ' [attached: '.implode(', ', $names).']' : ''),
        }, $rows));
    }
}
