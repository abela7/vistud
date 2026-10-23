<?php

namespace App\Livewire\Study;

use App\Engine\Dictation;
use App\Engine\Settings;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Voice typing's settings beside the microphone (the owner's ask, 2026-10-23): which model writes down what is said,
 * and the language spoken, so Amharic isn't guessed to be Arabic. The same choices as in AI settings, kept there.
 */
final class VoiceSettings extends Component
{
    public string $model = Dictation::WHISPER;

    /** One of Dictation::SPOKEN's codes. */
    public string $language = 'auto';

    /** Shown inside another panel (the chat's gear), without a button of its own. */
    public bool $inline = false;

    private Settings $settings;

    private PrincipalFactory $principals;

    public function boot(Settings $settings, PrincipalFactory $principals): void
    {
        [$this->settings, $this->principals] = [$settings, $principals];
    }

    public function mount(): void
    {
        $choices = $this->settings->get($this->principal());
        // One kept for own keys, chosen before the student was on the shared key, shows as the default.
        $this->model = isset(Dictation::modelsFor($this->settings->shared($this->principal()))[$choices->dictationModel]) ? $choices->dictationModel : Dictation::WHISPER;
        // Not set here yet: what "Teach me in" means, else Detect it.
        $this->language = $choices->dictationLanguage ?? (Dictation::languageFor($choices) ?? 'auto');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $this->settings->setDictation($this->principal(), $this->model, $this->language);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->dispatch('voice-settings-saved');
    }

    public function render(): View
    {
        // On the shared key, not the models kept for own keys (Dictation::OWN_KEY_ONLY).
        return view('livewire.study.voice-settings', ['models' => Dictation::modelsFor($this->settings->shared($this->principal()))]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
