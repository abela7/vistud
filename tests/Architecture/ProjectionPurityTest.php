<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0001 principle 3 and ADR 0002 §1: the projection is pure. The rules
 * (Brain/Journal and Brain/Projection) never touch a database, the
 * framework, content text or an LLM. ProjectionRunner is the one adapter
 * that reads stored entries, through the learner-scoped reader.
 */
class ProjectionPurityTest extends TestCase
{
    private const PURE = ['app/Brain/Journal', 'app/Brain/Projection'];

    private const ADAPTERS = ['app/Brain/Projection/ProjectionRunner.php'];

    /** Framework, database, HTTP and network access, and anything that fetches content. */
    private const FORBIDDEN = [
        '/\buse\s+Illuminate\\\\/' => 'the framework',
        '/\bDB::|\bPDO\b|mysqli/' => 'a database',
        '/\b(?:curl_|file_get_contents|fopen|Http::)/' => 'I/O or the network',
        '/journal_content|->content\(/' => 'content text',
        '/\b(?:now|today)\(\)|time\(\)|new\s+DateTimeImmutable\(\s*\)/' => 'the clock (time is an argument)',
    ];

    public function test_the_rules_touch_no_database_framework_content_clock_or_network(): void
    {
        $offenders = [];
        foreach (self::PURE as $directory) {
            foreach (glob(dirname(__DIR__, 2)."/{$directory}/*.php") as $path) {
                $file = substr($path, strlen(dirname(__DIR__, 2)) + 1);
                if (in_array($file, self::ADAPTERS, true)) {
                    continue;
                }
                $code = file_get_contents($path);
                foreach (self::FORBIDDEN as $pattern => $what) {
                    if (preg_match($pattern, $code) === 1) {
                        $offenders[] = "{$file} uses {$what}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
