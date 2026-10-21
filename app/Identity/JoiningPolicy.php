<?php

namespace App\Identity;

use App\Platform\Errors\Unprocessable;

/** The exact public policy presented and accepted when joining. S7 owns its text. */
final class JoiningPolicy
{
    public function text(): ?string
    {
        $path = config('vistud.privacy.path', base_path('docs/legal/privacy.md'));

        return is_string($path) && is_file($path) ? file_get_contents($path) : null;
    }

    public function version(): ?string
    {
        $text = $this->text();

        return $text === null || trim($text) === '' ? null : hash('sha256', $text);
    }

    public function requireAcceptance(?string $version): string
    {
        $current = $this->version();
        if ($current === null || $version === null || ! hash_equals($current, $version)) {
            throw new Unprocessable('privacy_required', 'Read and accept the current privacy policy before joining.');
        }

        return $current;
    }
}
