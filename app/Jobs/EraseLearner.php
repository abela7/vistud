<?php

namespace App\Jobs;

use App\Identity\Erasure;
use App\Platform\Access\Principal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class EraseLearner implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $timeout = 360;

    public int $uniqueFor = 14400;

    public function __construct(public string $requestId) {}

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function backoff(): int
    {
        return 300;
    }

    public function handle(Erasure $erasure): void
    {
        if (! $erasure->erase(Principal::system('job'), $this->requestId)) {
            $this->release(300);
        }
    }
}
