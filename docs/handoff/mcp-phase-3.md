# ViStud · M6.3 (Sessions and writes): handoff, part 1

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.3; sections 3.3, 3.6 and 3.7). Decision 6 (explicit sessions) was made by Abel on 2026-10-18 and ADR 0002 §9 amended the same day. Part 1 is the core: a whole study session runs in an AI app and every piece lands in ViStud. Part 2 (below, "left") is the session page and Undo.

## What was built

**Study sessions over MCP** (explicit, by `session_id`):
- `start_session` (course, optional module or topic, mode): the same session the study page starts, with its clock. It answers the `session_id` and a brief to teach from (`Stack::standing()`, new: the course, student, module and session layers, fenced, without the in-app rules). A second start while one is open is refused with the open session's id.
- `set_session_topic` (by `topic_id`, logged `by: tutor`), `set_checkpoint`, `resume_session`, `end_session` (a summary the in-app tutor already wrote is kept and the new one added after it; answers the minutes), `study_brief`.
- **The clock:** any call carrying an open `session_id` counts as activity; after 30 quiet minutes the session pauses "away" as on the page; the next **write** resumes it and says so (`resumed: true`); a read never resumes it. An ended session refuses saves and says to start a new one.
- A session taught from an AI app records its topic exposure as `explanation`, as the in-app tutor's does (`Sessions::contact`).

**Writes (level read + write):** `set_topic_status` (marked `status_by: chat`), `add_topics`, `save_key_points`, `make_flashcards`, `add_questions`, `answer_question`, `record_quiz`, `answer_flashcard` (new), `write_note`, `save_to_assignment`, `log_progress`. They run the in-app tutor's own tools with a Context from ids (`ContextBuilder::for()`): a `session_id` gives the course, module and folder; a `course_id` that is not the session's is refused, naming the right course; a write that needs a session and has none is refused with `missing: session_id`.

**The capture contract** (ADR 0002 §9, plan §3.6), on every write:
- An acknowledgement in `structuredContent`: `status` (`recorded`, `duplicate`, `rejected`, `unchanged`), `session_id`, `resumed`, what was saved and its ids, or the `reason` and the fix.
- `capture_key`: the same key again answers `duplicate` and saves nothing; the same key with other content is `rejected` (`capture_key_conflict`).
- `App\Study\CaptureFilter` (new): a password, key, token or connection string is `rejected` as `sensitive` before any service runs; nothing of it is kept, not even in `mcp_calls`.
- A daily cap of 500 saved items per student (`McpTool::DAILY_ROWS`), counted from `mcp_calls`; over it, `rejected` `rate_limited` and `mcp.rate_limited` in the audit log.

**Who judged it:** every write's Context carries the judge `{model: '<company>-connector', prompt: 'mcp-rules@<hash>', via: 'mcp'}`, never what the app calls itself. `Flashcards::answer()` takes a judge: `answer_flashcard` records `judged_by: ai` unless `graded_by: student`.

**Levels:** a read-only connection neither lists nor runs a write (`McpTool::shouldRegister()`); calling one is a JSON-RPC "not found".

**Rules:** `resources/prompts/mcp-rules.md` now says to start a session before saving, pass `session_id`, and say "saved" only after `recorded`.

**Contract:** `tests/Feature/Mcp/ContractSnapshotTest` compares the tool list (names, arguments, required, hints) with `tests/Feature/Mcp/fixtures/tools.json`: 38 tools at level full.

**Tests (new):** `Mcp/SessionToolsTest` (start, the open-session answer, topic by id logged as the tutor's, quiet pause and resume by a write only, end keeping an earlier summary, explanation exposure), `Mcp/WriteToolsTest` (in a session with an acknowledgement, no session, the wrong course, capture keys, secrets, the judge on quiz attempts, `chat` marks, flashcard grading, read-only apps, the daily cap), `Mcp/ContractSnapshotTest`.

## Choices made while building (from the plan)

- **One cap, not five:** 500 saved items a day in all, instead of separate caps for topics, quizzes and note text. One number is easier to explain; the per-kind caps can come if a real chat app needs them.
- **The capture filter catches secrets only.** Wellbeing, health, other people's data and venting can't be told apart by a pattern; the rules tell the model not to save them, and the student reviews what was saved.
- **No `revision` check yet** on `set_checkpoint` and `end_session` (plan §3.7): the summary is appended, never replaced, which covers the risk the revision guarded.

## What Abel tests (plain steps)

With Claude Code connected at level "Read and write" (`claude mcp add --transport http vistud http://127.0.0.1:8000/mcp`):
1. "Start a study session on <course>, <module>, and quiz me on <topic>." Claude calls `start_session`, asks questions one at a time, then saves the quiz.
2. "Save two flashcards about what we did." They appear in ViStud, in that session.
3. "End the session." The session page shows it ended, with the summary and the minutes.

## What is left (part 2)

- **The session page:** the "saved" rail and end screen reading `mcp_calls`, the "Claude is connected" chip, marks shown as *set by a chat app (Anthropic)* with Undo.
- **Undo what this connection did** on the Connections screen (plan §3.8).
- **Notes edited in two places:** the 409 banner naming the AI app and the version poll (plan §3.7).
- `Architecture/AdapterParityTest`.
- **ChatGPT write probes:** they need the public server.

## Rollback order

Revert the M6.3 commit. No migration.
