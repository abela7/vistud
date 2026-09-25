<?php

namespace Tests\Architecture;

use App\Platform\Database\LearnerTables;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Structural rules behind learner isolation (ADR 0001 I3 and day-one rule 2,
 * ADR 0003 §10.4). These fail the build rather than relying on review.
 */
class LearnerIsolationTest extends TestCase
{
    use RefreshesDatabase;

    /** Code that may create a LearnerScope from a bare learner ID. */
    private const FOR_JOB_ALLOWED = [
        'app/Jobs/',
        'app/Console/Commands/',
        'app/Brain/Redaction/',
        'app/Platform/Outbox/',
    ];

    public function test_every_learner_table_carries_learner_id(): void
    {
        foreach (LearnerTables::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Learner table '{$table}' does not exist.");
            $this->assertTrue(Schema::hasColumn($table, 'learner_id'), "Learner table '{$table}' has no learner_id column.");
        }
    }

    public function test_learner_tables_are_reached_only_through_the_isolation_layer(): void
    {
        $tables = implode('|', array_map('preg_quote', LearnerTables::TABLES));
        $direct = '/(?:table|from|join|leftJoin|rightJoin)\(\s*[\'"](?:'.$tables.')[\'"]|\$table\s*=\s*[\'"](?:'.$tables.')[\'"]/';

        $offenders = [];
        foreach ($this->phpFiles('app') as $file) {
            if ($file === 'app/Platform/Database/LearnerTables.php') {
                continue;
            }
            if (preg_match($direct, file_get_contents(base_path($file))) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Use LearnerTables::query() for learner tables.');
    }

    public function test_bare_learner_scopes_are_created_only_by_trusted_server_code(): void
    {
        $offenders = [];
        foreach ($this->phpFiles('app') as $file) {
            if ($file === 'app/Platform/Access/LearnerScope.php') {
                continue;
            }
            $allowed = array_filter(self::FOR_JOB_ALLOWED, fn ($prefix) => str_starts_with($file, $prefix));
            if ($allowed === [] && str_contains(file_get_contents(base_path($file)), 'LearnerScope::forJob(')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Request-driven code must use LearnerScope::of($principal).');
    }

    public function test_the_brain_never_reads_the_request_session_or_login(): void
    {
        $forbidden = '/\b(?:Auth::|Session::|Request::|auth\(\)|session\(\)|request\(\))|use Illuminate\\\\Http\\\\Request;/';

        $offenders = [];
        foreach ($this->phpFiles('app/Brain') as $file) {
            if (preg_match($forbidden, file_get_contents(base_path($file))) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Brain services take a Principal or LearnerScope argument instead.');
    }

    /** @return list<string> paths relative to the project root */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }
        sort($files);

        return $files;
    }
}
