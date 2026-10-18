# ViStud · M6.0 (Truth first): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.0; section 3 for the architecture rules). M6.0 prepares ViStud's domain, tools, context and data hygiene before any external AI client connects in M6.1.

## What was built

**Who said it** (plan §4 M6.0, §3.6)
- `Topics::report()` now writes `status_by = 'stated'`.
- `Topics::accept()` (new) writes `status_by = 'accepted'` and records a `self_report` journal entry with `origin = 'reported'` (no `judge` field). `WriteBack::apply()` routes `status` items through `Topics::accept()` instead of `Topics::report()`.
- `Topics::mark()` writes `status_by = 'chat'` when `$by->channel === 'mcp'`, and `'tutor'` otherwise. `Topics::unmark()` reverts both `'tutor'` and `'chat'` marks.
- Any legacy row that still carries `status_by = 'student'` reads back via `TopicDetails::statusSource()` as `'stated_unverified'`. The `?? 'student'` fallback in `Topics::details()` is removed.
- `TopicDetails` gains `statusSource(): string` (`'stated' | 'stated_unverified' | 'accepted' | 'tutor' | 'chat' | 'derived' | 'none'`), `studentsWord(): bool` (`'stated'`, `'accepted'`, `'student'`), and `byAi(): bool` (`'tutor'`, `'chat'`; `byTutor()` delegates to it). Raw `status_by` comparisons across `Topics`, `TopicDetails`, `SetTopicStatusTool`, `RecordQuizTool`, `Stack`, `StudySession`, and `Rollups` now go through these helpers.
- `Stack::status()`, `Stack::sessionWord()`, `Briefings::who()` (and the "All topics" intro), `TopicsTool`, `TopicSheet`, and `TopicPage` use matching attribution labels for all seven sources. `TopicsTool` says `"ViStud's tutor"` instead of `"you"` when `Principal::$channel === 'mcp'`.

**History, not rewritten** (plan §4 M6.0)
- `php artisan vistud:topics:attribution-audit [--confirm-all] [--skip-all]` (`App\Console\Commands\AuditTopicAttribution`) finds topics whose `status_by` is `'student'` and whose topic/status pair matches an applied `status` item in `study_sessions.captured`.
- Confirmed rows get `status_by = 'accepted'` and an append-only `self_report` journal entry with `origin = 'reported'` (or an amendment via `EntryWriter::amend()` if an active `self_report` entry is linked to the topic). Existing journal entries are never overwritten. Unconfirmed rows stay `'student'` and report `statusSource() === 'stated_unverified'`.

**Ids in every list** (plan §4 M6.0)
- Every read tool now includes `'id'` on every returned item: `course_overview` (modules and folders), `topics`, `questions`, `findings`, `assignments`, `assignment_plan` (assignment, sections, criteria, milestones, steps), `notes`, `files` (files and links), `module_files`, `earlier_sessions`, `courses`, `search_everything`, and `memories` (both course-tutor and ViStud AI `memories`, returning memory `id` alongside `number`).

**Exact targets for writes over MCP, and no silent ambiguity** (plan §4 M6.0)
- `Lookup::exact()` and `Lookup::hereExact()` accept only an id or an exact normalised name (`Lookup::key()` ignores quotes, case, and extra spaces). `Lookup::forWrite()` and `Lookup::hereForWrite()` dispatch to the exact path when `$by->channel === 'mcp'` and keep `Lookup::one()` / `Lookup::here()` in-app.
- Wired into `SetTopicStatusTool`, `DeleteTopicTool`, `RenameTopicTool`, `RenameModuleTool`, `RenameFolderTool`, `RenameNoteTool`, `EditNoteTool`, `WriteNoteTool`, `AddTopicsTool` (module), `SaveToAssignmentTool`, and `UpdateAssignmentPlanItemTool`.
- `Saving` (`make_flashcards`, `save_key_points`, `add_questions`) and `record_quiz` (`RecordQuizTool`, `Quizzes::record()`) accept `topic_id`. When a topic name matches multiple topics in the course (e.g. `"join"` matching *Outer joins* and *Inner joins*), `Quizzes::record()` saves nothing for that answer in both channels and lists the candidates with their `[id: N]`. Over MCP, `Quizzes::record()` requires `topic_id` or an exact normalised name (dropping the `TopicSuggestions::core` and partial fallbacks).
- `AnswerQuestionTool` accepts `question_id`. Over MCP it requires `question_id` or an exact normalised question text, and refuses to overwrite an already-answered question (`status = 'open'` only).
- `RememberTool` (`replaces`) and `ForgetMemoryTool` accept memory `id` (preferred) everywhere; `number` remains a fallback in-app only (`$by->channel !== 'mcp'`) and is refused over MCP.
- `App\Engine\Vistud\Courses::find()` drops its substring pass when `InCourse` wraps a write (`$forWrite = true`).
- `Topics::row()` verifies the topic's `workspace_id` through `Input::workspace()`, so a stale topic id from a deleted course is rejected as not found (`Study/OrphanWriteTest`).

**Provenance** (plan §4 M6.0, §3.6)
- `App\Engine\Tools\Context` gains `public string $via = 'chat'` and `public ?string $prompt = null`; `Context::judge()` reads them instead of hard-coding `'chat'` and `Stack::promptVersion()`.
- `'mcp'` is added to `App\Brain\Journal\Vocabulary::JUDGE_VIA`.
- `Memory::actor()` passes `'mcp'` through, and `Sessions::record()` writes `Memory::actor($scope, $by)['channel']`.

**Fences** (plan §4 M6.0)
- `App\Study\Fence::wrap()` and `Fence::escape()` (new) generalise `NoteDoc::fenced()` so every untrusted string (student text, file text/metadata, titles, instructions, memories, understandings, summaries) is wrapped in `<material>...</material>` with any literal `</material>` neutralised to `<\/material>` before wrapping.
- Wired into `NoteDoc`, `FileText::profile()`, `ReadFileTool::pages()` and `outline()`, `ReadNoteTool`, `AssignmentSourceTool`, `Tutoring`, `Briefings`, `Stack`, `Overview`, `VistudAi`, and `InCourse`.

**Data hygiene** (plan §4 M6.0, decision 12)
- `LearnerTables::TABLES` registers `outbox` and `canonical_files`. `LearnerTables::shared('canonical_files')` scopes queries to `whereNull('learner_id')->where('visibility', 'shared')` and is enforced by `LearnerIsolationTest::SHARED_ALLOWED`. The dead `app/Platform/Outbox/` entry in `FOR_JOB_ALLOWED` is removed, and `LearnerIsolationTest` now checks reverse schema coverage (`learner_id` tables) and raw SQL patterns.
- Migration `2026_10_17_100000_add_child_table_cascades` cleans any orphan child rows and adds `ON DELETE CASCADE` foreign keys on `engine_messages.thread_id → engine_threads.id`, `session_segments.session_id → study_sessions.id`, and `note_versions.note_id → notes.id`.
- `php artisan vistud:engine:prune-threads` (`PruneEngineThreads`) prunes stale `engine_threads` rows whose course or session is gone via `Spent::forgetMessages()`.
- `php artisan vistud:db:orphans [--prune]` (`DbOrphans`) reports (and optionally prunes) rows in learner/course child tables whose parent row is gone.
- Both commands are scheduled daily in `routes/console.php`.

**Tests added or updated**: `Engine/ToolTruthTest`, `Study/TopicsTest`, `Study/WriteBackTest`, `Study/TrackingTest`, `Study/OrphanWriteTest` (new), `Engine/ProvenanceTest`, `Engine/FenceTest` (new), `Architecture/LearnerIsolationTest`, `Engine/EngineCommandsTest`, `Engine/ToolboxTest`, `Engine/VistudAiTest`, `Web/SessionChatFirstTest`, `Web/TopicSheetScreenTest`.

## What changed for the in-app tutor

- **Ids in read tool output:** Every read tool now includes `'id'` on every row. Guard tests `ToolboxTest:138-139` and `VistudAiTest:177-183` were updated to expect `'id'` in `memories` output.
- **Fenced file outline headings:** `ReadFileTool::outline()` now wraps headings in `Fence::wrap()`. Guard test `ToolboxTest:526` was updated accordingly.
- **Ambiguous quiz topic names refused:** In `Quizzes::record()`, if a topic name partially matches more than one topic in the course (e.g. `"join"` when both *Outer joins* and *Inner joins* exist), nothing is saved for that answer and the candidate topics are listed with their ids. Unique partial names (e.g. `"Outer join"` → *Outer joins*) continue to work in-app.
- **Status attribution wording:** Accepting a status proposal from "Save from the chat" or `WriteBack` now records `status_by = 'accepted'` (*you accepted the tutor's word* / *the student accepted the AI's word*) instead of `'student'`. Pre-M6.0 `'student'` rows show *you said (earlier — may have come from an AI suggestion)* on the topic sheet until confirmed via `vistud:topics:attribution-audit`.

## What Abel tests (plain steps)

1. The new migration (`2026_10_17_100000_add_child_table_cascades`) and cleanup commands have already been run on your local database:
   - `php artisan migrate --database=mysql_owner --force` — **DONE**
   - `php artisan vistud:engine:prune-threads` — **Pruned 4 threads**
   - `php artisan vistud:db:orphans` — **0 orphans**
   - `php artisan vistud:topics:attribution-audit` — **No topics need re-attribution**
2. Open a course in ViStud, start a study session (or use "Save from the chat"), let the tutor propose marking a topic **Understood**, and accept the proposal. Open that topic's sheet: the status line says **You accepted the AI's word**, not *You said so*.

## What is left or deferred

- **Projection weight for `origin = 'reported'`:** As noted in plan §3.6, `app/Brain/Projection` currently counts `origin = 'reported'` the same as `first_hand`. Whether projection rules should weight `reported` differently remains an open question for the ADR 0002 amendment.
- **M6.1+ (OAuth, Passport, `/mcp` transport):** No MCP routes, Passport tables, or new packages were added in M6.0.

## Rollback order

1. If rolling back the database migration:
   `php artisan migrate:rollback --database=mysql_owner --step=1`
   (drops the three `ON DELETE CASCADE` foreign keys on `engine_messages`, `session_segments`, and `note_versions`).
2. The M6.0 code changes to `Lookup`, `WriteBack`, `Topics`, `Fence`, and tool outputs carry no feature flag; rolling back the M6.0 commits restores the previous behaviour cleanly. Any `topics.status_by` rows written as `'stated'` or `'accepted'` fit the existing `VARCHAR(10)` column.

## Message for the local tester

```
Please bring your copy up to date with M6.0 (Truth first). Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude-latest
2. git checkout claude-latest
3. git merge --ff-only origin/claude-latest
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (one new migration: 2026_10_17_100000_add_child_table_cascades; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   .tools\php\php.exe vendor\bin\phpunit tests/Feature/Engine/ToolTruthTest.php tests/Feature/Study/TopicsTest.php tests/Feature/Study/WriteBackTest.php tests/Feature/Study/OrphanWriteTest.php tests/Feature/Engine/ProvenanceTest.php tests/Feature/Engine/FenceTest.php tests/Architecture/LearnerIsolationTest.php tests/Feature/Engine/EngineCommandsTest.php tests/Feature/Web/TopicSheetScreenTest.php tests/Feature/Engine/ToolboxTest.php tests/Feature/Engine/SessionChatTest.php tests/Feature/Engine/AgreementTest.php tests/Feature/Engine/StackTest.php tests/Feature/Engine/VistudAiTest.php

Do not push anything.
```
