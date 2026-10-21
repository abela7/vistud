<?php

namespace Tests\Concerns;

use App\Identity\JoiningPolicy;
use Illuminate\Support\Facades\File;

trait JoiningPolicyFixture
{
    protected function joiningPolicy(): string
    {
        $path = config('vistud.files.temp_root').'/privacy.md';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, '# Synthetic privacy policy');
        config(['vistud.privacy.path' => $path]);

        return app(JoiningPolicy::class)->version();
    }
}
