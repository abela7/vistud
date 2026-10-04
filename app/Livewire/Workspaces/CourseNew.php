<?php

namespace App\Livewire\Workspaces;

use App\Appearance\Theme;
use App\Engine\Role;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Errors\Unprocessable;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The page that makes a course (docs/specs/vistud-2-blueprint.md, Phase 8): its name, how it looks, a few optional details,
 * and how to set it up: with the AI's guide (App\Livewire\Workspaces\GuideChat), which asks about the course and adds what
 * the student approves, or by hand on the course's own page. Only the name is needed. A thin adapter over
 * App\Study\Workspaces, which checks everything.
 */
final class CourseNew extends Component
{
    public string $name = '';

    public string $colour = 'blue';

    public string $icon = 'book-open';

    public string $code = '';

    public string $term = '';

    public string $startsOn = '';

    public string $endsOn = '';

    /** guide or myself */
    public string $how = 'guide';

    /** Why the guide can't be used now, in one line; null when it can. */
    #[Locked]
    public ?string $notReady = null;

    private Workspaces $workspaces;

    private Settings $settings;

    private PrincipalFactory $principals;

    public function boot(Workspaces $workspaces, Settings $settings, PrincipalFactory $principals): void
    {
        [$this->workspaces, $this->settings, $this->principals] = [$workspaces, $settings, $principals];
    }

    public function mount(): void
    {
        $this->notReady = $this->notReady();
        if ($this->notReady !== null) {
            $this->how = 'myself';
        }
    }

    public function create(): mixed
    {
        $by = $this->principal();
        try {
            $workspace = $this->workspaces->create($by, [
                'name' => $this->name,
                'colour' => $this->colour,
                'icon' => $this->icon,
                'code' => $this->code,
                'term' => $this->term,
                'starts_on' => $this->startsOn,
                'ends_on' => $this->endsOn,
            ]);
        } catch (Unprocessable $e) {
            $this->resetErrorBag();
            $names = ['starts_on' => 'startsOn', 'ends_on' => 'endsOn'];
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($names[$field] ?? $field, $messages[0]);
            }

            return null;
        }

        // The guide only where the AI is ready; the course's own page otherwise (its setup sheet asks the same, by hand).
        return $this->how === 'guide' && $this->notReady === null
            ? $this->redirectRoute('workspaces.guide', ['workspace' => $workspace->id], navigate: true)
            : $this->redirectRoute('workspaces.show', ['workspace' => $workspace->id, 'setup' => 1], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.workspaces.course-new', ['colours' => Theme::CATEGORIES, 'icons' => Workspaces::ICONS]);
    }

    private function notReady(): ?string
    {
        try {
            $this->settings->ready($this->principal(), Role::Tutor);
        } catch (AppError) {
            return 'Set up your AI first to use the guide.';
        }

        return null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
