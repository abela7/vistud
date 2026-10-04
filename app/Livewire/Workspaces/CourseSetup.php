<?php

namespace App\Livewire\Workspaces;

use App\Engine\Jobs\ProfileCourse;
use App\Engine\Jobs\Runner;
use App\Engine\Role;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\CourseProfiles;
use App\Study\Files;
use App\Study\LearnerProfiles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Setting a course up (docs/specs/vistud-2-blueprint.md §3.5.2), after "New course" and later from the course's ⋯
 * menu (*About this course*, *How you learn*): two skippable steps in one sheet. *What is this course?* takes a
 * syllabus (a file, or pasted text) that the reader turns into the course's profile and a module list to tick, or
 * the student writes it themselves; *How do you like to learn?* is four short questions. A thin adapter over
 * App\Study\CourseProfiles and LearnerProfiles, which check everything; the reading runs as a job
 * (App\Engine\Jobs\ProfileCourse) the sheet polls until it is done.
 */
final class CourseSetup extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public bool $openOnLoad = false;

    /** Opened by "New course": Skip goes on to the next step instead of closing. */
    #[Locked]
    public bool $flow = false;

    /** about or learn */
    #[Locked]
    public string $step = 'about';

    /** The reading in progress, when there is one. */
    #[Locked]
    public ?string $jobId = null;

    /** The code of why the last reading didn't work. */
    #[Locked]
    public ?string $problem = null;

    /** What was added to the course, to say so on the page the sheet ends on ("2 modules added."). */
    #[Locked]
    public ?string $added = null;

    public $file = null;

    public string $syllabusText = '';

    public bool $writing = false;

    public string $about = '';

    public string $outcomes = '';

    public string $textbook = '';

    /** @var list<array{name: string, kind: string, weight: string, due_on: string, activity_id: ?string}> */
    public array $assessment = [];

    /** @var array<int, bool> */
    public array $moduleTicks = [];

    /** @var array<int, bool> */
    public array $assessmentTicks = [];

    /** @var list<string> */
    public array $explain = [];

    public string $pace = '';

    public string $check = '';

    public string $goal = '';

    public string $note = '';

    private CourseProfiles $profiles;

    private LearnerProfiles $learner;

    private Runner $runner;

    private Files $files;

    private Settings $settings;

    private PrincipalFactory $principals;

    public function boot(CourseProfiles $profiles, LearnerProfiles $learner, Runner $runner, Files $files, Settings $settings, PrincipalFactory $principals): void
    {
        $this->profiles = $profiles;
        $this->learner = $learner;
        $this->runner = $runner;
        $this->files = $files;
        $this->settings = $settings;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, bool $openOnLoad = false): void
    {
        $this->workspaceId = $workspaceId;
        $this->openOnLoad = $openOnLoad;
        $this->flow = $openOnLoad;
        $this->load();
    }

    /** Opens the sheet on a step: from the ⋯ menu, or the course home. */
    #[On('course-setup-open')]
    public function open(string $step = 'about'): void
    {
        $this->step = $step === 'learn' ? 'learn' : 'about';
        $this->flow = false;
        $this->problem = null;
        $this->resetErrorBag();
        $this->load();
        $this->dispatch('course-setup-dialog-open');
    }

    /** Hands the syllabus (the chosen file, or the pasted text) to the reader. */
    public function read(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();
        $this->problem = null;
        if ($this->notReady() !== null) {
            return;
        }
        try {
            if ($this->file !== null) {
                $stored = $this->files->upload($by, 'workspace', $this->workspaceId, $this->file->getRealPath(), $this->file->getClientOriginalName());
                $this->profiles->setSyllabus($by, $this->workspaceId, $stored->id, null);
            } else {
                $this->profiles->setSyllabus($by, $this->workspaceId, null, $this->syllabusText);
            }
            $this->jobId = $this->runner->start($by, new ProfileCourse($this->workspaceId));
        } catch (Unprocessable $e) {
            $this->addError('syllabus', $e->details['fields']['file'][0] ?? $e->details['fields']['syllabus'][0] ?? $e->getMessage());

            return;
        } catch (Conflict $e) {
            $this->addError('syllabus', $e->getMessage());

            return;
        }
        $this->reset('file', 'syllabusText');
        $this->check();
    }

    /** What the reading has come to: still going, done (its findings fill the sheet), or why not. */
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
        if ($status['status'] === 'done') {
            $this->load();

            return;
        }
        $this->problem = $status['error_code'] ?? 'error';
    }

    /** Shows the fields to fill in by hand. */
    public function write(): void
    {
        $this->writing = true;
    }

    /** Keeps what is written, adds what is ticked (the modules, an assignment for each dated assessment), and goes on. */
    public function add(): mixed
    {
        $by = $this->principal();
        $this->resetErrorBag();
        [$rows, $ticks] = $this->cleanRows();
        try {
            $this->profiles->save($by, $this->workspaceId, $this->aboutInput($rows));
            $added = $this->profiles->addFromReading(
                $by, $this->workspaceId,
                array_keys(array_filter($this->moduleTicks)),
                array_keys(array_filter($ticks)),
            );
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }
        $parts = array_filter([
            $added['modules'] > 0 ? $added['modules'].($added['modules'] === 1 ? ' module' : ' modules') : null,
            $added['assignments'] > 0 ? $added['assignments'].($added['assignments'] === 1 ? ' assignment' : ' assignments') : null,
        ]);
        $this->added = $parts === [] ? 'The course is saved.' : implode(' and ', $parts).' added.';

        return $this->next();
    }

    public function addRow(): void
    {
        $this->assessment[] = ['name' => '', 'kind' => 'assignment', 'weight' => '', 'due_on' => '', 'activity_id' => null];
        // Once it has a date it is an assignment, unless the student unticks it.
        $this->assessmentTicks[count($this->assessment) - 1] = true;
    }

    public function removeRow(int $index): void
    {
        unset($this->assessment[$index], $this->assessmentTicks[$index]);
        $this->assessment = array_values($this->assessment);
        $this->assessmentTicks = array_values($this->assessmentTicks);
    }

    /** All the proposed modules ticked, or none. */
    public function tickModules(bool $on): void
    {
        $this->moduleTicks = array_fill(0, count($this->moduleTicks), $on);
    }

    /** Not these modules after all. */
    public function dismiss(): void
    {
        $this->profiles->dismissProposal($this->principal(), $this->workspaceId);
        $this->moduleTicks = [];
    }

    /** Skip, or done with this step: on to the next, or close. */
    public function next(): mixed
    {
        if ($this->step === 'about' && $this->flow) {
            $this->step = 'learn';
            $this->load();

            return null;
        }

        return $this->finish();
    }

    public function saveLearn(): mixed
    {
        $this->resetErrorBag();
        try {
            $this->learner->save($this->principal(), $this->workspaceId, [
                'explain' => $this->explain, 'pace' => $this->pace, 'check' => $this->check, 'goal' => $this->goal, 'note' => $this->note,
            ]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        return $this->finish();
    }

    /** The sheet closed without saving: back to what is stored. */
    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->reset('file', 'syllabusText');
        $this->load();
    }

    public function render(): View
    {
        $by = $this->principal();
        $profile = $this->profiles->get($by, $this->workspaceId);

        return view('livewire.workspaces.course-setup', [
            'reading' => $this->jobId !== null,
            'proposed' => $profile->proposedModules,
            'notReady' => $this->notReady(),
            'problemText' => $this->problem === null ? null : self::problemText($this->problem),
            'kinds' => Activities::KINDS,
            'chips' => ['explain' => LearnerProfiles::EXPLAIN, 'pace' => LearnerProfiles::PACE, 'check' => LearnerProfiles::CHECK, 'goal' => LearnerProfiles::GOAL],
        ]);
    }

    // ---------- Inside ----------

    private function finish(): mixed
    {
        // A passing notice lasts one request, so it is said where the sheet ends, not on the way.
        if ($this->added !== null) {
            session()->flash('workspace-notice', $this->added);
        }

        return $this->redirectRoute('workspaces.show', $this->workspaceId, navigate: true);
    }

    private function load(): void
    {
        $by = $this->principal();
        $profile = $this->profiles->get($by, $this->workspaceId);
        $this->about = $profile->about;
        $this->outcomes = implode("\n", $profile->outcomes);
        $this->textbook = $profile->textbook;
        $this->assessment = array_map(fn (array $item) => [
            'name' => $item['name'], 'kind' => $item['kind'], 'weight' => $item['weight'] === null ? '' : (string) $item['weight'],
            'due_on' => (string) $item['due_on'], 'activity_id' => $item['activity_id'],
        ], $profile->assessment);
        $this->assessmentTicks = array_map(fn (array $item) => $item['due_on'] !== null && $item['activity_id'] === null, $profile->assessment);
        $this->moduleTicks = array_fill(0, count($profile->proposedModules), true);
        $this->writing = $profile->hasContent() || $profile->proposedModules !== [];

        $learn = $this->learner->get($by, $this->workspaceId);
        [$this->explain, $this->pace, $this->check, $this->goal, $this->note] = [$learn->explain, (string) $learn->pace, (string) $learn->check, (string) $learn->goal, $learn->note];
    }

    /**
     * The assessment rows with something in them, and the ticks that go with them: a row the student emptied is gone.
     *
     * @return array{0: list<array>, 1: array<int, bool>}
     */
    private function cleanRows(): array
    {
        $rows = [];
        $ticks = [];
        foreach (array_values($this->assessment) as $index => $row) {
            if (trim((string) ($row['name'] ?? '')) === '' && trim((string) ($row['due_on'] ?? '')) === '' && trim((string) ($row['weight'] ?? '')) === '') {
                continue;
            }
            $rows[] = $row;
            $ticks[count($rows) - 1] = (bool) ($this->assessmentTicks[$index] ?? false);
        }

        return [$rows, $ticks];
    }

    private function aboutInput(array $rows): array
    {
        return ['about' => $this->about, 'outcomes' => $this->outcomes, 'textbook' => $this->textbook, 'assessment' => $rows];
    }

    /** @return ?string why the reader can't be asked now, in one line; null when it can */
    private function notReady(): ?string
    {
        try {
            $this->settings->ready($this->principal(), Role::Reader);
        } catch (AppError $e) {
            return self::problemText($e->errorCode);
        }

        return null;
    }

    private static function problemText(string $code): string
    {
        return match ($code) {
            'engine_key', 'engine_model', 'engine_consent' => 'Set up your AI first, then read the syllabus.',
            'engine_cap' => 'This month\'s AI limit is reached. Raise it in AI settings.',
            'file_preparing' => 'The file is still being prepared. Try again in a minute.',
            'no_text' => 'No words found in it. Paste the syllabus text instead.',
            'engine_unreadable' => 'The AI\'s answer couldn\'t be read. Try again.',
            default => 'The AI didn\'t answer. Try again.',
        };
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
