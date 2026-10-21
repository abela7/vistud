# ADR 0004: Delayed account erasure and the audit exception

Accepted for S4a, 2026-10-21, by Abel's explicit phase instructions.

Deletion immediately blocks the account and revokes sessions and MCP access.
A durable request queues a database job no earlier than three hours later.
That exceeds the one-hour temporary-file expiry plus hourly janitor interval,
and the enforced request/job lifetime. There is no learner work barrier or
ownership tracking for temporary workspaces. Every learner job checks current
account status. First key load checks it once per scoped ring; revocation evicts
the current ring, and request/job boundaries discard it. Work already in flight
finishes inside the bounded grace period. Synchronous and after-response work
use the same checks. No status query is added per decrypted value.

The system-only worker removes registered content through the privileged raw
learner filter, authentication material, learner caches, queued/failed jobs,
private source and derived trees, and expired temporary work. It destroys the
separate learner key only after cleanup succeeds, then sweeps metadata again.
Completion is transactional: one content-free ledger row and the request's
completion timestamp. Retrying any stage is safe, including after key deletion.
A lost dispatch is recovered by the ten-minute pending-request scan.

## Explicit exception to append-only audit history

ADR 0003's read-only audit view and the normal runtime SELECT/INSERT-only grant
remain. The privileged erasure worker may UPDATE existing `audit_log` events
solely to anonymise the erased account: null account/learner actor or target
IDs, IP, user agent, request correlation ID, and associated metadata (which can
include credential hints). References in other events' metadata are nulled.
It retains every event, its action, timestamp and role; it never deletes events.
No general audit editing endpoint or runtime UPDATE privilege is introduced.
This is Abel's approved schema-contract exception, not journal mutation.

Erasure requests and completion rows deliberately survive the deleted identity;
there are no identity foreign keys. Their UUID references are not study content
and must remain for restore replay. The schema rollback refuses nonempty erasure
tables rather than silently losing recovery evidence.

S4b must supply independent ledger export and restore replay before release.
S4a alone cannot protect a restoration that replaces this database's ledger.
The admission gate remains closed; independent G3 validation remains separate
from the builder's regression tests.

## S4b independent evidence and replay

Pending intent and completion are authenticated records in an append-only journal
on separately retained filesystem storage outside the checkout/data restore set.
An independent authentication key and installation UUID bind a chained sequence;
a durably published head detects committed truncation as well as alteration,
removal and reordering. No names, email, IP, credentials or content are recorded.
The authentication key is never in config cache and does not rotate with learner
or master keys. Operator/off-site checkpoints establish freshness; a valid old
signature alone cannot do so.

An authorised intent is durable before the account transaction acknowledges it.
It survives a failed/lost DB commit and is not cancelled by a failed HTTP response.
The retry scan imports it and preserves its original UUID/grace. Key destruction
requires durable intent; independent completion follows actual cleanup and
precedes local completion commit. Thus an interrupted key-last/completion window
is recoverable without relying on the restored database's own erasure log.

Replay requires maintenance/stopped workers, validates all evidence and restored
identity bindings first, then blocks all named accounts before cleanup. It forces
cleanup even for local completed rows, covering keys/files restored independently.
It preserves the original grace and fails closed while anything is deferred.
A completion-only advance permits idempotent resume; any new intent requires a
fresh export. Missing, stale, conflicting or corrupt evidence never reopens the
app. The independent mount is never overwritten by a restore, and old exported
files are never overwritten by export. See operations.md for the approved
retention, two offline recovery kits and mandatory monthly restore drill.

S4b implements replay machinery and builder regressions. The independent G3
acceptance drill, legal policy and admission gate remain later phases.
