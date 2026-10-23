<?php

namespace App\Livewire\Study;

use App\Engine\Skills\Skills;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A student's switches for the skills of ViStud's chats, in AI settings (the owner's ask, 2026-10-23): each one an
 * admin left on, on unless they turn it off. Off, its instructions are left out of their chats. Saved at once.
 */
final class SkillSwitches extends Component
{
    private PrincipalFactory $principals;

    public function boot(PrincipalFactory $principals): void
    {
        $this->principals = $principals;
    }

    public function toggle(string $name): void
    {
        $skill = Skills::forStudent($this->principal())[$name] ?? null;
        if ($skill === null) {
            return;
        }
        try {
            Skills::setForStudent($this->principal(), $name, ! $skill['on']);
        } catch (Unprocessable) {
            return;
        }
    }

    public function render(): View
    {
        return view('livewire.study.skill-switches', ['skills' => Skills::forStudent($this->principal())]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
