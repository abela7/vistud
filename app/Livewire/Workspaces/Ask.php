<?php

namespace App\Livewire\Workspaces;

use App\Engine\Attachments;
use App\Engine\Helper;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Study\FileTypes;
use App\Study\Folders;
use App\Study\Sessions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ask (docs/specs/vistud-2-blueprint.md §3.6.1, Phase 5): a short chat with the quick helper about the course, from a button in
 * the top bar of every course page. It can look things up in the course (topics, questions, key points, notes, files) and
 * changes nothing. The talk lives on this page only: it is not stored, and leaving the page ends it. Teaching and long
 * explanations belong to the tutor, so one line at the bottom starts a session. The course's id is locked.
 */
final class Ask extends Component
{
    use Notices, WithFileUploads;

    /** The most the student can ask at once, and how much of the talk the helper is reminded of. */
    public const MAX_ASK = 2_000;

    public const REMEMBER = 3_000;

    /** The talk kept on the page: the oldest go first. */
    public const KEEP = 12;

    public const MAX_UPLOADS = 4;

    public const MAX_UPLOAD_KB = 20_480;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public ?string $moduleId = null;

    #[Locked]
    public ?string $folderId = null;

    /** @var list<array{from: string, text: string, attachments?: list<array{kind: string, name: string, url?: string, extension?: string}>}> from is you, helper or problem */
    #[Locked]
    public array $talk = [];

    public string $text = '';

    /**
     * Pictures or documents chosen, pasted or dropped, waiting to go with the question.
     *
     * @var list<UploadedFile>
     */
    public array $uploads = [];

    private Helper $helper;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    private Folders $folders;

    public function boot(Helper $helper, Sessions $sessions, PrincipalFactory $principals, Folders $folders): void
    {
        $this->helper = $helper;
        $this->sessions = $sessions;
        $this->principals = $principals;
        $this->folders = $folders;
    }

    public function mount(string $workspaceId, ?string $moduleId = null, ?string $folderId = null): void
    {
        $this->workspaceId = $workspaceId;
        $this->moduleId = $moduleId;
        $this->folderId = $folderId;
        if ($this->folderId !== null && $this->moduleId === null) {
            try {
                $folder = $this->folders->find($this->principal(), $this->folderId);
                $this->moduleId = $folder->moduleId;
            } catch (NotFound) {
                $this->folderId = null;
            }
        }
    }

    /**
     * Keeps valid files and pictures (up to MAX_UPLOADS); drops invalid or oversized files with an error.
     */
    public function updatedUploads(): void
    {
        $this->resetErrorBag('uploads');
        $kept = [];
        foreach ($this->uploads as $file) {
            $why = ! $file instanceof UploadedFile ? 'That is not a valid file.' : self::refusal($file);
            if ($why === null && count($kept) >= self::MAX_UPLOADS) {
                $why = 'Attach up to '.self::MAX_UPLOADS.' pictures or files to one question.';
            }
            if ($why === null) {
                $kept[] = $file;

                continue;
            }
            $this->addError('uploads', $file instanceof UploadedFile ? mb_substr(mb_scrub($file->getClientOriginalName()), 0, 80).': '.$why : $why);
            if ($file instanceof UploadedFile && method_exists($file, 'delete')) {
                $file->delete();
            }
        }
        $this->uploads = $kept;
    }

    /** Removes one upload from the pending attachments. */
    public function removeUpload(int $index): void
    {
        $file = $this->uploads[$index] ?? null;
        if ($file instanceof UploadedFile && method_exists($file, 'delete')) {
            $file->delete();
        }
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->resetErrorBag('uploads');
    }

    /** Sends a suggested quick prompt straight to the helper. */
    public function quickAsk(string $prompt): void
    {
        $this->text = $prompt;
        $this->send();
    }

    /** Puts the student's question to the helper and adds its answer to the talk. */
    public function send(): void
    {
        $this->resetErrorBag();
        $words = trim($this->text);
        $files = $this->uploads;
        if ($words === '' && $files === []) {
            $this->addError('text', 'Write what you want to know.');

            return;
        }
        if (mb_strlen($words) > self::MAX_ASK) {
            $this->addError('text', 'Keep it to '.self::MAX_ASK.' characters.');

            return;
        }

        $earlier = $this->earlier();
        $attachedLog = [];
        $pictures = [];
        $documents = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $name = mb_scrub($file->getClientOriginalName());
            $ext = strtolower($file->getClientOriginalExtension());
            $bytes = (string) file_get_contents($file->getRealPath());
            $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);

            if ($isImage) {
                $mime = self::imageMime($bytes) ?: ('image/'.($ext === 'jpg' ? 'jpeg' : $ext));
                $dataUrl = Attachments::shrink($bytes, $mime);
                $pictures[] = [
                    'name' => $name,
                    'bytes' => $bytes,
                    'mime' => $mime,
                    'url' => $dataUrl,
                ];
                $attachedLog[] = [
                    'kind' => 'image',
                    'name' => $name,
                    'url' => $dataUrl,
                ];
            } else {
                $documents[] = [
                    'name' => $name,
                    'bytes' => $bytes,
                    'extension' => $ext,
                ];
                $attachedLog[] = [
                    'kind' => 'file',
                    'name' => $name,
                    'extension' => $ext,
                ];
            }
            if (method_exists($file, 'delete')) {
                $file->delete();
            }
        }

        $entry = ['from' => 'you', 'text' => $words];
        if ($attachedLog !== []) {
            $entry['attachments'] = $attachedLog;
        }
        $this->talk[] = $entry;
        $this->text = '';
        $this->uploads = [];

        try {
            $answer = $this->helper->quick(
                $this->principal(),
                $words !== '' ? $words : 'Tell me what you see in the attached material.',
                $earlier,
                $this->moduleId,
                $this->workspaceId,
                $this->folderId,
                $pictures,
                $documents,
            );
            $this->talk[] = ['from' => 'helper', 'text' => $answer];
        } catch (AppError $e) {
            $problem = $e->details['fields']['pictures'][0]
                ?? $e->details['fields']['task'][0]
                ?? $e->details['fields']['thing'][0]
                ?? $e->getMessage();
            $this->talk[] = ['from' => 'problem', 'text' => $problem];
        }
        $this->talk = array_slice($this->talk, -self::KEEP);
    }

    /** Starts over: nothing was kept anyway. */
    public function clear(): void
    {
        foreach ($this->uploads as $file) {
            if ($file instanceof UploadedFile && method_exists($file, 'delete')) {
                $file->delete();
            }
        }
        $this->uploads = [];
        $this->talk = [];
        $this->text = '';
        $this->resetErrorBag();
    }

    /** The tutor teaches: back to the open session, or a new one in this course. */
    public function teach(): void
    {
        $by = $this->principal();
        $open = $this->sessions->current($by);
        if ($open !== null && $open->workspaceId === $this->workspaceId) {
            $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $open->id], navigate: true);

            return;
        }
        try {
            $last = $this->sessions->lastChoices($by, $this->workspaceId);
            $session = $this->sessions->start($by, $this->workspaceId, null, $this->moduleId, $last['pomodoro'], $last['tutoring'], null, $this->folderId);
        } catch (Conflict) {
            $this->notify('Another session is open. End it first, or go back to it.', 'warning');

            return;
        }
        $this->dispatch('session-changed');
        $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.workspaces.ask');
    }

    /** What was said so far, as a reminder for the helper: the last exchanges, within a few thousand characters. */
    private function earlier(): string
    {
        $lines = [];
        foreach ($this->talk as $line) {
            if ($line['from'] !== 'problem') {
                $attText = '';
                if (! empty($line['attachments'])) {
                    $names = array_column($line['attachments'], 'name');
                    $attText = ' [Attached: '.implode(', ', $names).']';
                }
                $lines[] = ($line['from'] === 'you' ? 'Student: ' : 'You: ').$line['text'].$attText;
            }
        }
        $text = implode("\n", array_slice($lines, -6));

        return $text === '' ? '' : "The talk so far:\n".mb_substr($text, -self::REMEMBER);
    }

    /** Why a chosen file can't go as an attachment, in a few words; null when it can. */
    private static function refusal(UploadedFile $file): ?string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = array_keys(FileTypes::TYPES);
        $allowed[] = 'jpeg';
        if (! in_array($ext, $allowed, true)) {
            return 'Choose a picture, PDF, Word or text file.';
        }
        if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
            return 'That file is too big. Attach one under 20 MB.';
        }

        return null;
    }

    /** The kind of picture some bytes are, from the bytes themselves, or empty when not one. */
    private static function imageMime(string $bytes): string
    {
        $type = @getimagesizefromstring($bytes);

        return is_array($type) && in_array($type['mime'] ?? '', ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? $type['mime'] : '';
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
