<?php

namespace Tests\Unit\Platform;

use App\Platform\Access\MasterKeys;
use App\Platform\Errors\Unavailable;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

class MasterKeysTest extends TestCase
{
    private function environment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    public function test_active_and_previous_environment_keys_use_authenticated_encryption(): void
    {
        $active = random_bytes(32);
        $old = random_bytes(32);
        $this->environment('VISTUD_MASTER_KEY', 'base64:'.base64_encode($active));
        $this->environment('VISTUD_PREVIOUS_MASTER_KEY', 'base64:'.base64_encode($old));
        $ring = app(MasterKeys::class)->ring();
        $this->assertSame([hash('sha256', $active), hash('sha256', $old)], array_keys($ring));
        $this->assertInstanceOf(Encrypter::class, $ring[hash('sha256', $active)]);
        $encrypted = $ring[hash('sha256', $active)]->encryptString('synthetic payload');
        $decoded = json_decode(base64_decode($encrypted), true);
        $this->assertSame(16, strlen(base64_decode($decoded['tag'])));
        $this->assertSame('synthetic payload', $ring[hash('sha256', $active)]->decryptString($encrypted));
        $this->assertFalse(str_contains(json_encode(config()->all()), base64_encode($active)));
    }

    public function test_missing_master_has_no_app_key_fallback(): void
    {
        $this->environment('VISTUD_MASTER_KEY', '');
        $this->expectException(Unavailable::class);
        app(MasterKeys::class)->ring();
    }

    public function test_malformed_master_fails_closed(): void
    {
        $this->environment('VISTUD_MASTER_KEY', 'base64:'.base64_encode(random_bytes(16)));
        $this->expectException(Unavailable::class);
        app(MasterKeys::class)->ring();
    }
}
