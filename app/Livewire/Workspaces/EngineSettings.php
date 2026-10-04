<?php

namespace App\Livewire\Workspaces;

use App\Engine\Models;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A student's engine settings (docs/specs/study-memory.md §6), in one dialog opened with the `engine-settings-open`
 * event from the Overview's ⋯ menu: the models they choose (any the service offers, by id, with the prices shown),
 * their spending limits, whether their words may train models, and their consent to the chat.
 */
final class EngineSettings extends Component
{
    use Notices;

    #[Locked]
    public bool $editing = false;

    public string $tutorModel = '';

    public string $quickModel = '';

    public string $fallbackModel = '';

    public string $sessionCap = '';

    public string $monthCap = '';

    public bool $noTraining = true;

    public bool $consent = false;

    private Settings $settings;

    private Models $models;

    private Setup $setup;

    private PrincipalFactory $principals;

    public function boot(Settings $settings, Models $models, Setup $setup, PrincipalFactory $principals): void
    {
        $this->settings = $settings;
        $this->models = $models;
        $this->setup = $setup;
        $this->principals = $principals;
    }

    #[On('engine-settings-open')]
    public function edit(): void
    {
        $this->close();
        $choices = $this->settings->get($this->principal());
        $this->editing = true;
        $this->tutorModel = $choices->tutorModel;
        $this->quickModel = $choices->quickModel;
        $this->fallbackModel = $choices->fallbackModel;
        $this->sessionCap = number_format($choices->sessionCapMicros / 1_000_000, 2, '.', '');
        $this->monthCap = number_format($choices->monthCapMicros / 1_000_000, 2, '.', '');
        $this->noTraining = $choices->noTraining;
        $this->consent = $choices->consentedAt !== null;
        $this->dispatch('engine-settings-dialog-open');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $this->settings->set($this->principal(), [
                'tutor_model' => $this->tutorModel, 'quick_model' => $this->quickModel, 'fallback_model' => $this->fallbackModel,
                'session_cap' => $this->sessionCap, 'month_cap' => $this->monthCap, 'no_training' => $this->noTraining, 'consent' => $this->consent,
            ]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['tutor_model' => 'tutorModel', 'quick_model' => 'quickModel', 'fallback_model' => 'fallbackModel', 'session_cap' => 'sessionCap', 'month_cap' => 'monthCap'][$field] ?? $field, $messages[0]);
            }

            return;
        }
        $this->notice = 'AI engine settings saved.';
        $this->close();
        $this->dispatch('engine-settings-dialog-close');
    }

    public function close(): void
    {
        $this->reset('editing', 'tutorModel', 'quickModel', 'fallbackModel', 'sessionCap', 'monthCap', 'noTraining', 'consent');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $models = $this->editing ? $this->models->all() : [];
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->id] = $model;
        }
        $words = fn (string $id) => $id !== '' && isset($byId[$id]) ? $byId[$id]->priceWords().($byId[$id]->tools ? '' : ' · no look-ups: this model can\'t call tools') : null;

        return view('livewire.workspaces.engine-settings', [
            'models' => $models,
            'keySet' => $this->setup->keySet(),
            'setupUrl' => $this->principal()->hasRole(Role::Admin) ? route('admin.engine') : null,
            'tutorWords' => $words($this->tutorModel),
            'quickWords' => $words($this->quickModel),
            'fallbackWords' => $words($this->fallbackModel),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
