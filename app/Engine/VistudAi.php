<?php

namespace App\Engine;

use App\Engine\Jobs\Run;
use App\Engine\Jobs\Runner;
use App\Engine\Tools\Context;
use App\Engine\Vistud\Overview;
use App\Engine\Vistud\VistudToolbox;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Fence;
use App\Study\Input;
use App\Study\Sessions;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * ViStud AI (the owner's ask, 2026-10-12): the student's own assistant above all their courses, on the main dashboard.
 * It is not a course's tutor: its own rules (resources/prompts/vistud-ai.md), its own conversations (engine_threads with
 * `purpose` vistud: no session, topic or course), its own tools (Vistud\VistudToolbox: across every course, and the
 * course tools with a course to name) and a fresh snapshot of the whole study each call (Vistud\Overview).
 *
 * Built for years of study: the snapshot is bounded, the tools filter and page, and a long conversation folds its oldest
 * turns into a summary as a session's chat does; the student keeps as many conversations as they like. It answers with
 * the student's tutor model (or the one picked in the chat); a conversation has the same limit as a session's chat.
 */
final class VistudAi
{
    public const PURPOSE = 'vistud';

    public const PROMPT = 'resources/prompts/vistud-ai.md';

    /** The conversations the list shows. */
    public const LIST = 30;

    public function __construct(
        private Engine $engine,
        private Settings $settings,
        private Models $models,
        private VistudToolbox $toolbox,
        private Overview $overview,
        private Sessions $sessions,
        private Runner $runner,
        private Usage $usage,
        private Attachments $attachments,
    ) {}

    /** Pictures uploaded with the message being answered, by ref, as data URLs: shown with that answer only, kept nowhere. */
    private array $shown = [];

    /**
     * The student's conversations, the latest first, with something in them; with $search, those whose title has it.
     *
     * @return list<array{id: string, title: string, updated_at: string}>
     */
    public function conversations(Principal $by, string $search = ''): array
    {
        $search = trim($search);

        return LearnerTables::query(Guard::learner($by), 'engine_threads')->where('purpose', self::PURPOSE)->where('turns', '>', 0)
            ->orderByDesc('updated_at')->orderBy('id')->get(['id', 'title', 'updated_at'])
            ->filter(fn ($row) => $search === '' || mb_stripos((string) $row->title, $search) !== false)->take(self::LIST)
            ->map(fn ($row) => ['id' => (string) $row->id, 'title' => (string) ($row->title ?: 'A conversation'), 'updated_at' => (string) $row->updated_at])->all();
    }

    /** A new conversation, named by its first words; nothing is sent yet. */
    public function start(Principal $by, mixed $text, ?string $model = null): string
    {
        $text = self::words($text);
        $choices = $this->ready($by);
        $id = Ids::new();
        $title = mb_strimwidth((string) preg_replace('/\s+/u', ' ', $text), 0, 80, '…');
        LearnerTables::insert(Guard::learner($by), 'engine_threads', [
            'id' => $id, 'purpose' => self::PURPOSE, 'title' => $title, 'model' => $model ?: $choices->tutorModel, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Sends the student's words in a conversation and returns the final answer; streamed to $onEvent (`text`, `looking`,
     * `saved`) as a session's chat is. Refused words keep nothing; once kept, a failing engine leaves them for retry().
     *
     * With them (the owner's ask, 2026-10-22): $attach, notes and files from any of the student's courses (`note:{id}`,
     * `file:{id}`), and $uploads, files uploaded with this message only (name and bytes, by key) and added to no course:
     * a document is read as text and kept with the message; a picture is shown with this answer only.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     * @param  array<string, array{name: string, bytes: string}>  $uploads
     */
    public function send(Principal $by, string $id, mixed $text, ?Closure $onEvent = null, ?string $model = null, mixed $attach = [], array $uploads = []): Reply
    {
        $scope = Guard::learner($by);
        $choices = $this->ready($by);
        $thread = $this->thread($scope, $id);
        $activeModel = ($model ?: (string) $thread->model) ?: $choices->tutorModel;
        [$items, $this->shown] = $this->attached($by, $attach, $uploads, $activeModel);
        // With something attached, the words may be left out.
        $text = $items === [] ? self::words($text) : mb_substr(trim(is_string($text) ? str_replace("\r\n", "\n", $text) : ''), 0, SessionChat::MAX_TEXT);
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text, 'attachments' => $items === [] ? null : json_encode($items)]);
        try {
            return $this->answer($by, $thread, $choices, $position, $onEvent, $model ?: (string) $thread->model);
        } finally {
            $this->shown = [];
        }
    }

    /**
     * What goes with a message: the chosen notes and files, then the uploads, at most Attachments::MOST in all; a picture
     * only to a model that sees pictures (one the catalogue doesn't know is given the benefit of the doubt).
     *
     * @param  array<string, array{name: string, bytes: string}>  $uploads
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function attached(Principal $by, mixed $attach, array $uploads, string $model): array
    {
        $refs = is_array($attach) ? array_values(array_unique(array_filter($attach, fn ($ref) => is_string($ref) && ! str_starts_with($ref, 'upload:')))) : [];
        Input::refuse(count($refs) + count($uploads) > Attachments::MOST ? ['attach' => 'Attach up to '.Attachments::MOST.' notes or files to one message.'] : []);
        $seeing = $this->models->find($model, $this->settings->credential($by));
        $items = $this->attachments->resolve($by, null, $refs, $seeing);
        $shown = [];
        foreach ($uploads as $key => $upload) {
            $made = Attachments::upload((string) $key, (string) ($upload['name'] ?? ''), (string) ($upload['bytes'] ?? ''));
            if ($made['url'] !== null) {
                Input::refuse($seeing !== null && ! $seeing->images ? ['attach' => 'This model can\'t see pictures. Choose one that can, or describe it in words.'] : []);
                $shown[$made['item']['ref']] = $made['url'];
            }
            $items[] = $made['item'];
        }

        return [$items, $shown];
    }

    /** @param (Closure(string, string): void)|null $onEvent */
    public function retry(Principal $by, string $id, ?Closure $onEvent = null): Reply
    {
        $scope = Guard::learner($by);
        $choices = $this->ready($by);
        $thread = $this->thread($scope, $id);
        if (! $this->waiting($by, $id)) {
            throw new Unprocessable('nothing_to_retry', 'There is nothing waiting for an answer.');
        }
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');

        return $this->answer($by, $thread, $choices, $position, $onEvent, (string) $thread->model);
    }

    /** Whether the last words are the student's (or a look-up's), with no answer after them. */
    public function waiting(Principal $by, string $id): bool
    {
        $last = LearnerTables::query(Guard::learner($by), 'engine_messages')->where('thread_id', $this->thread(Guard::learner($by), $id)->id)->orderByDesc('position')->first();

        return $last !== null && in_array($last->role, ['user', 'tool'], true);
    }

    public function model(Principal $by, string $id): ?string
    {
        return $this->thread(Guard::learner($by), $id)->model ?: null;
    }

    public function setModel(Principal $by, string $id, string $model): void
    {
        $scope = Guard::learner($by);
        LearnerTables::query($scope, 'engine_threads')->where('id', $this->thread($scope, $id)->id)->update(['model' => $model, 'updated_at' => now()]);
    }

    public function spent(Principal $by, string $id): int
    {
        return (int) $this->thread(Guard::learner($by), $id)->spent_micros;
    }

    /** Deletes a conversation and its messages (what it cost stays in the month's usage). */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $thread = $this->thread($scope, $id);
        DB::transaction(function () use ($scope, $thread) {
            Spent::forgetMessages($scope, [$thread->id]);
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->delete();
        });
    }

    /**
     * Empties a conversation (the owner's ask, 2026-10-22): its messages and their folded summary go, the conversation
     * stays to go on from, and what ViStud AI remembered about the student stays (memories aren't the chat's). What the
     * messages cost stays counted, as when a conversation is deleted.
     */
    public function clear(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $thread = $this->thread($scope, $id);
        DB::transaction(function () use ($scope, $thread) {
            Spent::forgetMessages($scope, [$thread->id]);
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['summary' => null, 'folded_through' => 0, 'updated_at' => now()]);
        });
    }

    /**
     * The conversation as the student reads it, in the shape the chat shows (App\Livewire\Workspaces\TutorChat): their
     * words and the answers, with the look-ups each answer made, what it remembered and what it cost.
     *
     * @return list<array<string, mixed>>
     */
    public function transcript(Principal $by, string $id): array
    {
        $scope = Guard::learner($by);
        $thread = $this->thread($scope, $id);
        $turns = [];
        [$tools, $cost, $remembered] = [[], 0, []];
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ($row->role === 'tool') {
                $effects = is_string($row->effects ?? null) ? (json_decode($row->effects, true) ?: []) : [];
                array_push($remembered, ...array_values(array_filter(is_array($effects['remembered'] ?? null) ? $effects['remembered'] : [], 'is_array')));

                continue;
            }
            $calls = array_map(fn ($c) => (string) ($c['function']['name'] ?? ''), is_string($row->tool_calls) ? (json_decode($row->tool_calls, true) ?: []) : []);
            if ($row->role === 'assistant' && trim((string) $row->content) === '' && $calls !== []) {
                [$tools, $cost] = [[...$tools, ...$calls], $cost + (int) $row->cost_micros];

                continue;
            }
            $mine = $row->role === 'assistant';
            $turns[] = [
                'role' => $row->role, 'text' => (string) $row->content,
                'attachments' => $row->role === 'user' ? array_map(fn (array $a) => ['ref' => (string) $a['ref'], 'name' => (string) $a['name'], 'kind' => (string) $a['kind'], 'course' => isset($a['course']) ? (string) $a['course'] : null], self::attachedTo($row)) : [],
                'tools' => $mine ? [...$tools, ...$calls] : [], 'saved' => [], 'notes' => [], 'topic' => null, 'statuses' => [], 'quizzes' => [],
                'remembered' => $mine ? $remembered : [],
                'cost_micros' => (int) $row->cost_micros + ($mine ? $cost : 0),
                'folded' => (int) $row->position <= (int) $thread->folded_through, 'at' => (string) $row->created_at,
            ];
            if ($mine) {
                [$tools, $cost, $remembered] = [[], 0, []];
            }
        }

        return $turns;
    }

    /** The rules, then the snapshot of the whole study, then what the conversation's folded part held. */
    public function system(Principal $by, ?string $folded = null): string
    {
        $rules = trim((string) preg_replace('/\A\s*<!--.*?-->\s*/s', '', (string) file_get_contents(base_path(self::PROMPT))));
        $parts = [$rules, "# Their study now\n\n".$this->overview->build($by)];
        if ($folded !== null && trim($folded) !== '') {
            $parts[] = "## Earlier in this conversation (folded to keep it short)\n\n".Fence::wrap(trim($folded));
        }

        return implode("\n\n", $parts);
    }

    private function answer(Principal $by, object $thread, Choices $choices, int $position, ?Closure $onEvent, string $activeModel): Reply
    {
        $scope = Guard::learner($by);
        $key = $this->settings->credential($by);
        $activeModel = $activeModel !== '' ? $activeModel : $choices->tutorModel;
        $model = $this->models->find($activeModel, $key);
        $tools = $model === null || $model->tools ? $this->toolbox->definitions() : [];
        $system = $this->system($by, $thread->summary);
        $messages = $this->messagesOf($by, $thread, $activeModel, $position);
        $context = new Context('', null, null, $this->sessions->timezone($by), threadId: $thread->id);
        $fallbacks = $choices->fallbackModel !== '' ? [$choices->fallbackModel] : [];
        $rounds = max(0, (int) config('vistud.engine.tool_rounds'));
        $reply = null;

        for ($round = 0; $round <= $rounds; $round++) {
            $request = new Request($activeModel, $system, $messages, $round < $rounds ? $tools : [], $fallbacks, maxTokens: 8000, noTraining: $choices->noTraining, key: $key);
            $reply = $onEvent === null ? $this->engine->reply($request) : $this->engine->stream($request, fn (string $words) => $onEvent('text', $words));
            $calls = array_map(fn (ToolCall $c) => $c->wire(), $reply->toolCalls);
            $this->keep($scope, $thread->id, ++$position, [
                'role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $calls === [] ? null : json_encode($calls), 'model' => $reply->model,
                'reasoning' => $reply->reasoning === [] ? null : json_encode($reply->reasoning),
                'tokens_in' => $reply->tokensIn, 'tokens_cached' => $reply->tokensCached, 'tokens_out' => $reply->tokensOut, 'cost_micros' => $reply->costMicros ?? 0,
            ]);
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['spent_micros' => DB::raw('spent_micros + '.(int) ($reply->costMicros ?? 0)), 'updated_at' => now()]);
            $messages[] = array_filter(['role' => 'assistant', 'content' => $reply->text !== '' ? $reply->text : null, 'tool_calls' => $calls ?: null, 'reasoning_details' => $reply->reasoning !== [] ? $reply->reasoning : null], fn ($v) => $v !== null);
            if (! $reply->wantsTools()) {
                break;
            }
            foreach ($reply->toolCalls as $call) {
                if ($onEvent !== null) {
                    $onEvent('looking', $call->name);
                }
                $out = $this->toolbox->run($by, $context->by($reply->model), $call->name, $call->arguments);
                $effects = $context->effects->take();
                $this->keep($scope, $thread->id, ++$position, ['role' => 'tool', 'content' => $out, 'tool_call_id' => $call->id, 'tool_name' => $call->name, 'effects' => $effects === [] ? null : json_encode($effects)]);
                if ($onEvent !== null && $effects !== []) {
                    $onEvent('saved', (string) json_encode($effects));
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $out];
            }
        }

        LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['turns' => DB::raw('turns + 1'), 'model' => $activeModel, 'updated_at' => now()]);
        $this->foldIfLong($by, $thread->id, SessionChat::historyRoom($model, $system, $tools));

        return $reply;
    }

    /** @return list<array<string, mixed>> the unfolded messages in the OpenAI chat shape; reasoning back to the model that gave it */
    private function messagesOf(Principal $by, object $thread, string $model, int $lastAsked): array
    {
        $scope = Guard::learner($by);
        $messages = [];
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ((int) $row->position <= (int) $thread->folded_through || ($row->role === 'assistant' && trim((string) $row->content) === '' && ! is_string($row->tool_calls))) {
                continue;
            }
            $reasoning = is_string($row->reasoning ?? null) && (string) $row->model === $model ? json_decode($row->reasoning, true) : null;
            $messages[] = match ($row->role) {
                'tool' => ['role' => 'tool', 'tool_call_id' => (string) $row->tool_call_id, 'content' => (string) $row->content],
                'assistant' => array_filter(['role' => 'assistant', 'content' => (string) $row->content !== '' ? (string) $row->content : null, 'tool_calls' => is_string($row->tool_calls) ? self::objectArguments(json_decode($row->tool_calls, true) ?: []) : null, 'reasoning_details' => is_array($reasoning) && $reasoning !== [] ? $reasoning : null], fn ($v) => $v !== null),
                // What the student attached goes with their words; a picture only with the message being answered.
                default => ['role' => 'user', 'content' => ($attached = self::attachedTo($row)) === [] ? (string) $row->content
                    : $this->attachments->content($by, (string) $row->content, $attached, (int) $row->position === $lastAsked, $this->shown)],
            };
        }

        return $messages;
    }

    /** Past a size, the oldest turns are summarised by the reader and kept only as that summary (as a session's chat). */
    /** @param  int  $room  the characters the unfolded chat may hold (SessionChat::historyRoom: less for a small model) */
    private function foldIfLong(Principal $by, string $threadId, int $room): void
    {
        $scope = Guard::learner($by);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('id', $threadId)->first();
        $rows = array_values(array_filter($this->rows($scope, $threadId), fn ($row) => (int) $row->position > (int) $thread->folded_through));
        if (array_sum(array_map(fn ($row) => mb_strlen((string) $row->content), $rows)) <= $room) {
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
            'assistant' => 'ViStud AI: '.((string) $row->content !== '' ? $row->content : '(asked for a look-up)'),
            default => 'Student: '.$row->content,
        }, $folded));
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What was already folded:\n".trim((string) $thread->summary)."\n\n---\n\n" : '';
        try {
            $reply = $this->runner->run($by, Role::Reader, 'fold', null, 'thread', $threadId, fn (Run $run) => $run->ask(
                'You summarise a conversation between a student and ViStud AI, their study assistant across all their courses, so it can go on later. Keep what they asked, what was found, decided and planned (with courses, topics and dates), and what they said about themselves. Ignore small talk. Plain text, at most '.SessionChat::SUMMARY_CHARS.' characters, no headings. Never invent anything not in the conversation.',
                $earlier.$transcript,
                800,
            ));
        } catch (EngineFailed|Unprocessable) {
            return;
        }
        $summary = mb_substr(trim($reply->text), 0, SessionChat::SUMMARY_CHARS * 2);
        if ($summary !== '') {
            LearnerTables::query($scope, 'engine_threads')->where('id', $threadId)->update([
                'summary' => $summary, 'folded_through' => (int) $folded[array_key_last($folded)]->position,
                'spent_micros' => DB::raw('spent_micros + '.(int) ($reply->costMicros ?? 0)), 'updated_at' => now(),
            ]);
        }
    }

    /** The student's choices, when the AI is set up (a model, consent and a key); refused otherwise. */
    private function ready(Principal $by): Choices
    {
        [$choices] = $this->settings->ready($by, Role::Tutor);

        return $choices;
    }

    private function refuseOverCap(LearnerScope $scope, object $thread, Choices $choices, string $zone): void
    {
        if ($choices->sessionCapMicros > 0 && (int) $thread->spent_micros >= $choices->sessionCapMicros) {
            throw new Unprocessable('engine_cap', 'This conversation has reached its limit of '.Choices::dollars($choices->sessionCapMicros).'. Start a new one, or raise the limit in your AI settings.');
        }
        $this->usage->refuseOverMonthCap($scope, $choices, $zone, chat: true);
    }

    private function thread(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'engine_threads')->where('id', $id)->where('purpose', self::PURPOSE)->first() ?? throw new NotFound;
    }

    /** @param array<string, mixed> $values */
    private function keep(LearnerScope $scope, string $threadId, int $position, array $values): void
    {
        LearnerTables::insert($scope, 'engine_messages', $values + ['id' => Ids::new(), 'thread_id' => $threadId, 'position' => $position, 'created_at' => now()]);
    }

    /** @return list<array<string, mixed>> what the student attached to a message */
    private static function attachedTo(object $row): array
    {
        $items = isset($row->attachments) && is_string($row->attachments) ? json_decode($row->attachments, true) : null;

        return is_array($items) ? array_values(array_filter($items, fn ($item) => is_array($item) && isset($item['ref'], $item['name'], $item['kind']))) : [];
    }

    /** @return list<object> */
    private function rows(LearnerScope $scope, string $threadId): array
    {
        return LearnerTables::query($scope, 'engine_messages')->where('thread_id', $threadId)->orderBy('position')->get()->all();
    }

    private static function words(mixed $text): string
    {
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        Input::refuse(match (true) {
            $text === '' => ['text' => 'Write something first.'],
            mb_strlen($text) > SessionChat::MAX_TEXT => ['text' => 'Keep a message to '.SessionChat::MAX_TEXT.' characters.'],
            default => [],
        });

        return $text;
    }

    private static function objectArguments(array $calls): array
    {
        foreach ($calls as &$call) {
            if (is_array($call) && isset($call['function']) && is_array($call['function']) && in_array($call['function']['arguments'] ?? null, ['[]', '', null], true)) {
                $call['function']['arguments'] = '{}';
            }
        }

        return $calls;
    }
}
