<?php

namespace App\Engine;

/** A model the service offers: its id and name, its prices per million tokens, and what it can take. */
final readonly class Model
{
    public function __construct(
        public string $id,
        public string $name,
        public float $inPerMillion,
        public float $outPerMillion,
        public int $contextLength,
        public bool $tools,
        public bool $images,
        public bool $files,
    ) {}

    /** "$0.15 in · $0.60 out per million tokens", or "free". */
    public function priceWords(): string
    {
        if ($this->inPerMillion == 0.0 && $this->outPerMillion == 0.0) {
            return 'free';
        }

        return '$'.self::money($this->inPerMillion).' in · $'.self::money($this->outPerMillion).' out per million tokens';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, $amount > 0 && $amount < 0.1 ? 3 : 2, '.', '');
    }
}
