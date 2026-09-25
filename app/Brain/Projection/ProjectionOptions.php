<?php

namespace App\Brain\Projection;

use DateTimeImmutable;

/**
 * Which view to project.
 *
 * - Belief view: set maxPosition to replay only what had been recorded then.
 * - Current view at a moment: set observedUntil to keep every claim but only
 *   the observations that had occurred by then.
 *
 * ignoreDisputes and humanJudgedOnly are used internally to compute the
 * rests_on_dispute and includes_ai_judged flags.
 */
final readonly class ProjectionOptions
{
    public function __construct(
        public DateTimeImmutable $now,
        public ?int $maxPosition = null,
        public ?DateTimeImmutable $observedUntil = null,
        public bool $ignoreDisputes = false,
        public bool $humanJudgedOnly = false,
    ) {}

    public function with(?bool $ignoreDisputes = null, ?bool $humanJudgedOnly = null): self
    {
        return new self(
            $this->now,
            $this->maxPosition,
            $this->observedUntil,
            $ignoreDisputes ?? $this->ignoreDisputes,
            $humanJudgedOnly ?? $this->humanJudgedOnly,
        );
    }
}
