<?php

namespace App\Livewire\Study;

use App\Engine\Models;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A student's AI engine page (docs/specs/study-memory.md §6): their own key for the service (pasted once and
 * tried at once; without one, the key an admin set up for everyone), the models they choose by id with the
 * prices shown, their spending limits, the training opt-out and their consent to the chat. A thin adapter over
 * App\Engine\Settings. The key typed here is sent once and never kept in the component.
 */
final class EngineSettings extends Component
{
    use Notices;

    public string $key = '';

    public string $tutorModel = '';

    public string $quickModel = '';

    public string $fallbackModel = '';

    public string $sessionCap = '';

    public string $monthCap = '';

    public bool $noTraining = true;

    public string $language = '';

    public bool $askTopics = false;

    public bool $consent = false;

    #[Locked]
    public bool $confirmingRemoval = false;

    #[Locked]
    public ?array $result = null;

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

    public function mount(): void
    {
        Guard::learner($this->principal());
        $this->load();
    }

    /** Keeps the key and tries it at once, so the student sees it work (or why it doesn't). */
    public function saveKey(): void
    {
        $this->resetErrorBag();
        try {
            $this->settings->setKey($this->principal(), $this->key);
        } catch (Unprocessable $e) {
            $this->addError('key', $e->details['fields']['key'][0] ?? $e->getMessage());

            return;
        }
        $this->key = '';
        $this->result = $this->settings->test($this->principal());
        $this->notify($this->result['ok'] ? 'Your key is saved and works.' : 'Your key is saved, but the service didn\'t answer as expected.', $this->result['ok'] ? 'success' : 'warning');
        $this->load();
    }

    public function test(): void
    {
        $this->result = $this->settings->test($this->principal());
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
        $this->settings->removeKey($this->principal());
        $this->confirmingRemoval = false;
        $this->result = null;
        $this->notify('Your key is removed.');
        $this->load();
    }

    /** The models, the language, the limits, training and consent. */
    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $this->settings->set($this->principal(), [
                'tutor_model' => $this->tutorModel, 'quick_model' => $this->quickModel, 'fallback_model' => $this->fallbackModel,
                'session_cap' => $this->sessionCap, 'month_cap' => $this->monthCap, 'no_training' => $this->noTraining, 'consent' => $this->consent, 'language' => $this->language, 'ask_topics' => $this->askTopics,
            ]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['tutor_model' => 'tutorModel', 'quick_model' => 'quickModel', 'fallback_model' => 'fallbackModel', 'session_cap' => 'sessionCap', 'month_cap' => 'monthCap'][$field] ?? $field, $messages[0]);
            }

            return;
        }
        $this->notify('Your AI engine settings are saved.');
        $this->load();
    }

    public function render(): View
    {
        $by = $this->principal();
        $choices = $this->settings->get($by);
        $ownKey = $this->settings->key($by);
        $keyState = $ownKey !== null ? 'own' : ($this->setup->keySet() ? 'shared' : 'none');
        $models = $keyState === 'none' ? [] : $this->models->all(key: $ownKey);
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->id] = $model;
        }
        $words = fn (string $id) => $id !== '' && isset($byId[$id]) ? $byId[$id]->priceWords().($byId[$id]->tools ? '' : ' · no look-ups: this model can\'t call tools') : null;

        return view('livewire.study.engine-settings', [
            'choices' => $choices,
            'keyState' => $keyState,
            'models' => $models,
            'setupUrl' => $by->hasRole(Role::Admin) ? route('admin.engine') : null,
            'tutorWords' => $words($this->tutorModel),
            'quickWords' => $words($this->quickModel),
            'fallbackWords' => $words($this->fallbackModel),
        ])->title('AI engine');
    }

    private function load(): void
    {
        $choices = $this->settings->get($this->principal());
        $this->tutorModel = $choices->tutorModel;
        $this->quickModel = $choices->quickModel;
        $this->fallbackModel = $choices->fallbackModel;
        $this->sessionCap = number_format($choices->sessionCapMicros / 1_000_000, 2, '.', '');
        $this->monthCap = number_format($choices->monthCapMicros / 1_000_000, 2, '.', '');
        $this->noTraining = $choices->noTraining;
        $this->language = $choices->language ?? '';
        $this->askTopics = $choices->askTopics;
        $this->consent = $choices->consentedAt !== null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
