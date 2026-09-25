<?php

namespace App\Brain\Journal;

final readonly class Actor
{
    public function __construct(
        public string $type,
        public string $id,
        public string $channel,
    ) {}

    /** @param array{type: string, id: string, channel?: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['id'], $data['channel'] ?? 'web');
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'channel' => $this->channel];
    }
}
