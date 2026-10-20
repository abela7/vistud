# S2b: remaining personal text

Implemented for review as one local commit. S3 and later phases remain pending;
the second-learner gate stays closed. No deploy, key provisioning, migration or
backfill was run on Abel's local database. Never pushed.

## Storage and behaviour

The exact list of 46 encrypted columns across 21 tables and their original types
is `ContentColumns::S2B`:

| Table | Encrypted columns |
|---|---|
| workspaces | name, code, term |
| modules | title, about |
| folders, files, topics | name |
| links | title, url |
| instructions | text |
| activities | title |
| activity_items | title, notes, labels |
| activity_members | name |
| activity_sources | title, text |
| activity_understandings | body, changes, sources |
| activity_log | text |
| course_profiles | about, outcomes, assessment, textbook, syllabus_text, proposed_modules, language, tools, exam_format |
| learner_profiles | preferences, note |
| engine_settings | language |
| file_digests | summary, outline, topics, language |
| topic_suggestions | name, reason, note, covers, sources |
| module_briefs, folder_briefs | text |
| quizzes | questions |

All use S2a's AES-256-GCM envelopes and authenticate learner, table, row identity,
column and encoding version. Briefs use their actual module/folder primary keys.
Services, screens, engine context and MCP receive the same logical text and JSON.
Metadata sorts/filters remain SQL; predicates, joins, JSON expressions, grouping,
sorting and aggregates on encrypted columns still fail loudly. The remaining
file-source and dismissed-suggestion name sorts decrypt first, then use a root
PRIMARY Collator with stable ID ties in PHP.

The existing suggestion uniqueness index remains `(learner_id,module_id,name_key)`.
`name_key` now stores HMAC-SHA256 under the learner's HKDF search subkey, with a
purpose label and the existing lowercase/120-character PHP normalization.
Insert/update always derive it from plaintext name, ignoring any copied index.
Duplicate checks use that hash; folder carries can compare it within the same
learner. Full-row reads and backfill verification check it against decrypted name.
This is only the existing exact-match uniqueness index, not a retrieval index.

Moves, restores, renames and metadata updates decrypt and re-encrypt through the
adapter. Digest reuse copies logical fields into a new row and new envelopes.
IDs, relationships, positions, dates, counts, fixed states and allowlisted codes
remain metadata. The student's AI credential remains APP_KEY-encrypted until S8.
File bytes, extracts, previews and recordings remain S3 work.

Both schema migrations now own frozen column/original-type lists. S2a also freezes
its two UUID identity decisions. S2b does not reference the runtime registry.
LONGTEXT preserves every previous column capacity after nested JSON/base64 growth;
existing service limits remain. Near-limit tests cover an 80-character Unicode
course name and a 40,000-character Unicode syllabus, alongside S2a's 2 MB note.

## Backfill and operations

Follow [deploy.md](../development/deploy.md#core-personal-text-s2a-offline-conversion-before-reopening).
Abel and the reviewer run this later; the builder has not done it locally.
Complete S1 migration/provisioning, including Abel, first. Keep matching verified
content/key backups and the master key. Stop workers and scheduler and keep the
app in maintenance until both phases are converted and verified.

`vistud:encrypt:backfill` defaults to both phases. `--phase=S2a` or `--phase=S2b`
selects one; `--table` must belong to that phase when supplied. `--learner` narrows
the stream. Dry-run counts rows, non-null content cells, legacy/encrypted rows,
learners and blockers without writing rows, keys or caches. Suggestion hashes are
derived indexes, not an additional content-cell count.

The existing stable-key batches, row locking, atomic conversion/readback checks,
version-based resume and read-only verify apply to every S2b table. Suggestion
conversion replaces the readable name_key with its hash and verifies it in the
same transaction. Missing keys, unowned rows, corrupt envelopes or mismatched
hashes block, without printing content. Repeating a completed conversion verifies
and skips it without changing ciphertext. Keep the ChooseTopics/Troubles deploy
cache purge: delete old entries and let them refill encrypted; do not convert.

Rollback only S2b with verified `--phase=S2b --decrypt --backup-confirmed`, then
roll back its migration as owner and deploy the approved S2a code. The decrypt
restores normalized name_key values. S2a rows stay encrypted. Rolling back both
requires decrypting both and restoring both original schemas before old code.
Schema down refuses encrypted rows and values outside original string/JSON/byte
limits. Keep maintenance on if any step fails; never shrink ciphertext or replace
missing keys. All rollback drills here use only named synthetic test schemas.

The S9 bulk-update measurement follow-up is recorded in ADR 0001 G4 and
[operations.md](../development/operations.md): measure heavy-module trash/restore
and large-folder moves, including latency, throughput, memory and locks on the
real server or equally sized copy. No scale result is claimed here.

## Validation

- Focused final run: **53 passed, 726 assertions**, including all new S2b tests,
  S2b-only and combined schema rollback/redeployment, folder study, plans and
  Architecture. The rollback drill refreshes only `vistud_test` and
  `vistud_keys_test`; no root account is used.
- Broader study/engine/platform run: 620 passed; the two old plaintext test
  assumptions it found were corrected and pass in the focused run. The known
  AssignmentsTest failure remains unchanged.
- Pint and PHPStan pass for all changed PHP application, migration and test files.
- Browser helpers now use scoped decrypted reads for uploaded names and topic
  names. Playwright has not been run; no resource JavaScript/CSS changed.
- The first isolated full run found eight more legacy plaintext assertions in
  MCP confirmation/panel/header tests. They now read through the corresponding
  scoped services; all confirmation, denial and no-change expectations remain.
  The focused rerun passes **18 tests, 228 assertions**; Pint and PHPStan pass.

Final full clean-copy run on snapshot
`a4b98f4183515cef3d82430c6a8457ab458ab985`: **1,509 tests; 1,507 passed;
1 known error; 1 existing Windows skip; 11,781 assertions; 233.4 seconds**.
The only error is the acknowledged
`AssignmentsTest::test_its_folder_follows_its_name_and_its_module_and_stays_with_its_files_when_it_goes`
(Not found at line 88). The skip is the existing pcntl-dependent concurrent-append
test on Windows. No new failure or skip remains.

Composer junction classmaps are normalised/remapped with the same bootstrap used
for S2a. Reflection confirms LearnerTables, Rollups, Tests\TestCase and UserFactory
load from the isolated checkout. The final commit differs from this tested
snapshot only in the handoff and operations validation notes; all application,
migration, browser-helper and test sources are identical. Only this phase's 23
files are committed. The four pre-existing leftovers are unchanged.

Independent G2/G3 suites remain the validator's responsibility. No S3 work started.
