<?php

namespace App\Livewire\Concerns;

use App\Study\Tutoring;

/**
 * The teaching fields of a form (starting a session, or changing how the
 * assistant teaches in one): App\Study\Tutoring's four choices. The service
 * checks them.
 */
trait TeachingForm
{
    public string $method = 'explain';

    public string $checkIns = 'section';

    public string $quiz = 'normal';

    public string $pace = 'slide';

    public string $customDo = '';

    public string $customDont = '';

    /** @var list<string> */
    public array $plugins = [];

    /** @return array{method: string, check_ins: string, quiz: string, pace: string, custom_do?: string, custom_dont?: string, plugins?: list<string>} */
    protected function teachingInput(): array
    {
        $input = [
            'method' => $this->method,
            'check_ins' => $this->checkIns,
            'quiz' => $this->quiz,
            'pace' => $this->pace,
        ];

        $do = trim($this->customDo);
        if ($do !== '') {
            $input['custom_do'] = $do;
        }

        $dont = trim($this->customDont);
        if ($dont !== '') {
            $input['custom_dont'] = $dont;
        }

        if ($this->plugins !== []) {
            $input['plugins'] = array_values($this->plugins);
        }

        return $input;
    }

    protected function fillTeaching(array $choices): void
    {
        $choices = Tutoring::normalised($choices);
        [$this->method, $this->checkIns, $this->quiz, $this->pace] = [$choices['method'], $choices['check_ins'], $choices['quiz'], $choices['pace']];
        $this->customDo = $choices['custom_do'] ?? '';
        $this->customDont = $choices['custom_dont'] ?? '';
        $this->plugins = $choices['plugins'] ?? [];
    }

    public function toggleTeachingPlugin(string $id): void
    {
        if (in_array($id, $this->plugins, true)) {
            $this->plugins = array_values(array_filter($this->plugins, fn ($p) => $p !== $id));
        } else {
            $this->plugins[] = $id;
        }
    }

    /** The service's field names (tutoring.check_ins) as this form's (checkIns). */
    protected function teachingErrorField(string $field): string
    {
        return [
            'tutoring.method' => 'method',
            'tutoring.check_ins' => 'checkIns',
            'tutoring.quiz' => 'quiz',
            'tutoring.pace' => 'pace',
            'tutoring.custom_do' => 'customDo',
            'tutoring.custom_dont' => 'customDont',
        ][$field] ?? $field;
    }
}
