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
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Input;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** The table each kind the tools save is kept in, and whether a deleted one can be left marked so (retired_at). */
    private const SAVED_IN = [
        'flashcard' => ['flashcards', true],
        'finding' => ['findings', false],
        'question' => ['questions', true],
        'topic' => ['topics', true],
    ];

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
        private Topics $topics,
        private Activities $activities,
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
    public function send(Principal $by, string $sessionId, mixed $text, ?Closure $onEvent = null, mixed $attach = [], ?string $model = null): Reply
    {
        $scope = Guard::learner($by);
        [$session, $choices, $key] = $this->ready($by, $sessionId);
        $activeModel = $model && trim($model) !== '' ? trim($model) : $choices->tutorModel;
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        $items = $this->attachments->resolve($by, $session, $attach, $this->models->find($activeModel, $key));
        Input::refuse(match (true) {
            $text === '' && $items === [] => ['text' => 'Write something first.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep a message to '.self::MAX_TEXT.' characters.'],
            default => [],
        });

        $thread = $this->thread($scope, $session, $activeModel);
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text, 'attachments' => $items === [] ? null : json_encode($items)]);

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent, $activeModel);
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
        $activeModel = $thread->model ?: $choices->tutorModel;
        $this->refuseOverCap($scope, $thread, $choices, $this->sessions->timezone($by));
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent, $activeModel);
    }

    /** Sets or switches the active model for an open session thread. */
    public function setModel(Principal $by, string $sessionId, string $model): void
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $model = trim($model);
        if ($model === '') {
            return;
        }
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread !== null) {
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update([
                'model' => $model,
                'updated_at' => now(),
            ]);
        }
    }

    /** The model recorded on the session thread, or null if no thread yet. */
    public function threadModel(Principal $by, string $sessionId): ?string
    {
        $scope = Guard::learner($by);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();

        return $thread?->model;
    }

    /**
     * The tools a topic's chat may use (the owner's ask, 2026-10-12): looking things up in the course, working things
     * out, answering a question and saying where the student stands on the topic. Saving cards, key points and
     * questions belongs to a study session's write-back (the topic's page has its own); renaming, deleting and moving
     * topics, the session's topic and its note are a session's or the page's.
     */
    public const TOPIC_TOOLS = ['notes', 'read_note', 'search_notes', 'files', 'read_file', 'module_files', 'findings', 'questions', 'topics', 'earlier_sessions', 'calculate', 'plot_graph', 'answer_question', 'set_topic_status', 'remember', 'assignments', 'assignment_plan'];

    /**
     * The chat on a topic's own page (the owner's ask, 2026-10-12): the same tutor, model, look-ups, history and stream as a
     * study session's chat, about this one topic, with no session (no clock; its time is not studying it). Its
     * thread is the topic's; the tutor is told the course, the module, the student and the topic as in a session, and a
     * section of its own: keep to the topic, teach from the student's material, depth from where they stand.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     *
     * @throws Unprocessable not set up, over the month's limit, an empty message
     * @throws NotFound another student's topic
     */
    public function sendOnTopic(Principal $by, string $topicId, mixed $text, ?Closure $onEvent = null, mixed $attach = [], ?string $model = null): Reply
    {
        $scope = Guard::learner($by);
        $topic = $this->topics->find($by, $topicId);
        [$choices, $key] = $this->settings->ready($by, Role::Tutor);
        $activeModel = $model && trim($model) !== '' ? trim($model) : $choices->tutorModel;
        $session = self::topicSession($topic);
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        $items = $this->attachments->resolve($by, $session, $attach, $this->models->find($activeModel, $key));
        Input::refuse(match (true) {
            $text === '' && $items === [] => ['text' => 'Write something first.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep a message to '.self::MAX_TEXT.' characters.'],
            default => [],
        });
        $thread = $this->topicThread($scope, $topic, $activeModel);
        $this->usage->refuseOverMonthCap($scope, $choices, $this->sessions->timezone($by), chat: true);
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text, 'attachments' => $items === [] ? null : json_encode($items)]);

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent, $activeModel, $topic);
    }

    /** Answers a topic chat's last words again, after the engine failed on them. */
    public function retryOnTopic(Principal $by, string $topicId, ?Closure $onEvent = null): Reply
    {
        $scope = Guard::learner($by);
        $topic = $this->topics->find($by, $topicId);
        [$choices, $key] = $this->settings->ready($by, Role::Tutor);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('topic_id', $topic->id)->first();
        if ($thread === null || ! $this->unanswered($scope, $thread->id)) {
            throw new Unprocessable('nothing_to_retry', 'There is nothing to try again: the tutor has answered.');
        }
        $this->usage->refuseOverMonthCap($scope, $choices, $this->sessions->timezone($by), chat: true);
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');

        return $this->answer($by, self::topicSession($topic), $thread, $choices, $key, $position, $onEvent, $thread->model ?: $choices->tutorModel, $topic);
    }

    /** @return list<array<string, mixed>> a topic chat's turns, as a session's */
    public function topicTranscript(Principal $by, string $topicId): array
    {
        $topic = $this->topics->find($by, $topicId);

        return $this->turnsOf($by, LearnerTables::query(Guard::learner($by), 'engine_threads')->where('topic_id', $topic->id)->first());
    }

    public function topicWaiting(Principal $by, string $topicId): bool
    {
        $scope = Guard::learner($by);
        $topic = $this->topics->find($by, $topicId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('topic_id', $topic->id)->first();

        return $thread !== null && $this->unanswered($scope, $thread->id);
    }

    public function topicThreadModel(Principal $by, string $topicId): ?string
    {
        $topic = $this->topics->find($by, $topicId);

        return LearnerTables::query(Guard::learner($by), 'engine_threads')->where('topic_id', $topic->id)->value('model');
    }

    public function setTopicModel(Principal $by, string $topicId, string $model): void
    {
        $topic = $this->topics->find($by, $topicId);
        if (trim($model) !== '') {
            LearnerTables::query(Guard::learner($by), 'engine_threads')->where('topic_id', $topic->id)->update(['model' => trim($model), 'updated_at' => now()]);
        }
    }

    /** Starts a topic's chat afresh: its turns go (what they cost stays counted). */
    public function clearTopicChat(Principal $by, string $topicId): void
    {
        $scope = Guard::learner($by);
        $topic = $this->topics->find($by, $topicId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('topic_id', $topic->id)->first();
        if ($thread !== null) {
            Spent::forgetMessages($scope, [$thread->id]);
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['summary' => null, 'folded_through' => 0, 'turns' => 0, 'updated_at' => now()]);
        }
    }

    /**
     * The topic as a session for the tutor's context (App\Engine\Context\Stack): its course, module, folder and itself,
     * in free mode, with no clock. Never kept: a topic's chat is not a study session.
     */
    public static function topicSession(TopicDetails $topic): SessionDetails
    {
        $now = now()->toIso8601String();

        return new SessionDetails('topic-chat-'.$topic->id, $topic->workspaceId, $topic->moduleId, $topic->id, 'running', null, false, $now, null, $now, 0, 0, null, null,
            mode: 'free', folderId: $topic->folderId);
    }

    private function topicThread(LearnerScope $scope, TopicDetails $topic, string $model): object
    {
        $thread = LearnerTables::query($scope, 'engine_threads')->where('topic_id', $topic->id)->first();
        if ($thread !== null) {
            if ($model !== '' && $thread->model !== $model) {
                LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['model' => $model, 'updated_at' => now()]);
                $thread->model = $model;
            }

            return $thread;
        }
        $id = Ids::new();
        LearnerTables::insert($scope, 'engine_threads', ['id' => $id, 'workspace_id' => $topic->workspaceId, 'session_id' => null, 'topic_id' => $topic->id, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);

        return LearnerTables::query($scope, 'engine_threads')->where('id', $id)->first();
    }

    /**
     * The tools an assignment companion chat may use.
     */
    public const ASSIGNMENT_TOOLS = ['assignment_source', 'add_assignment_source', 'log_progress', 'set_stage', 'assignment_plan', 'assignments', 'notes', 'read_note', 'search_notes', 'files', 'read_file', 'module_files', 'topics', 'findings', 'questions', 'earlier_sessions', 'calculate', 'remember'];

    /**
     * Sends the student's words to the tutor for an assignment, and returns the engine's reply.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     *
     * @throws Unprocessable not set up, over the month's limit, an empty message
     * @throws NotFound another student's assignment
     */
    public function sendOnAssignment(Principal $by, string $activityId, mixed $text, ?Closure $onEvent = null, mixed $attach = [], ?string $model = null): Reply
    {
        $scope = Guard::learner($by);
        $activity = $this->activities->find($by, $activityId);
        [$choices, $key] = $this->settings->ready($by, Role::Tutor);
        $activeModel = $model && trim($model) !== '' ? trim($model) : $choices->tutorModel;
        $session = self::activitySession($activity);
        $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
        $items = $this->attachments->resolve($by, $session, $attach, $this->models->find($activeModel, $key));
        Input::refuse(match (true) {
            $text === '' && $items === [] => ['text' => 'Write something first.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep a message to '.self::MAX_TEXT.' characters.'],
            default => [],
        });
        $thread = $this->assignmentThread($scope, $activity, $activeModel);
        $this->usage->refuseOverMonthCap($scope, $choices, $this->sessions->timezone($by), chat: true);
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');
        $this->keep($scope, $thread->id, ++$position, ['role' => 'user', 'content' => $text, 'attachments' => $items === [] ? null : json_encode($items)]);

        return $this->answer($by, $session, $thread, $choices, $key, $position, $onEvent, $activeModel, activity: $activity);
    }

    /** Answers an assignment chat's last words again, after the engine failed on them. */
    public function retryOnAssignment(Principal $by, string $activityId, ?Closure $onEvent = null): Reply
    {
        $scope = Guard::learner($by);
        $activity = $this->activities->find($by, $activityId);
        [$choices, $key] = $this->settings->ready($by, Role::Tutor);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('activity_id', $activity->id)->first();
        if ($thread === null || ! $this->unanswered($scope, $thread->id)) {
            throw new Unprocessable('nothing_to_retry', 'There is nothing to try again: the tutor has answered.');
        }
        $this->usage->refuseOverMonthCap($scope, $choices, $this->sessions->timezone($by), chat: true);
        $position = (int) LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->max('position');

        return $this->answer($by, self::activitySession($activity), $thread, $choices, $key, $position, $onEvent, $thread->model ?: $choices->tutorModel, activity: $activity);
    }

    /** @return list<array<string, mixed>> an assignment chat's turns */
    public function assignmentTranscript(Principal $by, string $activityId): array
    {
        $activity = $this->activities->find($by, $activityId);

        return $this->turnsOf($by, LearnerTables::query(Guard::learner($by), 'engine_threads')->where('activity_id', $activity->id)->first());
    }

    public function assignmentWaiting(Principal $by, string $activityId): bool
    {
        $scope = Guard::learner($by);
        $activity = $this->activities->find($by, $activityId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('activity_id', $activity->id)->first();

        return $thread !== null && $this->unanswered($scope, $thread->id);
    }

    public function assignmentSpent(Principal $by, string $activityId): int
    {
        $activity = $this->activities->find($by, $activityId);

        return (int) LearnerTables::query(Guard::learner($by), 'engine_threads')->where('activity_id', $activity->id)->value('spent_micros');
    }

    public function assignmentThreadModel(Principal $by, string $activityId): ?string
    {
        $activity = $this->activities->find($by, $activityId);

        return LearnerTables::query(Guard::learner($by), 'engine_threads')->where('activity_id', $activity->id)->value('model');
    }

    public function setAssignmentModel(Principal $by, string $activityId, string $model): void
    {
        $activity = $this->activities->find($by, $activityId);
        if (trim($model) !== '') {
            LearnerTables::query(Guard::learner($by), 'engine_threads')->where('activity_id', $activity->id)->update(['model' => trim($model), 'updated_at' => now()]);
        }
    }

    /** Starts an assignment's chat afresh: its turns go (what they cost stays counted). */
    public function clearAssignmentChat(Principal $by, string $activityId): void
    {
        $scope = Guard::learner($by);
        $activity = $this->activities->find($by, $activityId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('activity_id', $activity->id)->first();
        if ($thread !== null) {
            Spent::forgetMessages($scope, [$thread->id]);
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['summary' => null, 'folded_through' => 0, 'turns' => 0, 'updated_at' => now()]);
        }
    }

    /**
     * The assignment as a session for the chat's tools: its course and module, in free mode, with no clock. Never in its
     * own folder: tools that default to a session's folder (topics, module_files) would see only the brief's folder and
     * miss the module's topics the assignment draws on. Its sources and files are reached with assignment_source and
     * read_file. Never kept.
     */
    public static function activitySession(ActivityDetails $activity): SessionDetails
    {
        $now = now()->toIso8601String();

        return new SessionDetails('assignment-chat-'.$activity->id, $activity->workspaceId, $activity->moduleId, null, 'running', null, false, $now, null, $now, 0, 0, null, null, mode: 'free');
    }

    public function assignmentThread(LearnerScope $scope, ActivityDetails $activity, string $model): object
    {
        $thread = LearnerTables::query($scope, 'engine_threads')->where('activity_id', $activity->id)->first();
        if ($thread !== null) {
            if ($model !== '' && $thread->model !== $model) {
                LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['model' => $model, 'updated_at' => now()]);
                $thread->model = $model;
            }

            return $thread;
        }
        try {
            LearnerTables::insert($scope, 'engine_threads', [
                'id' => Ids::new(),
                'workspace_id' => $activity->workspaceId,
                'session_id' => null,
                'topic_id' => null,
                'activity_id' => $activity->id,
                'purpose' => 'assignment',
                'model' => $model,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another first message started it a moment ago: that one is the assignment's chat.
        }

        return LearnerTables::query($scope, 'engine_threads')->where('activity_id', $activity->id)->first();
    }

    /** What the tutor is told of a topic's chat, after everything a session's chat is told. */
    private static function topicChat(TopicDetails $topic): string
    {
        $name = Topics::cleanMarkdown($topic->name);

        return implode("\n", [
            '## This chat',
            "This is the chat on the page of the topic \"{$name}\": the student opened this one topic to understand it. It is not a timed study session: there is no clock, and nothing here is saved to a session.",
            "- Keep to \"{$name}\" and what it needs underneath it. Asked about something else, answer in a line and bring it back, or say which topic covers it.",
            '- Teach from their material first: look up the topic\'s notes, files and key points before you explain, and say where an idea comes from. Fill gaps from what you know, and say so.',
            '- When they ask: the core concepts in the order to learn them; real-world examples that fit this course and its level; the common pitfalls and misconceptions, each with how to avoid it; practice questions at their level, one at a time, checked before the next.',
            '- Where they stand on it (above) sets the depth: not started, build from the ground up; confusing, find what is unclear first; understood, test and stretch it.',
            "- To say where they stand now, use set_topic_status with the topic \"{$name}\". Cards, key points and questions are saved from a study session, or on this page's tabs: say so if they ask.",
        ]);
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
     * Clears the chat conversation and memory context of a session:
     * removes all messages, resets memory summary, turns and wrapped status.
     */
    public function resetChat(Principal $by, string $sessionId): void
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        if ($thread === null) {
            return;
        }
        DB::transaction(function () use ($scope, $thread) {
            LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->delete();
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update([
                'summary' => null,
                'folded_through' => 0,
                'turns' => 0,
                'wrapped_at' => null,
                'spent_micros' => 0,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * The engine's turn: the standing context, the chat so far and the tools; look-ups run and sent back, a few rounds
     * at most; every message kept with its cost.
     *
     * @param  (Closure(string, string): void)|null  $onEvent
     */
    private function answer(Principal $by, SessionDetails $session, object $thread, Choices $choices, ?string $key, int $position, ?Closure $onEvent, ?string $overrideModel = null, ?TopicDetails $onTopic = null, ?ActivityDetails $activity = null): Reply
    {
        $activeModel = $overrideModel && trim($overrideModel) !== '' ? trim($overrideModel) : $choices->tutorModel;
        $scope = Guard::learner($by);
        $model = $this->models->find($activeModel, $key);
        $withTools = $model === null || $model->tools;
        if ($activity !== null) {
            $built = $this->stack->forAssignment($by, $activity, $thread->summary, $withTools);
            [$system, $tools] = [$built->system, $built->tools];
            $toolbox = $this->toolbox->only(self::ASSIGNMENT_TOOLS);
        } else {
            $built = $this->stack->build($by, $session, $thread->summary, $withTools);
            [$system, $tools] = [$built->system, $built->tools];
            $toolbox = $this->toolbox;
            if ($onTopic !== null) {
                $system .= "\n\n".self::topicChat($onTopic);
                $tools = array_values(array_filter($tools, fn (array $tool) => in_array($tool['function']['name'] ?? '', self::TOPIC_TOOLS, true)));
                $toolbox = $this->toolbox->only(self::TOPIC_TOOLS);
            }
        }
        $messages = $this->messagesOf($by, $thread, $model === null || $model->images, $activeModel);
        $context = new Context($session->workspaceId, $session->moduleId, $onTopic === null && $activity === null ? $session->id : null, $this->sessions->timezone($by), folderId: $session->folderId, threadId: $thread->id, activityId: $activity?->id);
        $fallbacks = $choices->fallbackModel !== '' ? [$choices->fallbackModel] : [];
        $rounds = max(0, (int) config('vistud.engine.tool_rounds'));
        $reply = null;

        for ($round = 0; $round <= $rounds; $round++) {
            // Room for a model that thinks before it answers: its thinking counts against the limit.
            $request = new Request($activeModel, $system, $messages, $round < $rounds ? $tools : [], $fallbacks, maxTokens: 8000, noTraining: $choices->noTraining, key: $key);
            $reply = $onEvent === null ? $this->engine->reply($request) : $this->engine->stream($request, fn (string $words) => $onEvent('text', $words));
            // As the service sent them, with anything it wants back (App\Engine\ToolCall::wire); kept so a later turn sends it too.
            $calls = array_map(fn (ToolCall $c) => $c->wire(), $reply->toolCalls);
            $this->keep($scope, $thread->id, ++$position, [
                'role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $calls === [] ? null : json_encode($calls), 'model' => $reply->model,
                'reasoning' => $reply->reasoning === [] ? null : json_encode($reply->reasoning),
                'tokens_in' => $reply->tokensIn, 'tokens_out' => $reply->tokensOut, 'cost_micros' => $reply->costMicros ?? 0,
            ]);
            // Counted round by round, so a turn the engine fails on later still counts what it cost.
            LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['spent_micros' => DB::raw('spent_micros + '.(int) ($reply->costMicros ?? 0)), 'updated_at' => now()]);
            $messages[] = array_filter(['role' => 'assistant', 'content' => $reply->text !== '' ? $reply->text : null, 'tool_calls' => $calls ?: null, 'reasoning_details' => $reply->reasoning !== [] && self::sameModel($reply->model, $activeModel) ? $reply->reasoning : null], fn ($v) => $v !== null);
            if (! $reply->wantsTools()) {
                break;
            }
            foreach ($reply->toolCalls as $call) {
                if ($onEvent !== null) {
                    $onEvent('looking', $call->name);
                }
                $out = $toolbox->run($by, $context->by($reply->model), $call->name, $call->arguments);
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
            'turns' => DB::raw('turns + 1'), 'model' => $activeModel, 'updated_at' => now(),
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

        return $this->turnsOf($by, LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first());
    }

    /**
     * A thread's turns as the student reads them (a session's chat, or a topic's).
     *
     * @return list<array<string, mixed>>
     */
    private function turnsOf(Principal $by, ?object $thread): array
    {
        $scope = Guard::learner($by);
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
        $pendingStatuses = [];
        $pendingQuizzes = [];
        $pendingRemembered = [];
        $pendingSourcesKept = [];
        $pendingLogEntries = [];
        $pendingStagesChanged = [];
        $rows = $this->rows($scope, $thread->id);
        $changes = $this->topicChangesBefore($by, $thread, $rows);
        foreach ($rows as $row) {
            if ($row->role === 'tool') {
                $effects = isset($row->effects) && is_string($row->effects) ? (json_decode($row->effects, true) ?: []) : [];
                // What the tutor saved is said as what is still there: a card deleted since is no longer "saved" (an older
                // turn named no ids, so its count stands).
                $ids = is_array($effects['saved_ids'] ?? null) ? $effects['saved_ids'] : [];
                foreach (is_array($effects['saved'] ?? null) ? $effects['saved'] : [] as $kind => $count) {
                    $count = is_array($ids[$kind] ?? null) ? $this->stillThere($scope, (string) $kind, array_values(array_unique(array_map('strval', $ids[$kind])))) : (int) $count;
                    if ($count > 0) {
                        $pendingSaved[$kind] = ($pendingSaved[$kind] ?? 0) + $count;
                    }
                }
                foreach (is_array($effects['notes'] ?? null) ? $effects['notes'] : [] as $note) {
                    $pendingNotes[(string) $note['id']] = ['id' => (string) $note['id'], 'title' => (string) $note['title']];
                }
                $pendingTopic = is_string($effects['topic'] ?? null) ? $effects['topic'] : $pendingTopic;
                array_push($pendingStatuses, ...array_values(array_filter(is_array($effects['statuses'] ?? null) ? $effects['statuses'] : [], 'is_array')));
                array_push($pendingQuizzes, ...array_values(array_filter(is_array($effects['quizzes'] ?? null) ? $effects['quizzes'] : [], 'is_array')));
                array_push($pendingRemembered, ...array_values(array_filter(is_array($effects['remembered'] ?? null) ? $effects['remembered'] : [], 'is_array')));
                array_push($pendingSourcesKept, ...array_values(array_filter(is_array($effects['sources_kept'] ?? null) ? $effects['sources_kept'] : [], 'is_array')));
                array_push($pendingLogEntries, ...array_values(array_filter(is_array($effects['log_entries'] ?? null) ? $effects['log_entries'] : [], 'is_array')));
                array_push($pendingStagesChanged, ...array_values(array_filter(is_array($effects['stages_changed'] ?? null) ? $effects['stages_changed'] : [], 'is_array')));

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
                'statuses' => $row->role === 'assistant' ? $pendingStatuses : [],
                'quizzes' => $row->role === 'assistant' ? $pendingQuizzes : [],
                'remembered' => $row->role === 'assistant' ? $pendingRemembered : [],
                'sources_kept' => $row->role === 'assistant' ? $pendingSourcesKept : [],
                'log_entries' => $row->role === 'assistant' ? $pendingLogEntries : [],
                'stages_changed' => $row->role === 'assistant' ? $pendingStagesChanged : [],
                // The student's own topic change on the session page, just before these words of theirs.
                'topicChange' => $changes[(int) $row->position] ?? null,
                'cost_micros' => (int) $row->cost_micros + ($row->role === 'assistant' ? $pendingCost : 0),
                'folded' => (int) $row->position <= (int) $thread->folded_through,
                'at' => (string) $row->created_at,
            ];
            if ($row->role === 'assistant') {
                [$pendingTools, $pendingCost, $pendingSaved, $pendingNotes, $pendingTopic, $pendingStatuses, $pendingQuizzes, $pendingRemembered, $pendingSourcesKept, $pendingLogEntries, $pendingStagesChanged] = [[], 0, [], [], null, [], [], [], [], [], []];
            }
        }
        if ($pendingTools !== []) {
            // A turn that ended in look-ups without an answer (the engine failed after them).
            $turns[] = ['role' => 'assistant', 'text' => '', 'attachments' => [], 'tools' => $pendingTools, 'saved' => $pendingSaved, 'notes' => array_values($pendingNotes), 'topic' => $pendingTopic, 'statuses' => $pendingStatuses, 'quizzes' => $pendingQuizzes, 'remembered' => $pendingRemembered, 'sources_kept' => $pendingSourcesKept, 'log_entries' => $pendingLogEntries, 'stages_changed' => $pendingStagesChanged, 'cost_micros' => $pendingCost, 'folded' => false, 'at' => ''];
        }

        return $turns;
    }

    /**
     * What the tutor did in the course during this session, from what its tools reported: how much it saved, the
     * topics it set, and the status it set or proposed for each topic (the last word on each). For the session's rail
     * and its end screen.
     *
     * @return array{saved: array<string, int>, topics: list<string>, statuses: array<string, array<string, mixed>>, quizzes: int}
     */
    public function activity(Principal $by, string $sessionId): array
    {
        $this->sessions->find($by, $sessionId);

        return $this->activityOf($by, LearnerTables::query(Guard::learner($by), 'engine_threads')->where('session_id', $sessionId)->first());
    }

    /** What a topic's chat did in the course (as activity() says it for a session's). */
    public function topicActivity(Principal $by, string $topicId): array
    {
        $topic = $this->topics->find($by, $topicId);

        return $this->activityOf($by, LearnerTables::query(Guard::learner($by), 'engine_threads')->where('topic_id', $topic->id)->first());
    }

    /** What a topic's chat has cost, all together. */
    public function topicSpent(Principal $by, string $topicId): int
    {
        $topic = $this->topics->find($by, $topicId);

        return (int) LearnerTables::query(Guard::learner($by), 'engine_threads')->where('topic_id', $topic->id)->value('spent_micros');
    }

    private function activityOf(Principal $by, ?object $thread): array
    {
        $scope = Guard::learner($by);
        $out = ['saved' => [], 'topics' => [], 'statuses' => [], 'quizzes' => 0];
        if ($thread === null) {
            return $out;
        }
        // What was made is counted while it is still there: by id, from what the tools named; an older turn named no
        // ids, so its count stands.
        $made = [];
        $quizIds = [];
        foreach ($this->rows($scope, $thread->id) as $row) {
            if ($row->role !== 'tool' || ! isset($row->effects) || ! is_string($row->effects)) {
                continue;
            }
            $effects = json_decode($row->effects, true) ?: [];
            $ids = is_array($effects['saved_ids'] ?? null) ? $effects['saved_ids'] : [];
            foreach (is_array($effects['saved'] ?? null) ? $effects['saved'] : [] as $kind => $count) {
                if (is_array($ids[$kind] ?? null)) {
                    $made[$kind] = [...$made[$kind] ?? [], ...array_map('strval', $ids[$kind])];
                } else {
                    $out['saved'][$kind] = ($out['saved'][$kind] ?? 0) + (int) $count;
                }
            }
            if (is_string($effects['topic_id'] ?? null)) {
                $out['topics'][] = $effects['topic_id'];
            }
            foreach (is_array($effects['statuses'] ?? null) ? $effects['statuses'] : [] as $status) {
                if (is_array($status) && is_string($status['topic_id'] ?? null)) {
                    $out['topics'][] = $status['topic_id'];
                    $out['statuses'][$status['topic_id']] = $status;
                }
            }
            // A quiz or test is counted while it is still there (each record names it by id).
            foreach (is_array($effects['quizzes'] ?? null) ? $effects['quizzes'] : [] as $quiz) {
                is_array($quiz) && is_string($quiz['id'] ?? null) ? $quizIds[] = $quiz['id'] : $out['quizzes']++;
            }
        }
        if ($quizIds !== []) {
            $out['quizzes'] += LearnerTables::query($scope, 'quizzes')->whereIn('id', array_values(array_unique($quizIds)))->count();
        }
        foreach ($made as $kind => $ids) {
            $live = $this->stillThere($scope, $kind, array_values(array_unique($ids)));
            if ($live > 0) {
                $out['saved'][$kind] = ($out['saved'][$kind] ?? 0) + $live;
            }
        }
        // A session's cards say which session and author made them: counted from the deck, older turns included.
        if (($thread->session_id ?? null) !== null) {
            $cards = LearnerTables::query($scope, 'flashcards')->where('session_id', $thread->session_id)->where('author', 'ai')->whereNull('retired_at')->count();
            unset($out['saved']['flashcard']);
            if ($cards > 0) {
                $out['saved']['flashcard'] = $cards;
            }
        }
        $out['topics'] = array_values(array_unique($out['topics']));

        return $out;
    }

    /**
     * How many of these are still there (not deleted since).
     *
     * @param  list<string>  $ids
     */
    private function stillThere(LearnerScope $scope, string $kind, array $ids): int
    {
        [$table, $marked] = self::SAVED_IN[$kind] ?? [null, false];
        if ($table === null || $ids === []) {
            return 0;
        }
        $query = LearnerTables::query($scope, $table)->whereIn('id', $ids);

        return ($marked ? $query->whereNull('retired_at') : $query)->count();
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
        $firstUserText = mb_strtolower(trim((string) reset($asked)->content));
        if (count($asked) === 1 && in_array($firstUserText, ['hi', 'hello', 'hey', 'yo', 'test'], true)) {
            // Trivial greeting-only exchange: skip wrap-up LLM run to avoid token waste and hallucinated summaries.
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
                'You write the record of one study session\'s chat between a student and their tutor, for the student\'s own study memory in ViStud. Answer with one JSON object and nothing else: {"summary": "...", "checkpoint": "..."}. '
                .'summary: what was studied and explained, what the student got right or wrong, and what still confuses them, in plain prose of at most '.self::WRAP_SUMMARY_CHARS.' characters, no headings or lists. '
                .'checkpoint: the concept, section or topic reached and what comes next (never mention page or slide numbers; mentioning the file name if applicable is enough), in one or two sentences of at most '.self::WRAP_CHECKPOINT_CHARS.' characters. '
                .'Write in the language the student wrote in. Only what is in the chat: strictly never invent what wasn\'t said, and do not speculate. '
                .'Ignore greetings, pleasantries, small talk, questions about AI models or the interface, and quickly self-corrected typos. '
                .'If no real study happened, return empty strings: {"summary": "", "checkpoint": ""}.',
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
            if ($model !== '' && $thread->model !== $model) {
                LearnerTables::query($scope, 'engine_threads')->where('id', $thread->id)->update(['model' => $model, 'updated_at' => now()]);
                $thread->model = $model;
            }

            return $thread;
        }
        $id = Ids::new();
        LearnerTables::insert($scope, 'engine_threads', ['id' => $id, 'workspace_id' => $session->workspaceId, 'session_id' => $session->id, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);

        return LearnerTables::query($scope, 'engine_threads')->where('id', $id)->first();
    }

    private function refuseOverCap(LearnerScope $scope, object $thread, Choices $choices, string $zone): void
    {
        if ($choices->sessionCapMicros > 0 && (int) $thread->spent_micros >= $choices->sessionCapMicros) {
            throw new Unprocessable('engine_cap', 'This session\'s chat has reached its limit of '.Choices::dollars($choices->sessionCapMicros).'. Raise it in your AI settings to keep going.');
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
        $changes = $this->topicChangesBefore($by, $thread, $rows);
        $messages = [];
        foreach ($rows as $row) {
            if ((int) $row->position <= (int) $thread->folded_through) {
                continue;
            }
            $row = self::withTopicNote($row, $changes);
            $attached = self::attached($row);
            if ($row->role === 'assistant' && trim((string) $row->content) === '' && ! is_string($row->tool_calls)) {
                continue;
            }
            $reasoning = isset($row->reasoning) && is_string($row->reasoning) && self::sameModel((string) $row->model, $model) ? json_decode($row->reasoning, true) : null;
            $messages[] = match ($row->role) {
                'tool' => ['role' => 'tool', 'tool_call_id' => (string) $row->tool_call_id, 'content' => (string) $row->content],
                'assistant' => array_filter(['role' => 'assistant', 'content' => (string) $row->content !== '' ? (string) $row->content : null, 'tool_calls' => is_string($row->tool_calls) ? self::objectArguments(json_decode($row->tool_calls, true) ?: []) : null, 'reasoning_details' => is_array($reasoning) && $reasoning !== [] ? $reasoning : null], fn ($v) => $v !== null),
                default => ['role' => 'user', 'content' => $attached === [] ? (string) $row->content : $this->attachments->content($by, (string) $row->content, $attached, $see && (int) $row->position === $lastAsked)],
            };
        }

        return $messages;
    }

    /**
     * The topic changes the student made on the session page (the tutor's own are in the chat as its set_topic calls),
     * each under the first message of theirs after it, by its position: the last change before that message, when
     * there were several. Changes before the chat's first message only set it up (the standing context says the topic
     * now); a topic's chat has none.
     *
     * @param  list<object>  $rows
     * @return array<int, array{topic: ?string, name: ?string, by: string, at: string}>
     */
    private function topicChangesBefore(Principal $by, object $thread, array $rows): array
    {
        if (($thread->session_id ?? null) === null) {
            return [];
        }
        $log = $this->studentTopicChanges($by, (string) $thread->session_id);
        $under = [];
        $next = 0;
        $first = true;
        foreach ($rows as $row) {
            if ($row->role !== 'user') {
                continue;
            }
            $asked = CarbonImmutable::parse((string) $row->created_at, 'UTC');
            while ($next < count($log) && CarbonImmutable::parse($log[$next]['at'])->lessThanOrEqualTo($asked)) {
                if (! $first) {
                    $under[(int) $row->position] = $log[$next];
                }
                $next++;
            }
            $first = false;
        }

        return $under;
    }

    /** @return list<array{topic: ?string, name: ?string, by: string, at: string}> */
    private function studentTopicChanges(Principal $by, string $sessionId): array
    {
        return array_values(array_filter($this->sessions->topicLog($by, $sessionId), fn (array $change) => $change['by'] === 'student'));
    }

    /**
     * The student's topic change since their last message (null when there is none, or the chat hasn't begun), for
     * the chat to show after it until they write again.
     *
     * @return array{topic: ?string, name: ?string, by: string, at: string}|null
     */
    public function topicChangeSinceLastMessage(Principal $by, string $sessionId): ?array
    {
        $scope = Guard::learner($by);
        $log = $this->studentTopicChanges($by, $sessionId);
        if ($log === []) {
            return null;
        }
        $last = $log[array_key_last($log)];
        $thread = LearnerTables::query($scope, 'engine_threads')->where('session_id', $sessionId)->first();
        $asked = $thread === null ? null : LearnerTables::query($scope, 'engine_messages')->where('thread_id', $thread->id)->where('role', 'user')->max('created_at');

        return $asked !== null && CarbonImmutable::parse($last['at'])->greaterThan(CarbonImmutable::parse((string) $asked, 'UTC')) ? $last : null;
    }

    /**
     * The student's message with what the app tells the tutor before their words, when they changed the topic on the
     * session page just before it (a copy; the kept message stays as they wrote it).
     *
     * @param  array<int, array{topic: ?string, name: ?string, by: string, at: string}>  $changes
     */
    private static function withTopicNote(object $row, array $changes): object
    {
        $change = $changes[(int) $row->position] ?? null;
        if ($change === null) {
            return $row;
        }
        $note = $change['topic'] === null
            ? '(From the app, not the student\'s words: on the session page the student took the topic off this session, so it has none now.)'
            : "(From the app, not the student's words: on the session page the student moved this session to the topic \"{$change['name']}\". Teach that topic from here.)";
        $row = clone $row;
        $row->content = trim($note."\n\n".$row->content);

        return $row;
    }

    /**
     * Kept tool calls with their arguments as an object, even with none (older rows kept an empty list, which a
     * service refuses).
     *
     * @param  list<array<string, mixed>>  $calls
     * @return list<array<string, mixed>>
     */
    /**
     * Whether the model that answered is the one asked: a service may name it a little differently ("models/…", a
     * dated version, or with its maker in front), and reasoning goes back only to the model that wrote it.
     */
    private static function sameModel(string $said, string $asked): bool
    {
        $bare = fn (string $id) => strtolower((string) preg_replace('#^models/#', '', $id));
        [$said, $asked] = [$bare($said), $bare($asked)];

        return $said === $asked || str_ends_with($said, '/'.$asked) || str_ends_with($asked, '/'.$said)
            || ($asked !== '' && str_starts_with($said, $asked.'-') && preg_match('/-\d{4}-?\d{2}-?\d{2}$|-\d{4}$/', $said) === 1);
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
        $changes = $this->topicChangesBefore($by, $thread, $this->rows($scope, $threadId));
        $transcript = $this->asText(array_map(fn (object $row) => self::withTopicNote($row, $changes), $folded));
        $earlier = $thread->summary !== null && trim((string) $thread->summary) !== '' ? "What was already folded:\n".trim((string) $thread->summary)."\n\n---\n\n" : '';

        try {
            $reply = $this->runner->run($by, Role::Reader, 'fold', $workspaceId, 'thread', $threadId, fn (Run $run) => $run->ask(
                'You summarise one study session\'s chat between a student and their tutor, so the tutor can go on with it later. Keep only what was explained, how far the student got, what they got right or wrong, and what still confuses them. Ignore greetings, small talk, app/UI/model comments, and fleeting typos. Plain text, at most '.self::SUMMARY_CHARS.' characters, no headings. Never invent facts not in the chat.',
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
        if (! is_array($json) && preg_match('/\{[\s\S]*\}/', $clean, $match)) {
            $candidate = json_decode($match[0], true);
            if (is_array($candidate)) {
                $json = $candidate;
            }
        }
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
