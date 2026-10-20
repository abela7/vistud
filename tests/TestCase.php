<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?string $testMaster = null;

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
