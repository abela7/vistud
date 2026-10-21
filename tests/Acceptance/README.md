# Acceptance tests

Independent acceptance tests for the M1 gate (docs/handoff/m1-work-packages.md):

- `Brain/`: the golden replay and its edge cases (work package V1), written
  from docs/specs/golden-replay-sql-joins.md and ADR 0002 alone.
- `Security/`: T1–T10 from ADR 0003 §10.5 (work package V2).
- `G2/`: tenant isolation (ADR 0001, G2; second-learner phase S5): every MCP tool,
  resource and prompt, every route, every Livewire component and action, the tutor's
  context, every background job's outgoing prompts, caches, logs, and three deliberately
  broken builds the suite must catch.
- `G3/`: the erasure drill (ADR 0001, G3; phase S6). Its measured results are in
  ADR 0001, "What remains after erasure".

Rules:

- Validators write these tests. Implementers never edit them.
- Disagreements with an ADR or spec go to the PM, not into the test.
- Tests here may land before the code passes them. CI runs this suite in a
  separate job that reports without blocking, until the PM declares the M1
  gate; then it blocks like every other suite.
- Tests that touch the database use `Tests\Concerns\RefreshesDatabase`.
