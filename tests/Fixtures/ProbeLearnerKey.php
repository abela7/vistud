<?php

namespace Tests\Fixtures;

use App\Platform\Access\LearnerKeys;
use App\Platform\Access\LearnerScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class ProbeLearnerKey implements ShouldQueue
{
    use Queueable;

    public static array $observed = [];

    public function __construct(public string $learnerId, public bool $fail = false) {}

    public function handle(): void
    {
        $ring = app(LearnerKeys::class);
        $before = $ring->__debugInfo()['loaded'];
        $scope = LearnerScope::forJob($this->learnerId);
        $ciphertext = $scope->encrypter()->encryptString('synthetic probe');
        self::$observed[] = [
            $this->learnerId, $before, $ring->__debugInfo()['loaded'],
            $scope->encrypter()->decryptString($ciphertext),
        ];
        if ($this->fail) {
            throw new RuntimeException('Synthetic job failure.');
        }
    }
}
