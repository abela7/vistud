<?php

namespace Tests\Feature\Mcp;

use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The MCP tools are a contract (docs/specs/mcp-connections-plan.md §3.9; docs/architecture/contracts.md): chat apps
 * and saved prompts depend on each tool's name, its arguments and its hints. This compares what the server lists with
 * tests/Feature/Mcp/fixtures/tools.json, so a change is always on purpose. To change the contract, run the test with
 * MCP_SNAPSHOT=write, and say what changed in the pull request (a `contract-change`).
 */
class ContractSnapshotTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private const FIXTURE = __DIR__.'/fixtures/tools.json';

    public function test_the_tool_list_is_the_contract(): void
    {
        $token = $this->connectApp($this->student(), 'full')['access_token'];
        $tools = $this->mcp($token, 'tools/list')->assertOk()->json('result.tools');

        $contract = [];
        foreach ($tools as $tool) {
            $schema = $tool['inputSchema'];
            $properties = array_keys((array) ($schema['properties'] ?? []));
            sort($properties);
            $required = $schema['required'] ?? [];
            sort($required);
            $contract[$tool['name']] = [
                'arguments' => $properties,
                'required' => $required,
                'hints' => array_intersect_key($tool['annotations'], array_flip(['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'])),
            ];
        }
        $json = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (env('MCP_SNAPSHOT') === 'write' || ! is_file(self::FIXTURE)) {
            @mkdir(dirname(self::FIXTURE), 0775, true);
            file_put_contents(self::FIXTURE, $json);
        }
        $this->assertSame((string) file_get_contents(self::FIXTURE), $json, 'The MCP tool contract changed. If on purpose: MCP_SNAPSHOT=write, and call it out as a contract-change.');
    }
}
