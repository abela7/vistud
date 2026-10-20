# S2a: core personal text

Delivered for review, with final clean-copy validation recorded below. S2b must
wait for review of this commit. No live
migration, S1 provisioning, content backfill, cache purge or push was performed.

## Scope

The codec, scoped persistence adapter, registry and owner-run backfill cover:
notes/title; note_versions/title/doc; questions/text/answer; findings/text/locator;
flashcards/front/back; tutor_memories/text; study_sessions/summary/checkpoint/
topic_log/tutoring; engine_threads/title/summary; engine_messages/content/
tool_calls/attachments/effects/reasoning; journal_entries/body/links;
journal_content/text; journal_refs/locator; projection_snapshots/snapshot;
outbox/payload/last_error; mcp_confirmations/input; class_sessions/title/teacher/
language/problem; class_transcripts/text/segments/language; class_reviews/body.
Class notes/transcript notes/lecture notes use the normal versioned notes path.
Class recording/material rows contain metadata only; disk encryption is S3.

S2b remains all other planned columns: course/module/folder/file/topic/activity
names, links/instructions, plans and assignment text, course/learner profiles,
digests, suggestions, briefs, quizzes and engine_settings.language. The registry
currently describes only S2a. The gate needs both parts and remains closed.

## Storage and queries

- Laravel AES-256-GCM with the learner content subkey; authenticated payload binds
  format version, learner, table, row identity and column. No plaintext fallback.
- Immutable unique UUID identities are reserved before INSERT for the two
  auto-increment tables, journal_refs and outbox. Other rows bind their physical
  key within a learner. Journal refs also bind entry/role/reference type/id.
  This preserves append-only runtime privileges and numeric keys.
- Services still receive plaintext. Partial reads, get/first/value/pluck and lazy
  reads hydrate through the same adapter. Metadata-only aggregates remain SQL.
  Runtime insert/update encrypts with each destination's identity. SQL predicates,
  ordering, aggregation and JSON expressions on content are refused.
- VistudAi title search decrypts before filtering and limiting; Findings sorts
  decrypted note titles. MeasureG1b decrypts event bodies and reads judge.via in
  PHP. Agreement checks filter decrypted message effects in PHP. Journal
  replay/rebuild and stored projections use the same layer.
- All widened content columns are LONGTEXT. The existing 2 MB note limit is
  retained; nested JSON/base64 expansion is tested near that real limit.
- ChooseTopics note/review payloads and Troubles payloads refill under the derived
  cache key, authenticated to learner and cache key. Deploy deletes their old
  entries; no conversion and no global cache flush. Metadata caches stay metadata.
- AccountFactory's no-open-transaction check can be explicitly enabled in tests;
  production cannot disable it using that switch.
- OpenRouter refusal logs keep provider/model/status only; a provider's raw
  error text may echo a prompt. Its student-facing diagnostic stays available
  in memory and in the encrypted Troubles cache; AppError is not reported by
  Laravel and engine jobs persist only the error code.

## Backfill and recovery

See [deploy.md](../development/deploy.md) for the exact maintenance procedure.
S1 key migration and provisioning (including Abel) come first, performed by Abel.

`vistud:encrypt:backfill --dry-run` only reads and counts, by table: rows, non-null
cells, legacy/encrypted rows and blockers; it also reports learner/changed counts.
No content or keys are printed. Missing learners/unowned rows and missing keys
are blockers. `--table` and `--learner` narrow the scope; unknown tables fail.

The real command requires maintenance, stopped workers and `--backup-confirmed`.
It walks stable primary/composite keys in bounded batches, locks/rereads each row,
writes all fields and the version atomically, then rereads, decrypts and compares
every value before commit. Any exception rolls back that row. Committed version-1
rows are verified/skipped on restart; no cursor file can skip unconverted data.
`--batch` controls reads; `--limit` provides a repeatable interruption point.
`--verify` is read-only and fails for legacy, corrupt or unreadable rows.

For explicit offline rollback, `--decrypt --backup-confirmed` verifies then
restores plaintext with version 0, atomically per row. Schema down preflights all
tables for encrypted rows, original string/byte limits and JSON validity, then
restores original declarations. It never silently shrinks ciphertext. Keep the
app offline until either deployment or rollback finishes. Keep matching backups
and the master key; never replace a destroyed learner key to make a check pass.

## Validation

- Focused final run: **85 passed, 725 assertions**, covering the new content and
  backfill tests, OpenRouter, agreements, assignment companion, G1b, VistudAi and
  Architecture. This includes the six agreement-flow regressions found in the
  first broader run and fixed by moving the effects filter into PHP.
- Separate MySQL schema drill: **1 passed, 16 assertions**. It refuses rollback
  while ciphertext exists, explicitly decrypts, restores original column types,
  migrates/encrypts again and verifies the original note/title. Only the named
  synthetic content/key schemas are refreshed; no root account is used.
- Pint passes for every changed PHP file. PHPStan passes for changed application
  code, the migration, all three new feature-test files, G1b and OpenRouter tests.
- An expanded PHPStan scan including all modified legacy tests still reports
  **five existing diagnostics**, reproduced from unchanged `dc66e65` file contents:

| Existing file | Diagnostics |
|---|---|
| `tests/Feature/Engine/ToolTruthTest.php` | `form` is absent from the existing quiz-question PHPDoc shape |
| `tests/Feature/Engine/VistudAiTest.php` | A redundant `assertNotNull(SessionDetails)` and unrecognised `assertSeeLivewire` macro |
| `tests/Feature/Study/TutorMemoryTest.php` | Two unrecognised `assertSeeLivewire` macros |

No suppressions or baseline entries were added. Raw plaintext test
assertions/fixtures now use scoped reads/writes; dedicated raw checks assert
ciphertext. The browser flashcard assertion now uses the service too; Playwright
was not run, and no bundled resource JavaScript/CSS changed.

Full clean-copy suite on snapshot
`b20fee89f08f89d56b057346a8765998f3338351`: **1,502 tests; 1,500 passed;
1 known error; 1 existing skip; 11,433 assertions; 276.6 seconds**.
The only error is the acknowledged
`AssignmentsTest::test_its_folder_follows_its_name_and_its_module_and_stays_with_its_files_when_it_goes`
(Folders reports Not found at test line 88). The skip is the existing Windows
pcntl-dependent concurrent-append test. No new test failed or was skipped.

Dependency junction classmaps are normalised/remapped; reflection verifies
LearnerTables, Rollups, Tests\TestCase and UserFactory are loaded inside that
checkout. The final commit differs from the tested snapshot only in documentation
of validation results; application, migration and test sources are identical.

Independent G2/G3 suites remain the validator's responsibility. S2a does not erase
old logs/backups. Existing provider-refusal logs may contain echoed text and need
the S4 retention/deletion review. Unowned `outbox` rows block automatic conversion
for classification; the command never assigns them a shared or arbitrary key.
