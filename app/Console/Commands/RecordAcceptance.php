<?php

namespace App\Console\Commands;

use App\Identity\AdmissionGate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Runs G2 and G3 on the test database only, then records the commit and the result.
 * The parent process does not query or write the installed database.
 */
#[Signature('vistud:gate:record-acceptance')]
#[Description('Run the G2 and G3 acceptance suites on vistud_test and record the result for the gate.')]
class RecordAcceptance extends Command
{
    public function handle(AdmissionGate $gate): int
    {
        $env = $this->childEnvironment();
        if (($env['DB_DATABASE'] ?? '') !== 'vistud_test' || ($env['DB_KEYS_DATABASE'] ?? '') !== 'vistud_keys_test') {
            $this->error('Acceptance runs only on vistud_test and vistud_keys_test.');

            return self::FAILURE;
        }
        $temp = $this->outsideTemp();
        if ($temp === '') {
            $this->error('Need a temp directory outside the checkout.');

            return self::FAILURE;
        }
        $head = $gate->head();
        if ($head === null) {
            $this->error('The current commit could not be read.');

            return self::FAILURE;
        }

        $this->line('Running G2 and G3 on vistud_test. The installed database is not used.');
        $process = new Process(
            [PHP_BINARY, '-d', 'sys_temp_dir='.$temp, base_path('vendor/bin/phpunit'), 'tests/Acceptance/G2', 'tests/Acceptance/G3'],
            base_path(),
            $env,
            null,
            7200,
        );
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
        $passed = $process->isSuccessful();
        File::ensureDirectoryExists(dirname($gate->acceptancePath()));
        File::put($gate->acceptancePath(), json_encode([
            'commit' => $head,
            'passed' => $passed,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
        $this->line('acceptance recorded: '.($passed ? 'passed' : 'failed').' '.$head);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The child always uses the named test databases, whatever this process was started with.
     *
     * @return array<string, string|false> `false` removes the name from the inherited environment
     */
    public function childEnvironment(): array
    {
        $env = [];
        foreach (array_merge($_SERVER, getenv() ?: []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        // This process has already loaded the project's .env (real cache, queue, session, mail and keys). Environment
        // variables beat phpunit.xml, so the child would run the suite on those, and could carry the real master key.
        // Drop everything the .env defines: the suite then runs on phpunit.xml and its own generated keys. Only
        // where the database server is stays, because that is machine-specific.
        foreach ($this->definedByDotEnv() as $name) {
            if (! in_array($name, ['DB_HOST', 'DB_PORT'], true)) {
                // A process inherits its parent's environment; only `false` removes a name.
                $env[$name] = false;
            }
        }
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_DATABASE'] = 'vistud_test';
        $env['DB_KEYS_DATABASE'] = 'vistud_keys_test';
        $env['DB_URL'] = '';
        $port = getenv('DB_PORT');
        if (is_string($port) && $port !== '') {
            $env['DB_PORT'] = $port;
        }

        return $env;
    }

    /** @return list<string> the names the project's .env file sets */
    private function definedByDotEnv(): array
    {
        $file = base_path('.env');
        if (! is_file($file)) {
            return [];
        }
        preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', (string) file_get_contents($file), $found);

        return array_values(array_unique($found[1]));
    }

    private function outsideTemp(): string
    {
        $root = str_replace('\\', '/', strtolower((string) (realpath(base_path()) ?: base_path())));
        foreach ([getenv('TEMP'), getenv('TMP'), getenv('TMPDIR')] as $dir) {
            if (! is_string($dir) || $dir === '') {
                continue;
            }
            $real = str_replace('\\', '/', strtolower((string) (realpath($dir) ?: $dir)));
            if (! str_starts_with($real, $root)) {
                return $dir;
            }
        }

        return '';
    }
}
