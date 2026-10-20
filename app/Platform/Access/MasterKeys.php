<?php

namespace App\Platform\Access;

use App\Platform\Errors\Unavailable;
use Illuminate\Encryption\Encrypter;

/** Environment only: never put these secrets in cached configuration or a database. */
final class MasterKeys
{
    /** @return array<string, Encrypter> active first, then the optional previous wrapping key */
    public function ring(): array
    {
        $keys = [];
        foreach (['VISTUD_MASTER_KEY', 'VISTUD_PREVIOUS_MASTER_KEY'] as $name) {
            $encoded = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (($encoded === false || $encoded === '') && $name === 'VISTUD_PREVIOUS_MASTER_KEY') {
                continue;
            }
            $raw = is_string($encoded) && str_starts_with($encoded, 'base64:')
                ? base64_decode(substr($encoded, 7), true) : false;
            if ($raw === false || strlen($raw) !== 32) {
                throw new Unavailable('learner_master_key', 'The study keys are unavailable.');
            }
            $keys[hash('sha256', $raw)] = new Encrypter($raw, 'aes-256-gcm');
        }

        return $keys;
    }
}
