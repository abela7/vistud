<?php

namespace App\Livewire\Workspaces;

use App\Appearance\Icons;
use App\Engine\Attachments;
use App\Engine\ChatMarks;
use App\Engine\Choices;
use App\Engine\EngineFailed;
use App\Engine\Models;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\SuggestedReply;
use App\Engine\Toolbox;
use App\Engine\Troubles;
use App\Engine\VistudAi;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Files\UploadTemps;
use App\Study\Activities;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Files;
use App\Study\FileTypes;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\MarkdownPreview;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Plugins;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The built-in chat on a study session's page (docs/specs/study-memory.md §6): the student's turns and the
 * tutor's, the look-ups each answer made and what the chat has cost against the limit, a box to write in, and
 * a way to keep what the tutor marked (the write-back's review, opened on the chat's replies). Notes and files
 * from the module (the session's chosen material first; the folder's, in a folder's session) can be attached to a
 * message, and a file or a picture uploaded, pasted or dropped into the box goes into the "From the chat" folder of
 * the session's folder, or else of its module, first. The answer
 * streams in as the tutor writes it, with what it is looking up meanwhile; a turn the engine failed on can be
 * tried again. A thin adapter over App\Engine\SessionChat; the engine's refusals show as a line under the box.
 */
final class TutorChat extends Component
{
    use WithFileUploads;

    /**
     * ViStud AI's uploads waiting to go with the next message, by the key the page gave them (`upload:{key}`): read with
     * that message and kept in no course (the owner's ask, 2026-10-22).
     *
     * @var array<string, TemporaryUploadedFile>
     */
    public array $uploads = [];

    public const SUGGESTIONS = [
        'Where did we stop last time, and what should we do today?',
        'Explain this topic to me simply, one part at a time.',
        'Quiz me on what I should know by now.',
    ];

    /** What the tutor is asked when files are handed over for it to start, or to pick up, the conversation. */
    public const REVIEW = 'Review what I uploaded. Tell me what this assignment is asking, and the one thing still missing.';

    public const REVIEW_MORE = 'I added another file. Review it and continue from where we are.';

    public const ASSIGNMENT_STARTERS = [
        ['title' => 'Here is what I was given', 'hint' => 'Drop a file in the box, or paste what you were told', 'icon' => 'paperclip', 'prompt' => 'I am adding what I was given. Ask me for anything still missing, one thing at a time, and update your reading whenever I add a file.'],
        ['title' => 'Build my step-by-step plan & tracking', 'hint' => 'Confirm understanding, make the roadmap, and pick how to track progress', 'icon' => 'list-todo', 'prompt' => 'This understanding is right. Let\'s build my step-by-step plan and agree on how we will track my progress (by steps, marking criteria, or both), one step at a time.'],
        ['title' => 'Guide me through the next step', 'hint' => 'Work through the current step in my plan one piece at a time', 'icon' => 'compass', 'prompt' => 'Look at my plan and where I stand on this assignment, and guide me through the next step one piece at a time.'],
        ['title' => 'Explain the assignment to me', 'hint' => 'In plain words from what you were given', 'icon' => 'sparkles', 'prompt' => 'Explain the assignment to me'],
        ['title' => 'What will the teacher look for?', 'hint' => 'Criteria and expectations', 'icon' => 'eye', 'prompt' => 'What will the teacher look for?'],
        ['title' => 'What should I ask my teacher?', 'hint' => 'Gaps and unclear requirements', 'icon' => 'message-square-text', 'prompt' => 'What should I ask my teacher?'],
        ['title' => 'Where do I start?', 'hint' => 'Break down the first steps', 'icon' => 'list-todo', 'prompt' => 'Where do I start?'],
        ['title' => 'Check that I\'ve understood it', 'hint' => 'Confirm your understanding', 'icon' => 'clipboard-check', 'prompt' => 'Check that I\'ve understood it'],
    ];

    /** The chips above the box: one tap asks the tutor for the thing, so nothing is typed. */
    public const ACTIONS = [
        'cards' => ['Cards', 'gallery-vertical-end', 'Make flashcards of what we just covered.'],
        'note' => ['Note this', 'notebook-pen', 'Write what we just covered in my study note.'],
        'plot' => ['Plot graph', 'trending-up', 'Plot a graph for this function or equation.'],
        'where' => ['Where are we', 'flag', 'Where are we? What is done and what is left?'],
    ];

    /** A chip only for a subject that needs it: shown while its study plugin is on (a law student has no graphs to plot). */
    public const ACTION_PLUGINS = [
        'plot' => 'math_physics',
    ];

    public const TEST_ASK = 'Test me on this whole module: ten exam-level questions, one at a time, and score me at the end.';

    /** How often the answer so far is sent to the browser while it streams, in seconds. */
    private const EVERY = 0.08;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $sessionId = '';

    /**
     * On a topic's own page (the owner's ask, 2026-10-12): the same chat about this one topic, with no session
     * (App\Engine\SessionChat::sendOnTopic). Null in a study session.
     */
    #[Locked]
    public ?string $topicId = null;

    /**
     * An assignment's companion chat.
     */
    #[Locked]
    public ?string $activityId = null;

    /** A file review is asked once per visit, so opening the chat does not send it twice. */
    #[Locked]
    public bool $reviewed = false;

    /**
     * ViStud AI's chat (App\Engine\VistudAi) on the main dashboard, above every course: the same chat, another AI. Its
     * conversation, once it has one; null until the first words are sent.
     */
    #[Locked]
    public bool $vistud = false;

    #[Locked]
    public ?string $conversationId = null;

    public string $text = '';

    public ?string $activeModel = null;

    #[Locked]
    public ?string $error = null;

    public bool $marksDismissed = false;

    public ?string $dismissedMarkFingerprint = null;

    private SessionChat $chat;

    private Settings $settings;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    private ChatStream $stream;

    private Notes $notes;

    private Files $files;

    private Topics $topics;

    private Models $models;

    private Modules $modules;

    private Folders $folders;

    private TutorMemories $memories;

    private Activities $activities;

    public function boot(SessionChat $chat, Settings $settings, Sessions $sessions, PrincipalFactory $principals, ChatStream $stream, Notes $notes, Files $files, Topics $topics, Models $models, Modules $modules, Folders $folders, TutorMemories $memories, Activities $activities): void
    {
        [$this->notes, $this->files, $this->topics, $this->models, $this->modules, $this->folders, $this->memories, $this->activities] = [$notes, $files, $topics, $models, $modules, $folders, $memories, $activities];
        $this->chat = $chat;
        $this->settings = $settings;
        $this->sessions = $sessions;
        $this->principals = $principals;
        $this->stream = $stream;
    }

    public function mount(string $workspaceId = '', string $sessionId = '', ?string $topicId = null, bool $vistud = false, ?string $conversationId = null, ?string $activityId = null): void
    {
        [$this->workspaceId, $this->sessionId, $this->topicId, $this->activityId] = [$workspaceId, $sessionId, $topicId, $activityId];
        if ($activityId !== null) {
            $by = $this->principal();
            $activity = $this->activities->find($by, $activityId);
            $activity->workspaceId === $workspaceId || throw new NotFound;
            $this->activeModel = $this->chat->assignmentThreadModel($by, $activityId) ?: $this->settings->get($by)->tutorModel;
            $say = trim((string) request()->query('say'));
            if ($say !== '' && $this->chat->assignmentTranscript($by, $activityId) === []) {
                $this->text = mb_substr($say, 0, 500);
            }

            return;
        }
        if ($vistud) {
            $by = $this->principal();
            [$this->vistud, $this->conversationId] = [true, $conversationId];
            $this->activeModel = ($conversationId !== null ? app(VistudAi::class)->model($by, $conversationId) : null) ?: $this->settings->get($by)->tutorModel;

            return;
        }
        if ($topicId !== null) {
            $by = $this->principal();
            $topic = $this->topics->find($by, $topicId);
            $topic->workspaceId === $workspaceId || throw new NotFound;
            $this->activeModel = $this->chat->topicThreadModel($by, $topicId) ?: $this->settings->get($by)->tutorModel;

            return;
        }
        // A session that ended without its wrap-up (it ended by itself, or the engine was down) gets it once, here.
        $by = $this->principal();
        $session = $this->sessions->find($by, $sessionId);
        $choices = $this->settings->get($by);
        $this->activeModel = $this->chat->threadModel($by, $sessionId) ?: $choices->tutorModel;
        // A quiz or a test starts with its ask waiting in the message box, for the student to send (Study this ▾ on the
        // module page, or `?ask=`): the tutor asks nothing before they do.
        $ask = request()->query('ask');
        $ask = in_array($ask, ['quiz', 'test'], true) ? $ask : (in_array($session->mode, ['quiz', 'test'], true) && $this->chat->transcript($by, $sessionId) === [] ? $session->mode : null);
        // A question taken to the tutor (✦ on a question): free mode, with the question waiting in the box.
        $say = trim((string) request()->query('say'));
        if ($session->isOpen() && request()->query('ask') === 'free' && $session->mode === 'free' && $say !== '' && $this->chat->transcript($by, $sessionId) === []) {
            $this->text = mb_substr($say, 0, 500);
        }
        if ($session->isOpen() && $ask !== null) {
            $this->text = $ask === 'quiz'
                ? ($this->quizzes($by, $session)[0]['text'] ?? 'Quiz me on what I find hardest in this course.')
                : self::TEST_ASK;
        }
        if (! $session->isOpen()) {
            try {
                $this->chat->wrapUp($by, $sessionId);
            } catch (Unprocessable|NotFound) {
                // Nothing to wrap up, or not now.
            }
        }
    }

    /** Sends the student's words (given by the page, or in $text) with what they attached, and streams the answer. */
    public function send(?string $text = null, mixed $attach = []): void
    {
        $words = trim($text ?? $this->text);
        if ($words === '' && (! is_array($attach) || $attach === [])) {
            $this->dispatch('chat-done', restore: null);

            return;
        }
        if ($this->vistud) {
            // Files uploaded with this message (`upload:{key}`) go with it only; notes and files from any course by ref.
            $refs = is_array($attach) ? array_values(array_filter($attach, 'is_string')) : [];
            $keys = array_map(fn (string $ref) => substr($ref, 7), array_filter($refs, fn (string $ref) => str_starts_with($ref, 'upload:')));
            $this->turn(function (Closure $on) use ($words, $refs, $keys) {
                $ai = app(VistudAi::class);
                $uploads = $this->uploaded($keys);
                if ($this->conversationId === null) {
                    // The first words start the conversation: the page lists it and opens it at its address.
                    $this->conversationId = $ai->start($this->principal(), $words !== '' ? $words : 'Attached files', $this->activeModel);
                    $this->dispatch('vistud-conversation-started', id: $this->conversationId);
                }
                try {
                    return $ai->send($this->principal(), $this->conversationId, $words, $on, $this->activeModel, $refs, $uploads);
                } finally {
                    $this->uploads = array_diff_key($this->uploads, array_flip($keys));
                }
            }, $words);

            return;
        }
        if ($this->activityId !== null) {
            $this->turn(fn (Closure $on) => $this->chat->sendOnAssignment($this->principal(), $this->activityId, $words, $on, $attach, $this->activeModel), $words);
            $this->dispatch('assignment-changed');

            return;
        }
        $this->turn(fn (Closure $on) => $this->topicId !== null
            ? $this->chat->sendOnTopic($this->principal(), $this->topicId, $words, $on, $attach, $this->activeModel)
            : $this->chat->send($this->principal(), $this->sessionId, $words, $on, $attach, $this->activeModel), $words);
    }

    /**
     * Hands the assignment's new files to the tutor so it speaks first.
     * Files already sent stay in the conversation; a later visit only sends what is new.
     */
    public function reviewFiles(): void
    {
        if ($this->activityId === null || $this->reviewed) {
            return;
        }
        $this->reviewed = true;
        $by = $this->principal();
        $refs = $this->unreviewedFiles($by);
        if ($refs === []) {
            return;
        }
        $continuing = $this->chat->assignmentTranscript($by, $this->activityId) !== [];
        $words = $continuing ? self::REVIEW_MORE : self::REVIEW;
        $extra = count($refs) - Attachments::MOST;
        if ($extra > 0) {
            $words .= ' '.($extra === 1 ? 'One more file is' : "{$extra} more files are").' in this assignment\'s folder. Read those too.';
        }
        $this->send($words, array_slice($refs, 0, Attachments::MOST));
    }

    /**
     * Files in the assignment's folder that have not gone with a message yet.
     *
     * @return list<string>
     */
    private function unreviewedFiles(Principal $by): array
    {
        $seen = [];
        foreach ($this->chat->assignmentTranscript($by, (string) $this->activityId) as $turn) {
            foreach ($turn['attachments'] ?? [] as $item) {
                $seen[] = (string) ($item['ref'] ?? '');
            }
        }
        $refs = [];
        foreach (app(AssignmentSources::class)->files($by, (string) $this->activityId) as $file) {
            $ref = 'file:'.$file->id;
            if (! in_array($ref, $seen, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /** Switches the AI model to use for the next response in this session. */
    public function switchModel(string $model): void
    {
        $model = trim($model);
        if ($model === '') {
            return;
        }
        $this->activeModel = $model;
        if ($this->activityId !== null) {
            $this->chat->setAssignmentModel($this->principal(), $this->activityId, $model);

            return;
        }
        if ($this->vistud) {
            $this->conversationId !== null && app(VistudAi::class)->setModel($this->principal(), $this->conversationId, $model);

            return;
        }
        $this->topicId !== null
            ? $this->chat->setTopicModel($this->principal(), $this->topicId, $model)
            : $this->chat->setModel($this->principal(), $this->sessionId, $model);
    }

    /** Updates a tutoring choice in real time for this open session. */
    public function setTutoringChoice(string $key, string $value): void
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $current = Tutoring::normalised($session->tutoring);
        if (isset(Tutoring::CHOICES[$key][$value])) {
            $current[$key] = $value;
            $this->sessions->setTutoring($by, $this->sessionId, $current);
            $this->dispatch('session-changed');
        } elseif ($key === 'custom_do' || $key === 'custom_dont') {
            $val = trim($value);
            if ($val !== '') {
                $current[$key] = $val;
            } else {
                unset($current[$key]);
            }
            $this->sessions->setTutoring($by, $this->sessionId, $current);
            $this->dispatch('session-changed');
        }
    }

    /** Toggles a subject plugin (e.g. Math, Coding, Biology, Law) on or off for this session. */
    public function togglePlugin(string $pluginId): void
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $current = Tutoring::normalised($session->tutoring);
        $active = $current['plugins'] ?? [];
        if (in_array($pluginId, $active, true)) {
            $active = array_values(array_filter($active, fn ($id) => $id !== $pluginId));
        } else {
            $active[] = $pluginId;
        }
        $current['plugins'] = $active;
        $this->sessions->setTutoring($by, $this->sessionId, $current);
        $this->dispatch('session-changed');
    }

    /** One of the suggested openings, or a quiz from the Quiz me menu. */
    public function say(string $text): void
    {
        if ($this->activityId !== null) {
            in_array($text, $this->assignmentSuggestions(), true) ? $this->send($text) : $this->dispatch('chat-done', restore: null);

            return;
        }
        if ($this->vistud) {
            in_array($text, array_column(self::VISTUD_STARTERS, 'prompt'), true) ? $this->send($text) : $this->dispatch('chat-done', restore: null);

            return;
        }
        if ($this->topicId !== null) {
            in_array($text, self::topicSuggestions(), true) ? $this->send($text) : $this->dispatch('chat-done', restore: null);

            return;
        }
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $pluginStarters = Plugins::starterQuestions($session->tutoring['plugins'] ?? []);
        if (! in_array($text, [...self::SUGGESTIONS, ...$pluginStarters, self::TEST_ASK, ...array_column(self::ACTIONS, 2), ...array_column($this->quizzes($by, $session), 'text')], true)) {
            $this->dispatch('chat-done', restore: null);

            return;
        }
        $this->send($text);
    }

    #[On('chat-send-prompt')]
    public function sendPrompt(string $text): void
    {
        $this->send($text);
    }

    #[On('chat-set-text')]
    public function setText(string $text): void
    {
        $this->text = $text;
        $this->dispatch('chat-text-updated', text: $text);
    }

    #[On('chat-reload')]
    public function reload(): void
    {
        $this->text = '';
        $this->error = null;
        $this->marksDismissed = false;
        $this->dismissedMarkFingerprint = null;
        $this->dispatch('chat-done', restore: null);
    }

    /** On an assignment companion or a topic's page: starts its chat afresh (what it cost stays counted). */
    public function startOver(): void
    {
        if ($this->activityId !== null) {
            $this->chat->clearAssignmentChat($this->principal(), $this->activityId);
            $this->reload();
            $this->dispatch('assignment-changed');

            return;
        }
        if ($this->topicId === null) {
            return;
        }
        $this->chat->clearTopicChat($this->principal(), $this->topicId);
        $this->reload();
    }

    /** Answers the last message again, after the engine failed on it. */
    public function retry(): void
    {
        if ($this->activityId !== null) {
            $this->turn(fn (Closure $on) => $this->chat->retryOnAssignment($this->principal(), $this->activityId, $on));
            $this->dispatch('assignment-changed');

            return;
        }
        if ($this->vistud) {
            $this->conversationId !== null && $this->turn(fn (Closure $on) => app(VistudAi::class)->retry($this->principal(), (string) $this->conversationId, $on));

            return;
        }
        $this->turn(fn (Closure $on) => $this->topicId !== null
            ? $this->chat->retryOnTopic($this->principal(), $this->topicId, $on)
            : $this->chat->retry($this->principal(), $this->sessionId, $on));
    }

    /** Undoes a progress log entry made by the tutor during the companion chat. */
    public function undoLog(string $id): void
    {
        try {
            app(AssignmentLog::class)->delete($this->principal(), $id);
            $this->dispatch('assignment-changed');
        } catch (NotFound) {
            // Deleted already.
        }
    }

    /** Suggested prompts for the assignment companion. */
    public function assignmentSuggestions(): array
    {
        return array_column(self::ASSIGNMENT_STARTERS, 'prompt');
    }

    /** Opens the write-back's review on everything the tutor marked so far. */
    public function keep(): void
    {
        $texts = [];
        $latest = null;
        foreach ($this->chat->transcript($this->principal(), $this->sessionId) as $turn) {
            if ($turn['role'] === 'assistant' && $turn['text'] !== '') {
                $texts[] = $turn['text'];
                $latest = $turn['text'];
            }
        }
        if ($latest !== null) {
            $this->dismissedMarkFingerprint = md5($latest);
        }
        $this->marksDismissed = true;
        $this->dispatch('capture-open', text: implode("\n\n", $texts));
    }

    /** Dismisses the marked indicator for this session when the student chooses not to save. */
    public function dismissMarks(): void
    {
        $this->marksDismissed = true;
        $by = $this->principal();
        $turns = $this->chat->transcript($by, $this->sessionId);
        foreach (array_reverse($turns) as $turn) {
            if ($turn['role'] === 'assistant' && $turn['text'] !== '') {
                $this->dismissedMarkFingerprint = md5($turn['text']);
                break;
            }
        }
    }

    /** The tutor's proposed status for a topic, accepted with a tap: from now on it is the student's word. */
    public function applyStatus(string $topicId, string $status): void
    {
        $by = $this->principal();
        $this->topicId !== null || $this->sessions->find($by, $this->sessionId);
        if (! in_array($status, Topics::STATUSES, true)) {
            return;
        }
        try {
            $this->topics->report($by, $topicId, $status);
        } catch (NotFound) {
            return;
        }
        $this->dispatch('session-changed');
    }

    /** Takes back a status the tutor set: the topic goes back to what it was, unless the student has said something since. */
    public function undoStatus(string $topicId): void
    {
        $by = $this->principal();
        $last = ($this->topicId !== null ? $this->chat->topicActivity($by, $this->topicId) : $this->chat->activity($by, $this->sessionId))['statuses'][$topicId] ?? null;
        if (! is_array($last) || ($last['applied'] ?? false) !== true || ! is_array($last['before'] ?? null)) {
            return;
        }
        try {
            $this->topics->unmark($by, $topicId, $last['before']);
        } catch (NotFound) {
            return;
        }
        $this->dispatch('session-changed');
    }

    /** What the tutor remembers changed in its window: the chips say so. */
    #[On('memories-changed')]
    public function memoriesChanged(): void {}

    /** The student changed the session's topic on its page: the chat shows where. */
    #[On('session-topic-changed')]
    public function topicChanged(): void {}

    /**
     * The chat's line where the student changed the session's topic on its page.
     *
     * @param  array{topic: ?string, name: ?string}  $change
     */
    public static function topicChangeWords(array $change): string
    {
        return $change['topic'] === null ? 'You took the topic off the session' : 'You moved the session to '.($change['name'] ?? 'another topic');
    }

    /** The student forgets a line the tutor remembered in this chat (the chip's "Forget"). */
    public function forgetMemory(string $id): void
    {
        try {
            $this->memories->forget($this->principal(), $id);
        } catch (NotFound) {
            // Forgotten already, or changed in another tab.
        }
    }

    public function render(): View
    {
        $by = $this->principal();
        $onTopic = $this->topicId !== null ? $this->topics->find($by, $this->topicId) : null;
        $activity = $this->activityId !== null ? $this->activities->find($by, $this->activityId) : null;
        $ai = $this->vistud ? app(VistudAi::class) : null;
        $session = match (true) {
            $ai !== null => self::vistudSession(),
            $onTopic !== null => SessionChat::topicSession($onTopic),
            $activity !== null => SessionChat::activitySession($activity),
            default => $this->sessions->find($by, $this->sessionId),
        };
        $choices = $this->settings->get($by);
        $turns = match (true) {
            $ai !== null => $this->conversationId !== null ? $ai->transcript($by, $this->conversationId) : [],
            $onTopic !== null => $this->chat->topicTranscript($by, $this->topicId),
            $activity !== null => $this->chat->assignmentTranscript($by, $this->activityId),
            default => $this->chat->transcript($by, $this->sessionId),
        };
        $spent = match (true) {
            $ai !== null => ['session' => $this->conversationId !== null ? $ai->spent($by, $this->conversationId) : 0, 'session_cap' => $choices->sessionCapMicros],
            $onTopic !== null => ['session' => $this->chat->topicSpent($by, $this->topicId), 'session_cap' => 0],
            $activity !== null => ['session' => $this->chat->assignmentSpent($by, $this->activityId), 'session_cap' => 0],
            default => $this->chat->spent($by, $this->sessionId),
        };
        $ready = $this->settings->keyAvailable($by) && $choices->ready();
        $hasStatuses = array_filter($turns, fn (array $t) => ($t['statuses'] ?? []) !== []) !== [];
        $topicsNow = $hasStatuses ? array_column($this->topics->list($by, $session->workspaceId), null, 'id') : [];
        // What the tutor remembered: kept still, or forgotten since.
        $kept = array_filter($turns, fn (array $t) => ($t['remembered'] ?? []) !== []) !== [] ? array_flip(array_map(fn ($m) => $m->id, $ai !== null ? $this->memories->all($by) : $this->memories->list($by, $session->workspaceId))) : [];
        $latestAssistant = null;
        foreach ($turns as &$turn) {
            $turn['topicChangeWords'] = isset($turn['topicChange']) ? self::topicChangeWords($turn['topicChange']) : null;
            $turn['attachments'] = array_map(fn (array $a) => $a + [
                'url' => $this->urlOf($a['ref'], $a['course'] ?? null),
                'icon' => self::ICONS[$a['kind']] ?? 'file',
                'thumbUrl' => ($a['kind'] === 'picture' && str_starts_with($a['ref'], 'file:')) ? route('files.content', str_replace('file:', '', $a['ref'])) : null,
            ], $turn['attachments']);
            if ($turn['role'] === 'assistant') {
                [$turn['text'], $turn['suggest']] = SuggestedReply::split($turn['text']);
                $presented = ChatMarks::present($turn['text']);
                $turn['html'] = MarkdownPreview::html($presented);
                $turn['looked'] = array_values(array_unique(array_map(Toolbox::words(...), array_filter($turn['tools'], fn (string $tool) => ! Toolbox::writes($tool)))));
                $turn['savedWords'] = self::savedWords($turn['saved'] ?? []);
                $turn['notes'] = array_map(fn (array $note) => $note + ['url' => route('workspaces.notes.show', [$this->workspaceId, $note['id'], 'window' => 1])], $turn['notes'] ?? []);
                $turn['marks'] = self::marks($turn['statuses'] ?? [], $topicsNow);
                $turn['remembered'] = array_map(fn (array $m) => $m + ['kept' => isset($kept[$m['id'] ?? ''])], $turn['remembered'] ?? []);
                if ($turn['text'] !== '') {
                    $latestAssistant = $turn;
                }
            }
        }
        unset($turn);
        $suggestedReply = '';
        foreach (array_reverse($turns) as $turn) {
            if (($turn['role'] ?? '') === 'assistant' && (($turn['text'] ?? '') !== '' || ($turn['suggest'] ?? '') !== '')) {
                $suggestedReply = (string) ($turn['suggest'] ?? '');
                break;
            }
        }

        $latestText = $latestAssistant['text'] ?? '';
        $latestHasMarks = $latestAssistant !== null && ChatMarks::present($latestText) !== $latestText;
        $latestFingerprint = $latestHasMarks ? md5($latestText) : null;
        // What the tutor marked is kept by a session's write-back: a topic's chat has none to keep it.
        $marked = $onTopic === null && $ai === null && $activity === null && $latestHasMarks && ($this->dismissedMarkFingerprint !== $latestFingerprint);

        $chatModelIds = $choices->chatModels();
        $key = $this->settings->credential($by);
        $allModels = $this->models->all(key: $key);
        $modelsById = [];
        foreach ($allModels as $m) {
            $modelsById[$m->id] = $m;
        }
        $cleanName = function (string $rawName, string $id): string {
            $name = preg_replace('/^(Anthropic|Google|OpenAI|Meta|DeepSeek|Mistral|Cohere|Qwen):\s*/i', '', trim($rawName));

            return $name !== '' ? $name : (str_contains($id, '/') ? explode('/', $id, 2)[1] : $id);
        };
        $chatModels = [];
        foreach ($chatModelIds as $mId) {
            $mObj = $modelsById[$mId] ?? null;
            $raw = $mObj?->name ?? (str_contains($mId, '/') ? explode('/', $mId, 2)[1] : $mId);
            $chatModels[] = [
                'id' => $mId,
                'name' => $cleanName($raw, $mId),
                'priceWords' => $mObj?->priceWords() ?? null,
            ];
        }
        $currentModelId = $this->activeModel ?? $choices->tutorModel;
        $activeModelObj = $modelsById[$currentModelId] ?? null;
        $activeRaw = $activeModelObj?->name ?? (str_contains($currentModelId, '/') ? explode('/', $currentModelId, 2)[1] : $currentModelId);
        $activeModelName = $cleanName($activeRaw, $currentModelId);
        $tutoring = Tutoring::normalised($session->tutoring);
        $pluginStarters = Plugins::starterQuestions($tutoring['plugins'] ?? []);

        return view('livewire.workspaces.tutor-chat', [
            'open' => $session->isOpen(),
            'ready' => $ready,
            // On the shared key with a model chosen, the consent is all that's missing: asked here, in one click.
            'sharedConsent' => ! $ready && $choices->consentedAt === null && $choices->tutorModel !== '' && $this->settings->shared($by) !== null,
            'choices' => $choices,
            'turns' => $turns,
            'suggestedReply' => $suggestedReply,
            'topicChangeAfter' => $onTopic === null && $ai === null && $activity === null && ($change = $this->chat->topicChangeSinceLastMessage($by, $this->sessionId)) !== null ? self::topicChangeWords($change) : null,
            'marked' => $marked,
            'marksDismissed' => $this->marksDismissed,
            'waiting' => $session->isOpen() && $ready && match (true) {
                $ai !== null => $this->conversationId !== null && $ai->waiting($by, $this->conversationId),
                $onTopic !== null => $this->chat->topicWaiting($by, $this->topicId),
                $activity !== null => $this->chat->assignmentWaiting($by, $this->activityId),
                default => $this->chat->waiting($by, $this->sessionId),
            },
            'vistud' => $ai !== null,
            'assistant' => $ai !== null ? 'ViStud AI' : ($activity !== null ? 'your companion' : 'your tutor'),
            'onTopic' => $onTopic !== null ? Topics::cleanMarkdown($onTopic->name) : null,
            'trouble' => ($trouble = app(Troubles::class)->last($by)) !== null && $trouble['role'] === 'tutor' ? $trouble : null,
            'spentWords' => Choices::dollars($spent['session']).($spent['session_cap'] > 0 ? ' of '.Choices::dollars($spent['session_cap']) : '').($onTopic !== null || $ai !== null || $activity !== null ? ' in this chat' : ' this session'),
            'suggestions' => match (true) {
                $ai !== null => [],
                $onTopic !== null => self::topicSuggestions(),
                $activity !== null => $this->assignmentSuggestions(),
                default => array_merge(self::SUGGESTIONS, $pluginStarters),
            },
            'starters' => $ai !== null ? self::VISTUD_STARTERS : ($onTopic !== null ? self::TOPIC_STARTERS : []),
            'activity' => $activity,
            'pendingReview' => $activity !== null && $ready && ! $this->reviewed ? $this->unreviewedFiles($by) : [],
            'assignmentGoal' => $activity !== null ? (app(AssignmentUnderstandings::class)->latest($by, $activity->id)?->text('goal') ?: '') : null,
            'actions' => $onTopic !== null || $ai !== null || $activity !== null ? [] : array_filter(self::ACTIONS, fn (string $key) => ! isset(self::ACTION_PLUGINS[$key]) || in_array(self::ACTION_PLUGINS[$key], $tutoring['plugins'] ?? [], true), ARRAY_FILTER_USE_KEY),
            'testAsk' => self::TEST_ASK,
            'mode' => $session->mode,
            'hasModule' => $ai === null && $this->moduleOf($by, $session) !== null,
            'attachable' => match (true) {
                $ai !== null && $ready => $this->attachableEverywhere($by),
                $ai === null && $ready && $session->isOpen() => $this->attachable($by, $session),
                default => ['module' => null, 'items' => []],
            },
            'account' => (string) auth()->id(),
            'upload' => [
                'url' => route('api.v1.files.store'),
                // Into the session's folder (its "From the chat" folder), else the module's, else the course's.
                'type' => ($folder = $this->folderOf($by, $session)) !== null ? 'folder' : (($module = $this->moduleOf($by, $session)) !== null ? 'module' : 'workspace'),
                'id' => $folder?->id ?? $module ?? $session->workspaceId,
                'maxBytes' => Files::maxBytes(),
                'accept' => implode(',', array_map(fn (string $extension) => ".{$extension}", array_keys(FileTypes::TYPES))),
                'icons' => array_map(fn (string $icon) => Icons::url($icon), self::ICONS),
                // ViStud AI: uploads go with the message only (Livewire's upload), into no course.
                'chatOnly' => $ai !== null,
            ],
            'quizzes' => $onTopic === null && $ai === null && $ready && $session->isOpen() ? $this->quizzes($by, $session) : [],
            'chatModels' => $chatModels,
            'activeModel' => $currentModelId,
            'activeModelName' => $activeModelName,
            'sees' => ($model = $this->models->find($currentModelId, $this->settings->credential($by))) === null || $model->images,
            'tutoring' => $tutoring,
            'tutoringChoices' => Tutoring::CHOICES,
            'tutoringQuestions' => Tutoring::QUESTIONS,
            'tutoringSummary' => Tutoring::summary($session->tutoring),
        ]);
    }

    /** What a topic's chat offers to start with: the four ways in to one topic, each a card with what it sends. */
    public const TOPIC_STARTERS = [
        ['title' => 'Core concepts', 'hint' => 'Explained one at a time', 'icon' => 'lightbulb', 'prompt' => 'Explain the core concepts of this topic, one at a time.'],
        ['title' => 'Real-world examples', 'hint' => 'Where it shows up', 'icon' => 'globe', 'prompt' => 'Give me real-world examples of this topic.'],
        ['title' => 'Common pitfalls', 'hint' => 'Mistakes and misconceptions', 'icon' => 'triangle-alert', 'prompt' => 'What are the common pitfalls and misconceptions here?'],
        ['title' => 'Test me', 'hint' => 'A question at my level', 'icon' => 'target', 'prompt' => 'Test my understanding with a question at my level.'],
    ];

    /** ViStud AI's chat as a session for the chat's own workings (open, free, no clock): never kept, and in no course. */
    public static function vistudSession(): SessionDetails
    {
        $now = now()->toIso8601String();

        return new SessionDetails('vistud-ai', '', null, null, 'running', null, false, $now, null, $now, 0, 0, null, null, mode: 'free');
    }

    /** What ViStud AI offers to start with: the four questions about the whole of one's study. */
    public const VISTUD_STARTERS = [
        ['title' => 'What should I study today?', 'hint' => 'From deadlines and what needs attention', 'icon' => 'target', 'prompt' => 'What should I study today, across all my courses? Look at my deadlines and what needs attention.'],
        ['title' => 'Where am I weakest?', 'hint' => 'Across all my courses', 'icon' => 'trending-up', 'prompt' => 'Where am I weakest across all my courses, and what should I do about it?'],
        ['title' => 'Plan my week', 'hint' => 'Around my deadlines and time', 'icon' => 'calendar-days', 'prompt' => 'Plan my study for the next 7 days around my deadlines and the time I usually study.'],
        ['title' => 'What do you know about me?', 'hint' => 'How I study, and what is remembered', 'icon' => 'brain', 'prompt' => 'What do you know about how I study? Include what is remembered about me.'],
    ];

    /** The words the topic's starters send: the only quick asks a topic's chat answers. */
    public static function topicSuggestions(): array
    {
        return array_column(self::TOPIC_STARTERS, 'prompt');
    }

    /**
     * The statuses the tutor set or proposed in a turn, as chips that say where each stands now: marked by the tutor (with
     * an undo), accepted (the student's word now), taken back, or still only a suggestion (with a tap to accept it).
     *
     * @param  list<array<string, mixed>>  $statuses
     * @param  array<string, TopicDetails>  $topics
     * @return list<array{topic_id: string, topic: string, to: string, words: string, state: string, reason: ?string}>
     */
    private static function marks(array $statuses, array $topics): array
    {
        $words = array_map('strtolower', array_intersect_key(TopicDetails::STATUS_WORDS, array_flip(['covered', 'understood', 'confused'])));
        $marks = [];
        foreach ($statuses as $s) {
            $now = $topics[$s['topic_id']] ?? null;
            if ($now === null || ! isset($words[$s['to']])) {
                continue;
            }
            $state = match (true) {
                $s['applied'] && $now->status === $s['to'] && $now->byTutor() => 'marked',
                $now->status === $s['to'] => 'accepted',
                $s['applied'] => 'undone',
                default => 'proposed',
            };
            $marks[] = ['topic_id' => $s['topic_id'], 'topic' => $s['topic'], 'to' => $s['to'], 'words' => $words[$s['to']], 'state' => $state, 'reason' => $s['reason'] ?? null];
        }

        return $marks;
    }

    /** "3 flashcards, 1 key point, 2 new topics": what a turn saved in the course. */
    public static function savedWords(array $saved): ?string
    {
        $words = ['flashcard' => ['flashcard', 'flashcards'], 'finding' => ['key point', 'key points'], 'question' => ['question', 'questions'], 'topic' => ['new topic', 'new topics']];
        $parts = [];
        foreach ($words as $kind => [$one, $many]) {
            if (($saved[$kind] ?? 0) > 0) {
                $parts[] = $saved[$kind].' '.($saved[$kind] === 1 ? $one : $many);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** The icon of each kind of attachment. */
    private const ICONS = ['note' => 'notebook-pen', 'file' => 'file-text', 'picture' => 'file-image'];

    /**
     * What can be attached: the session's chosen material first, then the rest of its folder's notes and files (docs/
     * specs/vistud-2-blueprint.md, Phase 9), else its module's (the course's, when the session has no module).
     *
     * @return array{module: ?string, items: list<array{ref: string, name: string, kind: string, chosen: bool}>}
     */
    private function attachable(Principal $by, SessionDetails $session): array
    {
        $module = $this->moduleOf($by, $session);
        $folder = $this->folderOf($by, $session);
        $inside = $folder === null ? null : $this->folders->within($by, $folder->id);
        $here = fn (?string $itemModule, ?string $itemFolder) => match (true) {
            $inside !== null => $itemFolder !== null && in_array($itemFolder, $inside, true),
            default => $module === null || $itemModule === $module,
        };
        $items = [];
        foreach ($this->notes->list($by, $session->workspaceId) as $note) {
            if (! $here($note->moduleId, $note->folderId) && ! $session->uses("note:{$note->id}")) {
                continue;
            }
            $items[] = [
                'ref' => "note:{$note->id}",
                'name' => $note->displayTitle(),
                'kind' => 'note',
                'ext' => 'note',
                'chosen' => $session->uses("note:{$note->id}"),
                'inScope' => true,
            ];
        }
        foreach ($this->files->list($by, $session->workspaceId) as $file) {
            if (! $here($file->moduleId, $file->folderId) && ! $session->uses("file:{$file->id}")) {
                continue;
            }
            $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
            $items[] = [
                'ref' => "file:{$file->id}",
                'name' => $file->fileName(),
                'kind' => $file->kind === 'image' ? 'picture' : 'file',
                'ext' => $ext ?: 'file',
                'chosen' => $session->uses("file:{$file->id}"),
                'thumbUrl' => $file->kind === 'image' ? route('files.content', $file->id) : null,
                'inScope' => true,
            ];
        }
        usort($items, fn ($a, $b) => [$b['chosen'], $a['name']] <=> [$a['chosen'], $b['name']]);

        return ['module' => $module, 'items' => array_slice($items, 0, 120)];
    }

    /**
     * What the Quiz me menu offers: the session's topic, its module, and what the student finds hardest.
     *
     * @return list<array{label: string, text: string}>
     */
    private function quizzes(Principal $by, SessionDetails $session): array
    {
        $quizzes = [];
        if ($session->topicId !== null) {
            try {
                $topic = $this->topics->find($by, $session->topicId)->name;
                $quizzes[] = ['label' => "On {$topic}", 'text' => "Quiz me on {$topic}."];
            } catch (NotFound) {
                // Removed since.
            }
        }
        if (($folder = $this->folderOf($by, $session)) !== null) {
            $quizzes[] = ['label' => "On {$folder->name}", 'text' => "Quiz me on the folder {$folder->name}."];
        }
        if (($module = $this->moduleOf($by, $session)) !== null) {
            try {
                $title = $this->modules->find($by, $module)->title;
                $quizzes[] = ['label' => "On {$title}", 'text' => "Quiz me on the module {$title}."];
            } catch (NotFound) {
                // Deleted since.
            }
        }
        $quizzes[] = ['label' => 'On what I find hardest', 'text' => 'Quiz me on what I find hardest in this course.'];

        return $quizzes;
    }

    /** The session's module: its own, or its topic's. */
    /**
     * The folder the session studies in, while it is there; on an assignment, the assignment's own folder (uploads and
     * attachments stay with it, while its tutor's tools look across the module: SessionChat::activitySession).
     */
    private function folderOf(Principal $by, SessionDetails $session): ?FolderDetails
    {
        $folderId = $this->activityId !== null ? $this->activities->find($by, $this->activityId)->folderId : $session->folderId;
        if ($folderId === null) {
            return null;
        }
        try {
            return $this->folders->find($by, $folderId);
        } catch (NotFound) {
            return null;
        }
    }

    private function moduleOf(Principal $by, SessionDetails $session): ?string
    {
        if ($session->moduleId !== null || $session->topicId === null) {
            return $session->moduleId;
        }
        try {
            return $this->topics->find($by, $session->topicId)->moduleId;
        } catch (NotFound) {
            return null;
        }
    }

    /** Where an attached note or file opens: in its own course (ViStud AI's come from any); an upload opens nowhere. */
    private function urlOf(string $ref, ?string $course = null): ?string
    {
        [$kind, $id] = explode(':', $ref, 2) + [1 => ''];
        $course ??= $this->workspaceId;
        if ($course === '') {
            return null;
        }

        return match ($kind) {
            'note' => route('workspaces.notes.show', [$course, $id]),
            'file' => route('workspaces.files.show', [$course, $id]),
            default => null,
        };
    }

    /**
     * The files uploaded for ViStud AI with these keys, as name and bytes, checked as the course guide checks its own:
     * a picture or a Word, PDF, PowerPoint, Excel or text file, under 10 MB. A missing one is left out.
     *
     * @param  list<string>  $keys
     * @return array<string, array{name: string, bytes: string}>
     */
    private function uploaded(array $keys): array
    {
        $files = [];
        foreach ($keys as $key) {
            $file = $this->uploads[$key] ?? null;
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }
            $check = Validator::make(['file' => $file], ['file' => ['extensions:'.GuideChat::PICTURE_TYPES, 'max:'.GuideChat::PICTURE_KB]]);
            if ($check->fails()) {
                throw new Unprocessable('validation_failed', mb_substr(mb_scrub($file->getClientOriginalName()), 0, 80).': attach a picture, or a Word, PDF, PowerPoint, Excel or text file, under 10 MB.', ['fields' => ['attach' => ['That file can\'t be attached.']]]);
            }
            $files[$key] = ['name' => $file->getClientOriginalName(), 'bytes' => UploadTemps::bytes($file, Files::maxBytes())];
        }

        return $files;
    }

    /**
     * What ViStud AI can attach: notes and files from every one of the student's courses, the most recently studied
     * course first, at most 120, each with where it opens.
     *
     * @return array{module: null, items: list<array<string, mixed>>}
     */
    private function attachableEverywhere(Principal $by): array
    {
        $items = [];
        foreach (app(Workspaces::class)->list($by) as $course) {
            foreach ($this->notes->list($by, $course->id) as $note) {
                $items[] = ['ref' => "note:{$note->id}", 'name' => $note->displayTitle()." · {$course->name}", 'kind' => 'note', 'ext' => 'note', 'chosen' => false, 'inScope' => true];
            }
            foreach ($this->files->list($by, $course->id) as $file) {
                $items[] = [
                    'ref' => "file:{$file->id}", 'name' => $file->fileName()." · {$course->name}", 'kind' => $file->kind === 'image' ? 'picture' : 'file',
                    'ext' => strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) ?: 'file', 'chosen' => false,
                    'thumbUrl' => $file->kind === 'image' ? route('files.content', $file->id) : null, 'inScope' => true,
                ];
            }
            if (count($items) >= 120) {
                break;
            }
        }

        return ['module' => null, 'items' => array_slice($items, 0, 120)];
    }

    /**
     * Runs one turn, streaming the answer so far (with any half-written mark held back) and what the tutor is
     * looking up. Words the chat refused go back to the box; once kept, an engine that fails leaves them in the
     * chat, to try again.
     */
    private function turn(Closure $run, ?string $words = null): void
    {
        $this->error = null;
        $this->marksDismissed = false;
        // A long answer with look-ups can outlast PHP's usual 30 seconds, and leaving the page mid-answer
        // shouldn't lose it: it is kept, to read on return.
        @set_time_limit(300);
        ignore_user_abort(true);
        $answer = '';
        $sent = 0.0;
        $changed = [];
        $on = function (string $kind, string $value) use (&$answer, &$sent, &$changed) {
            if ($kind === 'looking') {
                $this->stream->push($this, 'status', e(Toolbox::doing($value)));

                return;
            }
            if ($kind === 'saved') {
                $effects = json_decode($value, true);
                $streamNotes = [];
                // Notes the tutor wrote in: an editor open on one is told to show the new version.
                foreach ($effects['notes'] ?? [] as $note) {
                    $changed[$note['id']] = ['id' => (string) $note['id'], 'version' => (int) $note['version']];
                    $streamNotes[] = ['id' => (string) $note['id'], 'version' => (int) $note['version']];
                }
                if ($streamNotes !== []) {
                    $this->dispatch('notes-changed', notes: $streamNotes);
                }
                // A new topic for the session: the session page shows it.
                if (isset($effects['topic'])) {
                    $this->dispatch('session-changed');
                }

                return;
            }
            if ($answer === '') {
                $this->stream->push($this, 'status', '');
            }
            $answer .= $value;
            if (microtime(true) - $sent >= self::EVERY) {
                $sent = microtime(true);
                $this->stream->push($this, 'answer', MarkdownPreview::html(ChatMarks::present(ChatMarks::partial($answer))));
            }
        };

        try {
            $run($on);
        } catch (Unprocessable $e) {
            $this->announce($changed);
            // A field's own words ("Write something first.") over the general ones.
            $fields = is_array($e->details['fields'] ?? null) ? $e->details['fields'] : [];
            $first = $fields !== [] ? (array_values($fields)[0][0] ?? null) : null;
            $this->error = is_string($first) ? $first : $e->getMessage();
            $this->dispatch('chat-done', restore: $words ?? '');

            return;
        } catch (EngineFailed $e) {
            $this->announce($changed);
            $this->error = $e->getMessage();
            // Kept past this page, so "hasn't answered" can say why (App\Engine\Troubles).
            app(Troubles::class)->note($this->principal(), 'tutor', (string) $this->activeModel, $e);
            $this->text = '';
            $this->dispatch('chat-done', restore: null);

            return;
        }
        if ($answer !== '') {
            $this->stream->push($this, 'answer', MarkdownPreview::html(ChatMarks::present(ChatMarks::partial($answer))));
        }
        app(Troubles::class)->clear($this->principal(), 'tutor');
        $this->announce($changed);
        $this->text = '';
        $this->dispatch('chat-turn');
        $this->dispatch('chat-done', restore: null);
    }

    /**
     * Tells the page what the tutor changed: open notes show their new version (resources/js/tutor-chat.js passes
     * it on to the editor), and the question board looks again.
     *
     * @param  array<string, array{id: string, version: int}>  $notes
     */
    private function announce(array $notes): void
    {
        if ($notes !== []) {
            $this->dispatch('notes-changed', notes: array_values($notes));
        }
        $this->dispatch('questions-changed');
        $this->dispatch('flashcards-changed');
        $this->dispatch('quizzes-changed');
    }

    /** A student on the shared key agrees to the chat sending their study material to the service, from the chat itself. */
    public function agreeToAi(): void
    {
        $this->settings->consent($this->principal());
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
