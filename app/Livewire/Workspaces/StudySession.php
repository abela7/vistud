<?php

namespace App\Livewire\Workspaces;

use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Livewire\Concerns\PomodoroForm;
use App\Livewire\Concerns\TeachingForm;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Briefings;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Instructions;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A study session's page (docs/specs/vistud-2-blueprint.md §3.5.4): the conversation, with everything else at its
 * side. The header holds the clock, Pause and End (or the Pomodoro bar under it) and the ⋯ menu; the rail holds the
 * module's topics (tap to switch the session's topic), its material and what this session saved; the chat is its own
 * component. Ending the session is one screen: the time, the topics the tutor touched with the status each would get
 * as a chip the student can change, what was saved, and, once ended, the tutor's summary. The service applies the
 * clock's rules; the IDs are locked.
 */
final class StudySession extends Component
{
    use Notices, PomodoroForm, TeachingForm;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $sessionId;

    /** end, done, delete, pomodoro, teaching, briefing, material, topic, question or tell: the side panel that's open, or null. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $error = null;

    /** The status to record for each topic the session touched when it ends, by topic id; empty leaves the topic as it is. */
    public array $statuses = [];

    /** A question to keep, from the + menu. */
    public string $questionText = '';

    /** What the student tells the tutor about this module. */
    public string $moduleNote = '';

    /** The session's topic, chosen in the topic panel: a topic's id, '' for none, or 'new' for $newTopic. */
    public string $topicChoice = '';

    public string $newTopic = '';

    private Sessions $sessions;

    private Topics $topics;

    private Modules $modules;

    private Notes $notes;

    private Files $files;

    private Folders $folders;

    private Briefings $briefings;

    private Questions $questions;

    private PrincipalFactory $principals;

    private SessionChat $chat;

    private Settings $settings;

    private Instructions $instructions;

    public function boot(Sessions $sessions, Topics $topics, Modules $modules, Notes $notes, Files $files, Briefings $briefings, Questions $questions, Folders $folders, PrincipalFactory $principals, SessionChat $chat, Settings $settings, Instructions $instructions): void
    {
        $this->settings = $settings;
        $this->instructions = $instructions;
        $this->chat = $chat;
        $this->folders = $folders;
        $this->briefings = $briefings;
        $this->questions = $questions;
        $this->sessions = $sessions;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->notes = $notes;
        $this->files = $files;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $sessionId): void
    {
        [$this->workspaceId, $this->sessionId] = [$workspaceId, $sessionId];
    }

    public function pause(): void
    {
        $this->act(fn () => $this->sessions->pause($this->principal(), $this->sessionId));
    }

    public function takeBreak(): void
    {
        $this->act(fn () => $this->sessions->takeBreak($this->principal(), $this->sessionId));
    }

    public function resume(): void
    {
        $this->act(fn () => $this->sessions->resume($this->principal(), $this->sessionId));
    }

    /** The Pomodoro clock's next phase, now. */
    public function skip(): void
    {
        $this->act(fn () => $this->sessions->skip($this->principal(), $this->sessionId));
    }

    /** The Pomodoro countdown reached zero in the browser: show the next phase. */
    public function phaseEnded(): void
    {
        $this->dispatch('session-changed');
    }

    /** Opens App\Livewire\Workspaces\FlashcardEditor for a card on the session's topic, in its module. */
    #[On('session-new-card')]
    public function newFlashcard(): void
    {
        $session = $this->sessions->find($this->principal(), $this->sessionId);
        $this->dispatch('flashcard-new', topicId: $session->topicId, moduleId: $session->moduleId);
    }

    /** What the session is about: one of the course's topics, a new one in the session's module, or none. */
    public function editTopic(): void
    {
        $topicId = $this->sessions->find($this->principal(), $this->sessionId)->topicId;
        $this->open('topic');
        $this->topicChoice = $topicId ?? '';
    }

    /** The rail's topic, tapped: the session is about it now (the tutor reads it from its next message). */
    public function switchTopic(string $topicId): void
    {
        $now = null;
        $this->act(function () use ($topicId, &$now) {
            try {
                $topic = $this->topics->find($this->principal(), $topicId);
                $this->sessions->setTopic($this->principal(), $this->sessionId, $topic->id);
                $now = $topic->name;
            } catch (NotFound) {
                $this->error = 'That topic no longer exists.';
            }
        });
        $this->notice = $now === null ? null : "Now on {$now}.";
    }

    /** A question to keep, from the + menu: it goes to the module's questions (and to this session). */
    #[On('session-new-question')]
    public function newQuestion(): void
    {
        $this->open('question');
    }

    /** What the student wants the tutor to know about this module, in their words. */
    public function tellTutor(): void
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $moduleId = $this->moduleIdOf($by, $session);
        if ($moduleId === null) {
            return;
        }
        $this->open('tell');
        $this->moduleNote = $this->instructions->get($by, "module:{$moduleId}");
    }

    /** What the assistant would receive now: the tutoring prompt and the briefing. */
    public function showBriefing(): void
    {
        $this->open('briefing');
    }

    /** The notes and files, to open or to Use in the briefing. */
    public function openMaterial(): void
    {
        $this->open('material');
    }

    /** The editor for a new note in the session's module (or the workspace's top level); nothing is kept until something is written. */
    #[On('session-new-note')]
    public function newNote(): void
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $moduleId = $session->moduleId;
        if ($moduleId === null && $session->topicId !== null) {
            try {
                $moduleId = $this->topics->find($by, $session->topicId)->moduleId;
            } catch (NotFound) {
                // Removed since: the note goes to the top level.
            }
        }
        $this->redirectRoute('workspaces.notes.create', [$this->workspaceId] + ($moduleId === null ? [] : ['in' => "module:{$moduleId}"]), navigate: true);
    }

    public function editTeaching(): void
    {
        $this->open('teaching');
        $this->fillTeaching($this->sessions->find($this->principal(), $this->sessionId)->tutoring);
    }

    /** A note or file (`note:{id}`, `file:{id}`) into the session's material, or out of it. */
    public function toggleMaterial(string $item): void
    {
        $this->act(function () use ($item) {
            try {
                $this->sessions->toggleMaterial($this->principal(), $this->sessionId, $item);
            } catch (NotFound) {
                $this->error = 'That note or file no longer exists.';
            }
        });
    }

    public function editPomodoro(): void
    {
        $this->open('pomodoro');
        $this->fillPomodoro($this->sessions->find($this->principal(), $this->sessionId)->pomodoro ?? ['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4, 'auto' => true]);
        $this->clock = 'pomodoro';
    }

    /** The automatic pause was wrong: the student was studying. */
    public function countAway(): void
    {
        $this->act(fn () => $this->sessions->countAway($this->principal(), $this->sessionId), 'The time away is counted as study.');
    }

    /** The end screen: each topic the session touched starts on the status the tutor gave or proposed for it, or on none. */
    public function confirmEnd(): void
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $activity = $this->chat->activity($by, $this->sessionId);
        $this->open('end');
        $this->statuses = [];
        foreach ($this->touched($by, $session, $activity) as $topic) {
            $this->statuses[$topic->id] = ($activity['statuses'][$topic->id]['to'] ?? '');
        }
    }

    public function confirmDelete(): void
    {
        $this->open('delete');
    }

    public function save(): void
    {
        $by = $this->principal();
        if ($this->mode === 'material') {
            $this->close();
            $this->dispatch('session-dialog-close');

            return;
        }
        if ($this->mode === 'delete') {
            $this->sessions->delete($by, $this->sessionId);
            $this->dispatch('session-changed');
            session()->flash('workspace-notice', 'The session is deleted.');
            $this->redirectRoute('workspaces.show', $this->workspaceId, navigate: true);

            return;
        }
        if ($this->mode === 'pomodoro') {
            try {
                $this->sessions->setPomodoro($by, $this->sessionId, $this->pomodoroInput());
            } catch (Unprocessable $e) {
                foreach ($e->details['fields'] ?? [] as $field => $messages) {
                    $this->addError($this->pomodoroErrorField($field), $messages[0]);
                }

                return;
            } catch (Conflict $e) {
                $this->error = $e->getMessage();
            }
            $this->notice = $this->clock === 'pomodoro' ? 'The Pomodoro clock is on.' : 'The free clock is on.';
            $this->dispatch('session-changed');
        }
        if ($this->mode === 'teaching') {
            try {
                $this->sessions->setTutoring($by, $this->sessionId, $this->teachingInput());
            } catch (Unprocessable $e) {
                foreach ($e->details['fields'] ?? [] as $field => $messages) {
                    $this->addError($this->teachingErrorField($field), $messages[0]);
                }

                return;
            } catch (Conflict $e) {
                $this->error = $e->getMessage();
            }
            $this->notice = $this->error === null ? 'The briefing now asks for this way of teaching.' : null;
        }
        if ($this->mode === 'topic') {
            $session = $this->sessions->find($by, $this->sessionId);
            $topicId = null;
            try {
                $topicId = match ($this->topicChoice) {
                    '' => null,
                    'new' => $this->newTopicId($by, $session->moduleId),
                    default => $this->topicChoice,
                };
                $this->sessions->setTopic($by, $this->sessionId, $topicId);
            } catch (Unprocessable $e) {
                $fields = $e->details['fields'] ?? [];
                $this->addError('newTopic', $fields === [] ? $e->getMessage() : reset($fields)[0]);

                return;
            } catch (NotFound) {
                $this->addError('topicChoice', 'That topic no longer exists. Choose another.');

                return;
            } catch (Conflict $e) {
                $this->error = $e->getMessage();
            }
            $this->notice = $this->error === null ? ($topicId === null ? 'The session has no topic now.' : 'The topic is set. The tutor and what you save use it from now on.') : null;
            $this->dispatch('session-changed');
        }
        if ($this->mode === 'question') {
            try {
                $session = $this->sessions->find($by, $this->sessionId);
                $this->questions->ask($by, $this->workspaceId, $this->questionText, $session->topicId, $this->moduleIdOf($by, $session), $session->id);
            } catch (Unprocessable $e) {
                $fields = array_values($e->details['fields'] ?? []);
                $this->addError('questionText', $fields === [] ? $e->getMessage() : $fields[0][0]);

                return;
            }
            $this->notice = 'The question is kept. You\'ll find it with the module\'s questions.';
            $this->dispatch('questions-changed');
        }
        if ($this->mode === 'tell') {
            $session = $this->sessions->find($by, $this->sessionId);
            $moduleId = $this->moduleIdOf($by, $session);
            try {
                if ($moduleId !== null) {
                    $this->instructions->set($by, "module:{$moduleId}", $this->moduleNote);
                }
            } catch (Unprocessable $e) {
                $fields = array_values($e->details['fields'] ?? []);
                $this->addError('moduleNote', $fields === [] ? $e->getMessage() : $fields[0][0]);

                return;
            }
            $this->notice = 'The tutor will use it from your next message.';
        }
        if ($this->mode === 'end') {
            $chosen = array_filter($this->statuses, fn ($status) => in_array($status, Topics::STATUSES, true));
            $this->act(function () use ($by, $chosen) {
                $ended = $this->sessions->end($by, $this->sessionId);
                foreach ($chosen as $topicId => $status) {
                    try {
                        $topic = $this->topics->find($by, (string) $topicId);
                        // Said here, it is now the student's word, unless it already was.
                        if ($topic->status !== $status || $topic->statusBy !== 'student') {
                            $this->topics->report($by, $topic->id, $status);
                        }
                    } catch (NotFound) {
                        // The topic was removed meanwhile; the session still ends.
                    }
                }

                return $ended;
            });
            if ($this->error === null) {
                $this->wrapUp($by);
                // The end screen goes on to say how it went: the time and the tutor's summary.
                $this->mode = 'done';
                $this->statuses = [];
                $this->dispatch('session-changed');

                return;
            }
        }
        $this->close();
        $this->dispatch('session-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'statuses', 'questionText', 'moduleNote', 'topicChoice', 'newTopic', 'clock', 'preset', 'focus', 'short', 'long', 'every', 'auto', 'method', 'checkIns', 'quiz', 'pace');
        $this->resetErrorBag();
    }

    #[On('session-changed')]
    #[On('questions-changed')]
    #[On('chat-turn')]
    public function refresh(): void {}

    public function render(): View
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);
        $zone = $this->sessions->timezone($by);
        $time = fn (string $at) => Carbon::parse($at)->setTimezone($zone)->format('H:i');

        $topic = null;
        if ($session->topicId !== null) {
            try {
                $topic = $this->topics->find($by, $session->topicId);
            } catch (NotFound) {
                // Removed since: the session keeps its time.
            }
        }
        $moduleId = $session->moduleId ?? $topic?->moduleId;
        $module = null;
        if ($moduleId !== null) {
            try {
                $module = $this->modules->find($by, $moduleId);
            } catch (NotFound) {
                // Deleted since.
            }
        }
        $material = $this->material($by, $session, $module);
        $activity = $this->chat->activity($by, $this->sessionId);
        $choices = $this->settings->get($by);
        $courseTopics = $this->topics->list($by, $this->workspaceId);

        return view('livewire.workspaces.study-session', [
            'session' => $session,
            'chatted' => in_array($this->mode, ['end', 'done'], true) && $this->chat->transcript($by, $this->sessionId) !== [],
            'topic' => $topic,
            'module' => $module,
            'courseTopics' => $this->mode === 'topic' ? $courseTopics : [],
            'railTopics' => $module === null ? [] : array_values(array_filter($courseTopics, fn ($t) => $t->moduleId === $module->id)),
            'activity' => $activity,
            'touched' => in_array($this->mode, ['end', 'done'], true) ? $this->touched($by, $session, $activity, $courseTopics) : [],
            'copyPaste' => $choices->copyPasteAi,
            'tutorMarks' => $choices->tutorMarksTopics,
            'groups' => $material['groups'],
            'alsoUsed' => $material['alsoUsed'],
            'materialCount' => $material['count'],
            'usedCount' => $material['used'],
            'briefing' => $this->mode === 'briefing' ? $this->briefings->forSession($by, $this->sessionId) : null,
            'started' => Carbon::parse($session->startedAt)->setTimezone($zone),
            'ended' => $session->endedAt === null ? null : Carbon::parse($session->endedAt)->setTimezone($zone),
            'awaySince' => $session->pausedBy === 'away' ? $time($session->lastActivityAt) : null,
        ]);
    }

    /**
     * The topics this session touched, for its end screen: the one it is on, and those the tutor set, marked or proposed.
     *
     * @param  array{topics: list<string>}  $activity
     * @param  ?list<TopicDetails>  $all
     * @return list<TopicDetails>
     */
    private function touched(Principal $by, SessionDetails $session, array $activity, ?array $all = null): array
    {
        $all ??= $this->topics->list($by, $this->workspaceId);
        $ids = array_values(array_unique(array_filter([$session->topicId, ...$activity['topics']])));

        return array_values(array_filter($all, fn (TopicDetails $t) => in_array($t->id, $ids, true)));
    }

    /** The session's module: its own, or its topic's. */
    private function moduleIdOf(Principal $by, SessionDetails $session): ?string
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

    /**
     * The notes and files for the panel, by place: in a module, only that
     * module's (its top level, then its folders in order, each with its
     * path), never another module's (the owner's review, 2026-09-27). With
     * no module, every module's and the top level's, each apart. Anything
     * used in the briefing from elsewhere (chosen before) is listed apart,
     * so it can be taken out.
     *
     * @return array{groups: list<array{key: string, title: ?string, depth: int, items: list<array>}>, alsoUsed: list<array>, count: int, used: int}
     */
    private function material(Principal $by, SessionDetails $session, ?ModuleDetails $module): array
    {
        $fileColours = ['pdf' => 'red', 'document' => 'blue', 'slides' => 'orange', 'spreadsheet' => 'green', 'text' => 'pink', 'image' => 'purple'];
        $byPlace = [];
        foreach ($this->notes->list($by, $this->workspaceId) as $note) {
            $byPlace[$note->placeKey()][] = ['key' => "note:{$note->id}", 'icon' => 'file-text', 'colour' => 'blue', 'name' => $note->displayTitle(), 'url' => route('workspaces.notes.show', [$this->workspaceId, $note->id]), 'type' => 'Note'];
        }
        foreach ($this->files->list($by, $this->workspaceId) as $file) {
            $byPlace[$file->placeKey()][] = ['key' => "file:{$file->id}", 'icon' => $file->icon(), 'colour' => $fileColours[$file->kind] ?? 'teal', 'name' => $file->fileName(), 'url' => route('workspaces.files.show', [$this->workspaceId, $file->id]), 'type' => $file->typeLabel()];
        }

        // The places to show, in order: [key, title, depth].
        $folders = $this->folders->tree($by, $this->workspaceId);
        $names = [];
        $places = function (?string $moduleId, ?string $title) use ($folders, &$names): array {
            $list = [[$moduleId === null ? "workspace:{$this->workspaceId}" : "module:{$moduleId}", $title, 0]];
            foreach ($folders as $folder) {
                if ($folder->moduleId !== $moduleId) {
                    continue;
                }
                $names[$folder->id] = ($folder->parentId !== null ? ($names[$folder->parentId] ?? '').' › ' : ($title !== null ? $title.' › ' : '')).$folder->name;
                $list[] = ["folder:{$folder->id}", $names[$folder->id], $folder->depth];
            }

            return $list;
        };
        $order = [];
        if ($module !== null) {
            $order = $places($module->id, null);
        } else {
            foreach ($this->modules->list($by, $this->workspaceId) as $each) {
                array_push($order, ...$places($each->id, $each->title));
            }
            array_push($order, ...$places(null, 'Notes & files'));
        }

        $groups = [];
        $shown = [];
        foreach ($order as [$key, $title, $depth]) {
            if (($byPlace[$key] ?? []) === []) {
                continue;
            }
            $groups[] = ['key' => $key, 'title' => $title, 'depth' => $depth, 'items' => $byPlace[$key]];
            foreach ($byPlace[$key] as $item) {
                $shown[$item['key']] = true;
            }
        }
        $alsoUsed = [];
        foreach ($byPlace as $items) {
            foreach ($items as $item) {
                if (! isset($shown[$item['key']]) && $session->uses($item['key'])) {
                    $alsoUsed[] = $item;
                }
            }
        }
        $all = [...array_merge([], ...array_column($groups, 'items')), ...$alsoUsed];

        return [
            'groups' => $groups,
            'alsoUsed' => $alsoUsed,
            'count' => count($shown),
            'used' => count(array_filter($all, fn ($item) => $session->uses($item['key']))),
        ];
    }

    /** The chat's summary and checkpoint into the session's record, once; an engine that fails leaves them for later. */
    private function wrapUp(Principal $by): bool
    {
        try {
            return $this->chat->wrapUp($by, $this->sessionId) !== null;
        } catch (Unprocessable|NotFound) {
            return false;
        }
    }

    private function act(callable $action, ?string $notice = null): void
    {
        $this->error = null;
        try {
            $action();
            $this->notice = $notice;
        } catch (Conflict $e) {
            $this->error = $e->getMessage();
        }
        $this->dispatch('session-changed');
    }

    /** The course's topic with that name, or a new one in $moduleId. */
    private function newTopicId(Principal $by, ?string $moduleId): string
    {
        $name = trim($this->newTopic);
        $same = collect($this->topics->list($by, $this->workspaceId))->first(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name));

        return $same?->id ?? $this->topics->create($by, $this->workspaceId, $name, $moduleId)->id;
    }

    private function open(string $mode): void
    {
        $this->close();
        $this->mode = $mode;
        $this->dispatch('session-dialog-open');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
