<?php

namespace App\Livewire\Admin;

use App\Engine\Skills\Skills;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\PasswordConfirmationRequired;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The admin area's Skills page (the owner's ask, 2026-10-23): every skill, what it is for and where it is used, and a
 * switch to turn it off for everyone. Off, a skill of ViStud's own chats is left out of every chat (and out of the
 * students' own switches); one for the AI apps students connect is left out of the plugin they download. A thin
 * adapter over App\Engine\Skills\Skills, which checks the admin and records the audit log.
 */
final class SkillsAdmin extends Component
{
    use Notices;

    private PrincipalFactory $principals;

    public function boot(PrincipalFactory $principals): void
    {
        $this->principals = $principals;
    }

    public function mount(): void
    {
        Guard::admin($this->principal());
    }

    public function toggle(string $name): void
    {
        $on = in_array($name, Skills::platformOff(), true);
        try {
            Skills::setForEveryone($this->principal(), $name, $on);
        } catch (PasswordConfirmationRequired) {
            redirect()->setIntendedUrl(route('admin.skills'));
            $this->redirectRoute('password.confirm');

            return;
        }
        $this->notify(Skills::read($name)['title'].($on ? ' is on for everyone.' : ' is off for everyone.'));
    }

    public function render(): View
    {
        Guard::admin($this->principal());

        return view('livewire.admin.skills-admin', ['skills' => Skills::all(), 'off' => Skills::platformOff()]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
