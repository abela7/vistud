# ViStud · M6.7 (Isolation and measurement): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.7). Goal: one student's app cannot see another's, and chat-captured evidence can be counted.

## What was built

**Isolation** (`tests/Feature/Mcp/McpIsolationSuiteTest.php`). The plan's name `McpIsolationSuite` is not a PHPUnit name, so the file ends in `Test`. Ada's full token calls every tool, three resources and four prompts with Grace's ids. Grace's canary never comes back. Grace's own token still sees her course. `vistud:db:orphans` is run inside the suite and must be clean.

Ten thousand further lookups call `Workspaces`, `Topics` and `Notes` as Ada, alternating Grace's ids with ids that never existed. They are the services the tools use, in one worker. Ten thousand full HTTP round-trips were not run: one pass of the HTTP matrix already covers every tool, and the extra lookups finish in the same test.

A second test shows what a break looks like: a query with no learner filter reads Grace's title, and a cache key with no learner id would hand that title to anyone. `app/Mcp` is scanned for `Cache::` and for `DB::` outside `Connections`, `Confirmations` and the measurement command.

**Headers** (`tests/Feature/Mcp/HeadersTest.php`). `McpNoStore` is the outer middleware on `/mcp`, so a 401 is `no-store` too, and the route sets no cookie. The consent page already had `frame-ancestors 'none'`. The confirm page now has the same, and neither page sets `form-action`. The tunnel guard is asserted again: with `TUNNEL=1` and debug off, `/login` answers and `/settings` is 404.

**Schema** (`tests/Feature/Mcp/SchemaBudgetTest.php`). Full level lists 47 tools, which is under the page size of 50. The input schemas measure about 6,500 tokens. The 5,000-token ChatGPT figure is unconfirmed, so nothing is trimmed. The test fails if the schemas pass 8,000, which is the point to add an OpenAI trim in `shouldRegister()`.

**G1b** (`php artisan vistud:measure:g1b`, `App\Mcp\G1b`, `App\Console\Commands\MeasureG1b`). It counts journal attempts whose `judge.via` is `mcp` or `chat`, one learner at a time, and prints the span in days. It reads the via only. Under 150 of each, or under 28 days, the result is `inconclusive`. Meeting the minimums prints that the sample is large enough and that no embedding comparison is stored, so nothing is decided. The command always exits 0.

On this database the first report is inconclusive (no chat-app attempts yet).

## Choices

- The plan's "deliberate breaks must fail the suite" is the second test: an unscoped read and a shared cache key contain the canary, and the suite's scan fails if `app/Mcp` grows a cache or a new query.
- G1's labelled retrieval cases are not in this database. The command counts attempts instead, which is what ADR 0003 D9 calls chat-captured evidence.

## What Abel tests (plain steps)

Nothing in the apps. Read this note. When there has been real use, run `php artisan vistud:measure:g1b` and keep the lines it prints.

## What is left

- The validator-written G2 and G3 suites. They are not this phase.
- A trim of the OpenAI tool list, only if ChatGPT actually refuses the schemas.
- Ten thousand HTTP calls. The service lookups stand in for them.

## Rollback order

Revert the M6.7 commit. No migration.
