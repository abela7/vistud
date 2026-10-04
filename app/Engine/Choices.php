<?php

namespace App\Engine;

/** A student's engine settings: the models they chose, their spending limits, and whether they agreed to the chat. */
final readonly class Choices
{
    public function __construct(
        public string $tutorModel,
        public string $quickModel,
        public string $fallbackModel,
        public int $sessionCapMicros,
        public int $monthCapMicros,
        public bool $noTraining,
        public ?string $consentedAt,
    ) {}

    public function ready(): bool
    {
        return $this->tutorModel !== '' && $this->consentedAt !== null;
    }

    /** The model for small jobs (folding a chat, making cards): the quick one, or the tutor when none is chosen. */
    public function quickOrTutor(): string
    {
        return $this->quickModel !== '' ? $this->quickModel : $this->tutorModel;
    }

    /** "$2.00" */
    public static function dollars(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 2);
    }
}
