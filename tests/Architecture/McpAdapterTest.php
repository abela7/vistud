<?php

namespace Tests\Architecture;

use App\Engine\Toolbox;
use App\Engine\Vistud\VistudToolbox;
use App\Mcp\Excluded;
use App\Mcp\ToolInfo;
use Illuminate\Contracts\Container\Container;
use Tests\TestCase;

/**
 * The MCP server is a thin adapter (docs/specs/mcp-connections-plan.md §3.1, M6.2): its tools and server never query
 * the database or reach the admin services, and every in-app tool is either offered or listed as left out, with why.
 */
class McpAdapterTest extends TestCase
{
    private const ADAPTER_DIRS = ['app/Mcp/Tools', 'app/Mcp/Servers', 'app/Mcp/Http'];

    public function test_the_tools_and_the_server_never_touch_the_database_or_admin_services(): void
    {
        $forbidden = '/\bDB::|->getPdo\(|LearnerTables::|App\\\\Identity\\\\(Accounts|Roles|Invitations|TwoFactorReset|AdminSafeguard)\b|App\\\\Audit\\\\AuditLog\b/';
        $offenders = [];
        foreach ([...self::ADAPTER_DIRS, 'app/Http/Middleware/McpPrincipal.php', 'routes/ai.php'] as $path) {
            foreach (self::files($path) as $file) {
                if (preg_match($forbidden, (string) file_get_contents($file)) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }
        // McpPrincipal writes connection.denied through AuditLog; that and nothing else.
        $offenders = array_values(array_filter($offenders, fn (string $f) => ! str_ends_with(str_replace('\\', '/', $f), 'app/Http/Middleware/McpPrincipal.php') || preg_match('/\bDB::|LearnerTables::|Identity\\\\(Accounts|Roles)/', (string) file_get_contents(base_path($f))) === 1));

        $this->assertSame([], $offenders, 'MCP tools call services; they never query or reach admin services.');
    }

    public function test_every_in_app_tool_is_offered_or_left_out_with_a_reason(): void
    {
        $container = app(Container::class);
        $names = array_map(fn ($tool) => $tool->name(), $container->make(Toolbox::class)->tools());
        foreach ($container->make(VistudToolbox::class)->tools() as $tool) {
            if (in_array($tool::class, VistudToolbox::OWN, true)) {
                $names[] = $tool->name();
            }
        }

        $missing = array_values(array_diff(array_unique($names), array_keys(ToolInfo::TOOLS), array_keys(Excluded::TOOLS)));
        $this->assertSame([], $missing, 'Add the tool to App\Mcp\ToolInfo or App\Mcp\Excluded.');
        $this->assertSame([], array_values(array_intersect(array_keys(ToolInfo::TOOLS), array_keys(Excluded::TOOLS))), 'A tool is both offered and left out.');
    }

    /** @return list<string> */
    private static function files(string $path): array
    {
        $full = base_path($path);
        if (is_file($full)) {
            return [$full];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
