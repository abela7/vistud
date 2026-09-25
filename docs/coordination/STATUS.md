# M1 status

The live state of milestone M1: who owns each work package, where it stands, and what is blocking it. Scope, acceptance criteria and owned files are defined in [m1-work-packages.md](../handoff/m1-work-packages.md); this page tracks progress only.

## People

| Who | Current activity |
|---|---|
| PM | Reviews, coordinates, assigns packages, approves gates |
| Architect (backend lead) | WP4, in progress |
| Grok | Reviewing the onboarding documents only. No package assigned |
| Gemini | Not started. No package assigned |

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
| Integration branch | `claude/persistent-study-context-zsilo6` (PM decision: no separate `main` for now). Package branches start from it and return to it by pull request |
| Baseline commit | `242317c`, the onboarding baseline (documentation only, on top of `27ce003`, the last implementation commit) |
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
| State | **In progress** (started on the PM's instruction) |
| Branch / PR | `m1/wp4-projection`, from `a154850` (the integration branch after the decisions were recorded). Pull request: see below |
| Depends on | WP1 (done). Independent acceptance tests (V1) will be assigned separately; WP4 starts with its own rule-level developer tests and does not take ownership of V1's files |
| Next handoff | A focused pull request with implemented rules, test coverage, actual results, remaining gaps, specification questions and any proposed contract changes |

**Starting point:** the untested draft from commit `95904c2` in `app/Brain/Projection/`.

**Progress:**
- `rules@1` fixes in `Replay` and `Derivation`: supersession only among verdicts of equal authority; `same_as` honours the named survivor; split assignments applied to every kind of evidence (attempts, verdicts, teaching, asks, answers, self-reports, exhibits); self-report flags scoped as ADR 0002 words them; `weak_part` through nested sub-topics; retired questions and misconceptions dropped; `merged` question flag; overridden verdicts listed per attempt.
- 93 rule-level developer tests in `tests/Unit/Brain/Projection/` (plain PHPUnit, no framework or database), plus `tests/Architecture/ProjectionPurityTest.php`. A developer run of the golden replay reproduces every checkpoint, A4–A6, V1, V2, V4 and V5, with three differences raised as specification questions (WP4-Q1 to WP4-Q3 in the pull request).
- Not touched: V1's acceptance files (`tests/Acceptance/**`) and every other package.

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

## Decisions

Recorded by the PM before WP4 started.

| # | Decision | Effect |
|---|---|---|
| Branch | The integration branch is `claude/persistent-study-context-zsilo6`. No new `main` is needed now | Package branches start from it |
| Q1 | Mandatory admin 2FA restricts admin access and admin operations. The account's own student workspace stays available | Matches the current code; [conventions.md](../architecture/conventions.md#authentication) updated |
| Q2 | Keep Fortify's required dependencies (`laravel/passkeys` and WebAuthn libraries), with passkeys disabled | No change |
| Q3 | Manually shared, expiring, single-use invitation links are enough for the owner and synthetic pilot. Tokens stay out of logs | Matches the current code: only a SHA-256 hash is stored, the token is returned once, and it never goes into logs, audit records or error messages |
| D9 | Staged retrieval measurement is approved. G1a covers in-app queries; G1b later checks chat queries. The agreed evidence thresholds apply, and insufficient evidence is reported as **inconclusive**, not decided just because 4–6 weeks have passed | [ADR 0003 §16](../adr/0003-web-workspaces-and-study-content.md#16-decisions) and [ADR 0001 G1](../adr/0001-permanent-store-and-retrieval-index.md#g1-retrieval-value) updated |

## Unresolved decisions

| # | Decision | Where | Current behaviour |
|---|---|---|---|
| — | Validation-gate thresholds, retention periods, G4 hardware, pilot size before G4, and whether private text may ever go to an external processor | [ADR 0001, open parameters](../adr/0001-permanent-store-and-retrieval-index.md#open-parameters-for-the-owner) | To be confirmed before any data is collected |

## Blockers

| Package | Blocked on |
|---|---|
| WP5, WP6, V1, V2 | Dispatch: no owners assigned yet |
| V2 (Livewire cases) | WP6 |
| V1 (redaction cases) | WP5 |
| Contracts from WP2 and WP3 becoming Stable | PM review of increments 2 and 3 |
| The M1 gate | V1 and V2 tests existing and passing, WP4 and WP5 delivered, and the PM's approval |
