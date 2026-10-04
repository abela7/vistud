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
 * write-back, as a pasted chat does: nothing is remembered until the student ticks it. When the session ends, the
 * chat is wrapped up once: the quick model writes the session's summary and checkpoint from it, so the next
 * session's briefing starts from what happened without the student explaining again.
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
     * Writes what the chat came to into the session's record (its summary and checkpoint, §4.3), once, when the
     * session ends: the next session's briefing and the earlier-sessions look-up start from them, so the student
     * never explains again what happened. The quick model writes them from the chat (the folded part's summary
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
        $choices = $this->settings->get($by);
        $key = $this->settings->key($by);
        if (($key === null && ! $this->setup->keySet()) || $choices->quickOrTutor() === '' || $choices->consentedAt === null) {
            return null;
        }

        $unfolded = array_values(array_filter($rows, fn ($row) => (int) $row->position > (int) $thread->folded_through));
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What happened first (already summarised):\n".trim((string) $thread->summary)."\n\n---\n\n" : '';
        $text = $earlier.$this->asText($unfolded);
        if (mb_strlen($text) > self::WRAP_INPUT_CHARS) {
            $text = "[The chat's start is cut; this is its end.]\n".mb_substr($text, -self::WRAP_INPUT_CHARS);
        }
        try {
            $reply = $this->engine->reply(new Request(
                $choices->quickOrTutor(),
                'You write the record of one study session\'s chat between a student and their tutor, for the student\'s own study memory in ViStud. Answer with one JSON object and nothing else: {"summary": "...", "checkpoint": "..."}. summary: what was studied and explained, what the student got right or wrong, and what still confuses them, in plain prose of at most '.self::WRAP_SUMMARY_CHARS.' characters, no headings or lists. checkpoint: where the session stopped (like slide 7 of 18) and what comes next, in one or two sentences of at most '.self::WRAP_CHECKPOINT_CHARS.' characters. Write in the language the student wrote in. Only what is in the chat: never invent what wasn\'t said.',
                [['role' => 'user', 'content' => $text]],
                maxTokens: 900,
                noTraining: $choices->noTraining,
                key: $key,
            ));
        } catch (EngineFailed) {
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
        $transcript = $this->asText($folded);
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
     * Messages as a plain transcript for the quick model: the student's and the tutor's words, a look-up as a line.
     *
     * @param  list<object>  $rows
     */
    private function asText(array $rows): string
    {
        return implode("\n", array_map(fn ($row) => match ($row->role) {
            'tool' => '[Looked up '.$row->tool_name.': '.mb_substr((string) $row->content, 0, 300).']',
            'assistant' => 'Tutor: '.((string) $row->content !== '' ? $row->content : '(asked for a look-up)'),
            default => 'Student: '.$row->content,
        }, $rows));
    }
}
