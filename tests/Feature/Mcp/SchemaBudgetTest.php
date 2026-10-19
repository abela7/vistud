<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ViStudServer;
use App\Mcp\ToolInfo;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The tool list has to fit one page (docs/specs/mcp-connections-plan.md §3.1, M6.7). The 5,000-token figure
 * people report for ChatGPT is unconfirmed, so this measures it and does not trim until a real refusal.
 */
class SchemaBudgetTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_the_full_list_fits_one_page(): void
    {
        $token = $this->connectApp($this->student(), 'full')['access_token'];
        $tools = $this->mcp($token, 'tools/list')->assertOk()->json('result.tools');
        $length = (new \ReflectionClass(ViStudServer::class))->getDefaultProperties()['defaultPaginationLength'];
        $this->assertLessThanOrEqual($length, count($tools));
        $this->assertSame(count(ToolInfo::TOOLS), count($tools));

        $tokens = (int) ceil(strlen((string) json_encode(array_column($tools, 'inputSchema'))) / 4);
        $this->assertGreaterThan(0, $tokens);
        // Measured 2026-10-19 at full level: about 6,500 tokens. Above the unconfirmed 5,000, so no OpenAI trim yet.
        $this->assertLessThan(8000, $tokens, "Input schemas grew past 8,000 tokens ({$tokens}). Look at a trim for OpenAI before ChatGPT refuses the list.");
    }
}
