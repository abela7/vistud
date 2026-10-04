<?php

namespace App\Engine;

/** A student's engine settings: the models they chose, their spending limits, their own key (if any), the language they're taught in, how the tutor keeps topics, and whether they agreed to the chat. */
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
        /** The last four characters of the student's own key for the service, or null without one. */
        public ?string $ownKeyHint = null,
        public ?string $keyUpdatedAt = null,
        /** The language the tutor teaches in; null for the one the student writes in. */
        public ?string $language = null,
        /** Whether the tutor asks before adding or switching topics; otherwise it keeps them as it teaches. */
        public bool $askTopics = false,
    ) {}

    public function ready(): bool
    {
        return $this->tutorModel !== '' && $this->consentedAt !== null;
    }

    public function ownKey(): bool
    {
        return $this->ownKeyHint !== null;
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
