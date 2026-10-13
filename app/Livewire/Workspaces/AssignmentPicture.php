<?php

namespace App\Livewire\Workspaces;

use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The full picture of an assignment, on its page (the owner's ask, 2026-10-13): everything the student was given about
 * it (its sources: the brief, the marking criteria, emails, announcements, what the teacher said, their notes and
 * corrections, and the files in its folder), and what the tutor's model understands from them, kept as revisions every
 * model reads first. A change to the sources updates the understanding by itself; the student says it is right, or
 * corrects it (a correction is a source too). A thin adapter: App\Study\AssignmentSources and AssignmentUnderstandings
 * check everything, and the reading runs as a job (App\Engine\Jobs\UnderstandAssignment) this polls until it is done.
 */
final class AssignmentPicture extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    /** The form for a source: open, and the one it edits (null for a new one). */
    public bool $adding = false;

    #[Locked]
    public ?string $editing = null;

    public string $kind = 'brief';

    public string $title = '';

    public string $text = '';

    public string $receivedOn = '';

    public bool $correcting = false;

    public string $correction = '';

    public bool $showHistory = false;

    /** The reading being waited for. */
    #[Locked]
    public ?string $jobId = null;

    /** Why the last reading didn't come to anything (its code), until another starts. */
    #[Locked]
    public ?string $problem = null;

    /** Read again once when the sources changed while it read: never more, so a reading can't chase itself. */
    #[Locked]
    public bool $readAgain = false;

    private AssignmentSources $sources;

    private AssignmentUnderstandings $understandings;

    private Runner $runner;

    private PrincipalFactory $principals;

    public function boot(AssignmentSources $sources, AssignmentUnderstandings $understandings, Runner $runner, PrincipalFactory $principals): void
    {
        [$this->sources, $this->understandings, $this->runner, $this->principals] = [$sources, $understandings, $runner, $principals];
    }

    public function mount(string $workspaceId, string $activityId): void
    {
        [$this->workspaceId, $this->activityId] = [$workspaceId, $activityId];
        // A reading still going (the page was reloaded meanwhile) is waited for, not started again.
        $this->jobId = $this->runner->pending($this->principal(), 'understand_assignment', 'activity', $activityId);
    }

    // ---------- Sources ----------

    public function add(string $kind = 'brief'): void
    {
        $this->reset('editing', 'title', 'text', 'receivedOn');
        $this->resetErrorBag();
        $this->kind = isset(AssignmentSources::KINDS[$kind]) ? $kind : 'brief';
        $this->adding = true;
    }

    public function edit(string $id): void
    {
        $source = collect($this->sources->list($this->principal(), $this->activityId))->firstWhere('id', $id);
        if ($source === null) {
            return;
        }
        $this->resetErrorBag();
        [$this->editing, $this->kind, $this->title, $this->text, $this->receivedOn] = [$source->id, $source->kind, (string) $source->title, $source->text, (string) $source->receivedOn];
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->reset('adding', 'editing', 'title', 'text', 'receivedOn', 'correcting', 'correction');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $input = ['kind' => $this->kind, 'title' => $this->title, 'text' => $this->text, 'received_on' => $this->receivedOn];
        try {
            $this->editing === null
                ? $this->sources->add($this->principal(), $this->activityId, $input)
                : $this->sources->update($this->principal(), $this->editing, $input);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['received_on' => 'receivedOn'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->cancel();

            return;
        }
        $this->cancel();
        $this->notify('Kept. The AI is updating what it understands.');
        $this->understand();
    }

    public function delete(string $id): void
    {
        $by = $this->principal();
        try {
            $this->sources->delete($by, $id);
        } catch (NotFound) {
            return;
        }
        // With nothing left to read, what it understood stays (out of date) until something is given again.
        if ($this->sources->list($by, $this->activityId) === [] && $this->sources->files($by, $this->activityId) === []) {
            $this->notify('Deleted.');

            return;
        }
        $this->notify('Deleted. The AI is updating what it understands.');
        $this->understand();
    }

    /** Files were added to its folder: drawn again, so the understanding says it is out of date. */
    #[On('assignment-files-changed')]
    public function filesChanged(): void {}

    // ---------- The understanding ----------

    /** Reads everything again, unless a reading is already going. */
    public function understand(): void
    {
        $by = $this->principal();
        if ($this->sources->list($by, $this->activityId) === [] && $this->sources->files($by, $this->activityId) === []) {
            $this->problem = 'no_sources';

            return;
        }
        $this->problem = null;
        $this->jobId = $this->runner->pending($by, 'understand_assignment', 'activity', $this->activityId)
            ?? $this->runner->start($by, new UnderstandAssignment($this->workspaceId, $this->activityId));
        $this->check();
    }

    /** What the reading has come to: still going, done (the new revision shows), or why not. */
    public function check(): void
    {
        if ($this->jobId === null) {
            return;
        }
        try {
            $status = $this->runner->status($this->principal(), $this->jobId);
        } catch (NotFound) {
            $this->jobId = null;

            return;
        }
        if (in_array($status['status'], ['queued', 'running'], true)) {
            return;
        }
        $this->jobId = null;
        if ($status['status'] !== 'done') {
            $this->problem = $status['error_code'] ?? 'error';

            return;
        }
        // The sources changed while it read: once more, with them.
        $latest = $this->understandings->latest($this->principal(), $this->activityId);
        if (! $this->readAgain && $latest !== null && $this->sources->outOfDate($this->principal(), $this->activityId, $latest)) {
            $this->readAgain = true;
            $this->understand();

            return;
        }
        $this->readAgain = false;
    }

    /** The student says it is right. */
    public function confirm(): void
    {
        try {
            $this->understandings->confirm($this->principal(), $this->activityId);
        } catch (NotFound) {
            return;
        }
        $this->notify('Thanks. Every model that helps you with it starts from this.');
    }

    /** What the AI got wrong, in the student's words: a source of its own, and the understanding is read again. */
    public function correct(): void
    {
        $this->resetErrorBag();
        try {
            $this->sources->add($this->principal(), $this->activityId, ['kind' => 'correction', 'text' => $this->correction]);
        } catch (Unprocessable $e) {
            $this->addError('correction', $e->details['fields']['text'][0] ?? $e->getMessage());

            return;
        }
        $this->reset('correcting', 'correction');
        $this->notify('Kept. The AI is correcting what it understands.');
        $this->understand();
    }

    public function render(): View
    {
        $by = $this->principal();
        $latest = $this->understandings->latest($by, $this->activityId);
        $written = $this->sources->list($by, $this->activityId);
        $files = $this->sources->files($by, $this->activityId);

        return view('livewire.workspaces.assignment-picture', [
            'written' => $written,
            'files' => $files,
            'understanding' => $latest,
            'stale' => $latest !== null && $this->sources->outOfDate($by, $this->activityId, $latest),
            'history' => $this->showHistory ? $this->understandings->history($by, $this->activityId) : [],
            'reading' => $this->jobId !== null,
            'problemText' => $this->problem === null ? null : self::problemText($this->problem),
            'kinds' => AssignmentSources::KINDS,
        ]);
    }

    private static function problemText(string $code): string
    {
        return match ($code) {
            'no_sources' => 'Add the brief, its marking criteria or a file first: there is nothing to understand yet.',
            'engine_key', 'engine_model', 'engine_consent' => 'Set up your AI first (AI settings), then try again.',
            'engine_cap' => 'This month\'s AI limit is reached. Raise it in AI settings.',
            'engine_unreadable' => 'The AI\'s answer couldn\'t be read. Try again.',
            default => 'The AI didn\'t answer this time. Try again.',
        };
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
