<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    private static ?string $testMaster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $temp = sys_get_temp_dir().'/vistud-test-'.bin2hex(random_bytes(16));
        config(['vistud.files.temp_root' => $temp]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($temp));
        // New disks are synthetic too: tests must never touch installed artifacts.
        Storage::fake('derived');
        Storage::fake('temporary');
    }

    public function createApplication()
    {
        // Always replace any real environment master with a synthetic process-local test key.
        self::$testMaster ??= 'base64:'.base64_encode(random_bytes(32));
        foreach (['VISTUD_MASTER_KEY' => self::$testMaster, 'VISTUD_PREVIOUS_MASTER_KEY' => ''] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = parent::createApplication();
        foreach (['mysql_keys' => 'mysql', 'mysql_keys_owner' => 'mysql_owner'] as $keys => $content) {
            $connection = $app['config']->get('database.connections.'.$content);
            // Tests cannot inherit a production key database from .env.
            $connection['database'] = 'vistud_keys_test';
            unset($connection['url']);
            $app['config']->set('database.connections.'.$keys, $connection);
        }

        return $app;
    }
}
