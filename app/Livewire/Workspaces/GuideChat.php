<?php

namespace App\Livewire\Workspaces;

use App\Engine\Attachments;
use App\Engine\ChatBlocks;
use App\Engine\Choices;
use App\Engine\CourseGuide;
use App\Engine\Models;
use App\Engine\Role;
use App\Engine\Settings;
use App\Engine\Usage;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Files\UploadTemps;
use App\Study\CourseProfiles;
use App\Study\Files;
use App\Study\Plugins;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The course guide's page (docs/specs/vistud-2-blueprint.md, Phase 8): a talk with the tutor. It has two jobs, each its own
 * talk: setting the course up (what it is about, what it should teach, how it is assessed, the textbook) and adding its
 * modules, from the Modules page, with the guide having read what the course is about. It asks one thing at a time, takes
 * a pasted page, and proposes what to add; the proposal sits under the talk with a tick on every part, and only what is
 * ticked is written (App\Engine\CourseGuide). The modules can be added a few at a time by coming back; what is already
 * there is never added twice. The talk is kept in the session (leaving and coming back
 * finds it), not in the database: what matters has been added to the course by then. A thin adapter: the course's id and
 * the talk are locked, and CourseGuide checks everything.
 */
final class GuideChat extends Component
{
    use Notices, WithFileUploads;

    /** The most one picture or document can weigh, in kilobytes (a picture is shown smaller; a document is read as text). */
    public const PICTURE_KB = 10_240;

    /** What can be attached, by name. A picture's bytes are checked again when the message goes; a document is read as text. */
    public const PICTURE_TYPES = 'png,jpg,jpeg,gif,webp,pdf,docx,pptx,xlsx,txt,md';

    /** Extensions that are pictures. Anything else in PICTURE_TYPES is a document the guide reads. */
    private const PICTURE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** How long a talk is kept when the student leaves and comes back, in seconds. */
    public const KEPT_FOR = 7_200;

    #[Locked]
    public string $workspaceId;

    /** Why the guide was opened: `modules` (add some modules) or nothing (set the course up). */
    #[Locked]
    public string $for = '';

    /**
     * @var list<array{from: string, text: string, pictures?: int, files?: int, ask?: string}> from is you, guide or done; pictures
     *                                                                                         and files are how many went with a message of yours;
     *                                                                                         ask is the question a line of the guide's asks
     *                                                                                         (CourseProfiles::QUESTIONS)
     */
    #[Locked]
    public array $talk = [];

    /** What the guide last proposed and the student hasn't added or dropped. */
    #[Locked]
    public ?array $proposal = null;

    /**
     * What is ticked in the proposal: course, about, outcomes, textbook (true or false), and assessment, assignments and
     * modules (by the number of the item).
     *
     * @var array<string, mixed>
     */
    public array $ticks = [];

    public string $text = '';

    /** The model for this talk. Empty uses the tutor model from settings. */
    #[Locked]
    public string $model = '';

    /**
     * Pictures or documents (a Word file, PDF, slides, a spreadsheet or text) of the course's page or timetable, chosen,
     * pasted or dropped, waiting to go with the next message. A picture is shown and a document is read, once, and not
     * kept: the course only gets what is ticked.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $pictures = [];

    /** Why the guide can't be asked now, in one line, with the code it came from; null when it can. */
    #[Locked]
    public ?string $problem = null;

    private CourseGuide $guide;

    private Settings $settings;

    private Workspaces $workspaces;

    private CourseProfiles $profiles;

    private PrincipalFactory $principals;

    private ChatStream $stream;

    private Models $models;

    private Usage $usage;

    public function boot(CourseGuide $guide, Settings $settings, Workspaces $workspaces, CourseProfiles $profiles, PrincipalFactory $principals, ChatStream $stream, Models $models, Usage $usage): void
    {
        [$this->guide, $this->settings, $this->workspaces, $this->profiles, $this->principals, $this->stream, $this->models, $this->usage] = [$guide, $settings, $workspaces, $profiles, $principals, $stream, $models, $usage];
    }

    public function mount(string $workspaceId, string $for = ''): void
    {
        $this->workspaceId = $workspaceId;
        $this->for = $for === 'modules' ? 'modules' : '';
        $kept = session($this->key());
        if (is_array($kept) && ($kept['talk'] ?? []) !== [] && now()->timestamp - (int) ($kept['at'] ?? 0) < self::KEPT_FOR) {
            $this->talk = $kept['talk'];
            $this->proposal = $kept['proposal'] ?? null;
            $this->ticks = $kept['ticks'] ?? [];
            $this->model = is_string($kept['model'] ?? null) ? $kept['model'] : '';
        } else {
            $this->talk = $this->opening();
        }
        $this->problem = $this->notReady();
    }

    /** Sends what the student wrote to the guide and adds its answer to the talk. */
    /**
     * Sends a message. The page clears the box the moment it is sent and passes the words here (resources/js/guide-chat.js),
     * so they don't sit in the box while the answer streams in; if the message can't go, they are put back in the box.
     */
    public function send(?string $message = null): void
    {
        $this->resetErrorBag();
        $words = trim($message ?? $this->text);
        $this->text = $words;
        $files = array_values(array_filter($this->pictures, fn ($file) => $file instanceof TemporaryUploadedFile));
        if ($words === '' && $files === []) {
            $this->addError('text', 'Write something first, or attach a picture or a file.');

            return;
        }
        if (mb_strlen($words) > CourseGuide::MAX_MESSAGE) {
            $this->addError('text', 'That is too long. Paste the main part of the course page.');

            return;
        }

        try {
            $by = $this->principal();
            $chosen = $this->chosenModel($this->settings->get($by));
            if (array_any($files, fn (TemporaryUploadedFile $file) => self::isPicture($file))) {
                $this->guide->requirePictureModel($by, $chosen);
            }
            try {
                $pictures = array_map(fn (TemporaryUploadedFile $file) => ['name' => $file->getClientOriginalName(), 'bytes' => UploadTemps::bytes($file, Files::maxBytes())], $files);
            } finally {
                UploadTemps::discard($files);
                $this->pictures = [];
            }
            // The reply streams in as it is written, as the tutor's does.
            @set_time_limit(120);
            $answer = $this->guide->turn($by, $this->workspaceId, $this->history(), $words, $this->for, $pictures,
                fn (string $reply) => $this->stream->push($this, 'guide-answer', ChatBlocks::html($reply)), $chosen);
        } catch (AppError $e) {
            $this->problem($e);

            return;
        }

        $this->problem = null;
        $this->text = '';
        $this->pictures = [];
        $pictureCount = count(array_filter($files, fn (TemporaryUploadedFile $file) => self::isPicture($file)));
        $fileCount = count($files) - $pictureCount;
        $this->talk[] = ['from' => 'you', 'text' => $words] + array_filter([
            'pictures' => $pictureCount > 0 ? $pictureCount : null,
            'files' => $fileCount > 0 ? $fileCount : null,
        ]);
        $this->talk[] = ['from' => 'guide', 'text' => $answer['reply']];
        if ($answer['proposal'] !== null) {
            $this->proposal = $answer['proposal'];
            $this->ticks = self::allTicked($answer['proposal']);
        }
        $this->keep();
    }

    /**
     * Keeps the files that can go: at most one message's worth, of a kind the guide can read and not too big; the others
     * are dropped with a word each on why. (A picture's bytes are checked when the message goes; a document is read then.)
     */
    public function updatedPictures(): void
    {
        $this->resetErrorBag('pictures');
        $kept = [];
        foreach ($this->pictures as $file) {
            $why = ! $file instanceof TemporaryUploadedFile ? 'That is not a file.' : self::refusal($file);
            if ($why === null && count($kept) >= Attachments::MOST) {
                $why = 'Attach up to '.Attachments::MOST.' files to one message.';
            }
            if ($why === null) {
                $kept[] = $file;

                continue;
            }
            $this->addError('pictures', $file instanceof TemporaryUploadedFile ? mb_substr(mb_scrub($file->getClientOriginalName()), 0, 80).': '.$why : $why);
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }
        $this->pictures = $kept;
    }

    /** Why a chosen file can't go, in a few words; null when it can. */
    private static function refusal(TemporaryUploadedFile $file): ?string
    {
        $check = Validator::make(['picture' => $file], ['picture' => ['extensions:'.self::PICTURE_TYPES, 'max:'.self::PICTURE_KB]], [
            'picture.extensions' => 'Attach a PNG, JPG, GIF or WebP picture, or a Word, PDF, PowerPoint, Excel or text file.',
            'picture.max' => 'That file is too big. Attach one under 10 MB.',
        ]);

        return $check->fails() ? (string) $check->errors()->first('picture') : null;
    }

    /** Whether an attached file is a picture (the guide shows it) rather than a document (the guide reads it). */
    public static function isPicture(TemporaryUploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), self::PICTURE_EXTENSIONS, true);
    }

    /** Uses another of the student's models for the next reply in this talk. */
    public function switchModel(string $model): void
    {
        $model = trim($model);
        $choices = $this->settings->get($this->principal());
        if (! in_array($model, $choices->chatModels(), true)) {
            return;
        }
        $this->model = $model;
        $this->keep();
    }

    /** Takes one attached file off the message it was going with. */
    public function removePicture(int $index): void
    {
        $file = $this->pictures[$index] ?? null;
        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
        }
        unset($this->pictures[$index]);
        $this->pictures = array_values($this->pictures);
        $this->resetErrorBag('pictures');
    }

    /** Adds what is ticked to the course. */
    public function add(): void
    {
        if ($this->proposal === null) {
            return;
        }
        $ticked = [
            'course' => (bool) ($this->ticks['course'] ?? false),
            'facts' => array_keys(array_filter(is_array($this->ticks['facts'] ?? null) ? $this->ticks['facts'] : [])),
            'about' => (bool) ($this->ticks['about'] ?? false),
            'outcomes' => (bool) ($this->ticks['outcomes'] ?? false),
            'textbook' => (bool) ($this->ticks['textbook'] ?? false),
            'assessment' => self::picked($this->ticks['assessment'] ?? []),
            'assignments' => self::picked($this->ticks['assignments'] ?? []),
            'modules' => self::picked($this->ticks['modules'] ?? []),
            // Inside each module, the topics and parts left ticked: module number => their numbers.
            'topics' => self::pickedInside($this->ticks['topics'] ?? []),
            'parts' => self::pickedInside($this->ticks['parts'] ?? []),
        ];
        $by = $this->principal();
        $done = $this->guide->apply($by, $this->workspaceId, $this->proposal, $ticked);
        $said = self::said($done);
        if ($said === '') {
            $this->notify('Tick what you want to add first.', 'warning');

            return;
        }

        $this->proposal = null;
        $this->ticks = [];
        $this->talk[] = ['from' => 'done', 'text' => "Added: {$said}."];
        $this->talk[] = $this->next();
        $this->keep();
        $this->dispatch('guide-added');
    }

    /** The student doesn't want what was proposed. */
    public function drop(): void
    {
        $this->proposal = null;
        $this->ticks = [];
        $this->talk[] = ['from' => 'guide', 'text' => 'Okay, I left it out. Tell me what to change, or what comes next.'];
        $this->keep();
    }

    /** Ticks or unticks every module of the proposal. */
    public function tickModules(bool $on): void
    {
        $this->ticks['modules'] = array_fill_keys(array_keys($this->proposal['modules'] ?? []), $on);
    }

    /**
     * The student's pick for a question asked with buttons (the kind of course, its level, where they start from):
     * written at once, with no AI asked, and the next question follows.
     */
    public function choose(string $question, string $value): void
    {
        try {
            $this->guide->choose($this->principal(), $this->workspaceId, $question, $value);
        } catch (AppError $e) {
            $this->notify($e->getMessage(), 'warning');

            return;
        }
        $label = collect(CourseGuide::choices($question))->firstWhere('value', $value)['label'] ?? $value;
        $this->talk[] = ['from' => 'you', 'text' => $label];
        $this->talk[] = $this->next();
        $this->keep();
        $this->dispatch('guide-added');
    }

    /** The student would rather not answer this one: it is never asked again, and the next question follows. */
    public function skip(string $question): void
    {
        try {
            $this->guide->skip($this->principal(), $this->workspaceId, $question);
        } catch (AppError $e) {
            $this->notify($e->getMessage(), 'warning');

            return;
        }
        $this->talk[] = ['from' => 'you', 'text' => 'Skip'];
        $this->talk[] = $this->next();
        $this->keep();
    }

    /** Asks a question again (from the list of what the AI knows): to answer one skipped, or change an answer. */
    public function askAgain(string $question): void
    {
        if ($this->for !== '' || ! in_array($question, CourseProfiles::QUESTIONS, true)) {
            return;
        }
        $this->guide->reopen($this->principal(), $this->workspaceId, $question);
        $this->talk[] = $this->asking($question);
        $this->keep();
    }

    /** Starts the talk again; nothing already added is touched. */
    #[On('guide-start-over')]
    public function startOver(): void
    {
        $this->talk = $this->opening();
        $this->proposal = null;
        $this->ticks = [];
        $this->text = '';
        $this->resetErrorBag();
        $this->keep();
    }

    public function render(): View
    {
        $by = $this->principal();
        $workspace = $this->workspaces->find($by, $this->workspaceId);
        $last = $this->talk === [] ? null : $this->talk[array_key_last($this->talk)];
        $profile = $this->profiles->get($by, $this->workspaceId);

        $choices = $this->settings->get($by);
        $activeModel = $this->chosenModel($choices);

        return view('livewire.workspaces.guide-chat', [
            'workspace' => $workspace,
            // Once the course has its About text, its modules are the next step: a way there sits beside "I'm done for now".
            'toModules' => $this->for === '' && $profile->about !== '',
            'chatModels' => $this->modelChoices($by, $choices),
            'activeModel' => $activeModel,
            'activeModelName' => self::modelName($this->models->find($activeModel, $this->settings->credential($by))?->name, $activeModel),
            'spentWords' => self::spentWords($this->usage->month($by)['tutor'], $choices->monthCapMicros),
            // What the AI knows about the course, question by question (setting it up only).
            'questions' => $this->for === '' ? $this->guide->questions($by, $this->workspaceId) : [],
            // The question the guide's last line asks, answered with its buttons or in words, with Skip either way.
            'asking' => $last !== null && $last['from'] === 'guide' && isset($last['ask']) ? $last['ask'] : null,
            'plugins' => $this->for === '' ? array_map(fn (string $id) => Plugins::find($id)?->name, $this->profiles->plugins($by, $this->workspaceId)) : [],
        ]);
    }

    // ---------- Inside ----------

    /**
     * What the guide says first, without asking the model: for setting the course up, what it is for and the first
     * question still open.
     *
     * @return list<array{from: string, text: string, ask?: string}>
     */
    private function opening(): array
    {
        $by = $this->principal();
        $name = $this->workspaces->find($by, $this->workspaceId)->name;
        $about = $this->profiles->get($by, $this->workspaceId)->about !== '';

        if ($this->for === 'modules') {
            return [['from' => 'guide', 'text' => $about
                ? "I have read what {$name} is about. Which modules do you want to add now: weeks, chapters, units, whatever the course is split into? Paste the timetable or syllabus, attach the Word or PDF file, tell me, or ask me to suggest some. I set each one up with its topics and parts, and you tick what to keep."
                : "Which modules of {$name} do you want to add now: weeks, chapters, units, whatever the course is split into? Paste the timetable or syllabus, attach the file, or tell me. The rest can come later."]];
        }
        if ($this->guide->next($by, $this->workspaceId) === null) {
            return [$this->next()];
        }

        return [
            ['from' => 'guide', 'text' => "Let's set up **{$name}**, so every AI that teaches you knows it. A few quick questions: answer what you know, and skip the rest. I add only what you tick."],
            $this->next(),
        ];
    }

    /** What the guide says next, without asking the model: the first question still open, or that the course is ready. */
    private function next(): array
    {
        if ($this->for === 'modules') {
            return ['from' => 'guide', 'text' => 'Tell me the next modules whenever you like. They can wait until you need them.'];
        }
        $question = $this->guide->next($this->principal(), $this->workspaceId);

        return $question !== null
            ? $this->asking($question)
            : ['from' => 'guide', 'text' => 'That is everything I need: every AI that teaches you will know this course. Next are its modules: open **Modules**, and the AI there reads all this and helps you set them up.'];
    }

    /** The guide's line that asks one question. */
    private function asking(string $question): array
    {
        return ['from' => 'guide', 'text' => CourseGuide::ask($question, $this->workspaces->find($this->principal(), $this->workspaceId)->type), 'ask' => $question];
    }

    /** The talk as the model's turns. */
    private function history(): array
    {
        $turns = [];
        foreach ($this->talk as $line) {
            if ($line['from'] === 'you') {
                $turns[] = ['role' => 'user', 'content' => trim($line['text'].self::earlier($line))];
            } elseif ($line['from'] === 'guide') {
                $turns[] = ['role' => 'assistant', 'content' => $line['text']];
            } elseif ($line['from'] === 'done') {
                $turns[] = ['role' => 'assistant', 'content' => $line['text']];
            }
        }

        return $turns;
    }

    /**
     * What a later turn is told about files that went with an earlier message. The bytes themselves are not kept.
     *
     * @param  array{text?: string, pictures?: int, files?: int}  $line
     */
    private static function earlier(array $line): string
    {
        $parts = [];
        $pictures = (int) ($line['pictures'] ?? 0);
        $files = (int) ($line['files'] ?? 0);
        if ($pictures > 0) {
            $parts[] = $pictures === 1 ? 'a picture' : "{$pictures} pictures";
        }
        if ($files > 0) {
            $parts[] = $files === 1 ? 'a file' : "{$files} files";
        }

        return $parts === [] ? '' : "\n[Attached earlier: ".implode(' and ', $parts).', shown with that message.]';
    }

    private function problem(AppError $e): void
    {
        $fields = is_array($e->details['fields'] ?? null) ? $e->details['fields'] : [];
        if ($e->errorCode === 'validation_failed' && $fields !== []) {
            // A refusal that names a field says what to change there; it is not about the AI's setup.
            $field = (string) array_key_first($fields);
            $this->addError($field === 'pictures' ? 'pictures' : 'text', (string) (reset($fields)[0] ?? $e->getMessage()));

            return;
        }
        $this->problem = self::problemText($e->errorCode, $e->getMessage());
        $this->addError('text', $this->problem);
    }

    private function notReady(): ?string
    {
        try {
            $this->settings->ready($this->principal(), Role::Tutor);
        } catch (AppError $e) {
            return self::problemText($e->errorCode, $e->getMessage());
        }

        return null;
    }

    private static function problemText(string $code, string $message): string
    {
        return match ($code) {
            'engine_key', 'engine_model', 'engine_consent' => 'Set up your AI first, then come back.',
            'engine_cap' => 'This month\'s AI limit is reached. Raise it in your AI settings.',
            'engine_daily' => 'Today\'s free AI is used up. It starts again tomorrow, or add your own key in your AI settings.',
            'validation_failed' => $message,
            default => 'The AI didn\'t answer. Try again.',
        };
    }

    /** The model this talk uses: the one picked here, or the tutor model when that pick is no longer offered. */
    private function chosenModel(Choices $choices): string
    {
        return in_array($this->model, $choices->chatModels(), true) ? $this->model : $choices->tutorModel;
    }

    /**
     * The models the student can switch to, as the picker shows them.
     *
     * @return list<array{id: string, name: string, priceWords: ?string}>
     */
    private function modelChoices(Principal $by, Choices $choices): array
    {
        $byId = [];
        foreach ($this->models->all(key: $this->settings->credential($by)) as $model) {
            $byId[$model->id] = $model;
        }
        $out = [];
        foreach ($choices->chatModels() as $id) {
            $model = $byId[$id] ?? null;
            $out[] = ['id' => $id, 'name' => self::modelName($model?->name, $id), 'priceWords' => $model?->priceWords()];
        }

        return $out;
    }

    private static function modelName(?string $name, string $id): string
    {
        $clean = preg_replace('/^(Anthropic|Google|OpenAI|Meta|DeepSeek|Mistral|Cohere|Qwen):\s*/i', '', trim((string) $name));
        if (is_string($clean) && $clean !== '') {
            return $clean;
        }

        return str_contains($id, '/') ? explode('/', $id, 2)[1] : $id;
    }

    private static function spentWords(int $month, int $cap): string
    {
        return Choices::dollars($month).($cap > 0 ? ' of '.Choices::dollars($cap) : '').' this month';
    }

    private function keep(): void
    {
        $this->talk = array_slice($this->talk, -40);
        session([$this->key() => ['talk' => $this->talk, 'proposal' => $this->proposal, 'ticks' => $this->ticks, 'model' => $this->model, 'at' => now()->timestamp]]);
    }

    private function key(): string
    {
        // Setting the course up and adding modules are two talks.
        return 'course-guide.'.$this->workspaceId.'.'.($this->for === '' ? 'setup' : $this->for);
    }

    /** Every part of a proposal ticked, the way it is first shown. */
    private static function allTicked(array $proposal): array
    {
        $assessment = array_keys($proposal['assessment'] ?? []);
        $dated = array_filter($assessment, fn ($i) => ($proposal['assessment'][$i]['due_on'] ?? null) !== null);

        return [
            'course' => ($proposal['course'] ?? []) !== [],
            'facts' => array_fill_keys(array_keys($proposal['facts'] ?? []), true),
            'about' => $proposal['about'] !== '',
            'outcomes' => $proposal['outcomes'] !== [],
            'textbook' => $proposal['textbook'] !== '',
            'assessment' => array_fill_keys($assessment, true),
            'assignments' => array_fill_keys($dated, true),
            'modules' => array_fill_keys(array_keys($proposal['modules'] ?? []), true),
            'topics' => array_map(fn (array $module) => array_fill_keys(array_keys($module['topics'] ?? []), true), $proposal['modules'] ?? []),
            'parts' => array_map(fn (array $module) => array_fill_keys(array_keys($module['parts'] ?? []), true), $proposal['modules'] ?? []),
        ];
    }

    /** The numbers of the items ticked in a list of ticks. */
    private static function picked(mixed $ticks): array
    {
        return is_array($ticks) ? array_map('intval', array_keys(array_filter($ticks))) : [];
    }

    /**
     * The topics or parts ticked inside each module: module number => the numbers ticked.
     *
     * @return array<int, list<int>>
     */
    private static function pickedInside(mixed $ticks): array
    {
        $out = [];
        foreach (is_array($ticks) ? $ticks : [] as $module => $items) {
            $out[(int) $module] = self::picked($items);
        }

        return $out;
    }

    /** What was added, in words ("3 modules and the About text"). */
    private static function said(array $done): string
    {
        $parts = array_filter([
            $done['modules'] > 0 ? $done['modules'].($done['modules'] === 1 ? ' module' : ' modules') : null,
            ($done['topics'] ?? 0) > 0 ? $done['topics'].($done['topics'] === 1 ? ' topic' : ' topics') : null,
            ($done['parts'] ?? 0) > 0 ? $done['parts'].($done['parts'] === 1 ? ' part' : ' parts') : null,
            $done['about'] ? 'the About text' : null,
            $done['outcomes'] > 0 ? 'what it should teach' : null,
            $done['assessment'] > 0 ? $done['assessment'].($done['assessment'] === 1 ? ' assessment' : ' assessments') : null,
            $done['assignments'] > 0 ? $done['assignments'].($done['assignments'] === 1 ? ' assignment' : ' assignments') : null,
            $done['textbook'] ? 'the textbook' : null,
            ($done['facts'] ?? 0) > 0 ? 'what kind of course it is' : null,
            $done['course'] ? 'the course details' : null,
        ]);

        return match (count($parts)) {
            0 => '',
            1 => reset($parts),
            default => implode(', ', array_slice($parts, 0, -1)).' and '.end($parts),
        };
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
