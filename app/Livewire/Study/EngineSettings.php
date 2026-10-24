<?php

namespace App\Livewire\Study;

use App\Engine\Dictation;
use App\Engine\Models;
use App\Engine\Providers;
use App\Engine\Role as EngineRole;
use App\Engine\Settings;
use App\Engine\Troubles;
use App\Engine\Usage;
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
 * A student's AI settings (docs/specs/study-memory.md §6, docs/specs/vistud-2-blueprint.md §3.6.1): their own key for
 * the service (pasted once and tried at once; without one, the key an admin set up for everyone), the model for each
 * of the three roles chosen by id with the prices shown, what the month has cost by role, how the tutor works (the
 * language and the trust toggles), their spending limits, the training opt-out and their consent. A thin adapter over
 * App\Engine\Settings. The key typed here is sent once and never kept in the component.
 */
final class EngineSettings extends Component
{
    use Notices;

    public string $key = '';

    /** Where a key comes from (the owner's ask, 2026-10-12): `openrouter`, one key for every model, or `own`, a provider's. */
    public string $source = 'openrouter';

    /** For a provider's own key: `own` to find out whose it is, or a provider's id (App\Engine\Providers). */
    public string $provider = 'own';

    public string $tutorModel = '';

    public string $readerModel = '';

    public string $helperModel = '';

    public string $fallbackModel = '';

    public array $favoriteModels = [];

    public string $newFavoriteModel = '';

    public string $sessionCap = '';

    public string $monthCap = '';

    public bool $noTraining = true;

    public string $language = '';

    /** The model that turns the microphone's recording into words (App\Engine\Dictation::MODELS). */
    public string $dictationModel = Dictation::WHISPER;

    public bool $askTopics = false;

    public bool $autoReadFiles = false;

    public bool $tutorMarksTopics = true;

    public bool $tutorRemembers = true;

    public bool $copyPasteAi = false;

    public bool $consent = false;

    #[Locked]
    public bool $confirmingRemoval = false;

    #[Locked]
    public ?array $result = null;

    /** Each role's model tried with the key (Try all my models): role => what happened. */
    #[Locked]
    public ?array $checks = null;

    /** The little chat to try one model: the last few messages and what each model answered. */
    #[Locked]
    public array $trial = [];

    public string $tryModel = '';

    public string $tryText = '';

    private Settings $settings;

    private Models $models;

    private PrincipalFactory $principals;

    private Usage $usage;

    public function boot(Settings $settings, Models $models, PrincipalFactory $principals, Usage $usage): void
    {
        $this->settings = $settings;
        $this->models = $models;
        $this->principals = $principals;
        $this->usage = $usage;
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
            $choices = $this->settings->setKey($this->principal(), $this->key, $this->source === 'own' ? $this->provider : Providers::OPENROUTER);
        } catch (Unprocessable $e) {
            $fields = $e->details['fields'] ?? [];
            $this->addError(isset($fields['provider']) ? 'provider' : 'key', ($fields['key'] ?? $fields['provider'] ?? [$e->getMessage()])[0]);

            return;
        }
        $this->key = '';
        $this->result = $this->settings->test($this->principal());
        $whose = Providers::name($choices->provider ?? Providers::OPENROUTER);
        $this->notify($this->result['ok'] ? "Your {$whose} key is saved and works." : 'Your key is saved, but the service didn\'t answer as expected.', $this->result['ok'] ? 'success' : 'warning');
        $this->load();
        // Listing models isn't answering: a plan can list a model it won't run, so each role's model is asked once.
        if ($this->result['ok']) {
            $this->checkModels();
        }
    }

    public function test(): void
    {
        $this->result = $this->settings->test($this->principal());
    }

    /** The step-by-step guide's key (resources/views/livewire/study/partials/key-guide.blade.php): an OpenRouter key. */
    public function saveGuideKey(): void
    {
        $this->source = 'openrouter';
        $this->saveKey();
    }

    /** The guide's last step: the model that teaches, from its picks or any the key offers; nothing else changes. */
    public function chooseGuideModel(string $model): void
    {
        $this->tutorModel = $model;
        $this->save();
    }

    /**
     * The guide's picks: good and cheap OpenRouter models, with their prices when the key's list has them.
     *
     * @return list<array{id: string, name: string, why: string, price: ?string}>
     */
    public static function guidePicks(array $byId): array
    {
        $picks = [
            ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'why' => 'Clear and quick. A good tutor for most courses.'],
            ['id' => 'google/gemini-2.5-flash-lite', 'name' => 'Gemini 2.5 Flash Lite', 'why' => 'The cheapest that still teaches well.'],
            ['id' => 'openai/gpt-4.1-mini', 'name' => 'GPT-4.1 mini', 'why' => 'Careful with steps and code.'],
        ];

        return array_map(fn (array $p) => $p + ['price' => isset($byId[$p['id']]) ? $byId[$p['id']]->priceWords() : null], $picks);
    }

    /**
     * Sends a one-word ask to each role's saved model, so the student sees which answer with their key before a
     * session leans on them (a provider can list a model its plan won't run). All answering clears the last problem.
     */
    public function checkModels(): void
    {
        $by = $this->principal();
        $choices = $this->settings->get($by);
        $models = [];
        foreach (EngineRole::cases() as $role) {
            $models[$role->label()] = $choices->modelFor($role);
        }
        $models['If the tutor fails'] = $choices->fallbackModel;
        $tried = [];
        $this->checks = [];
        foreach (array_filter($models) as $label => $model) {
            $tried[$model] ??= $this->settings->tryModel($by, $model);
            $this->checks[$label] = $tried[$model];
        }
        if ($this->checks !== [] && ! in_array(false, array_column($this->checks, 'ok'), true)) {
            app(Troubles::class)->clear($by);
        }
    }

    /** One message to one model, its answer (or why there is none) added to the little chat. */
    public function sendTry(): void
    {
        $by = $this->principal();
        $model = trim($this->tryModel) !== '' ? trim($this->tryModel) : $this->settings->get($by)->tutorModel;
        $result = $this->settings->tryModel($by, $model, $this->tryText);
        $this->trial = array_slice([...$this->trial, ['you' => mb_substr(trim($this->tryText), 0, 500)] + $result], -6);
        if ($result['ok']) {
            $this->tryText = '';
        }
    }

    public function clearTry(): void
    {
        $this->trial = [];
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

    public function addFavoriteModel(?string $model = null): void
    {
        $toAdd = trim($model ?? $this->newFavoriteModel);
        if ($toAdd === '') {
            return;
        }
        if (! in_array($toAdd, $this->favoriteModels, true)) {
            $this->favoriteModels[] = $toAdd;
            $this->save();
        }
        $this->newFavoriteModel = '';
    }

    public function removeFavoriteModel(string $model): void
    {
        $this->favoriteModels = array_values(array_filter(
            $this->favoriteModels,
            fn ($m) => $m !== $model
        ));
        $this->save();
    }

    /** The models, the language, the limits, training and consent. */
    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $shared = $this->settings->shared($this->principal());
            $this->settings->set($this->principal(), [
                'tutor_model' => $this->tutorModel, 'reader_model' => $this->readerModel, 'helper_model' => $this->helperModel, 'fallback_model' => $this->fallbackModel,
            ] + ($shared !== null
                // On the shared key the switches say which of its models are off (never the tutor), so one the admin
                // adds later is on at once; the favourites kept for their own key stay as they are.
                ? ['shared_models_off' => array_values(array_diff($shared['models'], $this->favoriteModels, [$this->tutorModel]))]
                : ['favorite_models' => $this->favoriteModels]) + [
                    'session_cap' => $this->sessionCap, 'month_cap' => $this->monthCap, 'no_training' => $this->noTraining, 'consent' => $this->consent, 'language' => $this->language, 'dictation_model' => $this->dictationModel, 'ask_topics' => $this->askTopics,
                    'auto_read_files' => $this->autoReadFiles, 'tutor_marks_topics' => $this->tutorMarksTopics, 'tutor_remembers' => $this->tutorRemembers, 'copy_paste_ai' => $this->copyPasteAi,
                ]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['tutor_model' => 'tutorModel', 'reader_model' => 'readerModel', 'helper_model' => 'helperModel', 'fallback_model' => 'fallbackModel', 'session_cap' => 'sessionCap', 'month_cap' => 'monthCap'][$field] ?? $field, $messages[0]);
            }

            return;
        }
        $this->notify('Your AI settings are saved.');
        $this->load();
    }

    public function render(): View
    {
        $by = $this->principal();
        $choices = $this->settings->get($by);
        $ownKey = $this->settings->key($by);
        $keyState = $ownKey !== null ? 'own' : ($this->settings->keyAvailable($by) ? 'shared' : 'none');
        $models = $keyState === 'none' ? [] : $this->models->all(key: $this->settings->credential($by));
        // On the shared key, only its models are offered.
        $shared = $this->settings->shared($by);
        if ($shared !== null) {
            $models = array_values(array_filter($models, fn ($m) => in_array($m->id, $shared['models'], true)));
        }
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->id] = $model;
        }
        $words = fn (string $id) => $id !== '' && isset($byId[$id]) ? $byId[$id]->priceWords().($byId[$id]->tools ? '' : ' · no look-ups: this model can\'t call tools') : null;

        // The shared key's models, in the admin's order, with their names and prices, for the switches and the lists.
        $sharedList = array_map(fn (string $id) => [
            'id' => $id,
            'name' => isset($byId[$id]) ? $byId[$id]->name : (str_contains($id, '/') ? explode('/', $id, 2)[1] : $id),
            'price' => $words($id),
        ], $shared['models'] ?? []);

        $favDetails = [];
        foreach ($this->favoriteModels as $favId) {
            $favDetails[] = [
                'id' => $favId,
                'name' => isset($byId[$favId]) ? $byId[$favId]->name : (str_contains($favId, '/') ? explode('/', $favId, 2)[1] : $favId),
                'price' => $words($favId),
            ];
        }

        $suggested = [
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet',
            'openai/gpt-4o' => 'GPT-4o',
            'openai/gpt-4o-mini' => 'GPT-4o mini',
            'google/gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'deepseek/deepseek-chat' => 'DeepSeek V3',
        ];

        return view('livewire.study.engine-settings', [
            'choices' => $choices,
            'roles' => EngineRole::cases(),
            'usage' => $this->usage->month($by),
            'cachedShare' => $this->usage->cachedShare($by),
            'keyState' => $keyState,
            // The shared key for students, when this student is on it: its one model and daily limit.
            'shared' => $shared,
            // The step-by-step guide's model picks (partials/key-guide.blade.php).
            'guidePicks' => self::guidePicks($byId),
            'sharedList' => $sharedList,
            'models' => $models,
            'setupUrl' => $by->hasRole(Role::Admin) ? route('admin.engine') : null,
            'tutorWords' => $words($this->tutorModel),
            'readerWords' => $words($this->readerModel),
            'helperWords' => $words($this->helperModel),
            'fallbackWords' => $words($this->fallbackModel),
            'favDetails' => $favDetails,
            'suggested' => $suggested,
            // The providers a key of their own can come from, by name (OpenRouter is the other option).
            'providers' => collect(Providers::all())->except(Providers::OPENROUTER)->map(fn (array $p) => (string) $p['name'])->all(),
            // The last time the service failed this student, and why (App\Engine\Troubles).
            'trouble' => app(Troubles::class)->last($by),
            'recommended' => Providers::recommended(),
        ])->title('AI settings');
    }

    private function load(): void
    {
        $choices = $this->settings->get($this->principal());
        // The option the saved key belongs to is the one shown.
        $this->source = $choices->provider !== null && Providers::direct($choices->provider) ? 'own' : 'openrouter';
        $this->tutorModel = $choices->tutorModel;
        $this->readerModel = $choices->readerModel;
        $this->helperModel = $choices->helperModel;
        $this->fallbackModel = $choices->fallbackModel;
        $this->favoriteModels = $choices->favoriteModels;
        $this->sessionCap = number_format($choices->sessionCapMicros / 1_000_000, 2, '.', '');
        $this->monthCap = number_format($choices->monthCapMicros / 1_000_000, 2, '.', '');
        $this->noTraining = $choices->noTraining;
        $this->language = $choices->language ?? '';
        $this->dictationModel = $choices->dictationModel;
        $this->askTopics = $choices->askTopics;
        $this->autoReadFiles = $choices->autoReadFiles;
        $this->tutorMarksTopics = $choices->tutorMarksTopics;
        $this->tutorRemembers = $choices->tutorRemembers;
        $this->copyPasteAi = $choices->copyPasteAi;
        $this->consent = $choices->consentedAt !== null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
