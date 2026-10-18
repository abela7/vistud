# ViStud · M6.2 (Reads, on the public server): handoff, part 1 of 2

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.2; section 3.1 for the tools). M6.2 has two halves: the **read tools** (built, this note) and the **public server** (waiting on decisions 2 and 7: where it runs and its address). Part 2 of this note comes with the server.

## What was built

**21 read tools over MCP.** An AI app connected at any level can now read the student's ViStud:

| Kind | Tools |
|---|---|
| Account | `me`, `courses`, `study_time`, `search_everything`, `memories`, `current_session` |
| One course (`course_id`, optional `module_id`) | `course_overview`, `topics`, `questions`, `findings`, `assignments`, `assignment_plan`, `calendar`, `notes`, `read_note`, `search_notes`, `files`, `module_files`, `read_file`, `earlier_sessions`, `flashcards_due` |

- **One set of rules.** `App\Mcp\Tools\EngineTool` wraps the in-app tutor's own `App\Engine\Tools` classes (and ViStud AI's account tools), so the app and the chat apps run the same code. `App\Mcp\ContextBuilder` builds their `Context` from ids only, each checked to be in the course: another student's id, or a module of another course, answers exactly like an id that never existed. Names never pick a course over MCP.
- **Built here on the services:** `current_session` (`Sessions::current`) and `flashcards_due` (`Flashcards::queue`), with structured content plus a text answer; the student's words in the text are fenced.
- **`App\Mcp\ToolInfo`** lists every tool with its four hints, all explicit (the 2026-07-28 schema would default a missing `destructiveHint` and `openWorldHint` to true), its source, whether it takes a course, and the lowest connection level allowed. **`App\Mcp\Excluded`** lists every in-app tool not offered, with why.
- **`App\Mcp\Tools\McpTool`** (base): acts as the token's student, refuses a tool above the connection's level, turns `NotFound` and refusals into MCP errors the app sees (`isError`), and writes one `mcp_calls` row per call (tool, outcome, a type and an id, characters returned, never text).
- **Server rules:** `resources/prompts/mcp-rules.md` is the server's instructions (key rules first). Tools are listed 50 to a page, so a chat app that reads one page sees all of them.
- **Connections screen:** "What they read lately" lists the recent calls per company (`Connections::disclosures`).

**Design change from the plan:** the plan sketched one PHP class per wrapped tool, each with its own hint attributes. One wrapper class plus the `ToolInfo` table does the same with far less code, and `Mcp/ReadToolsTest` checks that every listed tool carries exactly the table's hints.

**A fix found while testing:** M6.1's consent controller (`App\Mcp\Http\AuthorizeConnection`, Passport's subclass) did not get the web-login guard Passport gives its own controller by class name. It now does (`AppServiceProvider`). In a normal request this made no difference, but the code must never depend on which guard a container happened to cache.

**`study_brief`** moves to M6.3: it needs a session, and `start_session` will return it.

**Tests (new):** `tests/Feature/Mcp/ReadToolsTest` (every listed tool with its hints, ids, fences, errors the app sees, one call row without text), `tests/Feature/Mcp/IsolationTest` (another student's course, module and note ids answer exactly like random ones, a canary word never comes back, tokens interleaved), `tests/Architecture/McpAdapterTest` (no database, `LearnerTables` or admin services in the tools, server or consent controllers; every in-app tool offered or excluded). `tests/Feature/Web/ConnectionsScreenTest` shows the read list.

## What Abel tests (plain steps)

With Claude Code connected as in M6.1 (`claude mcp add --transport http vistud http://127.0.0.1:8000/mcp`):
1. Ask "what courses do I have in ViStud?" It lists them.
2. Ask "what's still confusing in <a course>?" Topics come back with who said so.
3. Ask "read page 3 of <a file> in <a course>". The text comes back.
4. Settings › AI › Connected AI apps › "What they read lately" lists those calls.

## Built since, before the server (2026-10-18)

- **Decisions recorded** (Abel): 2, his own VPS; 7, `https://vistud.app` (so `https://vistud.app/mcp`; `.app` is HTTPS-only); 6, explicit sessions, with ADR 0002 §9 amended the same day.
- **Big PDFs are read off the request**: `FileTexts::of()` answers "preparing" for a PDF over 4 MB that hasn't been read yet and queues `App\Jobs\ExtractFileText` (unique per file); with the sync queue it is read by the time it answers, as before. A PDF that can't be read leaves a `.failed` mark, so the tutor hears "no text" instead of "preparing" for ever. This is the in-app path too. Tests in `tests/Feature/Study/FileTextsTest.php`.
- **`/mcp` time limit**: `McpPrincipal` sets PHP's limit to 230 s, inside Claude's 240.
- **Guides**: `docs/development/deploy.md` (the server, nginx, `.env`, processes, the go-live migration with the same `APP_KEY`, a release, connecting the apps, the restore drill), `docs/development/operations.md` (checks, the kill switch, what to do), `docs/development/mcp-golden-prompts.md` (twelve asks to rerun after any tool change).

## What is left (part 2, needs the server)

- **Hosting** (plan section 5): the VPS, domain and TLS, `APP_URL`, `/mcp` timeouts, queue worker, scheduler, backups, monitoring, `docs/development/deploy.md` and `operations.md`. Then the connector in claude.ai, ChatGPT and Antigravity CLI.
- **ChatGPT step 1**: whether Abel's plan can add a custom server at all.
- **The `securitySchemes` spike** (needs ChatGPT reaching the server).
- `Mcp/SchemaBudgetTest`, `Mcp/KillSwitchTest` (the kill switch is already covered by `UnauthenticatedTest`).

## Rollback order

Revert the M6.2 commit. No migration.

## Message for the local tester

```
Please bring your copy up to date with M6.2 part 1 (read tools). Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude-latest
2. git checkout claude-latest
3. git merge --ff-only origin/claude-latest
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer install
5. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   .tools\php\php.exe vendor\bin\phpunit tests/Feature/Mcp tests/Architecture tests/Feature/Web/ConnectionsScreenTest.php

Do not push anything.
```
