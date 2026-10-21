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
