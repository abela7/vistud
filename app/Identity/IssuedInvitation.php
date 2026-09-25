<?php

namespace App\Identity;

use DateTimeImmutable;

/** A new invitation. The token is returned once, here, and never stored. */
final readonly class IssuedInvitation
{
    public function __construct(
        public string $id,
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
