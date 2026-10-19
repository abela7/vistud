# ViStud · M6.9 (The second-learner gate): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.9). Goal: before anyone but Abel connects a chat app, a leaked database or token cannot expose their learning.

## What was built

**A gate that reports, and fails while it is closed** (`php artisan vistud:gate:second-learner`). It prints:

- per-learner keys loaded: no (`LearnerKeys::unlock()` is still empty)
- notes, journal and files encrypted: no
- G2 suite: not present (a validator writes it)
- G3 erasure drill: not present (a validator writes it)
- privacy policy: missing (`docs/legal/privacy.md` is not there)
- result: closed

The command exits with a failure so a closed gate cannot be read as a pass. `tests/Feature/Mcp/SecondLearnerGateTest.php` locks that.

## What was not built, and why

Encrypting notes, note versions, the journal, findings, questions, tutor memories, engine messages, the files disk, previews and the file-text cache would rewrite the storage of every learner table. This working copy has a large amount of other people's uncommitted work in those same files. Doing it here would mix that work into the MCP commits or overwrite it. It is the real gate, and it is not done.

The G2 suite and the G3 erasure drill are validator work packages (the plan says so in M6.7). They are not in `tests/Acceptance`. There is no erasure job to revoke tokens first and then wipe `mcp_calls`.

A privacy policy and terms are consent documents. They are not invented here.

No canary dump was run against an encrypted database, because nothing is encrypted. A dump of this database would contain plaintext.

## What Abel decides

Read this note. Do not connect a second person until:

1. Per-learner keys load in `LearnerKeys::unlock()` and the tables and the files disk above are encrypted, with a test that a dump contains no canary.
2. A validator has written the G2 suite over MCP and the G3 drill, and both pass. The drill revokes tokens and connections first, wipes `mcp_calls`, and has a row for text already sent to Anthropic, OpenAI or Google, which ViStud cannot recall.
3. A privacy policy and terms exist, naming those companies, retention and the controls.
4. `php artisan vistud:gate:second-learner` exits 0.

## Rollback order

Revert the M6.9 commit. No migration. The command goes away. Nothing about stored learning changes, because nothing was encrypted.
