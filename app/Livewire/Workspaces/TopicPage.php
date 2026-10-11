<?php

namespace App\Livewire\Workspaces;

use App\Engine\Helper;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\FolderDetails;
use App\Study\Folders;
use App\Study\MarkdownPreview;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Dedicated full Topic Page component (App\Livewire\Workspaces\TopicPage).
 * Enables learners to deep dive into a single topic:
 * 1. Watch progress and update status (Not started, Studying, Not understand, Understood)
 * 2. Add and manage questions specifically on this topic
 * 3. Chat with an AI tutor specifically scoped to this topic with standard robust compose interface
 * 4. Manage key points, rename, move, or delete the topic
 * 5. Access and add notes and files relevant to this topic
 */
final class TopicPage extends Component
{
    use Notices, WithFileUploads;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $topicId;

    public string $tab = 'ai';

    public string $name = '';

    public string $place = '';

    public string $newQuestionText = '';

    public string $newPoint = '';

    public string $pointSource = '';

    public string $pointLocator = '';

    public bool $confirmingRemove = false;

    public array $chat = [];

    public string $chatText = '';

    public array $chatUploads = [];

    public $uploadedFile = null;

    /** For inline answering of a question */
    public ?string $answeringQuestionId = null;

    public string $answeringText = '';

    private Topics $topics;

    private Modules $modules;

    private Folders $folders;

    private Questions $questions;

    private Flashcards $flashcards;

    private Findings $findings;

    private Files $files;

    private Notes $notes;

    private Sessions $sessions;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    private Helper $helper;

    public function boot(
        Topics $topics,
        Modules $modules,
        Folders $folders,
        Questions $questions,
        Flashcards $flashcards,
        Findings $findings,
        Files $files,
        Notes $notes,
        Sessions $sessions,
        Workspaces $workspaces,
        PrincipalFactory $principals,
        Helper $helper,
    ): void {
        $this->topics = $topics;
        $this->modules = $modules;
        $this->folders = $folders;
        $this->questions = $questions;
        $this->flashcards = $flashcards;
        $this->findings = $findings;
        $this->files = $files;
        $this->notes = $notes;
        $this->sessions = $sessions;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
        $this->helper = $helper;
    }

    public function mount(string $workspaceId, string $topicId): void
    {
        $this->workspaceId = $workspaceId;
        $this->topicId = $topicId;

        $by = $this->principal();
        $topic = $this->topics->find($by, $topicId);
        $topic->workspaceId === $workspaceId || throw new NotFound;

        $this->name = $topic->name;
        $this->place = match (true) {
            $topic->folderId !== null => "folder:{$topic->folderId}",
            $topic->moduleId !== null => "module:{$topic->moduleId}",
            default => '',
        };

        // Load chat history or initialize welcome
        $cached = Cache::get("topic_chat:{$by->learnerId}:{$topicId}");
        if (is_array($cached) && $cached !== []) {
            $this->chat = $cached;
        } else {
            $clean = Topics::cleanMarkdown($topic->name);
            $welcome = "👋 Hi! I am your dedicated AI Tutor for **{$clean}**.\n\n"
                .'Ask me to explain any confusing concepts, give practical real-world examples, or test your understanding with practice questions!';
            $this->chat = [
                [
                    'role' => 'assistant',
                    'text' => $welcome,
                    'html' => MarkdownPreview::html($welcome),
                    'time' => now()->format('H:i'),
                ],
            ];
            Cache::put("topic_chat:{$by->learnerId}:{$topicId}", $this->chat, now()->addDays(14));
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['ai', 'questions', 'materials', 'settings'], true)) {
            $this->tab = $tab;
        }
    }

    /** 1. Watch progress, update it */
    public function report(string $status): void
    {
        $this->resetErrorBag();
        try {
            $this->topics->report($this->principal(), $this->topicId, $status);
        } catch (Unprocessable $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $label = match ($status) {
            'not_started' => 'Not started',
            'covered' => 'Studying',
            'confused' => 'Not understand',
            'understood' => 'Understood',
            default => ucfirst($status),
        };

        $this->notify("Status marked as {$label}.");
        $this->dispatch('topics-changed');
    }

    /** 2. Add questions specifically on the topic */
    public function askQuestion(): void
    {
        $text = trim($this->newQuestionText);
        if ($text === '') {
            $this->addError('newQuestionText', 'Write a question first.');

            return;
        }

        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);

        try {
            $this->questions->ask(
                $by,
                $this->workspaceId,
                $text,
                topicId: $topic->id,
                moduleId: $topic->moduleId,
                folderId: $topic->folderId
            );
        } catch (Unprocessable $e) {
            $this->addError('newQuestionText', $e->getMessage());

            return;
        }

        $this->newQuestionText = '';
        $this->notify('Question added to this topic.');
        $this->dispatch('questions-changed');
    }

    public function startAnswering(string $questionId): void
    {
        $this->answeringQuestionId = $questionId;
        $this->answeringText = '';
    }

    public function cancelAnswering(): void
    {
        $this->answeringQuestionId = null;
        $this->answeringText = '';
    }

    public function saveAnswer(string $questionId): void
    {
        $answer = trim($this->answeringText);
        if ($answer === '') {
            return;
        }

        $by = $this->principal();
        $this->questions->setStatus($by, $questionId, 'answered', $answer);
        $this->answeringQuestionId = null;
        $this->answeringText = '';
        $this->notify('Question answered and marked understood.');
        $this->dispatch('questions-changed');
    }

    public function markQuestionStuck(string $questionId): void
    {
        $by = $this->principal();
        $this->questions->setStatus($by, $questionId, 'stuck');
        $this->notify('Question marked as stuck.');
        $this->dispatch('questions-changed');
    }

    public function removeChatUpload(int $index): void
    {
        unset($this->chatUploads[$index]);
        $this->chatUploads = array_values($this->chatUploads);
    }

    /** 3. Ask AI specifically on that topic only */
    public function sendChat(): void
    {
        $message = trim($this->chatText);
        $hasUploads = $this->chatUploads !== [];
        if ($message === '' && ! $hasUploads) {
            return;
        }

        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);
        $cleanName = Topics::cleanMarkdown($topic->name);

        $pictures = [];
        $files = [];
        $attSummaries = [];

        foreach ($this->chatUploads as $upload) {
            if (! $upload) {
                continue;
            }
            $origName = $upload->getClientOriginalName();
            $mime = (string) $upload->getMimeType();
            $isImage = str_starts_with($mime, 'image/');
            $bytes = (string) file_get_contents($upload->getRealPath());

            if ($isImage) {
                $pictures[] = [
                    'bytes' => $bytes,
                    'mime' => $mime,
                    'name' => $origName,
                ];
                $attSummaries[] = [
                    'name' => $origName,
                    'kind' => 'image',
                    'url' => method_exists($upload, 'temporaryUrl') ? $upload->temporaryUrl() : null,
                ];
            } else {
                $files[] = [
                    'bytes' => $bytes,
                    'name' => $origName,
                    'extension' => $upload->getClientOriginalExtension(),
                ];
                $attSummaries[] = [
                    'name' => $origName,
                    'kind' => 'file',
                    'url' => null,
                ];
            }
        }

        $this->chatUploads = [];

        $this->chat[] = [
            'role' => 'user',
            'text' => $message,
            'html' => null,
            'attachments' => $attSummaries,
            'time' => now()->format('H:i'),
        ];
        $this->chatText = '';

        // Contextual materials
        $points = $this->findings->byTopic($by, $this->workspaceId)[$topic->id] ?? [];
        $topicQuestions = array_filter($this->questions->list($by, $this->workspaceId), fn ($q) => $q->topicId === $topic->id);

        $contextLines = [];
        $contextLines[] = "Current Topic: {$cleanName}";
        $contextLines[] = "Student standing on topic: {$topic->shown()} ({$topic->evidence()})";

        if ($points !== []) {
            $contextLines[] = 'Key Points recorded:';
            foreach ($points as $pt) {
                $contextLines[] = "- {$pt->text}".($pt->sourceName ? " (from {$pt->sourceName})" : '');
            }
        }

        if ($topicQuestions !== []) {
            $contextLines[] = "Student's questions on this topic:";
            foreach (array_slice($topicQuestions, 0, 5) as $q) {
                $contextLines[] = "- [{$q->status}] {$q->text}".($q->answer ? " (Answer: {$q->answer})" : '');
            }
        }

        $context = implode("\n", $contextLines);
        $promptQuery = $message !== '' ? $message : 'Review the attached material(s) for this topic.';

        $task = "You are ViStud's dedicated AI Tutor helping a student deeply understand and master the specific topic '{$cleanName}'.\n"
            ."The student asks: \"{$promptQuery}\"\n\n"
            ."Instructions:\n"
            ."- Focus strictly and deeply on this topic ('{$cleanName}').\n"
            ."- Provide clear, engaging, structured explanations using markdown headers, bullet points, and bold terms.\n"
            ."- Use concrete examples, intuitive analogies, and walk-throughs.\n"
            ."- If the student is struggling or confused, break the idea down to fundamentals before building up.\n"
            .'- Keep the tone supportive, academic, and encouraging.';

        try {
            $response = $this->helper->quick(
                by: $by,
                task: $task,
                thing: $context,
                moduleId: $topic->moduleId,
                workspaceId: $this->workspaceId,
                folderId: $topic->folderId,
                pictures: $pictures,
                files: $files
            );
            $this->chat[] = [
                'role' => 'assistant',
                'text' => $response,
                'html' => MarkdownPreview::html($response),
                'time' => now()->format('H:i'),
            ];
        } catch (\Throwable $e) {
            $err = "I encountered an error connecting to the AI Tutor: {$e->getMessage()}. Please try again.";
            $this->chat[] = [
                'role' => 'assistant',
                'text' => $err,
                'html' => MarkdownPreview::html($err),
                'time' => now()->format('H:i'),
            ];
        }

        Cache::put("topic_chat:{$by->learnerId}:{$this->topicId}", $this->chat, now()->addDays(14));
    }

    public function quickPrompt(string $prompt): void
    {
        $this->chatText = $prompt;
        $this->sendChat();
    }

    public function clearChat(): void
    {
        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);
        $clean = Topics::cleanMarkdown($topic->name);
        $welcome = "Conversation reset. What would you like to explore about **{$clean}**?";
        $this->chat = [
            [
                'role' => 'assistant',
                'text' => $welcome,
                'html' => MarkdownPreview::html($welcome),
                'time' => now()->format('H:i'),
            ],
        ];
        Cache::put("topic_chat:{$by->learnerId}:{$this->topicId}", $this->chat, now()->addDays(14));
        $this->notify('Chat cleared.');
    }

    /** 4. Edit delete topic */
    public function rename(): void
    {
        $this->resetErrorBag();
        try {
            $this->topics->rename($this->principal(), $this->topicId, $this->name);
        } catch (Unprocessable $e) {
            $this->addError('name', array_values($e->details['fields'] ?? [])[0][0] ?? $e->getMessage());

            return;
        }

        $this->notify('Topic renamed.');
        $this->dispatch('topics-changed');
    }

    public function move(): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        [$type, $id] = array_pad(explode(':', $this->place, 2), 2, '');
        try {
            if (($this->place !== '' && ! in_array($type, ['module', 'folder'], true)) || ($type === 'folder' && $this->folders->find($by, $id)->moduleId === null)) {
                throw new NotFound;
            }
            $this->topics->move($by, $this->topicId, $type === 'module' ? $id : null, $type === 'folder' ? $id : null);
        } catch (NotFound|Unprocessable) {
            $this->addError('place', 'That module or folder no longer exists. Choose another.');

            return;
        }

        $this->notify('Topic moved.');
        $this->dispatch('topics-changed');
    }

    public function addPoint(): void
    {
        $this->resetErrorBag();
        try {
            $input = ['text' => $this->newPoint];
            if ($this->pointSource !== '') {
                $input['source'] = $this->pointSource;
            }
            if ($this->pointLocator !== '') {
                $input['locator'] = $this->pointLocator;
            }
            $this->findings->add($this->principal(), $this->topicId, $input);
        } catch (Unprocessable $e) {
            $this->addError('newPoint', array_values($e->details['fields'] ?? [])[0][0] ?? $e->getMessage());

            return;
        }

        $this->newPoint = '';
        $this->pointLocator = '';
        $this->notify('Key point added.');
    }

    public function removePoint(string $id): void
    {
        $this->findings->delete($this->principal(), $id);
        $this->notify('Key point removed.');
    }

    public function askRemove(): void
    {
        $this->confirmingRemove = true;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemove = false;
    }

    public function removeTopic()
    {
        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);
        $cleanName = Topics::cleanMarkdown($topic->name);

        $this->topics->retire($by, $this->topicId);
        $this->dispatch('topics-changed');

        $returnUrl = match (true) {
            $topic->folderId !== null => route('workspaces.folders.show', [$this->workspaceId, $topic->folderId]),
            $topic->moduleId !== null => route('workspaces.modules.show', [$this->workspaceId, $topic->moduleId]),
            default => route('workspaces.show', [$this->workspaceId, 'modules']),
        };

        session()->flash('notice', "Topic '{$cleanName}' removed. What you did on it stays in your study record.");

        return redirect()->to($returnUrl);
    }

    /** 5. Add note and file on that topic */
    public function uploadFileToTopic(): void
    {
        $this->validate([
            'uploadedFile' => 'required|file|max:'.(Files::maxBytes() / 1024),
        ]);

        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);

        [$placeType, $placeId] = match (true) {
            $topic->folderId !== null => ['folder', $topic->folderId],
            $topic->moduleId !== null => ['module', $topic->moduleId],
            default => ['workspace', $this->workspaceId],
        };

        try {
            $path = $this->uploadedFile->getRealPath();
            $origName = $this->uploadedFile->getClientOriginalName();
            $this->files->upload($by, $placeType, $placeId, $path, $origName);
            $this->uploadedFile = null;
            $this->notify("File '{$origName}' uploaded to topic materials.");
        } catch (\Throwable $e) {
            $this->addError('uploadedFile', $e->getMessage());
        }
    }

    public function render(): View
    {
        $by = $this->principal();
        $topic = $this->topics->find($by, $this->topicId);

        // Fetch related entities
        $modules = $this->modules->list($by, $this->workspaceId);
        $folderTree = $this->folders->tree($by, $this->workspaceId);
        $foldersByModule = self::byModule($folderTree);

        $module = $topic->moduleId !== null ? $this->modules->find($by, $topic->moduleId) : null;
        $folder = $topic->folderId !== null ? $this->folders->find($by, $topic->folderId) : null;

        $cards = $this->flashcards->counts($by, $this->workspaceId, $topic->id);
        $allQuestions = $this->questions->list($by, $this->workspaceId);
        $topicQuestions = array_values(array_filter($allQuestions, fn ($q) => $q->topicId === $topic->id));

        $points = $this->findings->byTopic($by, $this->workspaceId)[$topic->id] ?? [];
        $sessions = $topic->moduleId === null ? [] : array_values(array_filter($this->sessions->forModule($by, $this->workspaceId, $topic->moduleId), fn ($s) => $s->topicId === $topic->id));

        // Topic files & notes (from folder or module context)
        $inside = $folder ? $this->folders->within($by, $folder->id) : ($module ? [] : []);
        $allFiles = $this->files->list($by, $this->workspaceId);
        $allNotes = $this->notes->list($by, $this->workspaceId);

        $topicFiles = array_values(array_filter($allFiles, function ($f) use ($folder, $inside, $module) {
            if ($folder) {
                return in_array($f->folderId, $inside, true);
            }
            if ($module) {
                return $f->moduleId === $module->id && $f->folderId === null;
            }

            return false;
        }));

        $topicNotes = array_values(array_filter($allNotes, function ($n) use ($folder, $inside, $module) {
            if ($folder) {
                return in_array($n->folderId, $inside, true);
            }
            if ($module) {
                return $n->moduleId === $module->id && $n->folderId === null;
            }

            return false;
        }));

        $sources = $this->findings->sources($by, $this->workspaceId);

        return view('livewire.workspaces.topic-page', [
            'workspace' => $this->workspaces->find($by, $this->workspaceId),
            'topic' => $topic,
            'cleanName' => Topics::cleanMarkdown($topic->name),
            'module' => $module,
            'folder' => $folder,
            'modules' => $modules,
            'foldersByModule' => $foldersByModule,
            'cards' => $cards,
            'questions' => $topicQuestions,
            'points' => $points,
            'sessions' => $sessions,
            'topicFiles' => $topicFiles,
            'topicNotes' => $topicNotes,
            'sources' => $sources,
        ]);
    }

    /**
     * @param  list<FolderDetails>  $tree
     * @return array<string, list<array{id: string, name: string}>>
     */
    private static function byModule(array $tree): array
    {
        $byId = [];
        foreach ($tree as $folder) {
            $byId[$folder->id] = $folder;
        }
        $grouped = [];
        foreach ($tree as $folder) {
            if ($folder->moduleId === null) {
                continue;
            }
            $names = [$folder->name];
            for ($parent = $folder->parentId; $parent !== null && isset($byId[$parent]); $parent = $byId[$parent]->parentId) {
                array_unshift($names, $byId[$parent]->name);
            }
            $grouped[$folder->moduleId][] = ['id' => $folder->id, 'name' => implode(' › ', $names)];
        }

        return $grouped;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
