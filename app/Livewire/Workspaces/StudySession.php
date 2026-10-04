<?php

namespace App\Livewire\Workspaces;

use App\Engine\SessionChat;
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
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\SessionSegment;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A study session's page (docs/specs/study-memory.md §4): the clock and its
 * controls, what to do next (study with an AI, save from the chat, ask a
 * question, write a card or a note, the notes and files), the questions,
 * and what happened once it has ended. The service applies the clock's
 * rules; the IDs are locked.
 */
final class StudySession extends Component
{
    use Notices, PomodoroForm, TeachingForm;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $sessionId;

    /** end, delete, pomodoro, teaching, briefing or material: the side panel that's open, or null. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $error = null;

    /** The topic's status to record when the session ends; empty leaves it. */
    public string $topicStatus = '';

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

    public function boot(Sessions $sessions, Topics $topics, Modules $modules, Notes $notes, Files $files, Briefings $briefings, Questions $questions, Folders $folders, PrincipalFactory $principals, SessionChat $chat): void
    {
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

    /** Opens App\Livewire\Workspaces\FlashcardEditor for a card on the session's topic. */
    public function newFlashcard(): void
    {
        $this->dispatch('flashcard-new', topicId: $this->sessions->find($this->principal(), $this->sessionId)->topicId);
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

    public function confirmEnd(): void
    {
        $this->open('end');
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
        if ($this->mode === 'end') {
            $status = in_array($this->topicStatus, Topics::STATUSES, true) ? $this->topicStatus : null;
            $this->act(function () use ($by, $status) {
                $ended = $this->sessions->end($by, $this->sessionId);
                if ($status !== null && $ended->topicId !== null) {
                    try {
                        $this->topics->report($by, $ended->topicId, $status);
                    } catch (NotFound) {
                        // The topic was removed meanwhile; the session still ends.
                    }
                }
            });
            if ($this->error === null) {
                $wrapped = $this->wrapUp($by);
                $this->notice = 'Session ended. You studied '.SessionDetails::duration($this->sessions->find($by, $this->sessionId)->studySeconds).'.'.($wrapped ? ' The tutor\'s summary of the chat is saved below.' : '');
            }
        }
        $this->close();
        $this->dispatch('session-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'topicStatus', 'clock', 'preset', 'focus', 'short', 'long', 'every', 'auto', 'method', 'checkIns', 'quiz', 'pace');
        $this->resetErrorBag();
    }

    #[On('session-changed')]
    #[On('questions-changed')]
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

        return view('livewire.workspaces.study-session', [
            'session' => $session,
            'chatted' => $this->mode === 'end' && $this->chat->transcript($by, $this->sessionId) !== [],
            'topic' => $topic,
            'module' => $module,
            'groups' => $material['groups'],
            'alsoUsed' => $material['alsoUsed'],
            'materialCount' => $material['count'],
            'usedCount' => $material['used'],
            'openQuestions' => count(array_filter(
                $module !== null ? $this->questions->list($by, $this->workspaceId, $module->id) : $this->questions->list($by, $this->workspaceId, null, $this->sessionId),
                fn ($question) => $question->status !== 'answered',
            )),
            'briefing' => $this->mode === 'briefing' ? $this->briefings->forSession($by, $this->sessionId) : null,
            'timeline' => $this->timeline($session, $time),
            'started' => Carbon::parse($session->startedAt)->setTimezone($zone),
            'ended' => $session->endedAt === null ? null : Carbon::parse($session->endedAt)->setTimezone($zone),
            'awaySince' => $session->pausedBy === 'away' ? $time($session->lastActivityAt) : null,
        ]);
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

    /**
     * What happened, in order: study, breaks, and the pauses between them.
     *
     * @return list<array{kind: string, words: string, from: string, to: ?string, seconds: ?int}>
     */
    private function timeline(SessionDetails $session, callable $time): array
    {
        $rows = [];
        $previous = null;
        foreach ($session->segments as $segment) {
            if ($previous?->endedAt !== null && $previous->endedAt < $segment->startedAt) {
                $rows[] = ['kind' => 'pause', 'words' => self::pauseWords($previous),
                    'from' => $time($previous->endedAt), 'to' => $time($segment->startedAt), 'seconds' => null];
            }
            $end = $segment->endedAt ?? now()->toIso8601ZuluString('microsecond');
            $rows[] = [
                'kind' => $segment->kind,
                'words' => match (true) {
                    $segment->kind === 'break' => 'Break',
                    $segment->endedBy === 'pomodoro' => 'Pomodoro done',
                    $segment->endedBy === 'skip' => 'Focus, ended early',
                    default => 'Studied',
                },
                'from' => $time($segment->startedAt),
                'to' => $segment->endedAt === null ? null : $time($segment->endedAt),
                'seconds' => max(0, (int) Carbon::parse($segment->startedAt)->diffInSeconds(Carbon::parse($end))),
            ];
            $previous = $segment;
        }
        if ($session->state === 'paused' && $previous?->endedAt !== null) {
            $rows[] = ['kind' => 'pause', 'words' => self::pauseWords($previous),
                'from' => $time($previous->endedAt), 'to' => null, 'seconds' => null];
        }

        return $rows;
    }

    /** Why the clock stopped after $previous. */
    private static function pauseWords(SessionSegment $previous): string
    {
        return match ($previous->endedBy) {
            'away' => 'Paused: no activity',
            'long_break' => 'Paused after a long break',
            'pomodoro' => 'Waiting to start the next focus',
            default => 'Paused',
        };
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
