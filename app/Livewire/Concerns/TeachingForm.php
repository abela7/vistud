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

    /** @return array{method: string, check_ins: string, quiz: string, pace: string} */
    protected function teachingInput(): array
    {
        return ['method' => $this->method, 'check_ins' => $this->checkIns, 'quiz' => $this->quiz, 'pace' => $this->pace];
    }

    protected function fillTeaching(array $choices): void
    {
        $choices = Tutoring::normalised($choices);
        [$this->method, $this->checkIns, $this->quiz, $this->pace] = [$choices['method'], $choices['check_ins'], $choices['quiz'], $choices['pace']];
    }

    /** The service's field names (tutoring.check_ins) as this form's (checkIns). */
    protected function teachingErrorField(string $field): string
    {
        return ['tutoring.method' => 'method', 'tutoring.check_ins' => 'checkIns', 'tutoring.quiz' => 'quiz', 'tutoring.pace' => 'pace'][$field] ?? $field;
    }
}
