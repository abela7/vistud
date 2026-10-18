<?php

namespace App\Mcp;

/** One AI app the student let in, as the Connections screen shows it. */
final readonly class ConnectionDetails
{
    public function __construct(
        public string $id,
        public string $oauthClientId,
        public string $provider,
        public ?string $clientLabel,
        public ?string $redirectHost,
        public string $level,
        public string $consentedAt,
        public ?string $revokedAt,
        public ?string $lastUsedAt,
    ) {}

    public function live(): bool
    {
        return $this->revokedAt === null;
    }

    public function providerName(): string
    {
        return Provider::name($this->provider);
    }

    public function levelLabel(): string
    {
        return Connections::LEVELS[$this->level] ?? $this->level;
    }

    /** Unused for longer than config('mcp.stale_days'): the screen says so before it is cut. */
    public function stale(): bool
    {
        $since = $this->lastUsedAt ?? $this->consentedAt;

        return $this->live() && now()->diffInDays($since, true) >= (int) config('mcp.stale_days', 30);
    }
}
