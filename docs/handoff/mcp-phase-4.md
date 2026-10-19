# ViStud · M6.4 (The rules travel: skills, prompts, plugins): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.4). Goal: a chat app teaches the way ViStud's tutor does without ViStud's system prompt, and the student installs ViStud in one step.

## What was built

**Prompts** (`App\Mcp\Prompts\*`, listed by the server): `start_study_session`, `quiz_me`, `review_topic` and `weekly_brief`. A prompt is a ready first message that names ViStud's tools in order. It reads and writes nothing itself. What the student types into an argument is quoted on one line (no line breaks, quotes or markup), so it cannot become an instruction; a step about an argument left out is not written.

**Resources** (`App\Mcp\Resources\*`, templates): `vistud://courses/{course}/brief`, `vistud://notes/{note}` and `vistud://files/{file}/pages/{page}`. Each acts as the student behind the token, answers "not found" for anything that is not theirs, wraps the text in the `<material>` fence, is cut at 60,000 characters, and writes one `mcp_calls` row (an id, never the text) so the Connections screen discloses the read.

**Skills and packages:** one source, `resources/skills/*/SKILL.md` (`study-session`, `quiz-me`, `student-record`; descriptions written as student situations, under 1,024 characters). `App\Mcp\Plugins\PluginBuilder` builds three packages from it, and `php artisan vistud:mcp:plugins` writes them (folders and zips) to `plugins/` (git-ignored):
- `claude`: `.claude-plugin/plugin.json`, `.mcp.json` (the public address), the skills, a README, a LICENSE.
- `openai`: `plugin.json`, `mcp.json` (`streamable-http`, auth `oauth`), `agents/openai.yaml`, the skills with "Claude" rewritten. **Unverified for a private plugin.**
- `gemini`: one `SKILL.md`, marked untested.
The address is `MCP_PUBLIC_URL` (else `APP_URL`) plus `/mcp`. Every package is stamped with the contract version `ViStudServer::CONTRACT` (now `0.4.0`). A package holds no key, token or student data (tested). The stamp is not used as `judge.prompt`, since nothing proves a skill was loaded.

**Connections screen** (Settings, AI): an "Install ViStud in your chat app" help block with the Claude steps, the ChatGPT connector steps, the Gemini note, and a download for each package (`GET /connections/plugin/{claude|openai|gemini}`, signed-in students only; built when asked). It also points to the session's "Prompt for another AI" as the no-connection path.

**Tests (new):** `Mcp/PromptsTest`, `Mcp/ResourcesTest`, `Mcp/PluginBuildTest`, and two in `Web/ConnectionsScreenTest`.

## Choices made while building

- The prompts are fixed text, not generated from the student's data: they need no data and cannot leak any.
- The course brief resource is the `course_overview` tool's text, so the two never disagree.
- The prefilled "Add to Claude" install link is not built: it needs the public address, so it comes with the deploy.

## What Abel tests (plain steps)

1. In ViStud: Settings, AI, "Install ViStud in your chat app", download the Claude package.
2. Claude: Customize, Plugins, upload the zip. Say "let's study Week 1". Claude should start a session, read the brief, teach, save, and end with a summary, and never say "you told me" about a status that is not `stated`.
3. In Claude Code, `/vistud:quiz_me` works.
4. ChatGPT skills spike: read the result in the next handoff (it needs the public server).

## What is left

- The ChatGPT skills spike (`@plugin-creator`) and the real install test of the zips: both need the public server.
- The prefilled Claude install link.

## Rollback order

Revert the M6.4 commit. No migration.
