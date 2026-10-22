<?php

namespace App\Livewire\Admin;

use App\Engine\Model;
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

    public string $readerModel = '';

    public string $helperModel = '';

    /** The shared key for students without their own: on or off, its key (sent once), its models, a daily limit in dollars. */
    public bool $sharedOn = false;

    public string $sharedKey = '';

    /** One model id a line; the first is the default. */
    public string $sharedModels = '';

    public string $sharedDaily = '';

    /** Narrowing the list of cheap models: words in the name or id, the most it may cost, and what it can do. */
    public string $modelSearch = '';

    public string $modelPrice = '1';

    public bool $modelPictures = false;

    public bool $modelTools = false;

    private bool $freshModels = false;

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
        [$this->url, $this->tutorModel, $this->readerModel, $this->helperModel] = [$status['url'], $status['tutor_model'], $status['reader_model'], $status['helper_model']];
        $this->loadShared($status);
        if (request()->boolean('confirmed')) {
            $this->notify('Password confirmed. Paste the key again and press Save; you have ten minutes.', 'info');
        }
    }

    public function saveShared(): void
    {
        $this->resetErrorBag();
        try {
            $this->setup->setShared($this->principal(), ['on' => $this->sharedOn, 'key' => $this->sharedKey, 'models' => $this->sharedModels, 'daily' => $this->sharedDaily]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['key' => 'sharedKey', 'models' => 'sharedModels', 'daily' => 'sharedDaily'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (PasswordConfirmationRequired) {
            $this->confirmPasswordFirst();

            return;
        }
        $this->sharedKey = '';
        $this->loadShared($this->setup->status($this->principal()));
        $this->notify($this->sharedOn ? 'Students without a key now use the shared one.' : 'Saved. The shared key is off.');
    }

    public function removeSharedKey(): void
    {
        try {
            $this->setup->removeSharedKey($this->principal());
        } catch (PasswordConfirmationRequired) {
            $this->confirmPasswordFirst();

            return;
        }
        $this->loadShared($this->setup->status($this->principal()));
        $this->notify('The key for students is removed, and the shared key is off.');
    }

    /** Asks OpenRouter for its models again (the list is otherwise kept a few hours). */
    public function refreshModels(): void
    {
        $this->freshModels = true;
    }

    /** Adds a model from the list of cheap ones to the box; Save keeps it. */
    public function addShared(string $id): void
    {
        $ids = array_values(array_filter(array_map('trim', explode("\n", $this->sharedModels))));
        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $this->sharedModels = implode("\n", $ids);
    }

    /** @param array<string, mixed> $status */
    private function loadShared(array $status): void
    {
        [$this->sharedOn, $this->sharedModels, $this->sharedDaily] = [(bool) $status['shared_on'], implode("\n", $status['shared_models']), ltrim((string) $status['shared_daily'], '$')];
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
            $this->confirmPasswordFirst();

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
            $this->confirmPasswordFirst();

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
            $this->setup->setDefaults($this->principal(), ['url' => $this->url, 'tutor_model' => $this->tutorModel, 'reader_model' => $this->readerModel, 'helper_model' => $this->helperModel]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['url' => 'url', 'tutor_model' => 'tutorModel', 'reader_model' => 'readerModel', 'helper_model' => 'helperModel'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (PasswordConfirmationRequired) {
            $this->confirmPasswordFirst();

            return;
        }
        $status = $this->setup->status($this->principal());
        [$this->url, $this->tutorModel, $this->readerModel, $this->helperModel] = [$status['url'], $status['tutor_model'], $status['reader_model'], $status['helper_model']];
        $this->notify('The defaults are saved.');
    }

    public function render(): View
    {
        $status = $this->setup->status($this->principal());

        return view('livewire.admin.engine-setup', [
            'status' => $status,
            'models' => $status['key_set'] ? $this->models->all(key: $this->setup->key()) : [],
            // The free and very cheap models the students' key can use, narrowed by the filters, to pick from.
            'cheapModels' => $cheap = $status['shared_key_hint'] !== null ? $this->setup->cheapModels($this->freshModels) : [],
            'shownModels' => self::filterModels($cheap, $this->modelSearch, $this->modelPrice, $this->modelPictures, $this->modelTools),
            'chosenModels' => array_values(array_filter(array_map('trim', explode("\n", $this->sharedModels)))),
        ]);
    }

    /**
     * The models matching every filter: each word of $search in the name or id, at most $price dollars a million
     * tokens in and out ("free" for free only), and able to see pictures or use tools when asked.
     *
     * @param  list<Model>  $models
     * @return list<Model>
     */
    public static function filterModels(array $models, string $search, string $price, bool $pictures, bool $tools): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($search))) ?: []);
        $most = $price === 'free' ? 0.0 : (is_numeric($price) ? (float) $price : Setup::MAX_SHARED_PRICE);

        return array_values(array_filter($models, function (Model $m) use ($words, $most, $pictures, $tools) {
            $text = mb_strtolower($m->name.' '.$m->id);
            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    return false;
                }
            }

            return $m->inPerMillion <= $most && $m->outPerMillion <= $most && (! $pictures || $m->images) && (! $tools || $m->tools);
        }));
    }

    /**
     * Saving needs a password confirmed in the last ten minutes: confirm it, then come back to this page (not the
     * dashboard), told to paste the key again, since a key is never kept between requests.
     */
    private function confirmPasswordFirst(): void
    {
        redirect()->setIntendedUrl(route('admin.engine', ['confirmed' => 1]));
        $this->redirectRoute('password.confirm');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
