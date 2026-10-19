# ViStud · M6.5 (Changes you confirm in ViStud): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.5; sections 3.1 and 3.4). Goal: renames and other hard-to-undo changes can be asked for in a chat app, but only happen after the student presses Yes on a ViStud page.

## What was built

**Eight manage tools** (`App\Mcp\Tools\ManageTool`, level *full* only, so a read or read and write app never sees them): `rename_topic`, `rename_module`, `rename_folder`, `rename_course`, `rename_note`, `forget_memory`, `remember` and `replace_note`. All take ids (never names). Each is marked destructive and carries `_meta["anthropic/requiresUserInteraction"]: true`. **A tool changes nothing.** It checks the id is the student's, stores the change (`mcp_confirmations`, 10 minutes) and answers `status: pending_confirmation` with a ViStud link and the instruction to give the link to the student and never to call again to confirm.

**The confirmation** (`App\Mcp\Confirmations`):
- `GET /connections/confirm/{id}` (signed-in student) shows the change read-only: what it is, the target's name as it is now, the new words, which company's app asked. It has no side effects, so a link preview or a model that fetches the page changes nothing.
- `POST /connections/confirm/{id}` (CSRF) runs it once, on the target as it is then, through the same services the screens use. `forget_memory`, `remember` and `replace_note` first ask for the password (the existing password-confirm page, then back).
- A wrong, expired, used, voided or another student's link answers "not found", all the same. Disconnecting an app voids its waiting changes. Each confirmed change is audited `mcp.destructive_confirmed` (the tool name only, never the change).
- No MCP tool can confirm anything.

**Rules:** `resources/prompts/mcp-rules.md` says renames and the rest are asks: give the student the link and wait.

**Contract:** `tests/Feature/Mcp/fixtures/tools.json` now lists 46 tools at level full (38 at the default level). The tool list is checked against `ToolInfo`; `Excluded` no longer lists the renames, `forget_memory` or `remember`.

**Tests (new):** `Mcp/ConfirmationTest` (10): nothing changes by the tool or the GET, a POST runs once, every rename on its own target, the password step, forget and replace note, expired/wrong/other student's/voided links, a foreign id refused before anything is stored, levels and the interaction hint. `AdapterParityTest` gained the row for these capabilities.

## Left out, on purpose

- **The in-app chat's own agreement** for `rename_topic`, `rename_note` and the note replace (the plan's "Agreement in the app"): it changes how ViStud's own tutor behaves for every student, and has nothing to do with chat apps. It is a separate, small change with its own tests.
- The browser spec `mcp-confirm.spec.js` (the page at 320 px): not written.
- `remember` does not yet check the student's "remember" switch beyond what `TutorMemories::add` does.

## What Abel tests (plain steps)

1. Connect the app at *Read, write, and changes you confirm*. Ask: "Rename the module Week 2 to Scheduling." A ViStud link appears; open it. It shows the module and the new name and changes nothing.
2. Press Yes. The module is renamed. Using the link again is refused.
3. Tell the app "just confirm it yourself": nothing changes.
4. "Delete the topic Paging" is not available over MCP.

## Rollback order

Revert the M6.5 commit. No migration (the table existed since M6.1).
