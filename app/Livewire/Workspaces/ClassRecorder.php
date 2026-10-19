<?php

declare(strict_types=1);

namespace App\Livewire\Workspaces;

use App\Engine\Jobs\Runner;
use App\Engine\Jobs\TranscribeClass;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassNote;
use App\Study\ClassRecordings;
use App\Study\ClassSessionDetails;
use App\Study\ClassSessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Permission to record a class, then one recording and one note of marks. */
final class ClassRecorder extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $moduleId;

    #[Locked]
    public string $classId;

    public string $from = '';

    public string $until = '';

    public string $about = '';

    public string $body = '';

    /** @var list<array{from: string, until: string, about: string, body: string}> */
    public array $marks = [];

    /** The transcription under way, if one is (its engine_jobs row). */
    #[Locked]
    public ?string $transcribeJob = null;

    /** Why the last transcription did not finish, in plain words. */
    public ?string $transcribeProblem = null;

    private Runner $runner;

    private ClassSessions $classes;

    private ClassRecordings $recordings;

    private ClassNote $classNote;

    private PrincipalFactory $principals;

    public function boot(ClassSessions $classes, ClassRecordings $recordings, ClassNote $classNote, PrincipalFactory $principals, Runner $runner): void
    {
        $this->runner = $runner;
        $this->classes = $classes;
        $this->recordings = $recordings;
        $this->classNote = $classNote;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $moduleId, string $classId): void
    {
        $this->workspaceId = $workspaceId;
        $this->moduleId = $moduleId;
        $this->classId = $classId;
        $this->class();
        $this->marks = $this->classNote->read($this->principal(), $this->classId);
        $this->transcribeJob = $this->runner->pending($this->principal(), 'transcribe_class', 'class_session', $this->classId);
    }

    /** Writes the class down and makes lecture notes from it, in the background (TranscribeClass). */
    public function transcribe(): void
    {
        $by = $this->principal();
        $this->class();
        $this->transcribeProblem = null;
        $this->transcribeJob = $this->runner->pending($by, 'transcribe_class', 'class_session', $this->classId)
            ?? $this->runner->start($by, new TranscribeClass($this->workspaceId, $this->classId));
    }

    public function allow(): void
    {
        $this->resetErrorBag();
        $this->class();
        $this->recordings->allow($this->principal(), $this->classId);
    }

    /** The id of the part to fill, or an empty string when recording cannot start. */
    public function startPart(): string
    {
        $this->resetErrorBag();
        $this->class();
        try {
            return $this->recordings->start($this->principal(), $this->classId)->id;
        } catch (Unprocessable $e) {
            $this->addError('recording', $this->message($e));

            return '';
        }
    }

    public function finishPart(string $id, int $seconds): void
    {
        $this->resetErrorBag();
        $this->class();
        try {
            $this->recordings->finish($this->principal(), $this->classId, $id, $seconds);
        } catch (Unprocessable $e) {
            $this->addError('recording', $this->message($e));
        } catch (NotFound) {
            $this->addError('recording', 'This recording could not be found.');
        }
    }

    public function addMark(): void
    {
        $this->resetErrorBag();
        $this->class();
        $marks = $this->marks;
        $marks[] = [
            'from' => $this->from,
            'until' => $this->until,
            'about' => $this->about,
            'body' => $this->body,
        ];
        if (! $this->keep($marks)) {
            return;
        }
        $this->reset('from', 'until', 'about', 'body');
    }

    public function saveMarks(): void
    {
        $this->resetErrorBag();
        $this->class();
        $this->keep($this->marks);
    }

    public function removeMark(int $index): void
    {
        $this->resetErrorBag();
        $this->class();
        if (! isset($this->marks[$index])) {
            return;
        }
        $marks = $this->marks;
        array_splice($marks, $index, 1);
        $this->keep($marks);
    }

    /** Keeps what arrived of a take that never finished. */
    public function keepPart(string $id): void
    {
        $this->resetErrorBag();
        $this->class();
        try {
            $this->recordings->recover($this->principal(), $this->classId, $id);
        } catch (Unprocessable $e) {
            $this->addError('recording', $this->message($e));
        } catch (NotFound) {
            $this->addError('recording', 'This recording could not be found.');
        }
    }

    public function discard(string $id): void
    {
        $this->resetErrorBag();
        $this->class();
        try {
            $this->recordings->discard($this->principal(), $this->classId, $id);
        } catch (Conflict $e) {
            $this->addError('recording', $e->getMessage());
        } catch (NotFound) {
            $this->addError('recording', 'This recording could not be found.');
        }
    }

    public function render(): View
    {
        $by = $this->principal();
        $class = $this->class();

        $open = [];
        $tracks = [];
        foreach ($this->recordings->forClass($by, $this->classId) as $part) {
            if ($part->isOpen()) {
                $open[] = $part;
            }
        }
        foreach ($this->recordings->timeline($by, $this->classId) as $track) {
            $tracks[] = [
                'src' => route('workspaces.modules.classes.audio', [$this->workspaceId, $this->moduleId, $this->classId, $track['id']]),
                'seconds' => $track['seconds'],
            ];
        }

        // A transcription that has ended: done shows its notes; a failure says why, once.
        if ($this->transcribeJob !== null) {
            $run = $this->runner->status($by, $this->transcribeJob);
            if (! in_array($run['status'], ['queued', 'running'], true)) {
                $this->transcribeJob = null;
                $this->transcribeProblem = $run['status'] === 'done' ? null : self::problem($run['error_code']);
                $class = $this->class();
            }
        }

        return view('livewire.workspaces.class-recorder', [
            'class' => $class,
            'open' => $open,
            'tracks' => $tracks,
            'chunkBase' => url('/courses/'.$this->workspaceId.'/modules/'.$this->moduleId.'/classes/'.$this->classId.'/recordings'),
        ]);
    }

    /** What went wrong with a transcription, in the student's words. */
    private static function problem(?string $code): string
    {
        return match ($code) {
            'engine_key', 'engine_not_set_up', 'engine_bad_key' => 'Add your AI key in Settings, AI, then try again.',
            'engine_consent' => 'Agree to use the AI in Settings, AI, then try again.',
            'engine_cap' => 'This month\'s AI limit is reached. Raise it in Settings, AI, to keep going.',
            'engine_credit' => 'Your AI account is out of credit. Top it up, then try again.',
            'engine_model' => 'The model that writes classes down is not available with your key. Check Settings, AI.',
            'ffmpeg_missing' => 'The recording could not be cut into pieces on this server.',
            'no_recording' => 'There is no finished recording to write down.',
            default => 'Writing the class down didn\'t work. Try again.',
        };
    }

    /** @param  array<mixed>  $marks */
    private function keep(array $marks): bool
    {
        try {
            $this->marks = $this->classNote->write($this->principal(), $this->classId, $marks);
        } catch (Unprocessable $e) {
            $this->fields($e);

            return false;
        }

        return true;
    }

    private function class(): ClassSessionDetails
    {
        $class = $this->classes->find($this->principal(), $this->classId);
        ($class->workspaceId === $this->workspaceId && $class->moduleId === $this->moduleId) || throw new NotFound;

        return $class;
    }

    private function message(Unprocessable $e): string
    {
        $fields = $e->details['fields'] ?? [];
        $first = is_array($fields) ? reset($fields) : false;

        return is_array($first) ? (string) ($first[0] ?? $e->getMessage()) : $e->getMessage();
    }

    private function fields(Unprocessable $e): void
    {
        $fields = $e->details['fields'] ?? [];
        if (! is_array($fields) || $fields === []) {
            $this->addError('mark', $e->getMessage());

            return;
        }
        foreach ($fields as $field => $messages) {
            $text = is_array($messages) ? (string) ($messages[0] ?? '') : '';
            $this->addError((string) $field, $text !== '' ? $text : $e->getMessage());
        }
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
