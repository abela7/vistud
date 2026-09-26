<?php

namespace App\Identity;

/** An invitation nobody has accepted or cancelled yet, as an admin sees it. Never the token. */
final readonly class PendingInvitation
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $invitedByName,
        public string $createdAt,
        public string $expiresAt,
        public bool $expired,
    ) {}
}
