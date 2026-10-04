<?php

namespace App\Engine;

/**
 * A student's engine settings: the model for each of the three roles, the one to try when the tutor's fails,
 * their spending limits, their own key (if any), the language they're taught in, how the tutor keeps topics,
 * the trust toggles, and whether they agreed to the chat.
 */
final readonly class Choices
{
    public function __construct(
        public string $tutorModel,
        public string $readerModel,
        public string $helperModel,
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
        /** Files uploaded to a module are read by the reader at once. */
        public bool $autoReadFiles = true,
        /** The tutor sets a topic's status itself (with undo); otherwise it proposes one. */
        public bool $tutorMarksTopics = true,
        /** The copy-paste path (briefing, paste-and-review) is shown, for use with another AI. */
        public bool $copyPasteAi = false,
    ) {}

    public function ready(): bool
    {
        return $this->tutorModel !== '' && $this->consentedAt !== null;
    }

    public function ownKey(): bool
    {
        return $this->ownKeyHint !== null;
    }

    /**
     * The model that plays a role. One left empty uses the next one up: the helper the reader's, the reader the
     * tutor's, so a student who chose only a tutor model has all three working.
     */
    public function modelFor(Role $role): string
    {
        return match ($role) {
            Role::Tutor => $this->tutorModel,
            Role::Reader => $this->readerModel !== '' ? $this->readerModel : $this->tutorModel,
            Role::Helper => $this->helperModel !== '' ? $this->helperModel : $this->modelFor(Role::Reader),
        };
    }

    /** "$2.00" */
    public static function dollars(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 2);
    }

    /** What something cost, as a student reads it: "$0.00", "under 1¢" or "$1.25". */
    public static function spent(int $micros): string
    {
        return $micros > 0 && $micros < 5_000 ? 'under 1¢' : self::dollars($micros);
    }
}
