<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Personal derived text only; locks, timers and model catalogues remain metadata. */
final class LearnerCache
{
    public static function get(LearnerScope $scope, string $key): mixed
    {
        $stored = Cache::get($key);
        if ($stored === null) {
            return null;
        }
        if (! is_string($stored)) {
            throw new RuntimeException('Clear legacy learner text caches before starting workers.');
        }
        try {
            $data = json_decode(self::encrypter($scope)->decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
            if (($data['v'] ?? null) !== 1 || ($data['learner'] ?? null) !== $scope->learnerId || ($data['key'] ?? null) !== $key) {
                throw new RuntimeException;
            }

            return $data['value'];
        } catch (\Throwable $e) {
            throw new RuntimeException('Learner cache authentication failed.', previous: $e);
        }
    }

    public static function put(LearnerScope $scope, string $key, mixed $value, mixed $ttl): void
    {
        Cache::put($key, self::encrypter($scope)->encryptString(json_encode([
            'v' => 1, 'learner' => $scope->learnerId, 'key' => $key, 'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $ttl);
    }

    private static function encrypter(LearnerScope $scope): Encrypter
    {
        return new Encrypter($scope->key('cache'), 'aes-256-gcm');
    }
}
