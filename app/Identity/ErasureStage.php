<?php

namespace App\Identity;

/** Content-free progress, also usable to verify interruption recovery. */
final readonly class ErasureStage
{
    public function __construct(public string $requestId, public string $stage) {}
}
