<?php

namespace App\Platform\Access;

/** Request/job-scoped key ring. No static secrets, shared cache, or serialized key material. */
final class LearnerKeys
{
    /** @var array<string, string> */
    private array $keys = [];

    public function __construct(private LearnerKeyStore $store) {}

    public static function unlock(Principal $principal): void
    {
        app(self::class)->derive(LearnerScope::of($principal), 'content');
    }

    public function derive(LearnerScope $scope, string $purpose): string
    {
        if (! in_array($purpose, ['content', 'search', 'cache', 'files'], true)) {
            throw new \InvalidArgumentException('Unknown learner key purpose.');
        }
        $key = $this->keys[$scope->learnerId] ??= $this->store->load($scope);

        return hash_hkdf('sha256', $key, 32, 'vistud:'.$purpose.':v1', $scope->learnerId);
    }

    /** Also used at sync job boundaries, which do not flush Laravel's worker scope. */
    public function forget(): void
    {
        $this->keys = [];
    }

    public function __debugInfo(): array
    {
        return ['loaded' => count($this->keys)];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Learner keys must never be serialized.');
    }
}
