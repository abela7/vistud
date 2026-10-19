# ViStud · M6.3 (Sessions and writes): handoff, part 1

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.3; sections 3.3, 3.6 and 3.7). Decision 6 (explicit sessions) was made by Abel on 2026-10-18 and ADR 0002 §9 amended the same day. Part 1 is the core: a whole study session runs in an AI app and every piece lands in ViStud. Part 2 (below) is the session page, Undo and notes in two places.

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

## Part 2: the session page, Undo and notes in two places

**The session page** reads what chat apps saved into a session from `mcp_calls` (`App\Mcp\SessionWork`, merged into the in-app activity by `StudySession`):
- The "Saved in this session" line and the end screen count what an app saved (key points, questions, quizzes), looked up live so something deleted since no longer counts. Cards are counted from the deck as before.
- A chip, "Anthropic is connected to this session", while an app called into the session in the last 30 minutes.
- A topic mark an app set reads "Set by a chat app (Anthropic)" with **Undo** (`undoAppMark`: `Topics::unmark`, which leaves it alone if the student has said something since). A quiz an app recorded carries the same tag and its button reads Undo (it is the existing quiz delete, which retracts the answers in the journal).

**Undo what this app did** (Settings, Connected AI apps; `App\Mcp\Undo`): pick a window (an hour, 24 hours, 7 days, everything), see what it would take back, confirm. It takes back, by the ids in `mcp_calls`: flashcards, key points and questions the app made (deleted as the student would), quizzes it recorded (deleted, which retracts their answers), topic marks it set (back to what they were), and answers it wrote to open questions (`Questions::reopenAiAnswer`, the question open again). **Not** taken back, and the screen says so: topics it added, notes it wrote (every note keeps its versions), flashcard reviews, assignment log entries. A call undone once is marked `undone_at` and not taken back twice. `answer_question` now records the question it answered (`saved_ids.answered`) for this.

**Notes in two places** (plan §3.7): a version an app writes is kind `chat_app` (`Notes::writerKind`). `GET /courses/{course}/notes/{note}/pulse` answers the newest version, which company's app wrote it (only while that is the current version) and whether any app was used in the last 10 minutes. The note page polls it every 30 seconds only while an app was active when the page opened (`data-poll`); a newer version is shown like another tab's save, or becomes the usual conflict choice (Keep my version / Use the newer version, both texts kept) if the student has unsaved typing. The conflict alert names the app. The poll ends when no app is active. The editor code (`resources/js/note/editor.js`) is checked by `node --check` and by the server tests for what it reads; it has **not** been exercised in a browser yet.

**Parity registry:** `tests/Architecture/AdapterParityTest.php` has one row per student-facing capability, saying which of Livewire, the JSON API and MCP carry it. A new MCP tool or API route that no row names fails; a row with no API route or no MCP tool must give its reason.

**Tests (new):** `Web/SessionAppWorkTest`, `Mcp/UndoConnectionTest`, `Web/NoteConflictTest`, `Architecture/AdapterParityTest`.

## What is left

- **ChatGPT write probes:** they need the public server (the deploy).
- The note poll in a real browser (two tabs, an app writing).
- Not built, by choice: `revision` checks on `set_checkpoint` and `end_session` (the summary is appended, never replaced); per-kind daily caps; undo of flashcard reviews.

## Rollback order

Revert the M6.3 commit. No migration.
