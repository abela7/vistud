<?php

namespace Tests;

use App\Identity\ErasureLedger;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    private static ?string $testMaster = null;

    private static ?string $testLedgerKey = null;

    protected function setUp(): void
    {
        // HTTP/MCP handlers set a PHP request timer. PHPUnit handles many
        // simulated requests in one CLI process; never inherit the previous
        // request's timer into this test. Production limits stay in the handlers.
        set_time_limit(0);
        parent::setUp();
        $temp = sys_get_temp_dir().'/vistud-test-'.bin2hex(random_bytes(16));
        config(['vistud.files.temp_root' => $temp]);
        $ledger = $temp.'-ledger';
        File::ensureDirectoryExists($ledger, 0700);
        config(['vistud.erasure.ledger_path' => $ledger, 'vistud.erasure.ledger_id' => '11111111-1111-4111-8111-111111111111']);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($ledger));
        app(ErasureLedger::class)->initialize();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($temp));
        // New disks are synthetic too: tests must never touch installed artifacts.
        Storage::fake('derived');
        Storage::fake('temporary');
    }

    public function createApplication()
    {
        // Always replace any real environment master with a synthetic process-local test key.
        self::$testMaster ??= 'base64:'.base64_encode(random_bytes(32));
        self::$testLedgerKey ??= 'base64:'.base64_encode(random_bytes(32));
        foreach (['VISTUD_MASTER_KEY' => self::$testMaster, 'VISTUD_PREVIOUS_MASTER_KEY' => '', 'VISTUD_ERASURE_LEDGER_KEY' => self::$testLedgerKey] as $name => $value) {
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
