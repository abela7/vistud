# M1 status

The live state of milestone M1: who owns each work package, where it stands, and what is blocking it. Scope, acceptance criteria and owned files are defined in [m1-work-packages.md](../handoff/m1-work-packages.md); this page tracks progress only.

**Handover (2026-09-25).** Cloud development by the architect is paused and handing over to the local team. Read [LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md) first: branches, what works, what is left, and setup that has been verified in the cloud but not yet on Windows. The PM assigns packages; this page assigns no new work.

## People

| Who | Current activity |
|---|---|
| PM | Reviews, coordinates, assigns packages, approves gates |
| Architect (backend lead) | Paused: cloud development handed over to the local team ([LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md)). WP4 delivered, awaiting review; WP6 in progress, visual direction approved |
| Grok | DOC1 accepted. No package assigned |
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
| Baseline commit | `f70abd0`: DOC1 merged (documentation only), then this handover (documentation only). The last implementation commit on this branch is still `27ce003`; WP4 and WP6 are on their own branches, not merged |
| CI | [.github/workflows/ci.yml](../../.github/workflows/ci.yml): Pint, then the Unit, Feature and Architecture suites on MySQL 8.4. The Acceptance suite runs as a separate step that doesn't fail the build until the PM declares the M1 gate |
| Tests at the baseline | 117 passing: Unit 22, Feature 91, Architecture 4. The Acceptance suite has no tests yet, so CI skips its step |

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
| State | **Delivered, awaiting review** (revised with the PM's rulings on WP4-Q1 to WP4-Q6) |
| Branch / PR | `m1/wp4-projection`, from `a154850`. Pull request: [abela7/vistud#2](https://github.com/abela7/vistud/pull/2) |
| Depends on | WP1 (done). Independent acceptance tests (V1) will be assigned separately; WP4 used its own developer tests and does not own V1's files |
| Next handoff | The PM has approved WP4's additive contract changes. Independent acceptance (V1) is outstanding, so WP4 is not accepted and the pull request stays open. M1 is not complete until the independent acceptance tests exist and pass |

**Starting point:** the untested draft from commit `95904c2` in `app/Brain/Projection/`.

**Delivered:**
- `rules@1` fixes in `Replay` and `Derivation`: supersession only among verdicts of equal authority; `same_as` honours the named survivor; split assignments applied to every kind of evidence; self-report flags scoped as ADR 0002 words them; `weak_part` through nested parts, safe with cycles; retired questions and misconceptions dropped; the `merged` question flag; overridden verdicts listed per attempt.
- The PM's rulings (review of #2): `underconfident` and `practised` as ADR 0002 states them, with `practised` recomputed after a regression; a strict `needs_review` boundary from the contact's upper bound; `merged` on the survivor and undoable; never-asked questions open with nothing invented; the approved `overridden` and `merged` clarifications documented and tested.
- Split completeness: an incomplete split never takes effect. The projection reports it in `invalid_splits` and keeps the previous interpretation; the writer refuses it with `422 split_incomplete` (an explicit contract change, awaiting approval).
- ADR 0002, the golden replay spec and `contracts.md` updated together, marked as PM-approved clarifications.

**Evidence:** 106 projection developer tests in `tests/Unit/Brain/Projection/` (plain PHPUnit, no framework or database), `tests/Architecture/ProjectionPurityTest.php`, and a writer test for split refusal. The developer run of the golden replay reproduces every checkpoint, A4–A6, V1, V2, V4 and V5 as the spec now states them. Full suite: 225 tests pass (Unit 128, Feature 92, Architecture 5). V1's acceptance files (`tests/Acceptance/**`) are untouched. Delivered at `dc55126`, where CI [run 36188952981](https://github.com/abela7/vistud/actions/runs/36188952981) (push) and [run 36188958390](https://github.com/abela7/vistud/actions/runs/36188958390) (pull request) passed.

### WP5 · Redaction, clean-up and canonical files

| | |
|---|---|
| Owner | A second backend developer (proposed) |
| State | **Proposed, awaiting dispatch** |
| Branch / PR | None. Proposed branch: `m1/wp5-redaction` |
| Depends on | WP1 (done); WP3's writer (delivered, awaiting review) for the redaction amendment |
| Next handoff | PM assigns an owner. The tables it uses are Draft in [schema.md](../architecture/schema.md#redaction-clean-up-and-files-draft), and the owner may revise them through the contract-change process |

### WP6 · M1 web adapters

| | |
|---|---|
| Owner | Architect (backend lead), assigned by the PM. Paused with the handover; who continues it is the PM's decision |
| State | **In progress.** The PM approved the visual direction for continuation after reviewing PR #3 at `8ac9f03`; that does not accept WP6. Built: the visual foundation (DESIGN.md, themes with gradients, enforcement, shared components) and the login screen. Not built: the other WP6 screens |
| Branch / PR | `m1/wp6-web-adapters`, from `a154850`, with this integration branch merged in for the handover: [PR #3](https://github.com/abela7/vistud/pull/3), open for further review |
| Depends on | WP2's service contracts (delivered, awaiting review) |
| Next handoff | The PM's logo decision, then the remaining screens in the order in [LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md#4-what-is-left-in-wp6), each with the DESIGN.md §10 checks and a PM visual review |

**Evidence:** commits `7ffab31`, `a4eeb4f`, `9e629da`, `8ac9f03`; CI [run 36193910486](https://github.com/abela7/vistud/actions/runs/36193910486) (push) and [run 36193914240](https://github.com/abela7/vistud/actions/runs/36193914240) (pull request) passed, both the `test` and `browser` jobs. PHP 145 passing (Unit 43, Feature 95, Architecture 7); browser 14 passing (`tests/Browser/theme-sentinel.spec.js`, `theme-switch.spec.js`, `login-accessibility.spec.js`), with the 11 preview generators skipped unless `PREVIEWS=1`.

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

### DOC1 · Setup documentation

| | |
|---|---|
| Owner | Grok |
| State | **Accepted** by the PM at `362b090` |
| Branch / PR | `doc1/setup-docs`, pull request [#1](https://github.com/abela7/vistud/pull/1), merged into the integration branch as `f70abd0` |
| Depends on | Onboarding baseline `242317c` |
| Next handoff | None |

**Baselines:** The implementation baseline is `27ce003` (M1 increment 3, the last implementation commit). The onboarding baseline is `242317c` (documentation only, added on top of `27ce003`). DOC1 is branched from `242317c`.

**Scope:** `PROJECT.md` section 6, `docs/development/setup.md`, the setup wording in `README.md`, and this entry. No application code, dependencies, database changes, or migrations.

**Evidence:** commit `f25d7d6`; CI [run 36186221486](https://github.com/abela7/vistud/actions/runs/36186221486) passed; pull request [#1](https://github.com/abela7/vistud/pull/1).

## Decisions

Recorded by the PM before WP4 started, and since.

| # | Decision | Effect |
|---|---|---|
| Branch | The integration branch is `claude/persistent-study-context-zsilo6`. No new `main` is needed now | Package branches start from it |
| Q1 | Mandatory admin 2FA restricts admin access and admin operations. The account's own student workspace stays available | Matches the current code; [conventions.md](../architecture/conventions.md#authentication) updated |
| Q2 | Keep Fortify's required dependencies (`laravel/passkeys` and WebAuthn libraries), with passkeys disabled | No change |
| Q3 | Manually shared, expiring, single-use invitation links are enough for the owner and synthetic pilot. Tokens stay out of logs | Matches the current code: only a SHA-256 hash is stored, the token is returned once, and it never goes into logs, audit records or error messages |
| Design | DESIGN.md is the shared UI and UX guide, under ADR 0003. The owner's logo sets the brand colours (deep blue to ocean to teal), replacing the earlier indigo proposal. Gradients are part of the identity and fully theme-controlled. WP6 uses the shared visual foundation instead of unstyled markup; M2 is not started | Recorded in ADR 0003 §6 and §16 on the WP6 branch |
| Design review | After reviewing PR #3 at `8ac9f03`, the visual direction is approved for continuation. Keep "Your study brain, kept for you." for now. Keep Ember as the third built-in theme. Prefer the white logo directly on dark or gradient surfaces, and keep the full-colour logo on light surfaces | WP6 is not accepted. The logo preference is **not implemented yet**: the code still shows the full-colour logo on a light plate on dark surfaces. It is the first next task in [LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md) |
| WP4 contracts | WP4's additive contract changes are approved | WP4 stays delivered, awaiting review, until independent acceptance (V1) |
| DOC1 | DOC1 is accepted | Merged as `f70abd0` |
| D9 | Staged retrieval measurement is approved. G1a covers in-app queries; G1b later checks chat queries. The agreed evidence thresholds apply, and insufficient evidence is reported as **inconclusive**, not decided just because 4–6 weeks have passed | [ADR 0003 §16](../adr/0003-web-workspaces-and-study-content.md#16-decisions) and [ADR 0001 G1](../adr/0001-permanent-store-and-retrieval-index.md#g1-retrieval-value) updated |

## Unresolved decisions

| # | Decision | Where | Current behaviour |
|---|---|---|---|
| — | Validation-gate thresholds, retention periods, G4 hardware, pilot size before G4, and whether private text may ever go to an external processor | [ADR 0001, open parameters](../adr/0001-permanent-store-and-retrieval-index.md#open-parameters-for-the-owner) | To be confirmed before any data is collected |

## Blockers

| Package | Blocked on |
|---|---|
| WP5, V1, V2 | Dispatch: no owners assigned yet |
| WP6's remaining screens | The PM deciding who continues WP6 locally |
| WP4 acceptance | V1's independent acceptance tests |
| V2 (Livewire cases) | WP6 |
| V1 (redaction cases) | WP5 |
| Contracts from WP2 and WP3 becoming Stable | PM review of increments 2 and 3 |
| Local development on Windows | Not verified: PHP 8.4 and MySQL 8.4 there, the database-user step (documented for Unix shells only), and the Unix-only concurrency test ([LOCAL-TAKEOVER.md](../handoff/LOCAL-TAKEOVER.md#9-what-was-verified-where)) |
| The M1 gate | V1 and V2 tests existing and passing (the concurrency test executing on Unix), WP4, WP5 and WP6 delivered, and the PM's approval |
