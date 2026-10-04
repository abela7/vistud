<?php

namespace App\Livewire\Admin;

use App\Engine\Models;
use App\Engine\Setup;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The admin area's AI engine page (docs/specs/study-memory.md §6): set the service's key once, see that it
 * works, and choose the models every student starts with. A thin adapter over App\Engine\Setup, which checks
 * the admin and records the audit log. The key typed here is sent once and never kept in the component.
 */
final class EngineSetup extends Component
{
    use Notices;

    public string $key = '';

    public string $url = '';

    public string $tutorModel = '';

    public string $quickModel = '';

    #[Locked]
    public bool $confirmingRemoval = false;

    #[Locked]
    public ?array $result = null;

    private Setup $setup;

    private Models $models;

    private PrincipalFactory $principals;

    public function boot(Setup $setup, Models $models, PrincipalFactory $principals): void
    {
        $this->setup = $setup;
        $this->models = $models;
        $this->principals = $principals;
    }

    public function mount(): void
    {
        $status = $this->setup->status($this->principal());
        [$this->url, $this->tutorModel, $this->quickModel] = [$status['url'], $status['tutor_model'], $status['quick_model']];
    }

    /** Keeps the key and tries it at once, so the admin sees it work (or why it doesn't). */
    public function saveKey(): void
    {
        $this->resetErrorBag();
        try {
            $this->setup->setKey($this->principal(), $this->key);
        } catch (Unprocessable $e) {
            $this->addError('key', $e->details['fields']['key'][0] ?? $e->getMessage());

            return;
        } catch (PasswordConfirmationRequired) {
            $this->redirectRoute('password.confirm');

            return;
        }
        $this->key = '';
        $this->result = $this->setup->test($this->principal());
        $this->notify($this->result['ok'] ? 'The key is saved and works.' : 'The key is saved, but the service didn\'t answer as expected.', $this->result['ok'] ? 'success' : 'warning');
    }

    public function test(): void
    {
        $this->result = $this->setup->test($this->principal());
    }

    public function askRemove(): void
    {
        $this->confirmingRemoval = true;
    }

    public function keepKey(): void
    {
        $this->confirmingRemoval = false;
    }

    public function removeKey(): void
    {
        try {
            $this->setup->removeKey($this->principal());
        } catch (PasswordConfirmationRequired) {
            $this->redirectRoute('password.confirm');

            return;
        }
        $this->confirmingRemoval = false;
        $this->result = null;
        $this->notify('The key is removed. The chat can\'t answer until a new one is set.');
    }

    public function saveDefaults(): void
    {
        $this->resetErrorBag();
        try {
            $this->setup->setDefaults($this->principal(), ['url' => $this->url, 'tutor_model' => $this->tutorModel, 'quick_model' => $this->quickModel]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['url' => 'url', 'tutor_model' => 'tutorModel', 'quick_model' => 'quickModel'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (PasswordConfirmationRequired) {
            $this->redirectRoute('password.confirm');

            return;
        }
        $status = $this->setup->status($this->principal());
        [$this->url, $this->tutorModel, $this->quickModel] = [$status['url'], $status['tutor_model'], $status['quick_model']];
        $this->notify('The defaults are saved.');
    }

    public function render(): View
    {
        $status = $this->setup->status($this->principal());

        return view('livewire.admin.engine-setup', [
            'status' => $status,
            'models' => $status['key_set'] ? $this->models->all() : [],
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
