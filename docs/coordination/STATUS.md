# M1 status

The live state of milestone M1: who owns each work package, where it stands, and what is blocking it. Scope, acceptance criteria and owned files are defined in [m1-work-packages.md](../handoff/m1-work-packages.md); this page tracks progress only.

## How this page is kept

- **Each developer updates only their own package's entry**, at each handoff and whenever its state changes, in the same pull request as the work where possible.
- **Changes to shared scope or ownership go through the PM.** Don't edit another package's entry, and don't move work between packages yourself.
- **Only the PM moves a package to Accepted**, and only the PM declares the M1 gate.
- Record evidence as commit IDs, pull request links, CI runs and named tests, not descriptions.

**States:**

| State | Meaning |
|---|---|
| Proposed, awaiting dispatch | Defined in the work packages, but no owner has been confirmed and no work has started |
| In progress | Owner confirmed and working on it |
| Blocked | Can't continue; the entry says on what |
| Delivered, awaiting review | Pushed with evidence; waiting for the PM |
| Changes requested | The PM has asked for changes |
| Accepted | Approved by the PM |

## Integration

| | |
|---|---|
| Integration branch | `claude/persistent-study-context-zsilo6`, currently the repository's only and default branch. The PM decides whether to create `main` before dispatch |
| Baseline commit | `27ce003` (M1 increment 3), the last implementation commit. The onboarding commit that adds this page changes documentation only |
| CI | [.github/workflows/ci.yml](../../.github/workflows/ci.yml): Pint, then the Unit, Feature and Architecture suites on MySQL 8.4. The Acceptance suite runs as a separate step that doesn't fail the build until the PM declares the M1 gate |
| Tests at the baseline | 117 passing: Unit 22, Feature 91, Architecture 4. The Acceptance suite has no tests yet |

## Work packages

### WP1 · Foundations and contracts

| | |
|---|---|
| Owner | Architect (backend lead) |
| State | **Delivered, awaiting review** |
| Branch / PR | Committed directly to the integration branch as `db7876e` (no pull request) |
| Depends on | — |
| Next handoff | PM review of the handoff documents, schema and contracts |

**Evidence:** commit `db7876e`; CI [run 36180988096](https://github.com/abela7/vistud/actions/runs/36180988096) passed. Tests (24): `tests/Feature/Platform/ErrorEnvelopeTest.php`, `tests/Feature/Platform/AuditLogPermissionsTest.php` (the M1 checklist item "audit log append-only at the database-permission level"), `tests/Architecture/LearnerIsolationTest.php`, `tests/Unit/Platform/GuardTest.php`.

### WP2 · Identity and access services

| | |
|---|---|
| Owner | Architect (backend lead), as proposed |
| State | **Delivered, awaiting review** |
| Branch / PR | Committed directly to the integration branch as `2e03975` (no pull request) |
| Depends on | WP1 |
| Next handoff | PM review, including questions Q1–Q3 below. On approval, the Identity and Audit contracts become Stable, and WP6 and V2 can build on them |

**Evidence:** commit `2e03975`; CI [run 36182463741](https://github.com/abela7/vistud/actions/runs/36182463741) passed. Tests (56): `tests/Feature/Identity/*` (accounts, roles, invitations, 2FA reset, admin workspace, Fortify, console commands, `/me`, and a two-connection last-admin concurrency test), `tests/Feature/Audit/AuditLogTest.php`, `tests/Feature/Api/OpenApiContractTest.php`.

**Not done here, by design:** no screens (WP6); Fortify views are off until WP6 asks; no invitation email (Q3); account deletion marks the account deleted and ends its sessions, but the erasure job comes before another real learner joins (ADR 0001).

### WP3 · Journal writer and store

| | |
|---|---|
| Owner | Architect (backend lead), as proposed |
| State | **Delivered, awaiting review** |
| Branch / PR | Committed directly to the integration branch as `27ce003` (no pull request) |
| Depends on | WP1 |
| Next handoff | PM review. On approval, the Journal contracts become Stable. WP5 builds its redaction amendment on the writer |

**Evidence:** commit `27ce003`; CI [run 36183422270](https://github.com/abela7/vistud/actions/runs/36183422270) passed. Tests (37): `tests/Feature/Brain/Writer/JournalWriterTest.php`, `tests/Feature/Brain/Store/StoredProjectionTest.php`, `tests/Feature/Brain/Store/ConcurrentAppendTest.php` (two forked processes, 1,000 appends, gap-free positions), `tests/Feature/Api/JournalEntryApiTest.php`, `tests/Unit/Brain/Journal/EntryValidatorTest.php`.

**Not done here, by design:** the writer does not yet check claims' acceptance against `policy@1` (ADR 0002 §8); that belongs to the capture services in M6.

### WP4 · Projection engine to `rules@1`

| | |
|---|---|
| Owner | Architect (backend lead), as proposed |
| State | **Not started** (proposed, awaiting the PM's go-ahead) |
| Branch / PR | None yet |
| Depends on | WP1 (done). Works against V1's acceptance tests |
| Next handoff | Start when the PM confirms it, ideally once V1 is dispatched |

**Current progress, exactly:**
- The projection classes in `app/Brain/Projection/` (`Replay`, `Derivation`, `Projector`, `Rules`, `ProjectionOptions`) are the **untested, unreviewed draft** from commit `95904c2`. They are byte-for-byte unchanged since then.
- They have **not** been run against the golden replay, its variants or its edge cases. Whether they pass is unknown.
- Their only exercise so far is WP3's `StoredProjectionTest`, one small scenario that checks stored and in-memory projections are identical and that the topic reaches `working`. That is not evidence that `rules@1` is implemented correctly.
- Two WP3 changes touch what the projection uses, without changing its behaviour: `ReviewPolicy::violation()` now returns a structured result (the projection only checks it against `null`), and `ProjectionRunner` was added to read stored entries.

### WP5 · Redaction, clean-up and canonical files

| | |
|---|---|
| Owner | A second backend developer (proposed) |
| State | **Proposed, awaiting dispatch** |
| Branch / PR | None. Proposed branch: `m1/wp5-redaction` |
| Depends on | WP1 (done); WP3's writer (delivered, awaiting review) for the redaction amendment |
| Next handoff | PM assigns an owner. The tables it uses are Draft in [schema.md](../architecture/schema.md#redaction-clean-up-and-files-draft), and the owner may revise them through the contract-change process |

### WP6 · M1 web adapters (unstyled)

| | |
|---|---|
| Owner | A Livewire/Blade developer (proposed) |
| State | **Proposed, awaiting dispatch** |
| Branch / PR | None. Proposed branch: `m1/wp6-web-adapters` |
| Depends on | WP2's service contracts (delivered, awaiting review) |
| Next handoff | PM assigns an owner. Its routes go in `routes/web/auth.php`, `routes/web/student.php` and `routes/web/admin-screens.php`; turning on Fortify's views is a one-line change the architect makes on request |

### V1 · Golden replay and edge-case acceptance tests (independent)

| | |
|---|---|
| Owner | An independent validator (proposed) |
| State | **Proposed, awaiting dispatch** |
| Branch / PR | None. Proposed branch: `m1/v1-golden-replay` |
| Depends on | WP1 only (the entry format and projection output are Stable). The redaction tests (V3, R1–R3) also need WP5 |
| Next handoff | PM assigns a validator. Can start immediately; WP4 works against these tests |

### V2 · Security acceptance tests T1–T10 (independent)

| | |
|---|---|
| Owner | An independent validator, ideally not V1's (proposed) |
| State | **Proposed, awaiting dispatch** |
| Branch / PR | None. Proposed branch: `m1/v2-security` |
| Depends on | WP2 and WP3 (delivered, awaiting review), and WP6 (not dispatched) for the Livewire cases |
| Next handoff | PM assigns a validator. The HTTP, API and service-level cases can start before WP6 lands |

## Unresolved decisions

| # | Decision | Where | Current behaviour |
|---|---|---|---|
| Q1 | Does an admin without 2FA lose access to everything, or only to the admin workspace? | [m1-work-packages.md](../handoff/m1-work-packages.md#questions-for-the-pm) | Admin workspace and admin services only |
| Q2 | Is it acceptable that Fortify 1.40 requires `laravel/passkeys` and its WebAuthn libraries? | same | Installed; the passkeys feature is off |
| Q3 | Is showing the invitation link once to the admin enough, without email, for the pilot? | same | No email |
| D9 | Staging retrieval gate G1 against the chat features | [ADR 0003 §16](../adr/0003-web-workspaces-and-study-content.md#16-decisions) | Not needed until M3–M4 |
| — | Validation-gate thresholds, retention periods, G4 hardware, pilot size before G4, and whether private text may ever go to an external processor | [ADR 0001, open parameters](../adr/0001-permanent-store-and-retrieval-index.md#open-parameters-for-the-owner) | To be confirmed before any data is collected |
| — | The integration branch for other developers (`main`, or the current branch) | This page | Everything is on `claude/persistent-study-context-zsilo6` |

## Blockers

| Package | Blocked on |
|---|---|
| WP4 | The PM's go-ahead; ideally V1 dispatched first, so the projection is fixed against independent tests |
| WP5, WP6, V1, V2 | Dispatch: no owners assigned yet |
| V2 (Livewire cases) | WP6 |
| V1 (redaction cases) | WP5 |
| Contracts from WP2 and WP3 becoming Stable | PM review of increments 2 and 3 |
| The M1 gate | V1 and V2 tests existing and passing, WP4 and WP5 delivered, and the PM's approval |
