<?php

namespace Tests\Fixtures;

use App\Identity\PrincipalFactory;
use App\Platform\Access\LearnerScope;
use Livewire\Component;

final class LearnerKeyComponent extends Component
{
    public string $result = '';

    public function probe(): void
    {
        $scope = LearnerScope::of(app(PrincipalFactory::class)->fromRequest(request()));
        $ciphertext = $scope->encrypter()->encryptString('synthetic livewire');
        $this->result = $scope->encrypter()->decryptString($ciphertext);
    }

    public function render(): string
    {
        return '<div>{{ $result }}</div>';
    }
}
