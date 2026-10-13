<?php

namespace App\Livewire\Study;

use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Sessions;
use App\Study\TutorMemories;
use App\Study\TutorMemory;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What the tutor remembers about the student (App\Study\TutorMemories), in one window on every student page, opened with
 * the `memories-open` event (from a "Remembered" chip in the chat, with that line's id; from Settings; from a course's
 * Instructions for the AI). Every line, by where it holds: all courses, then this course, then the others. Each can be
 * changed or deleted, and the student adds their own. Changes are kept at once and the chats are told.
 */
final class Memories extends Component
{
    /** The course the page is in, or null (Settings, Home): new lines are for it unless "all courses" is ticked. */
    #[Locked]
    public ?string $workspaceId = null;

    #[Locked]
    public bool $open = false;

    /** The line opened from a chat's chip, shown marked. */
    #[Locked]
    public ?string $highlight = null;

    /** The line being changed, or null. */
    #[Locked]
    public ?string $editId = null;

    public string $editText = '';

    public bool $editEverywhere = false;

    public string $newText = '';

    public bool $newEverywhere = false;

    private TutorMemories $memories;

    private PrincipalFactory $principals;

    public function boot(TutorMemories $memories, PrincipalFactory $principals): void
    {
        $this->memories = $memories;
        $this->principals = $principals;
    }

    public function mount(?string $workspaceId = null): void
    {
        $this->workspaceId = $workspaceId;
    }

    #[On('memories-open')]
    public function show(?string $id = null): void
    {
        $this->reset('editId', 'editText', 'editEverywhere', 'newText', 'newEverywhere');
        $this->resetErrorBag();
        [$this->open, $this->highlight] = [true, $id];
        $this->dispatch('memories-dialog-open');
    }

    public function close(): void
    {
        $this->reset('open', 'highlight', 'editId', 'editText', 'editEverywhere', 'newText', 'newEverywhere');
        $this->resetErrorBag();
    }

    public function add(): void
    {
        $this->resetErrorBag('newText');
        try {
            $this->memories->add($this->principal(), $this->newEverywhere || $this->workspaceId === null ? null : $this->workspaceId, $this->newText, 'student');
        } catch (Unprocessable $e) {
            $this->addError('newText', $e->details['fields']['text'][0] ?? $e->getMessage());

            return;
        } catch (NotFound) {
            $this->addError('newText', 'That course is gone. Close this and try again.');

            return;
        }
        $this->reset('newText', 'newEverywhere');
        $this->dispatch('memories-changed');
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag('editText');
        try {
            $memory = $this->memories->find($this->principal(), $id);
        } catch (NotFound) {
            return;
        }
        [$this->editId, $this->editText, $this->editEverywhere] = [$memory->id, $memory->text, $memory->everywhere()];
    }

    public function save(): void
    {
        if ($this->editId === null) {
            return;
        }
        $this->resetErrorBag('editText');
        $by = $this->principal();
        try {
            $memory = $this->memories->find($by, $this->editId);
            // A line for one course stays in it; one for all courses can come to the course the page is in.
            $course = $memory->workspaceId ?? $this->workspaceId;
            $this->memories->update($by, $memory->id, $this->editText, $this->editEverywhere || $course === null ? null : $course);
        } catch (Unprocessable $e) {
            $this->addError('editText', $e->details['fields']['text'][0] ?? $e->getMessage());

            return;
        } catch (NotFound) {
            // Deleted meanwhile: nothing to change.
        }
        $this->cancel();
        $this->dispatch('memories-changed');
    }

    public function cancel(): void
    {
        $this->reset('editId', 'editText', 'editEverywhere');
        $this->resetErrorBag('editText');
    }

    public function forget(string $id): void
    {
        try {
            $this->memories->forget($this->principal(), $id);
        } catch (NotFound) {
            // Gone already.
        }
        if ($this->editId === $id) {
            $this->cancel();
        }
        $this->dispatch('memories-changed');
    }

    public function render(): View
    {
        if (! $this->open) {
            return view('livewire.study.memories', ['groups' => [], 'count' => 0, 'remembers' => true, 'zone' => 'UTC', 'courseName' => null]);
        }
        $by = $this->principal();
        $names = [];
        foreach (app(Workspaces::class)->list($by) as $workspace) {
            $names[$workspace->id] = $workspace->name;
        }
        // All courses, then the course the page is in, then the others by name.
        $groups = [];
        foreach ($this->memories->all($by) as $memory) {
            $key = $memory->workspaceId ?? '';
            $groups[$key] ??= ['title' => $memory->everywhere() ? 'All your courses' : ($names[$key] ?? 'A course'), 'here' => $key !== '' && $key === $this->workspaceId, 'items' => []];
            $groups[$key]['items'][] = $memory;
        }
        uksort($groups, fn (string $a, string $b) => [$a !== '', $a !== $this->workspaceId, $groups[$a]['title']] <=> [$b !== '', $b !== $this->workspaceId, $groups[$b]['title']]);

        return view('livewire.study.memories', [
            'groups' => array_values($groups),
            'count' => array_sum(array_map(fn (array $g) => count($g['items']), $groups)),
            'remembers' => app(Settings::class)->get($by)->tutorRemembers,
            'zone' => app(Sessions::class)->timezone($by),
            'courseName' => $this->workspaceId !== null ? ($names[$this->workspaceId] ?? null) : null,
        ]);
    }

    /** For the view: whether a line can be moved between all courses and one course from here. */
    public function movable(TutorMemory $memory): bool
    {
        return ($memory->workspaceId ?? $this->workspaceId) !== null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
