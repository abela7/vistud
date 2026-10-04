<?php

namespace App\Engine;

use App\Engine\Tools\Context;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Briefings;
use App\Study\Input;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The built-in chat of a study session (docs/specs/study-memory.md §6): the student's message goes to the
 * engine with the session's briefing as its standing instructions and the tools it may look things up with;
 * the engine answers, or asks for look-ups first (each run here, its result sent back, a few rounds at most);
 * every turn is kept with what it cost. A session's chat may not cost more than the student's limit, nor a
 * month's chats theirs. When a chat grows long, its oldest turns are folded into a short summary the engine
 * reads instead, so a long session stays sharp and cheap. Saving what the tutor marked goes through the
 * write-back, as a pasted chat does: nothing is remembered until the student ticks it.
 */
final class SessionChat
{
    public const MAX_TEXT = 8_000;

    public const SUMMARY_CHARS = 1_500;

    public function __construct(
        private Engine $engine,
        private Sessions $sessions,
        private Briefings $briefings,
        private Settings $settings,
        private Models $models,
        private Toolbox $toolbox,
        private WriteBack $writeBack,
        private Setup $setup,
    ) {}

    /** Sends the student's words and returns the engine's final answer for the turn. */
    public function send(Principal $by, string $sessionId, mixed $text): Reply
    {
        $scope = Guard::learner($by);
        $session = $this->sessions->find($by, $sessionId);
        if (! $session->isOpen()) {
            throw new Unprocessable('session_ended', 'This session has ended. Start a new one to keep chatting.');
        }
        $choices = $this->settings->get($by);
        $key = $this->settings->key($by);
        if ($key === null && ! $this->setup->keySet()) {
            throw new Unprocessable('engine_key', 'Add your OpenRouter key in your AI engine settings first.');
        }
        if ($choices->tutorModel === '') {
            throw new Unprocessable('engine_model', 'Choose a model in your AI engine settings first.');
        }
        if ($choices->consentedAt === null) {
            throw new Unprocessable('engine_consent', 'Agree to the chat in your AI engine settings first.');
        }
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        Input::refuse(match (true) {
            $text === '' => ['text' => 'Write something first.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep a message to '.self::MAX_TEXT.' characters.'],
            default => [],
        });

        $thread = $this->thread($scope, $session, $choices->tutorModel);
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text]);

        $model = $this->models->find($choices->tutorModel, $key);
        $withTools = $model === null || $model->tools;
        $system = $this->system($by, $session, $thread->summary, $withTools);
        $messages = $this->messagesOf($scope, $thread);
        $tools = $withTools ? $this->toolbox->definitions() : [];
        $context = new Context($session->workspaceId, $session->moduleId, $session->id, $this->sessions->timezone($by));
        $fallbacks = $choices->fallbackModel !== '' ? [$choices->fallbackModel] : [];
        $rounds = max(0, (int) config('vistud.engine.tool_rounds'));
        $spent = 0;
        $reply = null;

        for ($round = 0; $round <= $rounds; $round++) {
            $reply = $this->engine->reply(new Request($choices->tutorModel, $system, $messages, $round < $rounds ? $tools : [], $fallbacks, noTraining: $choices->noTraining, key: $key));
            $spent += $reply->costMicros ?? 0;
            $calls = array_map(fn (ToolCall $c) => ['id' => $c->id, 'type' => 'function', 'function' => ['name' => $c->name, 'arguments' => (string) json_encode($c->arguments)]], $reply->toolCalls);
            $this->keep($scope, $thread->id, ++$position, [
                'role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $calls === [] ? null : json_encode($calls), 'model' => $reply->model,
                'tokens_in' => $reply->tokensIn, 'tokens_out' => $reply->tokensOut, 'cost_micros' => $reply->costMicros ?? 0,
            ]);
            $messages[] = array_filter(['role' => 'assistant', 'content' => $reply->text !== '' ? $reply->text : null, 'tool_calls' => $calls ?: null], fn ($v) => $v !== null);
            if (! $reply->wantsTools()) {
                break;
            }
            foreach ($reply->toolCalls as $call) {
                $out = $this->toolbox->run($by, $context, $call->name, $call->arguments);
                $this->keep($scope, $thread->id, ++$position, ['role' => 'tool', 'content' => $out, 'tool_call_id' => $call->id, 'tool_name' => $call->name]);
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $out];
            }
        }

        LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update([
            'spent_micros' => DB::raw('spent_micros + '.(int) $spent), 'turns' => DB::raw('turns + 1'), 'model' => $choices->tutorModel, 'updated_at' => now(),
        ]);
        $this->foldIfLong($scope, $thread->id, $choices, $key);

        return $reply;
    }

    /**
     * The chat as the student reads it: their turns and the tutor's, with the look-ups each turn made and what
     * it cost; the engine's tool results stay out.
     *
     * @return list<array{role: string, text: string, tools: list<string>, cost_micros: int, folded: bool, at: string}>
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
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ($row->role === 'tool') {
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
                'tools' => $row->role === 'assistant' ? [...$pendingTools, ...$tools] : [],
                'cost_micros' => (int) $row->cost_micros + ($row->role === 'assistant' ? $pendingCost : 0),
                'folded' => (int) $row->position <= (int) $thread->folded_through,
                'at' => (string) $row->created_at,
            ];
            if ($row->role === 'assistant') {
                [$pendingTools, $pendingCost] = [[], 0];
            }
        }
        if ($pendingTools !== []) {
            // A turn that ended in look-ups without an answer (the engine failed after them).
            $turns[] = ['role' => 'assistant', 'text' => '', 'tools' => $pendingTools, 'cost_micros' => $pendingCost, 'folded' => false, 'at' => ''];
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
            'month' => $this->monthSpend($scope, $this->sessions->timezone($by)),
            'session_cap' => $choices->sessionCapMicros,
            'month_cap' => $choices->monthCapMicros,
        ];
    }

    // ---------- Inside ----------

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
        if ($choices->monthCapMicros > 0 && $this->monthSpend($scope, $zone) >= $choices->monthCapMicros) {
            throw new Unprocessable('engine_cap', 'This month\'s chats have reached their limit of '.Choices::dollars($choices->monthCapMicros).'. Raise it in the AI engine settings to keep going.');
        }
    }

    private function monthSpend(LearnerScope $scope, string $zone): int
    {
        $since = CarbonImmutable::now($zone)->startOfMonth()->utc();

        return (int) LearnerTables::query($scope, 'engine_messages')->where('created_at', '>=', $since)->sum('cost_micros');
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

    /** @return list<array<string, mixed>> the unfolded messages, in the OpenAI chat shape */
    private function messagesOf(LearnerScope $scope, object $thread): array
    {
        $messages = [];
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ((int) $row->position <= (int) $thread->folded_through) {
                continue;
            }
            $messages[] = match ($row->role) {
                'tool' => ['role' => 'tool', 'tool_call_id' => (string) $row->tool_call_id, 'content' => (string) $row->content],
                'assistant' => array_filter(['role' => 'assistant', 'content' => (string) $row->content !== '' ? (string) $row->content : null, 'tool_calls' => is_string($row->tool_calls) ? json_decode($row->tool_calls, true) : null], fn ($v) => $v !== null),
                default => ['role' => 'user', 'content' => (string) $row->content],
            };
        }

        return $messages;
    }

    /** The standing instructions: the tutor prompt and the briefing, how to look things up, and the folded turns. */
    private function system(Principal $by, SessionDetails $session, ?string $summary, bool $withTools): string
    {
        $system = $this->briefings->forSession($by, $session->id)->markdown;
        if ($withTools) {
            $system .= "\n\n## Looking things up\n\nYou are inside ViStud's own chat, so you can look things up with the tools: the course's modules and topics, the student's questions (all, or those on one topic or module), findings, assignments and their plans, the calendar, their notes and files, and earlier sessions (with what each used). Use them whenever the student asks about their own things, instead of guessing: what a tool returns is what ViStud holds. Before explaining a note or a topic, it's worth one look at the open questions on it. When you state a fact from a note, say which note. If something isn't in ViStud, say so plainly. Files can't be read here yet; ask the student to share the part that matters.";
        }
        if ($summary !== null && trim($summary) !== '') {
            $system .= "\n\n## Earlier in this chat\n\nThe chat's first part was folded to keep it short. What happened in it:\n\n".trim($summary);
        }

        return $system;
    }

    /**
     * Past a size, the oldest turns (all but the last few messages, cut at a student's message so a look-up
     * never loses its result) are summarised by the quick model and kept only as that summary.
     */
    private function foldIfLong(LearnerScope $scope, string $threadId, Choices $choices, ?string $key): void
    {
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
        $transcript = implode("\n", array_map(fn ($row) => match ($row->role) {
            'tool' => '[Looked up '.$row->tool_name.': '.mb_substr((string) $row->content, 0, 300).']',
            'assistant' => 'Tutor: '.((string) $row->content !== '' ? $row->content : '(asked for a look-up)'),
            default => 'Student: '.$row->content,
        }, $folded));
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What was already folded:\n".trim((string) $thread->summary)."\n\n---\n\n" : '';

        try {
            $reply = $this->engine->reply(new Request(
                $choices->quickOrTutor(),
                'You summarise one study session\'s chat between a student and their tutor, so the tutor can go on with it later. Keep what was explained and how far the student got, what they got right or wrong, what still confuses them, and where the chat stands. Plain text, at most '.self::SUMMARY_CHARS.' characters, no headings.',
                [['role' => 'user', 'content' => $earlier.$transcript]],
                maxTokens: 800,
                noTraining: $choices->noTraining,
                key: $key,
            ));
        } catch (EngineFailed) {
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
}
