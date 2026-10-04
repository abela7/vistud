<?php

namespace App\Livewire\Workspaces;

use App\Engine\CourseGuide;
use App\Engine\Role;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Study\CourseProfiles;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The course guide's page (docs/specs/vistud-2-blueprint.md, Phase 8): a talk with the tutor that sets a course up. It asks
 * one thing at a time, takes a pasted module page, and proposes what to add; the proposal sits under the talk with a tick
 * on every part, and only what is ticked is written (App\Engine\CourseGuide). The student can add the modules a week at a
 * time by coming back; what is already there is never added twice. The talk is kept in the session (leaving and coming back
 * finds it), not in the database: what matters has been added to the course by then. A thin adapter: the course's id and
 * the talk are locked, and CourseGuide checks everything.
 */
final class GuideChat extends Component
{
    use Notices;

    /** How long a talk is kept when the student leaves and comes back, in seconds. */
    public const KEPT_FOR = 7_200;

    #[Locked]
    public string $workspaceId;

    /** Why the guide was opened: `modules` (add some weeks) or nothing (set the course up). */
    #[Locked]
    public string $for = '';

    /** @var list<array{from: string, text: string}> from is you, guide, done or problem */
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

    /** Why the guide can't be asked now, in one line, with the code it came from; null when it can. */
    #[Locked]
    public ?string $problem = null;

    private CourseGuide $guide;

    private Settings $settings;

    private Workspaces $workspaces;

    private CourseProfiles $profiles;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(CourseGuide $guide, Settings $settings, Workspaces $workspaces, CourseProfiles $profiles, Modules $modules, PrincipalFactory $principals): void
    {
        [$this->guide, $this->settings, $this->workspaces, $this->profiles, $this->modules, $this->principals] = [$guide, $settings, $workspaces, $profiles, $modules, $principals];
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
        } else {
            $this->talk = [['from' => 'guide', 'text' => $this->opening()]];
        }
        $this->problem = $this->notReady();
    }

    /** Sends what the student wrote to the guide and adds its answer to the talk. */
    public function send(): void
    {
        $this->resetErrorBag();
        $words = trim($this->text);
        if ($words === '') {
            $this->addError('text', 'Write something first.');

            return;
        }
        if (mb_strlen($words) > CourseGuide::MAX_MESSAGE) {
            $this->addError('text', 'That is too long. Paste the main part of the course page.');

            return;
        }

        try {
            $answer = $this->guide->turn($this->principal(), $this->workspaceId, $this->history(), $words);
        } catch (AppError $e) {
            $this->problem($e);

            return;
        }

        $this->problem = null;
        $this->text = '';
        $this->talk[] = ['from' => 'you', 'text' => $words];
        $this->talk[] = ['from' => 'guide', 'text' => $answer['reply']];
        if ($answer['proposal'] !== null) {
            $this->proposal = $answer['proposal'];
            $this->ticks = self::allTicked($answer['proposal']);
        }
        $this->keep();
    }

    /** Adds what is ticked to the course. */
    public function add(): void
    {
        if ($this->proposal === null) {
            return;
        }
        $ticked = [
            'course' => (bool) ($this->ticks['course'] ?? false),
            'about' => (bool) ($this->ticks['about'] ?? false),
            'outcomes' => (bool) ($this->ticks['outcomes'] ?? false),
            'textbook' => (bool) ($this->ticks['textbook'] ?? false),
            'assessment' => self::picked($this->ticks['assessment'] ?? []),
            'assignments' => self::picked($this->ticks['assignments'] ?? []),
            'modules' => self::picked($this->ticks['modules'] ?? []),
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
        $this->talk[] = ['from' => 'guide', 'text' => $this->next()];
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

    /** Starts the talk again; nothing already added is touched. */
    #[On('guide-start-over')]
    public function startOver(): void
    {
        $this->talk = [['from' => 'guide', 'text' => $this->opening()]];
        $this->proposal = null;
        $this->ticks = [];
        $this->text = '';
        $this->resetErrorBag();
        $this->keep();
    }

    public function render(): View
    {
        return view('livewire.workspaces.guide-chat', ['workspace' => $this->workspaces->find($this->principal(), $this->workspaceId)]);
    }

    // ---------- Inside ----------

    /** What the guide says first, without asking the model. */
    private function opening(): string
    {
        $name = $this->workspaces->find($this->principal(), $this->workspaceId)->name;
        $has = $this->modules->list($this->principal(), $this->workspaceId) !== [];

        return $this->for === 'modules' || $has
            ? "Which weeks or chapters of {$name} do you want to add now? Tell me, or paste the timetable. You can add the rest later."
            : "Let's set up {$name}. Tell me what it is about in your own words, or paste its page from your university (the About text and the timetable). I'll ask for what is missing, and add only what you tick.";
    }

    /** What to ask after something was added: the first thing still missing. */
    private function next(): string
    {
        $by = $this->principal();
        $profile = $this->profiles->get($by, $this->workspaceId);

        return match (true) {
            $profile->about === '' => 'What is the course about? A few words will do.',
            $profile->assessment === [] => 'How is it assessed, and when are the deadlines?',
            $this->modules->list($by, $this->workspaceId) === [] => 'Which weeks or chapters do you want to add first? One is enough; the rest can come later.',
            default => 'That is set up. Add the next weeks whenever you like: tell me here, or use "Add with the AI" on Modules.',
        };
    }

    /** The talk as the model's turns. */
    private function history(): array
    {
        $turns = [];
        foreach ($this->talk as $line) {
            if ($line['from'] === 'you') {
                $turns[] = ['role' => 'user', 'content' => $line['text']];
            } elseif ($line['from'] === 'guide') {
                $turns[] = ['role' => 'assistant', 'content' => $line['text']];
            } elseif ($line['from'] === 'done') {
                $turns[] = ['role' => 'assistant', 'content' => $line['text']];
            }
        }

        return $turns;
    }

    private function problem(AppError $e): void
    {
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
            'validation_failed' => $message,
            default => 'The AI didn\'t answer. Try again.',
        };
    }

    private function keep(): void
    {
        $this->talk = array_slice($this->talk, -40);
        session([$this->key() => ['talk' => $this->talk, 'proposal' => $this->proposal, 'ticks' => $this->ticks, 'at' => now()->timestamp]]);
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
            'about' => $proposal['about'] !== '',
            'outcomes' => $proposal['outcomes'] !== [],
            'textbook' => $proposal['textbook'] !== '',
            'assessment' => array_fill_keys($assessment, true),
            'assignments' => array_fill_keys($dated, true),
            'modules' => array_fill_keys(array_keys($proposal['modules'] ?? []), true),
        ];
    }

    /** The numbers of the items ticked in a list of ticks. */
    private static function picked(mixed $ticks): array
    {
        return is_array($ticks) ? array_map('intval', array_keys(array_filter($ticks))) : [];
    }

    /** What was added, in words ("3 modules and the About text"). */
    private static function said(array $done): string
    {
        $parts = array_filter([
            $done['modules'] > 0 ? $done['modules'].($done['modules'] === 1 ? ' module' : ' modules') : null,
            $done['about'] ? 'the About text' : null,
            $done['outcomes'] > 0 ? 'what it should teach' : null,
            $done['assessment'] > 0 ? $done['assessment'].($done['assessment'] === 1 ? ' assessment' : ' assessments') : null,
            $done['assignments'] > 0 ? $done['assignments'].($done['assignments'] === 1 ? ' assignment' : ' assignments') : null,
            $done['textbook'] ? 'the textbook' : null,
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
